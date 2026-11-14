"""BROWSER tier contract (RC32, Issue #82): fail-closed evaluation of browser/HTTP observations.

Pure functions, no browser/DB/network. `require()` raises RuntimeError (never `assert`), so verdicts are identical under
`python -O`. Every check needs positive evidence: missing, empty, unexpected or error observations FAIL.
Note: the loopback check below constrains what the test scripts request; it is NOT OS-level network isolation.
"""
import hashlib
import ipaddress
from urllib.parse import urlparse, parse_qsl

from events import ABORT_REASON

ORIGIN_SCHEME, ORIGIN_NETLOC = 'http', '127.0.0.1:8080'
# Synthetic attachment files (name -> bytes). Content is unique per file, so a SHA256 match proves the exact file.
FILES = {
    'p02-text-and-file.txt': b'RC31 pilot02 synthetic attachment A\n',
    'p02-file-only.txt': b'RC31 pilot02 synthetic file-only B\n',
    'p02-compact.txt': b'RC31 pilot02 synthetic compact C\n',
    'p02-compact-only.txt': b'RC31 pilot02 synthetic compact-only D\n',
    'p02-direct-only.txt': b'RC32 browser synthetic direct multipart upload E\n',
}
SHA = {name: hashlib.sha256(data).hexdigest() for name, data in FILES.items()}
DENY_CODES = {403, 404}          # protected pluginfile refusal for a logged-in user without access
LOGIN_REDIRECT_CODES = {302, 303}  # anonymous user is sent to the login page (redirect not followed)
LIMIT_BYTES = 65536
CSRF_REFUSAL_CODES = {403, 404}   # Moodle require_sesskey() -> moodle_exception page; 5xx is a server error, never a refusal
CSRF_REFUSAL_MARKER = 'Your session has most likely timed out'  # Moodle 4.1 lang/en/error.php 'invalidsesskey'
LOGIN_PATH = '/login/index.php'
CENTER_PATH = '/local/kbacompany/communication_center.php'
# Historical rows compared before revoke, after revoke and after reopen (see ci_state.php, phase 'hist').
# Expected changes caused by revoke/reopen in these tables: NONE. Membership state lives in other tables and is not part of
# the snapshot. Any added, removed or changed row here is a FAIL.
HISTORY_TABLES = ('local_kbacompany_commmsg', 'local_kbacompany_commattach', 'local_kbacompany_commrev', 'files:communication_attachment')
HISTORY_EXPECTED_CHANGES = ()
TASK_TAB_CASES = ('page_and_tabs', 'keyboard_reaches_tab', 'focus_ring_visible', 'focus_ring_contrast_3to1', 'second_tab_focus_visible',
                  'enter_activates_tab', 'active_tab_distinct', 'mouse_click_navigates_without_ring', 'desktop_tabs_not_enlarged',
                  'desktop_no_browser_errors', 'mobile_focus_ring', 'mobile_no_page_hscroll', 'mobile_tap_target_44', 'mobile_no_browser_errors')


def require(condition, message='BROWSER condition failed'):
    if not condition:
        raise RuntimeError(message)


def local_url(url):
    """Allow only the exact disposable loopback origin; refuse before any request is made."""
    p = urlparse(url)
    require(p.scheme == ORIGIN_SCHEME and p.netloc == ORIGIN_NETLOC and not p.username and not p.password
            and p.hostname == '127.0.0.1' and p.port == 8080, 'Non-loopback request refused: %r' % url)
    return url


def exact_redirect(location, path, query=None):
    """Location must be the exact loopback origin + exact path; query parameters exactly as given (None = no query)."""
    require(isinstance(location, str) and location, 'redirect Location missing')
    p = urlparse(location)
    require(p.scheme == ORIGIN_SCHEME and p.netloc == ORIGIN_NETLOC and p.hostname == '127.0.0.1' and p.port == 8080
            and not p.username and not p.password, 'redirect to a foreign origin: %r' % location)
    require(p.path == path and not p.params and not p.fragment, 'redirect path %r != %r' % (p.path, path))
    try:
        pairs = parse_qsl(p.query, keep_blank_values=True, strict_parsing=bool(p.query))
    except ValueError:
        pairs = None
    require(pairs is not None, 'malformed redirect query: %r' % location)
    require(len(pairs) == len(dict(pairs)), 'duplicate redirect parameters: %r' % location)
    require(dict(pairs) == dict(query or {}), 'redirect parameters %r != %r' % (dict(pairs), dict(query or {})))
    return location


