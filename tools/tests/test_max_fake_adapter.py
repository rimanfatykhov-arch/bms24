"""RC33 PR-3: fake-provider MAX adapter against an in-process fake BMS (no Moodle, no MAX, loopback only).

Covers the adapter half of N01, N02/N25, N03, N09 (parallel identical webhooks), N10 (lost answer / crashed worker),
N20 (exception envelope with HTTP 200, 429/503 + Retry-After, network errors, bounded retry -> dead letter), N24 (no
secrets or content in logs/inbox after a terminal state) and N32 (401 pauses the connection). The BMS-side behaviour is
tested by local/kbacompany/tests/max_inbound_service_test.php and the FULL-tier scenario tools/ci/browser/max_inbound.py.
"""
import http.client
import importlib.util
import io
import json
import logging
import queue
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('fake_max_adapter', ROOT / 'tools/max_fake_adapter/fake_max_adapter.py')
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)

SECRET = 'synthetic-webhook-secret-0123456789'
TOKEN = 'synthetic-bms-token-abcdef'
CHAT = -7000000000001
BINDINGS = {str(CHAT): {'external_chat_id': str(CHAT), 'connection_uuid': '11111111-1111-4111-8111-111111111111',
                        'binding_uuid': '22222222-2222-4222-8222-222222222222', 'binding_revision': 1,
                        'company_uuid': '33333333-3333-4333-8333-333333333333',
                        'thread_uuid': '44444444-4444-4444-8444-444444444444'}}


def update(mid='mid.synthetic.1', text='Synthetic text', **over):
    msg = {'recipient': {'chat_id': CHAT, 'chat_type': 'chat'}, 'sender': {'user_id': 5000000000001, 'is_bot': False},
           'timestamp': 1794571200000, 'body': {'mid': mid, 'text': text, 'attachments': []}}
    msg.update(over.pop('message', {}))
    return dict({'update_type': 'message_created', 'timestamp': 1794571200000, 'message': msg}, **over)


class FakeBms:
    """Scripted Moodle REST endpoint: each call pops the next (status, body, headers) or repeats the last."""

    def __init__(self, responses):
        self.responses = queue.Queue()
        for r in responses:
            self.responses.put(r)
        self.last = responses[-1] if responses else (200, '"{}"', {})
        self.calls = []
        fake = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                length = int(self.headers['Content-Length'])
                fake.calls.append({'path': self.path, 'body': self.rfile.read(length).decode()})
                try:
                    status, body, headers = fake.responses.get_nowait()
                except queue.Empty:
                    status, body, headers = fake.last
                data = body.encode()
                self.send_response(status)
                for k, v in headers.items():
                    self.send_header(k, v)
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.url = 'http://127.0.0.1:%d' % self.server.server_address[1]

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def ok(outcome='created', **extra):
    body = dict({'schema_version': adapter.SCHEMA, 'outcome': outcome, 'receipt_uuid': 'r-1', 'correlation_id': 'c-1'},
                **extra)
    return (200, json.dumps(json.dumps(body)), {})


def envelope(code):
    return (200, json.dumps({'exception': 'moodle_exception', 'errorcode': code, 'message': 'synthetic'}), {})


class AdapterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.inbox = adapter.Inbox(Path(self.tmp.name) / 'inbox.sqlite')
        self.addCleanup(self.inbox.close)
        self.server = adapter.serve(self.inbox, BINDINGS, SECRET)
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.webhook = 'http://127.0.0.1:%d/webhook' % self.server.server_address[1]
        self.logs = io.StringIO()
        handler = logging.StreamHandler(self.logs)
        adapter.log.addHandler(handler)
        adapter.log.setLevel(logging.DEBUG)
        self.addCleanup(adapter.log.removeHandler, handler)

    def post(self, payload, secret=SECRET, raw=None, headers=None):
        data = raw if raw is not None else json.dumps(payload).encode()
        request = urllib.request.Request(self.webhook, data=data, method='POST')
        if secret is not None:
            request.add_header(adapter.SECRET_HEADER, secret)
        for k, v in (headers or {}).items():
            request.add_header(k, v)
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                return response.status
        except urllib.error.HTTPError as e:
            return e.code

    def bms(self, responses):
        fake = FakeBms(responses)
        self.addCleanup(fake.close)
        return fake, adapter.BmsClient(fake.url, TOKEN)

    def test_n01_missing_wrong_or_duplicated_secret_is_401_and_stores_nothing(self):
        self.assertEqual(401, self.post(update(), secret=None))
        self.assertEqual(401, self.post(update(), secret='wrong-secret-of-same-shape-000000'))
        body = json.dumps(update()).encode()
        conn = http.client.HTTPConnection('127.0.0.1', self.server.server_address[1], timeout=10)
        conn.putrequest('POST', '/webhook')
        conn.putheader(adapter.SECRET_HEADER, SECRET)
        conn.putheader(adapter.SECRET_HEADER, SECRET)
        conn.putheader('Content-Length', str(len(body)))
        conn.endheaders(body)
        self.assertEqual(401, conn.getresponse().status, 'a duplicated secret header is refused')
        conn.close()
        self.assertEqual([], self.inbox.rows())
        with self.assertRaises(ValueError):
            adapter.make_handler(self.inbox, BINDINGS, 'short')

    def test_n02_n25_malformed_or_ambiguous_updates_are_400_without_defaults(self):
        valid = json.dumps(update())
        bad = {
            'malformed': b'{"update_type":',
            'duplicate key': (valid[:-1] + ', "timestamp": 1}').encode(),
            'float id': valid.replace(str(CHAT), str(CHAT) + '.0').encode(),
            'id overflow': valid.replace(str(CHAT), '-9223372036854775809').encode(),
            'nan': valid.replace('1794571200000', 'NaN', 1).encode(),
        }
        missing_bot = update()
        del missing_bot['message']['sender']['is_bot']
        bad['missing is_bot'] = json.dumps(missing_bot).encode()
        bad['string is_bot'] = json.dumps(update(message={'sender': {'user_id': 1, 'is_bot': 'false'}})).encode()
        bad['bool id'] = json.dumps(update(message={'sender': {'user_id': True, 'is_bot': False}})).encode()
        missing_type = update()
        del missing_type['message']['recipient']['chat_type']
        bad['missing chat_type'] = json.dumps(missing_type).encode()
        bad['unknown field'] = json.dumps(dict(update(), extra=1)).encode()
        bad['unknown update'] = json.dumps(dict(update(), update_type='message_pinned')).encode()
        deep = update()
        deep['message']['body']['attachments'] = [{'a': {'b': {'c': {'d': {'e': {'f': 1}}}}}}]
        bad['too deep'] = json.dumps(deep).encode()
        for name, raw in bad.items():
            with self.subTest(name):
                self.assertEqual(400, self.post(None, raw=raw))
        self.assertEqual(413, self.post(None, raw=b'{' + b' ' * adapter.MAX_BODY + b'}'))
        self.assertEqual([], self.inbox.rows())

    def test_n03_unbound_chat_is_recorded_rejected_without_body_and_never_sent(self):
        self.assertEqual(200, self.post(update(message={'recipient': {'chat_id': -7999, 'chat_type': 'chat'}})))
        rows = self.inbox.rows()
        self.assertEqual(1, len(rows))
        self.assertEqual(('rejected', 'unbound', 0), (rows[0]['state'], rows[0]['reason'], rows[0]['has_body']))
        fake, client = self.bms([ok()])
        self.assertEqual(0, adapter.drain(self.inbox, client))
        self.assertEqual([], fake.calls)

    def test_normalizer_preserves_security_flags_and_control_events(self):
        kind, _, dto = adapter.normalize(update(message={'sender': {'user_id': 5, 'is_bot': True},
                                                         'recipient': {'chat_id': CHAT, 'chat_type': 'channel'},
                                                         'link': {'type': 'forward', 'mid': 'mid.x'}}), BINDINGS)
        self.assertEqual('message', kind)
        self.assertEqual((True, 'channel', 'forward', None), (dto['sender_is_bot'], dto['chat_type'], dto['link_type'],
                                                              dto['reply_to_external_message_id']))
        self.assertEqual(str(CHAT), dto['external_chat_id'])
        self.assertEqual('5', dto['external_sender_id'])
        _, _, reply = adapter.normalize(update(message={'link': {'type': 'reply', 'mid': 'mid.parent'}}), BINDINGS)
        self.assertEqual(('reply', 'mid.parent'), (reply['link_type'], reply['reply_to_external_message_id']))
        kind, key1, control = adapter.normalize({'update_type': 'message_removed', 'timestamp': 1794571200001,
                                                 'message': {'chat_id': CHAT, 'mid': 'mid.synthetic.1'}}, BINDINGS)
        self.assertEqual('control', kind)
        self.assertEqual({'schema_version', 'connection_uuid', 'binding_uuid', 'binding_revision', 'external_chat_id',
                          'external_message_id', 'event_type', 'source_timestamp_ms'}, set(control))
        _, key2, _ = adapter.normalize({'update_type': 'message_removed', 'timestamp': 1794571200002,
                                        'message': {'chat_id': CHAT, 'mid': 'mid.synthetic.1'}}, BINDINGS)
        self.assertNotEqual(key1, key2, 'a repeated delete is a separate control event')

    def test_n09_twenty_parallel_identical_webhooks_store_one_event_and_call_bms_once(self):
        statuses = []
        threads = [threading.Thread(target=lambda: statuses.append(self.post(update()))) for _ in range(20)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual([200] * 20, statuses)
        rows = self.inbox.rows()
        self.assertEqual(1, len(rows))
        self.assertEqual(20, rows[0]['deliveries'])
        fake, client = self.bms([ok()])
        self.assertEqual(1, adapter.drain(self.inbox, client))
        self.assertEqual(1, len(fake.calls))
        self.assertEqual('committed', self.inbox.rows()[0]['state'])
        call = fake.calls[0]
        self.assertEqual('/webservice/rest/server.php', call['path'], 'token never in the URL')
        self.assertIn('wsfunction=local_kbacompany_public_inbound_message', call['body'])

    def test_n20_exception_envelope_retry_after_and_bounded_dead_letter(self):
        self.post(update())
        fake, client = self.bms([envelope('inbound_retryable_unavailable'), (503, 'busy', {'Retry-After': '120'}),
                                 (429, 'slow', {}), ok()])
        self.assertEqual('retry_wait', adapter.process_one(self.inbox, client))
        row = self.inbox.rows()[0]
        self.assertEqual(('retry_wait', 'inbound_retryable_unavailable', 1), (row['state'], row['reason'], row['attempts']))
        self.assertIsNone(adapter.process_one(self.inbox, client), 'not due before its backoff')
        far = row['next_at'] + 1
        self.assertEqual('retry_wait', adapter.process_one(self.inbox, client, now=far))
        row = self.inbox.rows()[0]
        self.assertGreaterEqual(row['next_at'] - far, 119, 'Retry-After honoured')
        self.assertEqual('retry_wait', adapter.process_one(self.inbox, client, now=row['next_at'] + 1))
        self.assertEqual('committed', adapter.process_one(self.inbox, client, now=self.inbox.rows()[0]['next_at'] + 1))
        # A different event that never succeeds ends in dead_letter after MAX_ATTEMPTS, not in false success.
        self.post(update(mid='mid.synthetic.2'))
        fake2, client2 = self.bms([(500, 'boom', {})])
        now = 0
        for _ in range(adapter.MAX_ATTEMPTS):
            now = max(now, self.inbox.rows()[1]['next_at']) + 1
            adapter.process_one(self.inbox, client2, now=now)
        self.assertEqual(('dead_letter', 'http_500', adapter.MAX_ATTEMPTS),
                         tuple(self.inbox.rows()[1][k] for k in ('state', 'reason', 'attempts')))
        self.assertEqual(adapter.MAX_ATTEMPTS, len(fake2.calls))

    def test_backoff_is_bounded_with_jitter(self):
        self.assertEqual(0.5, adapter.backoff(1, rnd=lambda: 0))
        self.assertEqual(1.0, adapter.backoff(1, rnd=lambda: 1))
        self.assertEqual(adapter.BACKOFF_MAX, adapter.backoff(30, rnd=lambda: 1))
        self.assertEqual(adapter.BACKOFF_MAX, adapter.backoff(1, retry_after=10 ** 6, rnd=lambda: 0))

    def test_terminal_answers_map_to_states_and_drop_bodies(self):
        cases = [(ok('created', message_uuid='m-1'), 'committed'), (ok('duplicate', message_uuid='m-1'), 'committed'),
                 (ok('review_required', reason='sender_bot'), 'review_required'),
                 (envelope('inbound_scope_denied'), 'rejected'), (envelope('inbound_payload_invalid'), 'rejected'),
                 (envelope('inbound_idempotency_conflict'), 'dead_letter')]
        for i, (response, state) in enumerate(cases):
            self.post(update(mid='mid.terminal.%d' % i))
            fake, client = self.bms([response])
            with self.subTest(state=state, i=i):
                self.assertEqual(state, adapter.process_one(self.inbox, client))
                row = self.inbox.rows()[i]
                self.assertEqual((state, 0), (row['state'], row['has_body']))
        self.assertEqual('sender_bot', self.inbox.rows()[2]['reason'])

    def test_n32_unauthorised_answer_pauses_the_connection_and_keeps_the_event(self):
        self.post(update())
        self.post(update(mid='mid.synthetic.2'))
        fake, client = self.bms([envelope('invalidtoken'), ok()])
        self.assertEqual('paused', adapter.process_one(self.inbox, client))
        self.assertTrue(self.inbox.paused())
        self.assertEqual(0, adapter.drain(self.inbox, client))
        self.assertEqual(1, len(fake.calls), 'no calls while paused')
        self.assertEqual({'retry_wait': 1, 'validated': 1}, self.inbox.counts())
        self.inbox.resume()
        self.assertEqual(2, adapter.drain(self.inbox, client))
        self.assertEqual({'committed': 2}, self.inbox.counts())
        # HTTP 401/403 from a proxy in front of BMS also pause.
        self.post(update(mid='mid.synthetic.3'))
        fake2, client2 = self.bms([(403, 'no', {})])
        self.assertEqual('paused', adapter.process_one(self.inbox, client2))

    def test_n10_crashed_worker_lease_expires_and_restart_keeps_state(self):
        self.post(update())
        leased = self.inbox.lease()
        self.assertIsNotNone(leased)
        self.assertIsNone(self.inbox.lease(), 'a leased event is not handed out twice')
        # Worker crashed after BMS committed but before recording: the retry sees "duplicate" and commits locally.
        restarted = adapter.Inbox(Path(self.tmp.name) / 'inbox.sqlite')
        self.addCleanup(restarted.close)
        fake, client = self.bms([ok('duplicate', message_uuid='m-1')])
        self.assertIsNone(adapter.process_one(restarted, client), 'lease still valid')
        self.assertEqual('committed', adapter.process_one(restarted, client, now=10 ** 12))
        self.assertEqual({'committed': 1}, restarted.counts())

    def test_network_error_and_non_contract_answers_are_never_success(self):
        self.post(update())
        dead = adapter.BmsClient('http://127.0.0.1:9', TOKEN, timeout=2)
        self.assertEqual('retry_wait', adapter.process_one(self.inbox, dead))
        self.assertEqual('network', self.inbox.rows()[0]['reason'])
        fake, client = self.bms([(200, '"not json"', {}), (200, json.dumps({'schema_version': 'other'}), {}),
                                 (302, '', {'Location': 'http://127.0.0.1:1/'})])
        for _ in range(3):
            now = self.inbox.rows()[0]['next_at'] + 1
            self.assertEqual('retry_wait', adapter.process_one(self.inbox, client, now=now))

    def test_n24_loopback_only_and_no_secret_or_content_in_logs_or_terminal_rows(self):
        for url in ('https://bms.example.invalid', 'http://10.0.0.1:8080', 'http://user:pw@127.0.0.1',
                    'http://127.0.0.1:8080/?wstoken=x'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                adapter.BmsClient(url, TOKEN)
        with self.assertRaises(ValueError):
            adapter.serve(self.inbox, BINDINGS, SECRET, host='0.0.0.0')
        self.post(update(text='Secret synthetic body'))
        fake, client = self.bms([ok()])
        adapter.drain(self.inbox, client)
        dump = Path(self.tmp.name, 'inbox.sqlite').read_bytes() + self.logs.getvalue().encode()
        for leak in (SECRET, TOKEN, 'mid.synthetic.1', str(CHAT)):
            self.assertNotIn(leak.encode(), self.logs.getvalue().encode(), leak)
        self.assertNotIn(TOKEN.encode(), dump)
        self.assertNotIn(SECRET.encode(), dump)
        self.assertEqual(0, self.inbox.rows()[0]['has_body'])


if __name__ == '__main__':
    unittest.main()
