#!/usr/bin/env python3
"""RC33 fake-provider MAX adapter (owner D1-D4 24.11.2026, ADR-0012; contract §3, §8, §9 step 2). Localhost only.

fixture Update -> webhook (secret header) -> strict validation -> durable SQLite inbox -> worker -> the real Moodle
REST function local_kbacompany_public_inbound_message / _control -> stable state.

This is NOT a MAX client: it never talks to MAX, has no MAX token and accepts only a synthetic fake Update shape that
mimics the fields the contract needs. It binds to loopback only and calls BMS only on a loopback URL. Secrets come from
the environment (FAKE_MAX_WEBHOOK_SECRET, BMS_INBOUND_TOKEN) and are never logged, put in URLs or stored in the inbox.
Logs and metrics carry counts and states only: no text, external IDs, tokens or URLs with credentials.

Inbox states: validated -> committed | review_required | retry_wait | rejected | dead_letter. A webhook is answered
200 only after its row is durable. Retries use exponential backoff with jitter (1 s .. 5 min, Retry-After honoured)
and stop after MAX_ATTEMPTS (dead_letter). A 401/403-class answer pauses the connection: no further BMS calls until an
operator resumes it, and the event stays in the inbox.
"""
import argparse
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import random
import sqlite3
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SCHEMA = 'rc33.inbound/1'
SECRET_HEADER = 'X-Fake-Max-Secret'
MAX_BODY = 64 * 1024
MAX_DEPTH = 8
MAX_ATTEMPTS = 12
BACKOFF_MIN = 1.0
BACKOFF_MAX = 300.0
LEASE_SECONDS = 60.0
INT64_MIN, INT64_MAX = -(2 ** 63), 2 ** 63 - 1
TERMINAL = ('committed', 'review_required', 'rejected', 'dead_letter')
PAUSE_CODES = ('inbound_unauthenticated', 'invalidtoken', 'accessexception', 'webservicesnotenabled',
               'servicenotavailable', 'usernotallowed', 'invalidlogin', 'nopermissions')
REJECT_CODES = ('inbound_scope_denied', 'inbound_payload_invalid', 'invalidparameter')
DEAD_CODES = ('inbound_idempotency_conflict',)

log = logging.getLogger('fake_max_adapter')


class Invalid(ValueError):
    """Payload refused at the adapter boundary (HTTP 400); nothing is stored."""


def require(condition, what):
    if not condition:
        raise Invalid(what)


def strict_loads(raw: bytes):
    """JSON without duplicate keys, NaN/Infinity, floats or excessive depth. Integers stay exact Python ints."""
    def pairs(items):
        out = {}
        for key, value in items:
            require(key not in out, 'duplicate key')
            out[key] = value
        return out

    def no_float(_):
        raise Invalid('float')

    def no_constant(_):
        raise Invalid('constant')

    try:
        value = json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_float=no_float, parse_constant=no_constant)
    except Invalid:
        raise
    except (UnicodeDecodeError, ValueError):
        raise Invalid('malformed')

    def depth(v, d=1):
        require(d <= MAX_DEPTH, 'depth')
        if isinstance(v, dict):
            for x in v.values():
                depth(x, d + 1)
        elif isinstance(v, list):
            for x in v:
                depth(x, d + 1)
    depth(value)
    return value


def exact_fields(obj, required, optional=()):
    require(isinstance(obj, dict), 'object')
    keys = set(obj)
    require(set(required) <= keys and keys <= set(required) | set(optional), 'fields')


def int64(value):
    """A provider integer ID as a canonical decimal string; bool, float or out-of-range values are refused."""
    require(type(value) is int and INT64_MIN <= value <= INT64_MAX, 'unsafe integer id')
    return str(value)


def mid(value):
    require(isinstance(value, str) and 0 < len(value) <= 128 and all(0x20 < ord(c) != 0x7f for c in value), 'mid')
    return value


def timestamp_ms(value):
    require(type(value) is int and 0 < value < 10 ** 14, 'timestamp')
    return value