def _step(obs, name):
    require(isinstance(obs, dict) and 'execution_error' not in obs, 'scenario execution failed or missing')
    steps = obs.get('steps')
    require(isinstance(steps, dict) and isinstance(steps.get(name), dict), 'missing observation: ' + name)
    return steps[name]


def _download(row, name):
    d = (row.get('downloads') or {}).get(name)
    require(isinstance(d, dict), '%s: not downloaded' % name)
    require(d.get('http') == 200, '%s: download HTTP %r' % (name, d.get('http')))
    require(d.get('sha256') == SHA[name], '%s: downloaded bytes differ from the uploaded file' % name)


def _rendered(row, name, text=None):
    require(row.get('file_link_text') == name, 'attachment link text %r != %r' % (row.get('file_link_text'), name))
    _download(row, name)
    if text is not None:
        require(row.get('rendered_text_exact') == text, 'message text %r not rendered exactly' % text)


def attachments_checks(obs, state, phase='verify'):
    """name -> callable raising on failure."""
    msgs = (state or {}).get('messages')
    def body_count(body):
        require(isinstance(msgs, list) and msgs, 'DB state missing')
        return sum(1 for m in msgs if m.get('body') == body)
    return {
        'compact_has_file_control': lambda: require(_step(obs, 'compact_controls').get('file_inputs') == 1, 'compact file control missing'),
        'compact_text_and_file': lambda: _rendered(_step(obs, 'compact_text_and_file'), 'p02-compact.txt', 'RC31 P02 compact text ' + phase),
        'compact_file_only': lambda: _rendered(_step(obs, 'compact_file_only'), 'p02-compact-only.txt'),
        'full_text_only': lambda: require(_step(obs, 'full_text_only').get('rendered_text_exact') == 'RC31 P02 full text only ' + phase, 'text-only message not rendered'),
        'full_text_and_file_with_filename_feedback': lambda: (
            require((_step(obs, 'full_text_and_file').get('filename_feedback') or '').startswith('p02-text-and-file.txt · '),
                    'selected-file feedback missing or wrong'),
            _rendered(_step(obs, 'full_text_and_file'), 'p02-text-and-file.txt', 'RC31 P02 full text and file ' + phase)),
        'full_file_only': lambda: _rendered(_step(obs, 'full_file_only'), 'p02-file-only.txt'),
        'direct_multipart_new_file_stored_and_downloadable': lambda: (
            require(_step(obs, 'server_file_only_direct').get('http') in LOGIN_REDIRECT_CODES, 'direct POST not answered with a redirect'),
            require(isinstance(_step(obs, 'server_file_only_direct').get('expected_query'), dict)
                    and _step(obs, 'server_file_only_direct')['expected_query'].get('uuid')
                    and _step(obs, 'server_file_only_direct')['expected_query'].get('thread'), 'expected thread redirect not recorded'),
            exact_redirect(_step(obs, 'server_file_only_direct').get('location'), CENTER_PATH,
                           _step(obs, 'server_file_only_direct')['expected_query']),
            _rendered(_step(obs, 'server_file_only_direct'), 'p02-direct-only.txt')),
        'double_submit_creates_one_message': lambda: (
            require(isinstance(_step(obs, 'double_submit').get('center_posts'), int) and _step(obs, 'double_submit')['center_posts'] >= 1,
                    'double submit not performed'),
            require(body_count('RC31 P02 double ' + phase) == 1, 'double submit did not create exactly one message')),
        'csrf_invalid_sesskey_rejected': lambda: (
            require(_step(obs, 'csrf_invalid_sesskey').get('http') in CSRF_REFUSAL_CODES,
                    'forged request: HTTP %r is not the sesskey refusal (5xx/2xx/3xx not accepted)' % _step(obs, 'csrf_invalid_sesskey').get('http')),
            require(_step(obs, 'csrf_invalid_sesskey').get('refusal_marker') is True, 'sesskey refusal message not shown'),
            require(body_count('RC31 P02 csrf ' + phase) == 0, 'forged request created a message')),
        'no_orphan_files': lambda: (_step(obs, 'double_submit'), require(isinstance(msgs, list) and msgs, 'DB state missing'),
                                    require((state or {}).get('orphan_files') == 0, 'orphan files present')),
        'attachments_no_browser_errors': lambda: (_step(obs, 'double_submit'), page_error_check(obs, 'attachments')()),
    }


