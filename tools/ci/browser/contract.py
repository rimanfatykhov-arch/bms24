"""BROWSER tier contract (RC32, Issue #82): fail-closed evaluation of browser/HTTP observations.

Pure functions, no browser/DB/network. `require()` raises RuntimeError (never `assert`), so verdicts are identical under
`python -O`. Every check needs positive evidence: missing, empty, unexpected or error observations FAIL.
Note: the loopback check below constrains what the test scripts request; it is NOT OS-level network isolation.
"""
import hashlib
import ipaddress
import re
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
MULTI_CONTENT = {n: ('BMS retained multi-attachment synthetic file %d\n' % n).encode() for n in (1, 2, 3)}
MULTI_NAME = re.compile(r'multi-[0-9a-f]{32}-([123])\.txt')


def expected_sha(fname):
    """Exact expected bytes of a synthetic attachment by file name; None for any unknown name (never served intact).
    multiattachments.py files may already exist in a discussion (e.g. on a rehearsal baseline validated with the
    PR102 checkset) and are then part of the protected URL set; their content is fixed per index."""
    if fname in SHA:
        return SHA[fname]
    m = MULTI_NAME.fullmatch(fname or '')
    return hashlib.sha256(MULTI_CONTENT[int(m.group(1))]).hexdigest() if m else None


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
            require(r.get('http') == 200 and r.get('sha256') is not None and r.get('sha256') == expected_sha(fname),
                    '%s: %s not served intact' % (name, fname))

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


def multiattachment_event_violations(events, controls):
    """Apply the usual strict Recorder policy, with bounded provenance for two injected replies.

    Only a unique bmsnegative URL actually fulfilled by the test can account for one
    known Chromium consequence of each type. No time-window or handler-wide exclusion.
    """
    if not isinstance(events, list) or not isinstance(controls, list):
        return ['multiattachment browser events or controls missing']
    expected, markers, bad, locations, redirect_sources = {}, {}, [], {}, {}
    for control in controls:
        try:
            url = local_url(control.get('url', ''))
            parsed = urlparse(url)
            token = dict(parse_qsl(parsed.query)).get('bmsnegative', '')
            code = control.get('http')
            require(parsed.path == '/local/kbacompany/tasks.php' and code in (500, 302)
                    and token.startswith('multi-') and token.endswith('-' + str(code)) and url not in expected,
                    'invalid injection URL')
            expected[url] = code
            require(control.get('redirect_requests') == [], 'redirect requests missing or automatic redirect followed')
            location = control.get('location')
            if code == 302:
                require(location == 'http://127.0.0.1:8080/__bms_negative_redirect_' + token[:-4],
                        'redirect target does not match unique injected request')
                local_url(location)
                redirect_sources[location] = url
            else:
                require(location == '', 'unexpected redirect in HTTP 500 control')
            locations[url] = location
        except Exception:
            bad.append('missing or invalid negative-control URL')
    for event in events:
        if isinstance(event, dict) and event.get('source') == 'test_injected_response':
            url = event.get('url')
            if (not isinstance(url, str) or url not in expected or event.get('method') != 'POST'
                    or event.get('status') != expected[url] or event.get('location') != locations.get(url)):
                bad.append('unproven injected response')
            else:
                markers[url] = markers.get(url, 0) + 1
    if set(markers) != set(expected) or any(count != 1 for count in markers.values()):
        bad.append('each negative response must actually be injected once')
    remaining, consumed = [], set()
    for event in events:
        if not isinstance(event, dict):
            remaining.append(event); continue
        src, url = event.get('source'), event.get('url')
        if src == 'test_injected_response':
            continue
        if not isinstance(src, str) or not isinstance(url, str):
            remaining.append(event); continue
        # Chromium may locate the redirect:error console at Location rather than request URL.
        # This alias is allowed only for console, only for the exact proven synthetic target.
        provenance_url = redirect_sources.get(url, url) if src == 'console' else url
        code = expected.get(provenance_url) if markers.get(provenance_url) == 1 else None
        key = (src, provenance_url)
        allowed = False
        if code == 500 and src == 'http_error':
            allowed = event.get('method') == 'POST' and event.get('status') == 500
        elif code == 500 and src == 'console':
            allowed = event.get('type') == 'error' and event.get('text') in (
                'Failed to load resource: the server responded with a status of 500 (Internal Server Error)',
                'Failed to load resource: the server responded with a status of 500 ()')
        elif code == 302 and src == 'requestfailed':
            allowed = event.get('resource_type') == 'fetch' and event.get('failure') == 'net::ERR_FAILED'
        elif code == 302 and src == 'console':
            allowed = event.get('type') == 'error' and event.get('text') == 'Failed to load resource: net::ERR_FAILED'
        if allowed and key not in consumed:
            consumed.add(key)
        else:
            remaining.append(event)
    return bad + browser_event_violations(remaining)


