#!/usr/bin/env python3
"""RC33 PR-3 / owner D4: synthetic HTTP + concurrency + revocation acceptance of the inbound slice on the disposable
FULL-tier Moodle (PHP built-in server with 4 workers, MariaDB or Percona). CI only; synthetic data only.

Producer only: it records raw observations to $EVIDENCE/inbound-result.json and the DB state through ci_inbound.php.
Every verdict is made by contract.inbound_checks(); this script's own exit status is not a verdict.
No token, secret or message text is written to logs or to the result file.
"""
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
spec = importlib.util.spec_from_file_location('fake_max_adapter', ROOT / 'tools/max_fake_adapter/fake_max_adapter.py')
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)

BMS = 'http://127.0.0.1:8080'
EVIDENCE = Path(os.environ['EVIDENCE'])
CORE = os.environ['MOODLE_ROOT']
SECRET = os.urandom(24).hex()
result = {'status': 'INCOMPLETE'}


def php(*args):
    return subprocess.run(['php', str(HERE / 'ci_inbound.php'), CORE, *args], stdout=subprocess.DEVNULL,
                          stderr=subprocess.PIPE, timeout=300).returncode


def state(label):
    code = php('state', label)
    path = EVIDENCE / ('inbound-state-%s.json' % label)
    return json.loads(path.read_text()) if code == 0 and path.exists() else None


def rest(token, function, dto):
    """Return (http status, outcome or errorcode, reason)."""
    body = urllib.parse.urlencode({'wstoken': token, 'wsfunction': function, 'moodlewsrestformat': 'json',
                                   'payload': dto if isinstance(dto, str) else json.dumps(dto)}).encode()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(urllib.request.Request(BMS + '/webservice/rest/server.php', data=body), timeout=60) as r:
            status, raw = r.status, r.read()
    except urllib.error.HTTPError as e:
        return [e.code, 'http_error', '']
    except Exception as e:  # noqa: BLE001 - recorded as an observation, never a pass
        return [0, type(e).__name__, '']
    try:
        decoded = json.loads(raw)
        if isinstance(decoded, dict) and 'exception' in decoded:
            return [status, 'error:' + str(decoded.get('errorcode')), '']
        decoded = json.loads(decoded)
        return [status, decoded.get('outcome'), decoded.get('reason', '')]
    except Exception:  # noqa: BLE001
        return [status, 'unparsable', '']


def message(company, n, mid, **over):
    b = company['bindings'][n]
    dto = {'schema_version': 'rc33.inbound/1', 'connection_uuid': company['connection'], 'binding_uuid': b['binding'],
           'binding_revision': 1, 'company_uuid': company['uuid'], 'thread_uuid': b['thread'],
           'event_type': 'message_created', 'chat_type': 'chat', 'sender_is_bot': False, 'link_type': 'none',
           'external_chat_id': b['chat'], 'external_sender_id': b['sender'], 'external_message_id': mid,
           'source_timestamp_ms': int(time.time() * 1000), 'text': 'Synthetic CI import ' + mid, 'attachments': [],
           'reply_to_external_message_id': None}
    dto.update(over)
    return dto


def send(company, dto, retries=5):
    """Send once; a retryable answer (lock timeout) is retried with the identical payload, like the adapter does."""
    for _ in range(retries):
        r = rest(company['token'], 'local_kbacompany_public_inbound_message', dto)
        if r[1] != 'error:inbound_retryable_unavailable':
            return r
        time.sleep(0.5)
    return r


def fake_update(b, mid, kind='message_created', **msg_over):
    ts = int(time.time() * 1000)
    if kind == 'message_removed':
        return {'update_type': kind, 'timestamp': ts, 'message': {'chat_id': int(b['chat']), 'mid': mid}}
    msg = {'recipient': {'chat_id': int(b['chat']), 'chat_type': 'chat'},
           'sender': {'user_id': int(b['sender']), 'is_bot': False}, 'timestamp': ts,
           'body': {'mid': mid, 'text': 'Synthetic adapter import ' + mid, 'attachments': []}}
    for key, value in msg_over.items():
        if key in ('recipient', 'sender', 'body'):
            msg[key] = dict(msg[key], **value)
        else:
            msg[key] = value
    return {'update_type': kind, 'timestamp': ts, 'message': msg}


def webhook(url, payload, secret):
    request = urllib.request.Request(url, data=json.dumps(payload).encode(), method='POST')
    request.add_header(adapter.SECRET_HEADER, secret)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(request, timeout=30) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def race(company, n, prefix, revoke_phase):
    """5 imports committed before, 15 concurrent with, and 5 after a revocation running in its own PHP process."""
    out = {'pre': [], 'middle': [], 'post': []}
    for i in range(5):
        out['pre'].append([prefix + 'pre.%d' % i] + send(company, message(company, n, prefix + 'pre.%d' % i)))
    revoker = {}

    def revoke():
        time.sleep(0.15)
        revoker['started'] = time.time()
        revoker['exit'] = php(revoke_phase)
        revoker['finished'] = time.time()

    t = threading.Thread(target=revoke)
    t.start()

    def one(i):
        time.sleep(i * 0.03)
        mid = prefix + 'mid.%d' % i
        return [mid] + send(company, message(company, n, mid))

    with ThreadPoolExecutor(15) as pool:
        out['middle'] = list(pool.map(one, range(15)))
    t.join()
    for i in range(5):
        out['post'].append([prefix + 'post.%d' % i] + send(company, message(company, n, prefix + 'post.%d' % i)))
    out['revoker_exit'] = revoker.get('exit')
    path = EVIDENCE / ('inbound-%s.json' % revoke_phase)
    out['revocation'] = json.loads(path.read_text()) if path.exists() else None
    return out