def access_checks(acc):
    """Same browser contexts across phases; identical non-empty URL sets; explicit allowed/denied responses."""
    def phase(name):
        require(isinstance(acc, dict) and 'execution_error' not in acc, 'access scenario failed or missing')
        rows = (acc.get('phases') or {}).get(name)
        require(isinstance(rows, dict) and rows, 'no results for phase ' + name)
        return rows

    def expected():
        urls = acc.get('expected_urls')
        require(isinstance(urls, list) and len(urls) >= len(FILES), 'expected attachment URL set missing or incomplete')
        require({u.split('/')[-1].split('?')[0] for u in urls} >= set(FILES), 'expected attachment names incomplete')
        return set(urls)

    def same_set(name):
        rows = phase(name)
        require(set(rows) == expected(), 'phase %s did not request exactly the expected URL set' % name)
        return rows

    def allowed(name):
        for url, r in same_set(name).items():
            fname = url.split('/')[-1].split('?')[0]
            require(r.get('http') == 200 and r.get('sha256') == SHA.get(fname), '%s: %s not served intact' % (name, fname))

    def denied(name, codes):
        for url, r in same_set(name).items():
            require(r.get('http') in codes, '%s: HTTP %r not an accepted refusal' % (name, r.get('http')))
            require(r.get('sha256') not in SHA.values(), '%s: protected content served' % name)
            if codes is LOGIN_REDIRECT_CODES:
                exact_redirect(r.get('location'), LOGIN_PATH)

    def session_reused():
        logins = acc.get('logins') or {}
        require(logins.get('EA') == 1, 'EA must log in exactly once across before/revoked/reopened')
        require(acc.get('ea_context_id_before') and acc.get('ea_context_id_before') == acc.get('ea_context_id_after'),
                'EA phases did not reuse the same browser context')

    def access_browser_events():
        require(isinstance(acc, dict) and 'execution_error' not in acc, 'access scenario failed or missing')
        ev = acc.get('browser_events')
        require(isinstance(ev, dict) and set(ev) == {'EA', 'EB', 'anon'}, 'access browser events not recorded for EA/EB/anon')
        for ctxname, events in sorted(ev.items()):
            bad = browser_event_violations(events)
            require(not bad, 'access %s: %d browser error(s): %r' % (ctxname, len(bad), bad[:3]))

    def history_preserved():
        h = (acc or {}).get('history') or {}
        names = ('before', 'after_revoke', 'after_reopen')
        snaps = [h.get(k) for k in names]
        for k, snap in zip(names, snaps):
            require(isinstance(snap, dict) and isinstance(snap.get('rows'), dict), 'history snapshot %s missing' % k)
            require(sorted(snap.get('tables') or []) == sorted(HISTORY_TABLES), 'history snapshot %s does not cover %r' % (k, HISTORY_TABLES))
            require(set(snap['rows']) == set(HISTORY_TABLES), 'history snapshot %s tables incomplete' % k)
            require(all(isinstance(v, dict) or v == [] for v in snap['rows'].values()), 'history snapshot %s malformed' % k)
            require(isinstance(snap['rows'].get('local_kbacompany_commmsg'), dict) and snap['rows']['local_kbacompany_commmsg'],
                    'history snapshot %s has no messages' % k)
            require(snap.get('digest') == history_digest(snap['rows']), 'history snapshot %s digest does not match its rows' % k)
        for k, snap in zip(names[1:], snaps[1:]):
            for table in HISTORY_TABLES:
                a, b = snaps[0]['rows'][table] or {}, snap['rows'][table] or {}  # PHP encodes an empty table as []
                changed = sorted(set(a) ^ set(b)) + sorted(r for r in set(a) & set(b) if a[r] != b[r])
                require(not changed, 'history %s: %s rows changed %r (expected changes: %r)' % (k, table, changed[:5], HISTORY_EXPECTED_CHANGES))
            require(snap['digest'] == snaps[0]['digest'], 'history digest changed at ' + k)

    return {
        'download_participant_allowed': lambda: allowed('ea_before'),
        'download_other_company_denied': lambda: denied('eb', DENY_CODES),
        'download_anonymous_redirected_to_login': lambda: denied('anon', LOGIN_REDIRECT_CODES),
        'revoke_in_open_session_denied': lambda: (session_reused(), require(acc.get('revoked_flag') is True, 'revocation not applied'),
                                                  denied('ea_revoked', DENY_CODES)),
        'reopen_in_open_session_allowed': lambda: (session_reused(), require(acc.get('reopened_flag') is True, 'reopen not applied'),
                                                   allowed('ea_reopened')),
        'revocation_preserves_history': history_preserved,
        'access_no_browser_errors': access_browser_events,
    }


def history_digest(rows):
    """Stable digest of {table: {row key: row hash}}; recomputed by the validator, never trusted from the producer."""
    h = hashlib.sha256()
    for table in sorted(rows):
        for key in sorted(rows[table] or {}):
            h.update(('%s|%s|%s\n' % (table, key, rows[table][key])).encode())
    return h.hexdigest()