def multiattachment_checks(obs, exitcode=None):
    """Require real runs on both viewports; producer PASS alone is never sufficient."""
    def complete():
        require(exitcode == 0, 'multiattachment browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'multiattachment run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and len(rows) == 2, 'both multiattachment viewports required')
        require(sorted(r.get('width') for r in rows) == [320, 1440], 'unexpected or duplicate viewports')
        for row in rows:
            require(row.get('status') == 'PASS', 'viewport did not pass')
            require(row.get('selected_first') == 2 and row.get('selected_second') == 3, 'selection did not accumulate')
            require(row.get('post_count') == 1 and row.get('message_count') == 1, 'missing or duplicated message')
            require(row.get('removed_file_count') == 0, 'removed file persisted')
            require(row.get('browser_errors') == [], 'missing or non-empty browser error observations')
            controls = row.get('negative_controls')
            require(isinstance(controls, list) and [r.get('http') for r in controls] == [500, 302], 'negative controls missing')
            require(all(r.get('text_retained') is True and r.get('files_retained') == 2 and r.get('error_visible') is True
                        for r in controls), 'negative control lost draft or accepted failure')
            violations = multiattachment_event_violations(row.get('browser_events'), controls)
            require(not violations, 'multiattachment browser events: %r' % violations[:3])
            downloads = row.get('downloads')
            require(isinstance(downloads, list) and len(downloads) == 2, 'both attachment downloads required')
            for download, index in zip(downloads, (1, 3)):
                require(download.get('http') == 200, 'download failed or redirected')
                require(download.get('sha256') == hashlib.sha256(MULTI_CONTENT[index]).hexdigest(), 'download content mismatch')
                require(isinstance(download.get('name'), str) and download['name'].endswith('-%d.txt' % index), 'wrong attachment name')
                local_url(download.get('url', ''))
    return {'multiattachment_complete': complete}


MESSAGE_ACTION_CASES = [('owned', 1440, 'click', 'discussion'), ('owned', 320, 'keyboard', 'overview'),
                        ('legacy', 1440, 'keyboard', 'discussion'), ('legacy', 320, 'click', 'overview')]
MESSAGE_ACTION_NEGATIVES = ('wrong_sesskey', 'get_instead_of_post', 'withdraw_without_confirmation',
                            'other_author_same_company', 'other_company')