def main():
    result['setup_exit'] = php('setup')
    fixture = json.loads((EVIDENCE / 'inbound.private.json').read_text())
    a, b = fixture['companies']['A'], fixture['companies']['B']
    result['ids'] = {'A': {n: {'author': x['author']} for n, x in a['bindings'].items()}, 'peer_A': a['peer']}

    # N09: 20 identical deliveries straight to the restricted REST function, in parallel PHP workers.
    dto = message(a, '1', 'n09.same')
    with ThreadPoolExecutor(20) as pool:
        result['n09'] = list(pool.map(lambda _: send(a, dto), range(20)))
    result['state_n09'] = state('n09')

    # N20/N02/N04: refusals are Moodle exception envelopes with HTTP 200 and a safe category; nothing is written.
    env = {}
    env['malformed'] = rest(a['token'], 'local_kbacompany_public_inbound_message', '{"schema_version":')
    env['duplicate_key'] = rest(a['token'], 'local_kbacompany_public_inbound_message',
                                json.dumps(message(a, '1', 'n20.dup'))[:-1] + ', "text": "second"}')
    env['foreign_scope'] = send(b, message(a, '1', 'n20.foreign'))
    env['changed_content'] = send(a, dict(dto, text='Changed synthetic text'))
    env['bad_token'] = rest('0' * 32, 'local_kbacompany_public_inbound_message', message(a, '1', 'n20.badtoken'))
    php('switch', 'off')
    env['switch_off'] = rest(a['token'], 'local_kbacompany_public_inbound_message', message(a, '1', 'n20.off'))
    php('switch', 'on')
    result['envelopes'] = env
    result['state_envelopes'] = state('envelopes')

    # Fake adapter end to end: webhook -> durable inbox -> worker -> real REST function.
    with tempfile.TemporaryDirectory() as tmp:
        inbox = adapter.Inbox(Path(tmp) / 'inbox.sqlite')
        bindings = {x['chat']: {'external_chat_id': x['chat'], 'connection_uuid': a['connection'],
                                'binding_uuid': x['binding'], 'binding_revision': 1, 'company_uuid': a['uuid'],
                                'thread_uuid': x['thread']} for x in a['bindings'].values()}
        server = adapter.serve(inbox, bindings, SECRET)
        url = 'http://127.0.0.1:%d/webhook' % server.server_address[1]
        client = adapter.BmsClient(BMS, a['token'])
        b1 = a['bindings']['1']
        hooks = {'wrong_secret': webhook(url, fake_update(b1, 'adp.create'), os.urandom(24).hex())}
        with ThreadPoolExecutor(20) as pool:
            hooks['twenty_identical'] = list(pool.map(lambda _: webhook(url, fake_update(b1, 'adp.create'), SECRET),
                                                      range(20)))
        cases = {
            'adp.bot': fake_update(b1, 'adp.bot', sender={'is_bot': True}),
            'adp.attachment': fake_update(b1, 'adp.attachment', body={'attachments': [{'type': 'file'}]}),
            'adp.reply': fake_update(b1, 'adp.reply', link={'type': 'reply', 'mid': 'adp.create'}),
            'adp.forward': fake_update(b1, 'adp.forward', link={'type': 'forward', 'mid': 'adp.other'}),
            'adp.channel': fake_update(b1, 'adp.channel', recipient={'chat_type': 'channel'}),
            'adp.unbound': fake_update(dict(b1, chat='-7999000000001'), 'adp.unbound'),
        }
        for mid, update in cases.items():
            hooks[mid] = webhook(url, update, SECRET)
        # Removal before creation holds the source; the later create is never published.
        hooks['adp.held.remove'] = webhook(url, fake_update(b1, 'adp.held', 'message_removed'), SECRET)
        adapter.drain(inbox, client)
        hooks['adp.held.create'] = webhook(url, fake_update(b1, 'adp.held'), SECRET)
        adapter.drain(inbox, client)
        # Edit after a published create only marks it for review.
        hooks['adp.create.edit'] = webhook(url, dict(fake_update(b1, 'adp.create', 'message_edited'),
                                                     timestamp=int(time.time() * 1000) + 7), SECRET)
        # Kill switch while an event waits: retry, never success; after re-enabling it commits once.
        php('switch', 'off')
        hooks['adp.switch'] = webhook(url, fake_update(b1, 'adp.switch'), SECRET)
        adapter.drain(inbox, client)
        switch_off_rows = [r for r in inbox.rows() if r['state'] == 'retry_wait']
        php('switch', 'on')
        adapter.drain(inbox, client, now=time.time() + adapter.BACKOFF_MAX + 1)
        result['adapter'] = {'hooks': hooks, 'rows': inbox.rows(), 'switch_off_rows': switch_off_rows,
                             'paused': inbox.paused()}
        server.shutdown()
        server.server_close()
        inbox.close()
    result['state_adapter'] = state('adapter')

    # N21/N28: revocations in their own PHP processes while imports run in parallel PHP workers.
    result['race_sender'] = race(a, '1', 'race.s.', 'revoke_sender')
    result['race_membership'] = race(a, '2', 'race.m.', 'close_membership')
    result['state_final'] = state('final')
    result['status'] = 'PASS'


try:
    main()
except Exception as e:  # noqa: BLE001 - the contract fails closed on an incomplete result
    result['error'] = type(e).__name__
finally:
    (EVIDENCE / 'inbound-result.json').write_text(json.dumps(result, indent=1))
sys.exit(0 if result.get('status') == 'PASS' else 1)