# ---- Browser error policy (Codex review of PR #96, comment 6873684005) ----------------------------------------------------
# The ONLY tolerated browser error: a request to an EXPECTED external URL (EXPECTED_BLOCKED_EXTERNAL, exact string) that the
# test route itself aborted with the distinct code 'blockedbyclient' (events.Recorder.route), proven by a recorded route_abort
# event with the EXACT same URL. Its consequences, at most one 'requestfailed' and one console line per recorded abort of that
# URL, must carry the exact Chromium values observed for that abort (Playwright 1.49.1 / Chromium, run 43571513719 on both
# engines): failure 'net::ERR_BLOCKED_BY_CLIENT.Inspector', console 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector'
# located at the URL. Everything else FAILs: any net::ERR_FAILED, any failed request to a loopback host (127.0.0.0/8, ::1,
# localhost, 0.0.0.0), an abort of an unexpected external URL, a similar-but-different URL, consequences without (or in excess
# of) abort records, pageerror, any other console error, malformed or legacy (string) events, a missing event list.
ALLOWED_ABORT_FAILURE = 'net::ERR_BLOCKED_BY_CLIENT.Inspector'
ALLOWED_ABORT_CONSOLE = 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector'
# External resources the disposable Moodle 4.1.22 site is known to request; each is blocked by the test route and recorded.
# Evidence: run 43571513719 (d7430ff): task tabs, attachments and access contexts on MariaDB and Percona request this single
# external URL only (Moodle core filter_mathjaxloader default CDN). Adding a URL here is a reviewed policy change.
EXPECTED_BLOCKED_EXTERNAL = ('https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?delayStartupUntil=configured',)


def loopback_host(url):
    host = (urlparse(url or '').hostname or '').strip('[]').lower()
    if host in ('localhost', '0.0.0.0') or host.endswith('.localhost'):
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


def browser_event_violations(events):
    """Return the list of violations (empty = clean), failed requests first, then page errors, then the rest. Never raises."""
    if not isinstance(events, list):
        return ['browser event list not recorded']
    bad, aborted = [], {}
    req_bad, page_bad = [], []
    for e in events:
        if isinstance(e, dict) and e.get('source') == 'route_abort':
            u = e.get('url')
            if (isinstance(u, str) and urlparse(u).scheme in ('http', 'https') and urlparse(u).hostname and not loopback_host(u)
                    and e.get('reason') == ABORT_REASON and isinstance(e.get('resource_type'), str) and e['resource_type']
                    and u in EXPECTED_BLOCKED_EXTERNAL):
                aborted[u] = aborted.get(u, 0) + 1
            else:
                bad.append('route_abort malformed, loopback or unexpected external URL: %r' % (u,))
    used = {'requestfailed': {}, 'console': {}}

    def consume(kind, u):
        """At most one consequence of each kind per recorded abort of exactly this URL."""
        if not isinstance(u, str) or aborted.get(u, 0) <= used[kind].get(u, 0):
            return False
        used[kind][u] = used[kind].get(u, 0) + 1
        return True
    for e in events:
        if not isinstance(e, dict):
            bad.append('malformed event %r' % (e,))
            continue
        src, u = e.get('source'), e.get('url')
        if src == 'route_abort':
            continue
        if src == 'requestfailed':
            if e.get('failure') == ALLOWED_ABORT_FAILURE and consume('requestfailed', u):
                continue
            req_bad.append('failed request %r (%r, %r)' % (u, e.get('failure'), e.get('resource_type')))
        elif src == 'console':
            if e.get('type') == 'error' and e.get('text') == ALLOWED_ABORT_CONSOLE and consume('console', u):
                continue
            bad.append('console %s %r at %r' % (e.get('type'), e.get('text'), u))
        elif src == 'pageerror':
            page_bad.append('pageerror %s: %r' % (e.get('name'), e.get('message')))
        else:
            bad.append('unknown event source %r' % (src,))
    return req_bad + page_bad + bad


def page_error_check(obs, label, key='browser_events'):
    """The scenario must have recorded its structured browser events (even when it failed) and they must be clean."""
    def check():
        require(isinstance(obs, dict) and 'execution_error' not in obs, 'scenario execution failed or missing')
        require(isinstance(obs.get(key), list), '%s: browser events not recorded' % label)
        bad = browser_event_violations(obs[key])
        require(not bad, '%s: %d browser error(s): %r' % (label, len(bad), bad[:3]))
    return check