def message_action_checks(obs, exitcode=None):
    """Edit/withdraw own task-discussion messages (message_actions.py). Verdicts come from DB state + rendered observations;
    the producer's PASS alone is never sufficient."""
    def cases():
        require(exitcode == 0, 'message actions browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'message actions run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('mode'), r.get('tab')) for r in rows]
                == MESSAGE_ACTION_CASES, 'owned/legacy x desktop/mobile x click/keyboard cases required')
        for r in rows:
            texts = r.get('texts')
            require(isinstance(texts, list) and len(texts) == 4 and len(set(texts)) == 4 and all(isinstance(t, str) and t for t in texts),
                    'case texts missing')
        return rows

    def posted(r):
        s = r.get('posted') or {}
        require(s.get('status') == 'active' and s.get('body') == r['texts'][0] and s.get('revisions') == []
                and s.get('attachments') == 1 and s.get('stored_files') == 1, 'message with one file was not posted')

    def cancel():
        for r in cases():
            posted(r)
            s = r.get('after_cancel') or {}
            require(s.get('body') == r['texts'][0] and s.get('status') == 'active' and s.get('revisions') == [],
                    'cancel changed the message')

    def edit():
        for r in cases():
            s = r.get('after_edit') or {}
            require(s.get('body') == r['texts'][1] and s.get('status') == 'active' and s.get('revisions') == ['edit'],
                    'edit not stored once')
            require(r['texts'][1] in (s.get('task_text') or '') and r['texts'][0] not in (s.get('task_text') or ''),
                    'task card does not show the saved text')
            require(s.get('edited_marker') is True, 'Edited marker missing after reload')
            require(s.get('attachments') == 1 and s.get('stored_files') == 1, 'edit changed attachments')
            require(s.get('download_http') == 200 and s.get('download_ok') is True, 'attachment not downloadable after edit')
            cc = r.get('communication_center') or {}
            require(cc.get('edited_text_count') == 1 and cc.get('original_text_count') == 0,
                    'Communication Center does not show the same edited text')

    def conflict():
        for r in cases():
            s = r.get('conflict') or {}
            require(s.get('body') == r['texts'][2] and s.get('revisions') == ['edit', 'edit'],
                    'stale tab overwrote the newer text or the newer edit was lost')
            require(any('Message changed' in a for a in (s.get('alert') or [])), 'conflict not reported')
            require(s.get('draft_kept') is True, 'failed edit lost its draft')

    def confirm():
        for r in cases():
            s = r.get('unconfirmed_delete') or {}
            require(s.get('post_requests') == 0 and s.get('status') == 'active' and s.get('body') == r['texts'][2],
                    'unconfirmed delete was sent or applied')

    def withdraw():
        for r in cases():
            s = r.get('after_withdraw') or {}
            require(s.get('status') == 'withdrawn' and s.get('body') == '' and s.get('revisions') == ['edit', 'edit', 'withdraw'],
                    'withdraw not stored with its audit history')
            require(s.get('withdrawn_marker') is True and s.get('old_body_visible') is False and r['texts'][2] not in (s.get('task_text') or ''),
                    'withdrawn text still rendered')
            require(s.get('file_link_count') == 0 and s.get('controls_left') == 0, 'file link or controls left on a withdrawn message')
            require(s.get('old_file_http') in DENY_CODES and s.get('old_file_leaked') is False,
                    'old file URL must refuse access, not redirect or fail with HTTP 5xx')
            require(s.get('attachments') == 1, 'withdraw destroyed the attachment record instead of closing access')

    def events():
        for r in cases():
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx during message actions: %r' % (r.get('server_errors'),))

    def negatives():
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'),
                'message actions run incomplete')
        n = obs.get('negatives') or {}
        before = n.get('unchanged_before_valid') or {}
        require(before.get('body') == n.get('target_text') and before.get('status') == 'active' and before.get('revisions') == [],
                'negative target not recorded')
        for key in MESSAGE_ACTION_NEGATIVES:
            response = n.get(key) or {}
            expected = {200} if key in ('withdraw_without_confirmation', 'other_author_same_company') else DENY_CODES
            require(response.get('http') in expected and not response.get('location'),
                    '%s returned an unexpected HTTP status or redirect' % key)
            s = response.get('state')
            require(isinstance(s, dict) and s.get('body') == before['body'] and s.get('status') == 'active' and s.get('revisions') == [],
                    '%s changed the message' % key)
        rp = n.get('replay') or {}
        for label in ('first', 'again'):
            response = rp.get(label) or {}
            require(response.get('http') == 303, 'replay %s did not complete with the normal redirect' % label)
            location = response.get('location')
            local_url(location)
            parsed = urlparse(location)
            query = parse_qsl(parsed.query, keep_blank_values=True)
            require(parsed.path == '/local/kbacompany/tasks.php' and not parsed.fragment
                    and len(query) == len(dict(query))
                    and set(dict(query)) in ({'uuid', 'task', 'view', 'tab'}, {'uuid', 'task', 'view', 'tab', 'scope'})
                    and dict(query).get('view') == 'detail' and dict(query).get('tab') in ('overview', 'discussion')
                    and dict(query).get('uuid') and dict(query).get('task'), 'unexpected replay redirect target')
        require({k: dict(parse_qsl(urlparse(rp['first']['location']).query))[k] for k in ('uuid', 'task')}
                == {k: dict(parse_qsl(urlparse(rp['again']['location']).query))[k] for k in ('uuid', 'task')},
                'replay redirect changed company or task')
        require((rp.get('state') or {}).get('body') == n.get('replay_text') and (rp.get('state') or {}).get('revisions') == ['edit'],
                'replayed request applied more or less than once')
        rv = n.get('revoked_member') or {}
        require(rv.get('http') in DENY_CODES and not rv.get('location'),
                'revoked member returned an unexpected HTTP status or redirect')
        require(rv.get('revoked') is True and n.get('reopened') is True, 'revocation not applied/reverted')
        require((rv.get('state') or {}).get('body') == n.get('ea_text') and (rv.get('state') or {}).get('revisions') == [],
                'revoked member changed own message')

    return {'message_edit_cancel_unchanged': cancel, 'message_edit_saved_synced_with_files': edit,
            'message_edit_conflict_keeps_newer_and_draft': conflict, 'message_withdraw_requires_confirmation': confirm,
            'message_withdraw_hides_text_and_files': withdraw, 'message_actions_no_browser_errors': events,
            'message_actions_server_negatives': negatives}


TASK_OPEN_CASES = [('owned', 1440), ('legacy', 320)]