def normalize(update, bindings):
    """Fake Update -> (kind, dedup key, DTO or None). No security flag gets a default; unknown chats are not bound.

    Returns kind 'unbound' (DTO None) for a chat without an explicit binding: recorded as rejected, never sent.
    """
    exact_fields(update, ('update_type', 'timestamp', 'message'))
    kind = update['update_type']
    require(kind in ('message_created', 'message_edited', 'message_removed'), 'update_type')
    ts = timestamp_ms(update['timestamp'])
    msg = update['message']
    if kind == 'message_removed':
        exact_fields(msg, ('chat_id', 'mid'))
        chat, external_mid = int64(msg['chat_id']), mid(msg['mid'])
    else:
        exact_fields(msg, ('recipient', 'sender', 'timestamp', 'body'), ('link',))
        exact_fields(msg['recipient'], ('chat_id', 'chat_type'))
        exact_fields(msg['sender'], ('user_id', 'is_bot'))
        exact_fields(msg['body'], ('mid', 'text', 'attachments'))
        chat = int64(msg['recipient']['chat_id'])
        chat_type = msg['recipient']['chat_type']
        require(chat_type in ('chat', 'channel', 'dialog'), 'chat_type')
        require(type(msg['sender']['is_bot']) is bool, 'is_bot')
        sender = int64(msg['sender']['user_id'])
        external_mid = mid(msg['body']['mid'])
        text = msg['body']['text']
        require(isinstance(text, str), 'text')
        attachments = msg['body']['attachments']
        require(isinstance(attachments, list) and all(isinstance(a, dict) for a in attachments), 'attachments')
        link_type, reply_to = 'none', None
        if 'link' in msg:
            exact_fields(msg['link'], ('type', 'mid'))
            require(msg['link']['type'] in ('reply', 'forward'), 'link type')
            link_type = msg['link']['type']
            if link_type == 'reply':
                reply_to = mid(msg['link']['mid'])
        source_ms = timestamp_ms(msg['timestamp'])
    key_parts = [kind, chat, external_mid] + ([] if kind == 'message_created' else [str(ts)])
    dedup = hashlib.sha256('\n'.join(key_parts).encode('utf-8')).hexdigest()
    binding = bindings.get(chat)
    if binding is None:
        return 'unbound', dedup, None
    common = {'schema_version': SCHEMA, 'connection_uuid': binding['connection_uuid'],
              'binding_uuid': binding['binding_uuid'], 'binding_revision': binding['binding_revision'],
              'external_chat_id': chat, 'external_message_id': external_mid}
    if kind != 'message_created':
        return 'control', dedup, dict(common, event_type=kind, source_timestamp_ms=ts)
    attachments_dto = [{'kind': str(a.get('type', 'unknown'))[:32]} for a in attachments]
    return 'message', dedup, dict(common, company_uuid=binding['company_uuid'], thread_uuid=binding['thread_uuid'],
                                  event_type='message_created', chat_type=chat_type,
                                  sender_is_bot=msg['sender']['is_bot'], link_type=link_type,
                                  external_sender_id=sender, source_timestamp_ms=source_ms, text=text,
                                  attachments=attachments_dto, reply_to_external_message_id=reply_to)


def require_loopback_url(url):
    parts = urllib.parse.urlsplit(url)
    require(parts.scheme == 'http' and parts.hostname in ('127.0.0.1', 'localhost', '::1') and not parts.username
            and not parts.query and not parts.fragment, 'BMS URL must be a plain loopback http URL (fake provider)')
    return url.rstrip('/')


def require_loopback_host(host):
    require(host == 'localhost' or ipaddress.ip_address(host).is_loopback, 'adapter binds to loopback only')
    return host