def oversize_checks(obs, state):
    def nothing_stored():
        msgs = (state or {}).get('messages')
        require(isinstance(msgs, list) and msgs, 'DB state missing')
        require(not any('oversize' in (m.get('body') or '') for m in msgs), 'oversize message stored')
        require((state or {}).get('orphan_files') == 0, 'orphan files present')
    return {
        'oversize_php_limit_effective': lambda: require(_step(obs, 'limits').get('form_maxbytes') == LIMIT_BYTES, 'PHP upload limit 64K not effective for the web server'),
        'oversize_compact_request_sent_and_refused': lambda: (
            require(_step(obs, 'compact_oversize').get('request_sent') is True, 'compact oversize request not sent'),
            require('exceeds' in (_step(obs, 'compact_oversize').get('error_text') or ''), 'compact oversize refusal message missing')),
        'oversize_full_blocked_in_browser': lambda: (
            require(_step(obs, 'full_oversize_client').get('post_sent') is False, 'full centre sent an oversize request'),
            require(bool(_step(obs, 'full_oversize_client').get('file_validation')), 'no client-side size message')),
        'oversize_direct_request_sent_and_refused': lambda: (
            require(_step(obs, 'full_oversize_server_direct').get('sent_bytes') == 100 * 1024, 'direct oversize request not sent'),
            require(_step(obs, 'full_oversize_server_direct').get('error_in_page') is True, 'server did not refuse the oversize file')),
        'oversize_nothing_stored': nothing_stored,
        'oversize_no_browser_errors': lambda: (_step(obs, 'full_oversize_server_direct'), page_error_check(obs, 'oversize')()),
    }


def task_tab_checks(obs, phase='ci'):
    """Every required case PASS exactly once, no other case (execution, unknown) present, and the run's own status PASS."""
    def cases():
        require(isinstance(obs, dict) and isinstance(obs.get('cases'), list) and obs['cases'], 'task tab run missing')
        prefix = 'P01.%s.' % phase
        ids = [c.get('id') if isinstance(c, dict) else None for c in obs['cases']]
        require(all(isinstance(i, str) and i.startswith(prefix) for i in ids), 'task tab case ids not from phase ' + phase)
        names = [i[len(prefix):] for i in ids]
        require(len(names) == len(set(names)), 'duplicate task tab cases')
        return {n: c.get('status') for n, c in zip(names, obs['cases'])}

    def run_complete():
        got = cases()
        require(obs.get('status') == 'PASS', 'task tab run status %r' % obs.get('status'))
        bad = sorted(k for k, v in got.items() if v != 'PASS')
        require(not bad, 'task tab run has non-PASS cases %r' % bad)
        require(set(got) == set(TASK_TAB_CASES), 'task tab cases differ from the required set: %r' % sorted(set(got) ^ set(TASK_TAB_CASES)))

    def events_clean(viewport):
        ev = obs.get('browser_events') if isinstance(obs, dict) else None
        require(isinstance(ev, dict) and isinstance(ev.get(viewport), list), 'task tab %s browser events not recorded' % viewport)
        bad = browser_event_violations(ev[viewport])
        require(not bad, 'task tab %s: %d browser error(s): %r' % (viewport, len(bad), bad[:3]))

    out = {'task_tab_run_complete': run_complete}
    for k in TASK_TAB_CASES:
        out['task_tab_' + k] = (lambda k=k: (run_complete(), require(cases().get(k) == 'PASS', 'task tab case %s: %r' % (k, cases().get(k)))))
    # The producer's own case status is not trusted for browser errors: the validator re-evaluates the recorded events.
    out['task_tab_desktop_no_browser_errors'] = lambda: (run_complete(), require(cases().get('desktop_no_browser_errors') == 'PASS', 'desktop case'),
                                                         events_clean('desktop'))
    out['task_tab_mobile_no_browser_errors'] = lambda: (run_complete(), require(cases().get('mobile_no_browser_errors') == 'PASS', 'mobile case'),
                                                        events_clean('mobile'))
    return out


def required_check_names():
    """Every check a complete BROWSER report must contain (used by browser_acceptance.py and merge_gate.py)."""
    return (['moodle_install', 'fixtures_companies_A_B'] + list(task_tab_checks({})) + list(attachments_checks({}, {}))
            + list(access_checks({})) + list(oversize_checks({}, {})))


def evaluate(checks):
    """Run named checks; return {name: {'status', 'reason'}} — never raises."""
    out = {}
    for name, fn in checks.items():
        try:
            fn()
            out[name] = {'status': 'PASS'}
        except Exception as e:  # any error is a FAIL with its reason
            out[name] = {'status': 'FAIL', 'reason': '%s: %s' % (type(e).__name__, str(e)[:200])}
    return out