def task_open_checks(obs, exitcode=None):
    """"User opened the task" History event (task_open.py): one event per user+task per 15 minutes, only from a visible
    successfully rendered card, server-side access re-check, no business change. Verdicts come from DB rows."""
    def run():
        require(exitcode == 0, 'task open browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'task open run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width')) for r in rows] == TASK_OPEN_CASES,
                'owned/legacy x desktop/mobile cases required')
        require(obs.get('readable') == {'MA': True, 'EA': True, 'RA': False, 'EB': False},
                'fixture access precondition (MA/EA read, RA/EB cannot) not met')
        return rows

    def first():
        for r in run():
            require(r.get('list') == {'marker': 0, 'rows': 0}, 'task list wrote or prepared an opened event')
            require(r.get('first') == {'status': '200', 'rows': 1}, 'first opening did not write exactly one event')
            h = r.get('history') or {}
            texts = h.get('texts') or []
            require(h.get('count') == 1 and h.get('visible') is True and len(texts) == 1, 'History must show exactly one opening')
            require('opened the task' in texts[0] and not re.search(r'\bread\b', texts[0], re.I),
                    'History text must say "opened the task", never "read"')

    def dedupe():
        for r in run():
            require(r.get('repeat') == {'reload_status': '200', 'rows': 1}, 'reload or card tabs wrote a duplicate')
            i = r.get('interval') or {}
            require(i.get('idle_rows') == 1, 'an open page wrote an event by itself after the interval')
            require(i.get('status') == '200' and i.get('rows') == 2, 'reopening after the interval did not write one event')
            c = r.get('concurrent') or {}
            replies = c.get('replies') or []
            require(len(replies) == 8 and all(x.get('http') == 200 for x in replies), 'concurrent requests failed')
            require([x.get('result') for x in replies].count('recorded') == 1 and c.get('rows') == 3,
                    'concurrent requests wrote more or less than one event')

    def hidden():
        for r in run():
            h = r.get('hidden') or {}
            require(h.get('before_activation') == 0, 'hidden tab wrote an event before activation')
            require(h.get('status') == '200' and h.get('after_activation') == 1, 'activation or another user did not write own event')

    def business():
        for r in run():
            b = r.get('business') or {}
            require(isinstance(b.get('before'), dict) and b.get('before') == b.get('after'),
                    'task state, business history or notifications changed')
            shape = r.get('row_shape')
            require(isinstance(shape, list) and shape and all(x.get('oldstatus') == '' and x.get('newstatus') == ''
                    and x.get('details') == '{}' for x in shape), 'opened event carries status or extra data')

    def failure():
        run()
        fl = obs.get('failure') or {}
        require(fl.get('status') == 'failed' and fl.get('title_visible') is True and fl.get('tabs') == 1
                and fl.get('page_errors') == [] and fl.get('rows') == 0, 'a failing endpoint broke the card or wrote')

    def events():
        for r in run():
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx during task open: %r' % (r.get('server_errors'),))

    def negatives():
        run()
        n = obs.get('negatives') or {}
        for key, code in (('wrong_sesskey', 403), ('get', 405), ('head', 405), ('substituted_task', 403),
                          ('company_task_mismatch', 403)):
            x = n.get(key) or {}
            require(x.get('http') == code and x.get('rows') == 0, '%s not rejected without a record' % key)
        for key in ('same_company_no_access', 'other_company'):
            x = n.get(key) or {}
            require(x.get('http') == 403 and x.get('rows') == 0 and x.get('marker') == 0 and x.get('rows_after_page') == 0,
                    '%s not rejected or a denied page prepared an event' % key)
        us = n.get('user_substitution') or {}
        ids = obs.get('ids') or {}
        require(us.get('http') == 200 and us.get('result') == 'recorded' and isinstance(us.get('rows'), list)
                and [x.get('actorid') for x in us['rows']] == [ids.get('MA')] and ids.get('MA') != ids.get('EA'),
                'server did not record the session user')
        rv = n.get('revoked_member') or {}
        require(rv.get('http') == 403 and rv.get('rows') == 0 and rv.get('revoked') is True and n.get('reopened') is True,
                'revoked member not rejected or revocation not reverted')

    return {'task_open_recorded_once_in_history': first, 'task_open_deduplicated_and_concurrent': dedupe,
            'task_open_hidden_tab_and_other_user': hidden, 'task_open_no_business_change': business,
            'task_open_failure_keeps_card': failure, 'task_open_no_browser_errors': events,
            'task_open_server_negatives': negatives}


def required_check_names():
    """Every check a complete BROWSER report must contain (used by browser_acceptance.py and merge_gate.py)."""
    return (['moodle_install', 'fixtures_companies_A_B'] + list(task_tab_checks({})) + list(attachments_checks({}, {}))
            + list(access_checks({})) + list(oversize_checks({}, {})) + list(multiattachment_checks({}))
            + list(message_action_checks({})) + list(task_open_checks({})))


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