class Inbox:
    """Durable adapter inbox (SQLite, WAL). Bodies stay only as long as the event is not terminal (contract §8)."""

    def __init__(self, path):
        self.path = str(path)
        self._local = threading.local()
        self._all = []
        self._lock = threading.Lock()
        self._conn().execute('PRAGMA journal_mode=WAL')
        with self._db() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS inbox (id INTEGER PRIMARY KEY, dedupkey TEXT NOT NULL UNIQUE,
                kind TEXT NOT NULL, dto TEXT, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                next_at REAL NOT NULL DEFAULT 0, lease_until REAL NOT NULL DEFAULT 0, outcome TEXT NOT NULL DEFAULT '',
                reason TEXT NOT NULL DEFAULT '', receipt TEXT NOT NULL DEFAULT '', deliveries INTEGER NOT NULL DEFAULT 1,
                received_at REAL NOT NULL, updated_at REAL NOT NULL)''')
            db.execute('CREATE TABLE IF NOT EXISTS connection (id INTEGER PRIMARY KEY CHECK (id = 1), '
                       'paused INTEGER NOT NULL, reason TEXT NOT NULL)')
            db.execute("INSERT OR IGNORE INTO connection (id, paused, reason) VALUES (1, 0, '')")

    def _db(self):
        return _Tx(self._conn())

    def _conn(self):
        db = getattr(self._local, 'db', None)
        if db is None:
            # One connection per thread; check_same_thread=False only so close() can release every handle.
            db = sqlite3.connect(self.path, timeout=30, isolation_level=None, check_same_thread=False)
            db.execute('PRAGMA busy_timeout=30000')
            self._local.db = db
            with self._lock:
                self._all.append(db)
        return db

    def close(self):
        with self._lock:
            for db in self._all:
                db.close()
            self._all = []
        self._local = threading.local()

    def accept(self, kind, dedupkey, dto):
        """Insert once per source event; a repeated delivery only bumps the delivery counter. Durable on return."""
        now = time.time()
        state = 'rejected' if dto is None else 'validated'
        with self._db() as db:
            cur = db.execute('INSERT OR IGNORE INTO inbox (dedupkey, kind, dto, state, reason, received_at, updated_at) '
                             'VALUES (?, ?, ?, ?, ?, ?, ?)',
                             (dedupkey, kind, None if dto is None else json.dumps(dto, ensure_ascii=False), state,
                              'unbound' if dto is None else '', now, now))
            if cur.rowcount == 0:
                db.execute('UPDATE inbox SET deliveries = deliveries + 1 WHERE dedupkey = ?', (dedupkey,))
                return False
            return True

    def paused(self):
        with self._db() as db:
            return db.execute('SELECT paused FROM connection WHERE id = 1').fetchone()[0] == 1

    def pause(self, reason):
        with self._db() as db:
            db.execute('UPDATE connection SET paused = 1, reason = ? WHERE id = 1', (reason,))

    def resume(self):
        with self._db() as db:
            db.execute("UPDATE connection SET paused = 0, reason = '' WHERE id = 1")

    def lease(self, now=None):
        """Atomically lease one due event (crashed workers' leases expire)."""
        now = time.time() if now is None else now
        with self._db() as db:
            row = db.execute("SELECT id FROM inbox WHERE state IN ('validated', 'retry_wait') AND next_at <= ? "
                             'AND lease_until <= ? ORDER BY id LIMIT 1', (now, now)).fetchone()
            if not row:
                return None
            cur = db.execute('UPDATE inbox SET lease_until = ? WHERE id = ? AND lease_until <= ?',
                             (now + LEASE_SECONDS, row[0], now))
            if cur.rowcount != 1:
                return None
            r = db.execute('SELECT id, kind, dto, attempts FROM inbox WHERE id = ?', (row[0],)).fetchone()
            return {'id': r[0], 'kind': r[1], 'dto': json.loads(r[2]), 'attempts': r[3]}

    def finish(self, rowid, state, outcome='', reason='', receipt='', retry_after=None, attempts=None, now=None):
        now = time.time() if now is None else now
        with self._db() as db:
            if state in TERMINAL:
                # Terminal: drop the body, keep the minimal decision (contract §8 retention).
                db.execute('UPDATE inbox SET state = ?, outcome = ?, reason = ?, receipt = ?, dto = NULL, '
                           'lease_until = 0, attempts = COALESCE(?, attempts), updated_at = ? WHERE id = ?',
                           (state, outcome, reason, receipt, attempts, now, rowid))
            else:
                db.execute('UPDATE inbox SET state = ?, reason = ?, next_at = ?, lease_until = 0, '
                           'attempts = COALESCE(?, attempts), updated_at = ? WHERE id = ?',
                           (state, reason, now + (retry_after or 0), attempts, now, rowid))

    def rows(self):
        with self._db() as db:
            cur = db.execute('SELECT id, kind, state, attempts, outcome, reason, receipt, deliveries, next_at, '
                             'dto IS NOT NULL FROM inbox ORDER BY id')
            names = ['id', 'kind', 'state', 'attempts', 'outcome', 'reason', 'receipt', 'deliveries', 'next_at', 'has_body']
            return [dict(zip(names, r)) for r in cur.fetchall()]

    def counts(self):
        out = {}
        for r in self.rows():
            out[r['state']] = out.get(r['state'], 0) + 1
        return out


class _Tx:
    def __init__(self, db):
        self.db = db

    def __enter__(self):
        self.db.execute('BEGIN IMMEDIATE')
        return self.db

    def __exit__(self, exc_type, exc, tb):
        self.db.execute('ROLLBACK' if exc_type else 'COMMIT')
        return False


def backoff(attempts, retry_after=None, rnd=random.random):
    base = min(BACKOFF_MAX, BACKOFF_MIN * (2 ** max(0, attempts - 1)))
    delay = base / 2 + rnd() * base / 2
    if retry_after is not None:
        delay = max(delay, min(BACKOFF_MAX, float(retry_after)))
    return delay


class BmsClient:
    """Moodle REST transport. The token goes in the POST body, never in the URL or logs."""

    FUNCTIONS = {'message': 'local_kbacompany_public_inbound_message', 'control': 'local_kbacompany_public_inbound_control'}

    def __init__(self, base_url, token, timeout=30):
        self.url = require_loopback_url(base_url) + '/webservice/rest/server.php'
        require(isinstance(token, str) and token, 'BMS token missing')
        self._token = token
        self.timeout = timeout

    def call(self, kind, dto):
        """Return ('ok', result dict) | ('error', errorcode) | ('http', status, retry_after) | ('network', None)."""
        body = urllib.parse.urlencode({'wstoken': self._token, 'wsfunction': self.FUNCTIONS[kind],
                                       'moodlewsrestformat': 'json',
                                       'payload': json.dumps(dto, ensure_ascii=False, separators=(',', ':'))}).encode()
        request = urllib.request.Request(self.url, data=body, method='POST',
                                         headers={'Content-Type': 'application/x-www-form-urlencoded'})
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect)
        try:
            with opener.open(request, timeout=self.timeout) as response:
                raw = response.read(MAX_BODY + 1)
        except urllib.error.HTTPError as e:
            return ('http', e.code, e.headers.get('Retry-After'))
        except (urllib.error.URLError, OSError, TimeoutError):
            return ('network', None)
        try:
            decoded = json.loads(raw.decode('utf-8'))
        except (UnicodeDecodeError, ValueError):
            return ('http', 502, None)
        # Moodle answers exceptions with HTTP 200 and an envelope: never a success (contract §8, N20).
        if isinstance(decoded, dict) and 'exception' in decoded:
            return ('error', str(decoded.get('errorcode', 'unknown'))[:64])
        if isinstance(decoded, str):
            try:
                decoded = json.loads(decoded)
            except ValueError:
                return ('http', 502, None)
        if not isinstance(decoded, dict) or decoded.get('schema_version') != SCHEMA:
            return ('http', 502, None)
        return ('ok', decoded)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def process_one(inbox, client, now=None, rnd=random.random):
    """Deliver one due event. Returns the new state, or None if nothing was due or the connection is paused."""
    if inbox.paused():
        return None
    row = inbox.lease(now)
    if row is None:
        return None
    attempts = row['attempts'] + 1
    result = client.call(row['kind'], row['dto'])
    if result[0] == 'ok':
        body = result[1]
        outcome = body.get('outcome', '')
        if outcome in ('created', 'duplicate'):
            state = 'committed'
        elif outcome in ('review_required', 'held'):
            state = 'review_required'
        else:
            inbox.finish(row['id'], 'retry_wait', reason='unexpected_outcome', retry_after=backoff(attempts, rnd=rnd),
                         attempts=attempts, now=now)
            return 'retry_wait'
        inbox.finish(row['id'], state, outcome=outcome, reason=str(body.get('reason', ''))[:64],
                     receipt=str(body.get('receipt_uuid', ''))[:36], attempts=attempts, now=now)
        return state
    if result[0] == 'error':
        code = result[1]
        if code in PAUSE_CODES:
            inbox.pause(code)
            inbox.finish(row['id'], 'retry_wait', reason=code, attempts=row['attempts'], now=now)
            return 'paused'
        if code in REJECT_CODES:
            inbox.finish(row['id'], 'rejected', reason=code, attempts=attempts, now=now)
            return 'rejected'
        if code in DEAD_CODES:
            inbox.finish(row['id'], 'dead_letter', reason=code, attempts=attempts, now=now)
            return 'dead_letter'
        reason, retry_after = code, None
    elif result[0] == 'http' and result[1] in (401, 403):
        inbox.pause('http_%d' % result[1])
        inbox.finish(row['id'], 'retry_wait', reason='http_%d' % result[1], attempts=row['attempts'], now=now)
        return 'paused'
    elif result[0] == 'http':
        reason, retry_after = 'http_%d' % result[1], _retry_after(result[2])
    else:
        reason, retry_after = 'network', None
    if attempts >= MAX_ATTEMPTS:
        inbox.finish(row['id'], 'dead_letter', reason=reason, attempts=attempts, now=now)
        log.warning('dead_letter event=%d reason=%s', row['id'], reason)
        return 'dead_letter'
    inbox.finish(row['id'], 'retry_wait', reason=reason, retry_after=backoff(attempts, retry_after, rnd), attempts=attempts, now=now)
    return 'retry_wait'


def _retry_after(value):
    try:
        return max(0.0, float(value)) if value is not None else None
    except (TypeError, ValueError):
        return None


def drain(inbox, client, until_idle=True, max_steps=10000, now=None):
    steps = 0
    while steps < max_steps:
        state = process_one(inbox, client, now=now)
        if state is None:
            break
        steps += 1
    return steps


def make_handler(inbox, bindings, secret):
    require(isinstance(secret, str) and len(secret) >= 16, 'webhook secret missing or too short')
    expected = secret.encode('utf-8')

    class Handler(BaseHTTPRequestHandler):
        server_version = 'fake-max-adapter'
        sys_version = ''

        def log_message(self, fmt, *args):  # never log paths, headers or bodies
            pass

        def _answer(self, status, payload):
            data = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_POST(self):
            if self.path != '/webhook':
                return self._answer(404, {'ok': False})
            secrets_seen = self.headers.get_all(SECRET_HEADER) or []
            if len(secrets_seen) != 1 or not hmac.compare_digest(secrets_seen[0].encode('utf-8'), expected):
                # N01: no body read beyond the declared size, nothing stored or logged.
                self.close_connection = True
                return self._answer(401, {'ok': False})
            try:
                length = int(self.headers.get('Content-Length', ''))
            except ValueError:
                return self._answer(411, {'ok': False})
            if length < 0 or length > MAX_BODY:
                self.close_connection = True
                return self._answer(413, {'ok': False})
            raw = self.rfile.read(length)
            try:
                kind, dedup, dto = normalize(strict_loads(raw), bindings)
            except Invalid:
                return self._answer(400, {'ok': False})
            try:
                inbox.accept(kind, dedup, dto)
            except sqlite3.Error:
                # Not durable: the provider must retry; never acknowledge.
                return self._answer(503, {'ok': False})
            log.info('accepted kind=%s', kind)
            return self._answer(200, {'ok': True})

        def do_GET(self):
            return self._answer(404, {'ok': False})

    return Handler


def serve(inbox, bindings, secret, host='127.0.0.1', port=0):
    server = ThreadingHTTPServer((require_loopback_host(host), port), make_handler(inbox, bindings, secret))
    server.daemon_threads = True
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


def load_bindings(path):
    """{"bindings": [{"external_chat_id": "-7...", "connection_uuid", "binding_uuid", "binding_revision",
    "company_uuid", "thread_uuid"}]} -> {chat id: binding}. Only explicit bindings; never auto-binding."""
    data = json.loads(open(path, encoding='utf-8').read())
    out = {}
    for b in data['bindings']:
        exact_fields(b, ('external_chat_id', 'connection_uuid', 'binding_uuid', 'binding_revision', 'company_uuid',
                         'thread_uuid'))
        out[str(b['external_chat_id'])] = b
    return out


def main(argv=None):
    parser = argparse.ArgumentParser(description='RC33 fake-provider MAX adapter (localhost only, no MAX network).')
    parser.add_argument('command', choices=('serve', 'drain', 'status', 'resume'))
    parser.add_argument('--inbox', required=True)
    parser.add_argument('--bindings')
    parser.add_argument('--bms-url', default='http://127.0.0.1:8080')
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=0)
    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    inbox = Inbox(args.inbox)
    if args.command == 'status':
        print(json.dumps(inbox.counts(), sort_keys=True))
        return 0
    if args.command == 'resume':
        inbox.resume()
        return 0
    if args.command == 'drain':
        client = BmsClient(args.bms_url, os.environ.get('BMS_INBOUND_TOKEN', ''))
        drain(inbox, client)
        print(json.dumps(inbox.counts(), sort_keys=True))
        return 0
    server = serve(inbox, load_bindings(args.bindings), os.environ.get('FAKE_MAX_WEBHOOK_SECRET', ''), args.host,
                   args.port)
    print('listening on %s:%d' % server.server_address[:2], flush=True)
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        server.shutdown()
    return 0


if __name__ == '__main__':
    sys.exit(main())
