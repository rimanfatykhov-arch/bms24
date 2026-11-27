"""BROWSER tier contract (RC32, Issue #82): fail-closed evaluation of browser/HTTP observations.

Pure functions, no browser/DB/network. `require()` raises RuntimeError (never `assert`), so verdicts are identical under
`python -O`. Every check needs positive evidence: missing, empty, unexpected or error observations FAIL.
Note: the loopback check below constrains what the test scripts request; it is NOT OS-level network isolation.
"""
import hashlib
import json
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
CENTER_NAME = re.compile(r'cc-([0-9a-f]{12})\.txt')


def expected_sha(fname):
    """Exact expected bytes of a synthetic attachment by file name; None for any unknown name (never served intact).
    multiattachments.py files may already exist in a discussion (e.g. on a rehearsal baseline validated with the
    PR102 checkset) and are then part of the protected URL set; their content is fixed per index."""
    if fname in SHA:
        return SHA[fname]
    # communication_center.py writes these exact bytes; baseline attachments remain protected
    # when the subsequent install/restore ACL smoke enumerates the complete discussion.
    center = CENTER_NAME.fullmatch(fname or '')
    if center:
        return hashlib.sha256(('communication center attachment %s\n' % center.group(1)).encode()).hexdigest()
    m = MULTI_NAME.fullmatch(fname or '')
    return hashlib.sha256(MULTI_CONTENT[int(m.group(1))]).hexdigest() if m else None


DENY_CODES = {403, 404}          # protected pluginfile refusal for a logged-in user without access
LOGIN_REDIRECT_CODES = {302, 303}  # anonymous user is sent to the login page (redirect not followed)
LIMIT_BYTES = 65536
CSRF_REFUSAL_CODES = {403, 404}   # Moodle require_sesskey() -> moodle_exception page; 5xx is a server error, never a refusal
CSRF_REFUSAL_MARKER = 'Your session has most likely timed out'  # Moodle 4.1 lang/en/error.php 'invalidsesskey'
LOGIN_PATH = '/login/index.php'
CENTER_PATH = '/local/kbacompany/communication_center.php'
PLAN_PATH = '/local/kbacompany/battle_plan.php'
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


def upload_redirect_query(row):
    """The accepted redirect after a direct upload to a discussion: exactly uuid/tab=discussions/thread, plus filter=all
    when the Center renders the UX-1 filters (UX-1 always keeps the default filter in its own links). The schema is fixed
    here, never taken from the producer: only the company/thread values are recorded by it."""
    base = row.get('expected_query')
    require(isinstance(base, dict) and set(base) == {'uuid', 'tab', 'thread'} and base.get('tab') == 'discussions'
            and all(isinstance(base[k], str) and base[k] for k in base), 'upload redirect base query not recorded exactly')
    ux = row.get('ux_filters')
    require(ux in (0, 1) and not isinstance(ux, bool), 'UX-1 filter presence not recorded')
    return dict(base, filter='all') if ux == 1 else dict(base)


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
            exact_redirect(_step(obs, 'server_file_only_direct').get('location'), CENTER_PATH,
                           upload_redirect_query(_step(obs, 'server_file_only_direct'))),
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
            protected = set(SHA.values()) | {expected_sha(u.split('/')[-1].split('?')[0]) for u in expected()}
            protected.discard(None)
            require(r.get('sha256') not in protected, '%s: protected content served' % name)
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


TASK_OPEN_CASES = [('owned', 1440, 'en'), ('legacy', 320, 'ru')]
TASK_OPEN_TEXT = {'en': 'opened the task', 'ru': 'открыл задачу'}


def task_open_checks(obs, exitcode=None):
    """"User opened the task" History event (task_open.py): one event per user+task per 15 minutes, only from a visible
    successfully rendered card, server-side access re-check, no business change. Verdicts come from DB rows."""
    def run():
        require(exitcode == 0, 'task open browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'task open run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('lang')) for r in rows] == TASK_OPEN_CASES,
                'owned/legacy x desktop/mobile x EN/RU cases required')
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
            lang = r['lang']
            require((r.get('language') or {}).get('installed') is True and (h.get('html_lang') or '').split('-')[0] == lang,
                    'page language %s not active' % lang)
            require(TASK_OPEN_TEXT[lang] in texts[0] and TASK_OPEN_TEXT[lang] in ((r.get('language') or {}).get('sample') or ''),
                    'History text must say "%s"' % TASK_OPEN_TEXT[lang])
            require(not re.search(r'\bread\b|прочит|ознаком', texts[0], re.I),
                    'History must not claim reading or acknowledgement')

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


COMM_CASES = [('owned', 1440, 'click'), ('legacy', 320, 'keyboard')]


def communication_center_checks(obs, exitcode=None):
    """Communication Center cleanup, items 1-5 (communication_center.py). Verdicts come from DB rows and rendered
    observations; the producer's PASS alone is never sufficient."""
    def run():
        require(exitcode == 0, 'communication center browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'communication center run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('mode')) for r in rows] == COMM_CASES,
                'owned/legacy x desktop/mobile x click/keyboard cases required')
        ids = obs.get('ids') or {}
        require(all(isinstance(ids.get(k), int) and ids[k] > 0 for k in ('MA', 'EA', 'RA', 'EB')), 'fixture ids missing')
        require(isinstance(obs.get('viewer_id'), int) and obs['viewer_id'] not in ids.values(), 'separate task viewer missing')
        for r in rows:
            texts = r.get('texts')
            require(isinstance(texts, list) and len(texts) == 3 and len(set(texts)) == 3
                    and all(isinstance(t, str) and t for t in texts), 'case texts missing')
            require(isinstance(r.get('question'), str) and r['question'], 'question id missing')
        return rows, ids, obs['viewer_id']

    def served(d, sha):
        return isinstance(d, dict) and d.get('http') == 200 and d.get('sha256') is not None and d.get('sha256') == sha

    def refused(d):
        return isinstance(d, dict) and d.get('http') in DENY_CODES and d.get('sha256') is None

    def denied_post(d, kinds):
        require(isinstance(d, dict) and type(d.get('http')) is int and d['http'] in (200, 403, 404),
                'negative POST missing or unexpected HTTP (redirect/5xx are never refusals)')
        require(d.get('location') == '', 'negative POST redirected')
        require(local_url(d.get('url', '')) == 'http://127.0.0.1:8080/local/kbacompany/communication_center.php',
                'negative POST came from another page')
        require(isinstance(d.get('body_sha256'), str) and re.fullmatch('[0-9a-f]{64}', d['body_sha256']),
                'negative POST body evidence missing')
        strings = obs.get('denial_texts') or {}
        require(all(isinstance(strings.get(k), str) and strings[k] and not strings[k].startswith('[[') for k in kinds),
                'canonical denial strings missing')
        errors = d.get('error_texts')
        require(isinstance(errors, list) and errors and all(isinstance(x, str) for x in errors),
                'negative POST has no rendered error block')
        require(any(' '.join(strings[k].split()) in text for k in kinds for text in errors),
                'negative POST did not show the expected domain denial')

    def viewer():
        rows, ids, va = run()
        for i, r in enumerate(rows):
            v = r.get('viewer') or {}
            require(v.get('card_question') is True and v.get('card_reply') is True, 'task viewer does not read the card discussion')
            require(v.get('card_reader_note') is True, 'reader note missing in the card')
            require(served(v.get('download'), r.get('file_sha256')), 'task viewer cannot download the discussion file')
            require(v.get('center_question') == 1 and v.get('center_join') == 1 and v.get('center_composer') == 0
                    and v.get('center_reply_links') == 0, 'Center must show the thread read-only with an explicit join')
            p = v.get('parts_after_reading') or {}
            require(isinstance(p.get('active'), list) and va not in p['active'] and va not in (p.get('reads') or []),
                    'reading joined the viewer or created read state')
            if i == len(rows) - 1:
                j = v.get('parts_after_join') or {}
                require(va in (j.get('active') or []) and v.get('composer_after_join') == 1, 'explicit join did not work')

    def denied():
        rows, ids, va = run()
        for r in rows:
            d = r.get('denied') or {}
            require(set(d) == {'RA', 'EB'}, 'denied users missing')
            for label, x in d.items():
                require(x.get('center_text') is False and x.get('card_text') is False, label + ' read the task discussion')
                require(refused(x.get('download')), label + ' downloaded the discussion file')
                # The route deliberately masks a forbidden object as unavailable, without disclosing its existence.
                denied_post(x.get('join_response'), ['context'] if label == 'RA' else ['company'])
                require(x.get('join_http') == x['join_response']['http'] and ids[label] not in ((x.get('parts') or {}).get('active') or []),
                        label + ' joined the task discussion')
        rv = obs.get('revocation') or {}
        sha = rows[-1].get('file_sha256')
        require((rv.get('before') or {}).get('text') is True and served((rv.get('before') or {}).get('download'), sha),
                'revocation precondition not met')
        require(rv.get('revoked') is True and rv.get('reopened') is True, 'revocation not applied/reverted')
        require((rv.get('after') or {}).get('text') is False and refused((rv.get('after') or {}).get('download')),
                'revoked member still reads the thread or downloads its file')
        require((rv.get('restored') or {}).get('text') is True and served((rv.get('restored') or {}).get('download'), sha),
                'access not restored after reopen')

    def events():
        rows, ids, va = run()
        for r in rows:
            c, cc = r.get('card') or {}, r.get('center') or {}
            card_events, oldest, newest = c.get('events'), cc.get('events_oldest'), cc.get('events')
            require(isinstance(card_events, list) and any('Task created' in e for e in card_events), 'task events missing in the card')
            require(isinstance(oldest, list) and any('Task created' in e for e in oldest), 'task events missing in the Center')
            require(isinstance(newest, list), 'Center events not observed')
            require(not any('opened' in e.lower() for e in card_events + oldest + newest), 'task opening shown as a discussion event')
            require(c.get('messages_before') and c.get('messages_after') == c['messages_before'] + 1,
                    'events were stored as messages or the reply count is wrong')
            require(isinstance(c.get('history_opened'), int) and c['history_opened'] >= 1, 'opened missing from the task History')

    def reply():
        rows, ids, va = run()
        for r in rows:
            t = r['texts']
            target = r.get('reply_target') or {}
            require(target.get('count') == 1 and t[0] in (target.get('text') or ''), 'reply target not shown in the composer')
            m = r.get('reply') or {}
            require(m.get('reply_to') == r['question'] and m.get('body') == t[1] and m.get('status') == 'active',
                    'reply not linked to the original')
            require(m.get('mentions') == [ids['EA']], 'inline mention not resolved to exactly the participant')
            require('mention' in (r.get('mention_notification') or []), 'mention notification missing')
            require(any(t[0] in q for q in (r.get('card') or {}).get('quote') or []), 'quote missing in the card')
            require(any(t[0] in q for q in (r.get('center') or {}).get('quote') or []), 'quote missing in the Center')

    def direct():
        rows, ids, va = run()
        seen = set()
        for r in rows:
            d = r.get('direct') or {}
            responses = d.get('responses')
            require(isinstance(responses, list) and len(responses) == 2 and all(x.get('http') == 303 for x in responses),
                    'direct conversation request failed')
            for response in responses:
                destination = urlparse(local_url(response.get('location', '')))
                require(destination.path == '/local/kbacompany/communication_center.php' and
                        dict(parse_qsl(destination.query)).get('thread') == response.get('thread'),
                        'direct conversation redirected to an unexpected page or thread')
            uuids = {x.get('thread') for x in responses}
            require(len(uuids) == 1 and '' not in uuids and None not in uuids and d.get('threads') == list(uuids),
                    'repeated Write created another direct conversation')
            seen |= uuids
        require(len(seen) == 1, 'direct conversation changed between cases')

    def group():
        rows, ids, va = run()
        ma, ea, ra = ids['MA'], ids['EA'], ids['RA']
        for r in rows:
            g = r.get('group') or {}
            created = g.get('created') or {}
            require(created.get('active') == sorted([ma, ea, ra, va]) and created.get('owners') == [ma], 'group not created')
            for key in ('non_owner_remove', 'forged_sesskey'):
                x = g.get(key) or {}
                denied_post(x, ['sesskey'] if key == 'forged_sesskey' else ['permission'])
                require((x.get('parts') or {}).get('active') == created['active'], key + ' changed the group')
            lt = g.get('leave_task_discussion') or {}
            denied_post(lt, ['state'])
            require(ma in ((lt.get('parts') or {}).get('active') or []), 'left a task discussion')
            require(g.get('remove_controls') == 3 and g.get('owner_marker') is True, 'owner controls missing')
            require((g.get('after_remove') or {}).get('active') == sorted([ma, ea, va]), 'owner removal not applied')
            after = g.get('after_owner_leave') or {}
            require(after.get('active') == sorted([ea, va]) and after.get('owners') == [ea], 'ownership not passed on leave')
            require(g.get('new_owner_controls') == 1, 'new owner cannot manage members')
            require((g.get('access_after') or {}) == {'MA': False, 'RA': False}, 'removed or departed member still reads the group')

    def filters():
        rows, ids, va = run()
        for r in rows:
            fl, cc = r.get('filters') or {}, r.get('center') or {}
            require(fl.get('discussions') == {'task': 1, 'direct': 0} and fl.get('messages') == {'task': 0, 'direct': 1},
                    'filters do not separate conversations from object discussions')
            require(isinstance(fl.get('tabs'), int) and fl['tabs'] >= 2, 'filter tabs missing')
            link = cc.get('open_task') or {}
            href = urlparse(link.get('href') or '')
            require(link.get('text') == 'Open task' and href.path == '/local/kbacompany/tasks.php' and 'task=' in href.query,
                    'Open task link missing')
            require(cc.get('question') == 1 and cc.get('reply_links') == 1 and cc.get('composer') == 1,
                    'participant view of the Center incomplete')

    def browser_events():
        rows, ids, va = run()
        for r in rows:
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx in the Communication Center: %r' % (r.get('server_errors'),))

    return {'comm_task_discussion_read_by_task_viewer': viewer, 'comm_task_discussion_denied_and_revoked': denied,
            'comm_task_events_inline_opened_in_history': events, 'comm_reply_quote_and_mention': reply,
            'comm_direct_conversation_unique': direct, 'comm_group_remove_leave_transfer': group,
            'comm_filters_and_open_task': filters, 'comm_no_browser_errors': browser_events}


DAILY_REPORT_CASES = [('owned', 1440, 'en'), ('owned', 375, 'ru'), ('legacy', 1440, 'en'), ('legacy', 375, 'ru')]
DAILY_REPORT_LABELS = {
    'en': {'nav': 'Daily reports', 'nav_short': 'Reports', 'tabs': 'Daily report sections', 'tab_my': 'My report',
           'button': 'Daily report', 'button_short': 'Report'},
    'ru': {'nav': 'Отчёты за день', 'nav_short': 'Отчёты', 'tabs': 'Разделы отчётов за день', 'tab_my': 'Мой отчёт',
           'button': 'Отчёт за день', 'button_short': 'Отчёт'},
}
DAILY_REPORT_PATH = '/local/kbacompany/daily_report.php'


def aria_link_named(snapshot, name):
    """True when a Playwright aria_snapshot() of one element is a link whose accessible name is exactly the given name."""
    if not isinstance(snapshot, str) or not snapshot.strip():
        return False
    first = snapshot.strip().splitlines()[0].strip()
    return re.fullmatch(r'- link "%s"(?: \[[^\]]*\])*:?' % re.escape(name), first) is not None


def daily_reports_nav_checks(obs, exitcode=None):
    """Daily reports R0 entry (daily_reports_nav.py, ADR-0009). Owned menu order/active state, own URL and tab,
    report-first Task Center button, short phone labels with full accessible names, no horizontal overflow."""
    def run():
        require(exitcode == 0, 'daily reports browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'daily reports run incomplete')
        require(isinstance(obs.get('company'), str) and len(obs['company']) >= 8, 'company reference not recorded')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('lang')) for r in rows] == DAILY_REPORT_CASES,
                'owned/legacy x 1440 EN / 375 RU cases required')
        for r in rows:
            require(r.get('language_installed') is True, 'page language %s not active' % r.get('lang'))
            require((r.get('report') or {}).get('owned_shell') is (r['shell'] == 'owned'), 'shell mode %s not active' % r['shell'])
        return rows

    def menu():
        for r in run():
            if r['shell'] != 'owned':
                continue
            m = (r.get('report') or {}).get('menu') or {}
            labels = DAILY_REPORT_LABELS[r['lang']]
            require(m.get('present') is True and m.get('scope_visible') is True and m.get('visible_items') == 1,
                    'exactly one visible daily reports item in the %s px menu' % r['width'])
            order = [x.get('path') for x in (m.get('order') or [])]
            require('daily_report.php' in order, 'daily reports item missing from the workspace menu')
            i = order.index('daily_report.php')
            require(i > 0 and order[i - 1] == 'tasks.php' and order[i + 1:i + 2] == ['recurring_tasks.php'],
                    'daily reports must sit directly after Tasks and before Recurring tasks: %r' % order)
            current = [x.get('path') for x in m['order'] if x.get('current') == 'page']
            require(current == ['daily_report.php'], 'only the daily reports item may be current: %r' % current)
            item = m.get('item') or {}
            require(item.get('path') == DAILY_REPORT_PATH and item.get('uuid') == obs['company'] and item.get('params') == ['uuid'],
                    'menu URL must be daily_report.php?uuid=<company A> only')
            require(aria_link_named(item.get('aria_snapshot'), labels['nav']),
                    'menu link accessible name must be the full "%s": %r' % (labels['nav'], item.get('aria_snapshot')))
            fs, ss = item.get('full_span') or {}, item.get('short_span') or {}
            require(fs.get('text') == labels['nav'] and fs.get('aria_hidden') is False and fs.get('display') != 'none'
                    and fs.get('visibility') != 'hidden', 'full menu label must stay exposed to screen readers: %r' % (fs,))
            require(ss.get('label') == labels['nav_short'] and ss.get('text') == '' and ss.get('aria_hidden') is True,
                    'short menu label must be aria-hidden CSS text, not DOM text: %r' % (ss,))

    def labels():
        for r in run():
            if r['shell'] != 'owned':
                continue
            item = ((r.get('report') or {}).get('menu') or {}).get('item') or {}
            names = DAILY_REPORT_LABELS[r['lang']]
            full, short = item.get('full_box') or {}, item.get('short_box') or {}
            if r['width'] > 860:
                require(item.get('text') == names['nav'] and full.get('w', 0) > 1 and short.get('w', 1) == 0,
                        'desktop menu must show the full label only: %r' % item.get('text'))
            else:
                require(item.get('text') == names['nav_short'] and short.get('w', 0) > 1 and full.get('w', 9) <= 1
                        and full.get('h', 9) <= 1, 'phone menu must show the short label only: %r' % item.get('text'))

    def page():
        for r in run():
            rep = r.get('report') or {}
            names = DAILY_REPORT_LABELS[r['lang']]
            require(rep.get('http') == 200 and rep.get('path') == DAILY_REPORT_PATH, 'daily report page not served')
            require(rep.get('current_tabs') == [names['tab_my']] and rep.get('current_tab_paths') == [DAILY_REPORT_PATH],
                    'exactly one current "%s" tab required: %r' % (names['tab_my'], rep.get('current_tabs')))
            require(names['nav'] in (rep.get('h1') or []) and names['nav'] in (rep.get('title') or ''),
                    'page heading and title must say "%s"' % names['nav'])

    def button():
        for r in run():
            t = r.get('tasks') or {}
            b = t.get('button') or {}
            names = DAILY_REPORT_LABELS[r['lang']]
            require(t.get('http') == 200 and t.get('buttons') == 1, 'exactly one daily report button in the Task Center')
            require(b.get('path') == DAILY_REPORT_PATH and b.get('uuid') == obs['company'] and b.get('params') == ['uuid']
                    and b.get('visible') is True, 'button must be visible and open daily_report.php?uuid=<company A>')
            require(b.get('aria_label') == names['button'] and b.get('title') == names['button']
                    and aria_link_named(b.get('aria_snapshot'), names['button']),
                    'button must keep the full accessible name "%s"' % names['button'])
            expected = names['button'] if r['width'] >= 576 else names['button_short']
            require(b.get('text') == expected, 'button text at %s px must be "%s", got %r' % (r['width'], expected, b.get('text')))
            require(r.get('clicked_path') == DAILY_REPORT_PATH, 'clicking the button did not open the daily report page')

    def overflow():
        for r in run():
            for key, box in (('tasks', (r.get('tasks') or {}).get('hscroll')), ('report', (r.get('report') or {}).get('hscroll')),
                             ('menu', (r.get('report') or {}).get('hscroll_after_menu'))):
                require(isinstance(box, dict) and isinstance(box.get('scroll'), int) and box.get('inner') == r['width']
                        and box['scroll'] <= box['inner'], '%s page scrolls horizontally at %s px: %r' % (key, r['width'], box))

    def foreign():
        run()
        x = obs.get('foreign') or {}
        require(isinstance(x.get('http'), int) and x['http'] < 500 and x.get('tabs') == 0 and x.get('day_form') == 0,
                'foreign-company actor reached the daily report page: %r' % (x,))

    def events():
        for r in run():
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx on daily reports pages: %r' % (r.get('server_errors'),))

    return {'daily_reports_menu_after_tasks_and_current': menu, 'daily_reports_menu_full_and_short_labels': labels,
            'daily_reports_page_url_title_and_tab': page, 'daily_reports_task_center_button': button,
            'daily_reports_no_horizontal_overflow': overflow, 'daily_reports_foreign_company_denied': foreign,
            'daily_reports_no_browser_errors': events}


DAILY_REPORTS_FLOW_CASES = [('owned', 1440), ('legacy', 375)]


def daily_reports_flow_checks(obs, exitcode=None):
    """Saved daily reports R1/R2 (daily_reports_flow.py). Verdicts come from DB state read after each step; the producer's
    PASS alone is never sufficient."""
    def run():
        require(exitcode == 0, 'daily reports flow browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'daily reports flow incomplete')
        require((obs.get('setup') or {}).get('enabled') is True, 'daily reports not enabled by the fixture')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width')) for r in rows] == DAILY_REPORTS_FLOW_CASES,
                'owned 1440 / legacy 375 cases required')
        require([r.get('today') for r in rows] == [True, False], 'today and an earlier day required')
        for r in rows:
            t = r.get('texts') or {}
            require(len(t) == 6 and len(set(t.values())) == 6 and all(isinstance(v, str) and v for v in t.values()), 'case texts missing')
        return rows

    def submitted(st):
        return [v for v in (st or {}).get('versions') or [] if v.get('state') != 'draft']

    def draft_and_conflict():
        for r in run():
            require(r.get('relogin_draft') == r['texts']['draft'], 'server draft lost after a new login')
            c = r.get('conflict') or {}
            require(c.get('shown') == 1 and c.get('tab2_kept') is True and c.get('tab1_saved') is True,
                    'two-tab conflict not reported or a text was lost')

    def submit_once():
        for r in run():
            http = r.get('submit_http') or []
            require(len(http) == 8 and all(isinstance(x, int) and x < 500 for x in http) and 303 in http,
                    'concurrent submissions failed: %r' % (http,))
            st = r.get('after_submit') or {}
            versions = submitted(st)
            require((st.get('report') or {}).get('status') == 'submitted' and len(versions) == 1
                    and versions[0].get('done') == r['texts']['submit'], 'concurrent submissions did not create exactly one version')
            require(versions[0].get('reviewerid') == (r.get('ids') or {}).get('MA'), 'battle plan reviewer not stored on the version')

    def csrf():
        for r in run():
            forged = r.get('forged_http') or []
            require(len(forged) == 1 and isinstance(forged[0], int) and forged[0] != 303 and forged[0] < 500,
                    'forged sesskey request was redirected as a success or failed with 5xx: %r' % (forged,))
            require(isinstance(r.get('get_http'), int) and r['get_http'] < 500, 'GET with action parameters failed')
            require(submitted(r.get('after_forged')) == submitted(r.get('after_submit')), 'forged sesskey or GET changed the report')
            require(r['texts']['forged'] not in json.dumps(r.get('after_forged'), ensure_ascii=False), 'forged text stored')

    def snapshot():
        rows = run()
        today = rows[0]
        before, after = submitted(today.get('after_submit')), submitted(today.get('after_touch'))
        require(before and after and before[0].get('snapshot_has_marker') is True, 'task of the day missing from the snapshot')
        require(after[0].get('snapshot_sha') == before[0].get('snapshot_sha') and after[0].get('contenthash') == before[0].get('contenthash')
                and after[0].get('snapshot_has_marker') is True, 'submitted snapshot changed after the task changed')

    def review_cycle():
        for r in run():
            for key in ('return_http', 'accept_http'):
                http = r.get(key) or []
                require(len(http) == 8 and all(isinstance(x, int) and x < 500 for x in http), '%s failed: %r' % (key, http))
            ret = r.get('after_return') or {}
            v1 = submitted(ret)
            require((ret.get('report') or {}).get('status') == 'returned' and len(v1) == 1 and
                    [(d.get('versionuuid'), d.get('decision')) for d in ret.get('decisions') or []] == [(v1[0]['uuid'], 'returned')],
                    'concurrent return did not record exactly one decision')
            rw = r.get('after_rework') or {}
            vs = submitted(rw)
            require((rw.get('report') or {}).get('status') == 'submitted' and len(vs) == 2 and vs[0]['uuid'] == v1[0]['uuid']
                    and vs[0].get('done') == r['texts']['submit'] and vs[1].get('done') == r['texts']['rework'],
                    'rework did not create version 2 while keeping version 1')
            acc = r.get('after_accept') or {}
            require(r.get('accept_version') == vs[1]['uuid'], 'accept form not bound to the current version')
            require((acc.get('report') or {}).get('status') == 'accepted' and
                    [(d.get('versionuuid'), d.get('decision'), d.get('rating')) for d in acc.get('decisions') or []] ==
                    [(vs[0]['uuid'], 'returned', ''), (vs[1]['uuid'], 'accepted', 'positive')],
                    'concurrent accept did not bind exactly one positive decision to version 2')

    def notifications():
        for r in run():
            ids = r.get('ids') or {}
            rows = [n for n in (r.get('after_accept') or {}).get('notifications') or [] if r['day'] in (n.get('title') or '')]
            got = sorted((n.get('userid'), n.get('kind')) for n in rows)
            require(got == sorted([(ids.get('MA'), 'dailyreport_submitted')] * 2 + [(ids.get('EA'), 'dailyreport_decision')] * 2),
                    'notifications wrong recipient or duplicated: %r' % (got,))
            require(isinstance(r.get('notification_links'), int) and r['notification_links'] >= 1,
                    'reviewer notification has no link to the review tab')

    def negatives():
        for r in run():
            for label in ('EB', 'RA'):
                http = ((r.get('negatives') or {}).get(label) or {}).get('http') or []
                require(len(http) == 1 and isinstance(http[0], int) and http[0] < 500, '%s request failed unexpectedly' % label)
            require((r.get('after_negatives') or {}).get('decisions') == (r.get('after_accept') or {}).get('decisions'),
                    'a foreign or non-reviewer request changed the decisions')

    def layout_and_events():
        for r in run():
            require(r.get('hscroll_form') is True and r.get('hscroll_card') is True, 'horizontal scroll at %s px' % r.get('width'))
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx: %r' % (r.get('server_errors'),))

    def team_day():
        # R4 (owner decisions T1–T5): one row per current member of company A for the case day, the accepted report of EA
        # linked, nobody from company B, counts equal to the rows, no horizontal scroll; an employee never gets the list.
        for r in run():
            ids, team = r.get('ids') or {}, r.get('team') or {}
            rows = team.get('rows')
            require(isinstance(rows, list) and rows and all(isinstance(x, list) and len(x) == 2 for x in rows),
                    'team for the day rendered no rows at %s px' % r.get('width'))
            users = [x[0] for x in rows]
            require(len(users) == len(set(users)), 'a member is listed twice: %r' % (users,))
            require([x[1] for x in rows if x[0] == ids.get('EA')] == ['accepted'], 'EA not listed once as accepted: %r' % (rows,))
            require(ids.get('RA') in users, 'same-company employee RA missing from the team for the day')
            require(ids.get('EB') not in users, 'a member of another company is listed')
            states = set(x[1] for x in rows)
            require(states <= {'none', 'notsent', 'submitted', 'returned', 'accepted'}, 'unknown day state: %r' % (states,))
            counts = team.get('counts') or {}
            require(set(counts) == {'none', 'notsent', 'submitted', 'returned', 'accepted'} and
                    all(isinstance(v, int) for v in counts.values()) and sum(counts.values()) == len(rows) and
                    all(counts[s] == sum(1 for x in rows if x[1] == s) for s in counts),
                    'day counts do not match the rows: %r' % (counts,))
            require(team.get('filters') == 1 and isinstance(team.get('report_links'), int) and team['report_links'] >= 1,
                    'team filters or the link to the accepted report missing')
            require(team.get('hscroll') is True, 'horizontal scroll on the team for the day at %s px' % r.get('width'))
            require(r.get('team_employee_filters') == 0, 'an ordinary employee received the team for the day')

    def users(rows):
        require(isinstance(rows, list) and all(isinstance(x, list) and len(x) == 2 for x in rows), 'team rows malformed')
        return [x[0] for x in rows]

    def team_scopes_and_open():
        # #168 with a synthetic unit (MA manages EA): "my team" lists EA and only people of the whole company; "whole
        # company" lists EA and RA (RA only with a private draft, "not sent"); nobody of company B; the sent report opens
        # from the list by a click; RA's draft text is never rendered.
        for r in run():
            ids, scopes = r.get('ids') or {}, r.get('team_scopes') or {}
            team, comp = scopes.get('team') or {}, scopes.get('company') or {}
            for name, s in (('team', team), ('company', comp)):
                require(s.get('options') == ['team', 'company'] and s.get('selected') == name,
                        'scope %s not offered or not kept: %r' % (name, s.get('options')))
                require(s.get('draft_leak') is False and s.get('hscroll') is True, 'draft text or horizontal scroll on %s' % name)
            tu, cu = users(team.get('rows')), users(comp.get('rows'))
            require(ids.get('EA') in tu and ids.get('EB') not in tu and set(tu) <= set(cu) and len(tu) == len(set(tu)),
                    'my team list wrong: %r' % (tu,))
            require(ids.get('EA') in cu and ids.get('RA') in cu and ids.get('EB') not in cu and len(cu) == len(set(cu)),
                    'whole company list wrong: %r' % (cu,))
            require([x[1] for x in comp['rows'] if x[0] == ids.get('RA')] == ['notsent'], 'RA draft not shown as "not sent"')
            o = r.get('team_open') or {}
            require(o.get('link') == 1 and o.get('url_report') is True and isinstance(o.get('versions'), int)
                    and o['versions'] >= 1 and o.get('draft_leak') is False, 'sent report did not open from the list: %r' % (o,))

    def monthly_summary():
        # #173: the case month, EA's accepted positive cell linked, previous month by link, "my subordinates" through the
        # filter form, the cell opens the card, nobody of company B, no draft text, no horizontal scroll.
        for r in run():
            ids, s = r.get('ids') or {}, r.get('summary') or {}
            month = str(r.get('day') or '')[:7]
            require(len(month) == 7 and s.get('month') == month and s.get('filters') == 1, 'summary month or filters wrong')
            y, m = int(month[:4]), int(month[5:7])
            prev = '%04d-%02d' % ((y, m - 1) if m > 1 else (y - 1, 12))
            require(s.get('prev_month') == prev, 'previous month link wrong: %r' % (s.get('prev_month'),))
            ua, um = s.get('users_all'), s.get('users_mine')
            require(isinstance(ua, list) and ids.get('EA') in ua and ids.get('EB') not in ua, 'summary rows wrong: %r' % (ua,))
            require(isinstance(um, list) and ids.get('EA') in um and ids.get('EB') not in um and set(um) <= set(ua)
                    and s.get('scope_kept') == 'mine', 'my subordinates filter wrong: %r' % (um,))
            require(s.get('cell_links') == 1 and s.get('cell') == ['accepted', 'positive'], 'EA cell wrong: %r' % (s.get('cell'),))
            card = s.get('card') or {}
            require(card.get('url_report') is True and isinstance(card.get('versions'), int) and card['versions'] >= 1,
                    'summary cell did not open the card')
            require(s.get('draft_leak') is False and s.get('hscroll') is True, 'draft text or horizontal scroll in the summary')

    def release_access_denied():
        # #168 + #173: an ordinary employee has no summary; a member of another company gets no list, summary or card.
        for r in run():
            d = r.get('release_denied') or {}
            ea = d.get('EA') or {}
            require(ea.get('summary') == 0 and ea.get('summary_tab') == 0, 'an ordinary employee received the summary')
            for key in ('EB_team', 'EB_summary', 'EB_card'):
                x = d.get(key) or {}
                require(isinstance(x.get('http'), int) and x['http'] < 500, '%s failed: %r' % (key, x.get('http')))
                require(x.get('team') == 0 and x.get('summary') == 0 and x.get('versions') == 0 and x.get('leak') is False,
                        'another company member reached %s: %r' % (key, x))

    def discussion():
        # R3 (owner decision 24.11.2026): one canonical thread; the author's and the reviewer's messages stored once, in order,
        # markup kept as text; a forged sesskey, the same-company non-reader RA and the foreign EB store nothing.
        for r in run():
            ids, d = r.get('ids') or {}, r.get('discussion_texts') or {}
            require(set(d) == {'author', 'reviewer', 'forged', 'RA', 'EB'} and len(set(d.values())) == 5,
                    'discussion texts missing')
            require(r.get('discussion_author_composer') == 1, 'the author has no discussion composer')
            require(r.get('discussion_reviewer_sections') == 1 and r.get('discussion_reviewer_sees_author') is True,
                    'the reviewer does not read the author message on the card')
            require(r.get('discussion_injected_tags') == 0, 'message markup was rendered as HTML')
            forged = r.get('discussion_forged_http') or []
            require(len(forged) == 1 and isinstance(forged[0], int) and forged[0] != 303 and forged[0] < 500,
                    'forged discussion sesskey redirected as success or failed with 5xx: %r' % (forged,))
            for label in ('EB', 'RA'):
                http = ((r.get('discussion_negatives') or {}).get(label) or {}).get('http') or []
                require(len(http) == 1 and isinstance(http[0], int) and http[0] < 500,
                        '%s discussion request failed unexpectedly: %r' % (label, http))
            after = r.get('after_discussion') or {}
            msgs = after.get('discussion')
            require(isinstance(msgs, list) and msgs, 'discussion state missing')
            require(len(set(m.get('thread') for m in msgs)) == 1, 'report discussion is not one canonical thread')
            got = [(m.get('authorid'), m.get('body')) for m in msgs]
            require(got == [(ids.get('EA'), d['author']), (ids.get('MA'), d['reviewer'])],
                    'discussion messages wrong, duplicated or forged: %r' % (got,))
            require(after.get('report') == (r.get('after_accept') or {}).get('report') and
                    after.get('decisions') == (r.get('after_accept') or {}).get('decisions'),
                    'a discussion message changed the report or its decisions')
            require(r.get('hscroll_discussion') is True, 'horizontal scroll on the report discussion at %s px' % r.get('width'))

    return {'daily_report_draft_relogin_and_conflict': draft_and_conflict, 'daily_report_concurrent_submit_one_version': submit_once,
            'daily_report_csrf_and_get_rejected': csrf, 'daily_report_snapshot_immutable': snapshot,
            'daily_report_return_rework_accept_once': review_cycle, 'daily_report_notifications_once_with_link': notifications,
            'daily_report_foreign_and_nonreviewer_denied': negatives, 'daily_report_layout_and_browser_errors': layout_and_events,
            'daily_report_team_day_view': team_day, 'daily_report_team_scopes_and_open': team_scopes_and_open,
            'daily_report_monthly_summary': monthly_summary, 'daily_report_release_access_denied': release_access_denied,
            'daily_report_discussion_author_and_reviewer': discussion}


DAY_FEED_CASES = [('owned', 1440), ('legacy', 375)]


def day_feed_checks(obs, exitcode=None):
    """Day feed -> report draft (day_feed.py). Verdicts come from the draft state read after each step; the producer's
    PASS alone is never sufficient."""
    def run():
        require(exitcode == 0, 'day feed browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'day feed run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width')) for r in rows] == DAY_FEED_CASES,
                'owned 1440 / legacy 375 cases required')
        for r in rows:
            tasks = r.get('tasks')
            require(isinstance(tasks, list) and len(tasks) == 2 and all(isinstance(t, dict) and isinstance(t.get('event'), int)
                    and t.get('title') and t.get('result') for t in tasks), 'synthetic results missing')
            require(isinstance(r.get('pre'), str) and r['pre'] and isinstance(r.get('edit'), str) and r['edit'], 'case texts missing')
        return rows

    def block(t):
        return '«%s»: %s' % (t['title'], t['result'])

    def draft(st):
        d = (st or {}).get('draft')
        require(isinstance(d, dict) and isinstance(d.get('done'), str) and isinstance(d.get('revision'), int), 'draft missing')
        return d

    def view():
        for r in run():
            require(r.get('default_day') == r.get('today'), 'feed did not open on today: %r' % (r.get('default_day'),))
            require(r.get('tab_active') == 1, 'day feed section not active')
            items = r.get('items') or []
            require([i.get('id') for i in items] == [t['event'] for t in r['tasks']], 'feed does not show exactly the two results')
            for item, t in zip(items, r['tasks']):
                require(t['result'] in (item.get('text') or '') and t['title'] in (item.get('title') or ''), 'result or task missing')
                require('task=' + t['task'] in (item.get('task_href') or ''), 'task link missing')
                require(isinstance(item.get('time'), str) and len(item['time'].strip()) == 5, 'time missing')
                require(item.get('add') == 1 and item.get('added') == 0, '"Add to report" missing before adding')
            require(r.get('empty') == {'items': 0, 'state': 1}, 'empty day not shown as empty: %r' % (r.get('empty'),))

    def add():
        for r in run():
            t1, t2 = r['tasks']
            first = r['pre'] + '\n' + block(t1)
            require(draft(r.get('after_add'))['done'] == first, 'result not appended after the existing text')
            require(t1['task'] in draft(r['after_add']).get('links', []), 'task not linked to the draft')
            landing = r.get('landing') or {}
            require('/local/kbacompany/daily_report.php' in (landing.get('url') or '') and 'day=' + r['day'] in landing['url']
                    and landing.get('done') == first and landing.get('notice') == 1, 'report not opened with the added text')
            second = draft(r.get('after_second'))['done']
            require(second == first + '\n' + r['edit'] + '\n' + block(t2), 'second result or the unsaved edit lost: %r' % second)

    def marks(m, day, add, added):
        require(isinstance(m, dict) and m.get('day') == day and m.get('add') == add and m.get('added') == added,
                'feed marks on %s: %r (expected add %r, added earlier %r)' % (day, m, add, added))
        labels = m.get('labels')
        require(isinstance(labels, list) and len(labels) == len(added) and all(
            isinstance(x, dict) and x.get('visible') and x.get('hint') and x['visible'] != x['hint'] and x.get('shown') is True
            and x.get('hint_hidden') is True
            for x in labels), '"Added earlier" label without visible text or screen-reader hint: %r' % (labels,))

    def added_earlier():
        for r in run():
            e1, e2 = [t['event'] for t in r['tasks']]
            marks(r.get('feed_after_first'), r['day'], [e2], [e1])
            marks(r.get('feed_after_reload'), r['day'], [e2], [e1])
            require(r.get('hscroll_marks') is True, 'horizontal scroll with the mark at %s px' % r.get('width'))
            link = r.get('report_link') or {}
            first = r['pre'] + '\n' + block(r['tasks'][0])
            require('/local/kbacompany/daily_report.php' in (link.get('url') or '') and 'day=' + r['day'] in link['url']
                    and link.get('done') == first, 'report link did not open the selected date: %r' % (link,))
            marks(r.get('feed_other_day'), r['empty_day'], [], [])
            marks(r.get('feed_back_to_day'), r['day'], [e2], [e1])
            marks(r.get('feed_after_second'), r['day'], [], [e1, e2])
            sub = dict(r.get('after_submit_feed') or {})
            require(sub.pop('items', None) == 2 and sub.pop('sent_note', None) == 1, 'sent report note missing')
            marks(sub, r['day'], [], [e1, e2])

    def no_duplicate():
        for r in run():
            t1 = r['tasks'][0]
            for key in ('after_repeat_form', 'after_second', 'after_repeat_feed'):
                require(draft(r.get(key))['done'].count(t1['result']) == 1, 'result duplicated after %s' % key)
            require(r['edit'] in draft(r['after_repeat_form'])['done'], 'unsaved edit lost on the repeated click')
            http = r.get('repeat_http')
            require(isinstance(http, list) and len(http) == 2 and all(x == 303 for x in http),
                    'double POST of an added result not answered by the report page: %r' % (http,))
            u = r.get('unavailable_http')
            require(isinstance(u, int) and u != 303 and u < 500, 'unavailable event accepted or 5xx: %r' % (u,))
            require(draft(r['after_repeat_feed']) == draft(r['after_second']), 'double POST or unavailable event wrote the draft')
            events = [t['event'] for t in r['tasks']]
            require((r['after_add'] or {}).get('added_events') == events[:1]
                    and all((r.get(k) or {}).get('added_events') == events for k in ('after_second', 'after_repeat_feed')),
                    'repeat not keyed by the submit event id')

    def isolation():
        for r in run():
            http = [r.get('forged_http')] + list((r.get('foreign_http') or {}).values())
            require(len(http) == 3 and all(isinstance(x, int) and x != 303 and x < 500 for x in http),
                    'forged or foreign request redirected as a success or failed with 5xx: %r' % (http,))
            require(r.get('foreign_feed_items') == 0, 'company B feed shows company A results')
            require(draft(r.get('after_negatives')) == draft(r.get('after_repeat_feed')), 'forged or foreign request changed the draft')

    def submit_and_layout():
        for r in run():
            st = r.get('after_submit') or {}
            sent = st.get('sent') or []
            require((st.get('report') or {}).get('status') == 'submitted' and st.get('draft') is None and len(sent) == 1
                    and sent[0].get('done') == draft(r['after_negatives'])['done'], 'report not sent with the edited text')
            require(r.get('hscroll_feed') is True and r.get('hscroll_report') is True, 'horizontal scroll at %s px' % r.get('width'))
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx: %r' % (r.get('server_errors'),))

    return {'day_feed_today_date_and_empty_day': view, 'day_feed_add_keeps_text_and_links_task': add,
            'day_feed_added_earlier_marks_reload_date_and_link': added_earlier,
            'day_feed_repeated_click_adds_nothing': no_duplicate, 'day_feed_foreign_and_forged_denied': isolation,
            'day_feed_send_layout_and_browser_errors': submit_and_layout}


# ---- Information Centre by divisions (#190, infocenter.py): board order, tiles, trends, drill-down chart, isolation.
# #192: mini charts and the drill-down chart on the local Apache ECharts module; the view state published by the page
# (data-*) must agree with the ECharts option read back from the instance, and both with the fixture.
INFOCENTER_CASES = [('owned-1440', 'owned', 1440), ('owned-375', 'owned', 375), ('legacy-375', 'legacy', 375)]
INFOCENTER_MOBILE = 768
INFOCENTER_CODES = ('CIIC-M', 'CIIC-POS', 'CIIC-DEP', 'CIIC-NEG', 'CIIC-FLAT', 'CIIC-EMPTY', 'CIIC-LOOSE', 'CIIC-B')
INFOCENTER_MODULE = '/local_kbacompany/echarts-lazy'
INFOCENTER_VIEW_KEYS = ('start', 'end', 'span', 'selected', 'lo', 'hi', 'dots', 'factlines', 'planlines', 'gaps', 'trendlines')


def infocenter_checks(obs, exitcode=None):
    """Information Centre by divisions (infocenter.py). Expected periods and values come from the fixture output, never
    from the page; the producer's PASS only means the run completed."""
    def run():
        require(exitcode == 0, 'information centre browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'information centre run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('id'), r.get('shell'), r.get('width')) for r in rows]
                == [tuple(c) for c in INFOCENTER_CASES], 'information centre case list differs')
        metrics = (obs.get('fixture') or {}).get('metrics') or {}
        require(all(isinstance(metrics.get(k), dict) for k in INFOCENTER_CODES), 'fixture metrics missing')
        main = metrics['CIIC-M']
        require(isinstance(main.get('periods'), list) and len(main['periods']) == 20
                and isinstance(main.get('facts'), list) and len(main['facts']) == 20, 'main metric fixture periods missing')
        for r in rows:
            require(r.get('http') == 200 and r.get('owned') is (r['shell'] == 'owned'),
                    '%s: page not served in the requested shell' % r['id'])
        return rows, metrics

    def code(t):
        return (t.get('name') or '').split(' — ')[0]

    def tiles(ov):
        out = list(ov.get('main') or []) + list(ov.get('unbound') or [])
        for col in ov.get('columns') or []:
            for g in col.get('groups') or []:
                out += g.get('tiles') or []
        return {code(t): t for t in out if code(t).startswith('CIIC')}

    def drawn(s, what):
        """An ECharts drawing whose published view state equals the option read back from the instance."""
        o = s.get('opt')
        require(s.get('engine') == 'echarts' and s.get('instance') is True and isinstance(o, dict),
                '%s: not drawn by an ECharts instance' % what)
        diff = {k: (s.get(k), o.get(k)) for k in INFOCENTER_VIEW_KEYS if s.get(k) != o.get(k)}
        require(not diff, '%s: page view state differs from the ECharts option: %r' % (what, diff))
        require(o.get('fact_connect') is False and o.get('plan_connect') is False and o.get('symbols') is True
                and o.get('inside') is True and o.get('trend_type') == 'dashed'
                and [x for x in o.get('series') or [] if x in ('fact', 'plan', 'trend')] == ['fact', 'plan', 'trend'],
                '%s: series not fact/plan/dashed trend with breaks, real dots and inside zoom: %r' % (what, o))
        require(isinstance(s.get('paths'), int) and s['paths'] >= s.get('dots', 0) + s.get('factlines', 0)
                and isinstance(s.get('svg_w'), (int, float)) and s['svg_w'] > 0 and s['svg_w'] == s.get('canvas_w')
                and s.get('texts'), '%s: SVG drawing missing or not sized to its box' % what)

    def disposed(s, what):
        require(s.get('open') is False and s.get('instance') is False and s.get('svg_w') == 0 and s.get('engine') is None,
                '%s: chart instance not disposed on close' % what)

    def group_code(title):
        # Group headings are "<code> <name>"; fixture codes are "CIIC 1.1" (unit) and "CIIC-P" (position).
        parts = (title or '').split(' ')
        return parts[0] if parts[0].startswith('CIIC-') else ' '.join(parts[:2])

    def board():
        for r in run()[0]:
            ov = r.get('overview') or {}
            cols = ov.get('columns') or []
            require(ov.get('board_role') == 'list' and all(c.get('role') == 'listitem' for c in cols), '%s: board not a list' % r['id'])
            require([(c.get('title') or '')[:6] for c in cols] == ['CIIC 1', 'CIIC 2', 'CIIC 3'],
                    '%s: divisions not in board order: %r' % (r['id'], [c.get('title') for c in cols]))
            shape = [[(group_code(g.get('title')), sorted(code(t) for t in g.get('tiles') or [])) for g in c.get('groups') or []]
                     for c in cols]
            require(shape == [[('CIIC 1.1', ['CIIC-DEP']), ('CIIC-P', ['CIIC-POS'])],
                              [('CIIC 2', ['CIIC-EMPTY', 'CIIC-FLAT', 'CIIC-NEG'])], []],
                    '%s: departments/positions or bound metrics differ: %r' % (r['id'], shape))
            kinds = [g.get('kind') for g in cols[0]['groups']]
            require(len(set(kinds)) == 2 and all(kinds), '%s: unit and position not labelled differently' % r['id'])
            division = (cols[1]['groups'] or [{}])[0].get('kind')
            require(division and division not in kinds, '%s: division group labelled like a department or position' % r['id'])
            require(cols[2].get('empty') == 1, '%s: division without metrics not marked as empty' % r['id'])
            require([code(t) for t in ov.get('main') or [] if code(t).startswith('CIIC')] == ['CIIC-M'],
                    '%s: main company metric missing on top' % r['id'])
            require([code(t) for t in ov.get('unbound') or [] if code(t).startswith('CIIC')] == ['CIIC-LOOSE'],
                    '%s: unbound metric not listed separately' % r['id'])
            require('CIIC-B' not in tiles(ov), '%s: company B metric shown' % r['id'])

    def mini():
        rows, metrics = run()
        expect = {'CIIC-M': ('↑', True, 13, 2), 'CIIC-POS': ('↓', True, 8, 1), 'CIIC-FLAT': ('→', False, 6, 1),
                  'CIIC-DEP': ('·', False, 1, 0), 'CIIC-EMPTY': ('·', False, 0, 0), 'CIIC-LOOSE': ('↑', True, 3, 1)}
        for r in rows:
            ts = tiles(r.get('overview') or {})
            for c, (symbol, better, dots, lines) in expect.items():
                t = ts.get(c) or {}
                require(t.get('tag') == 'BUTTON' and t.get('type') == 'button' and t.get('haspopup') == 'dialog'
                        and c in (t.get('aria') or ''), '%s %s: tile is not a named button' % (r['id'], c))
                require(t.get('symbol') == symbol and len((t.get('trend') or '').replace(symbol, '').strip()) >= 3
                        and t.get('better') is better and t.get('worse') is False,
                        '%s %s: trend text/symbol/assessment wrong: %r' % (r['id'], c, (t.get('trend'), t.get('better'), t.get('worse'))))
                require(t.get('dots') == dots and t.get('lines') == lines,
                        '%s %s: mini chart dots/lines %r/%r, expected %r/%r' % (r['id'], c, t.get('dots'), t.get('lines'), dots, lines))
                if dots:
                    require(t.get('grid', 0) >= 2 and len(t.get('range') or '') >= 20 and not t.get('nodata'),
                            '%s %s: mini chart without grid or period labels' % (r['id'], c))
                    m = t.get('mini') or {}
                    require(m.get('engine') == 'echarts' and m.get('instance') is True and m.get('svg') == 1
                            and isinstance(m.get('paths'), int) and m['paths'] >= dots + lines
                            and m.get('attr') == {'dots': dots, 'lines': lines, 'grid': t.get('grid')},
                            '%s %s: mini chart not drawn by ECharts as published: %r' % (r['id'], c, m))
                    require(m.get('datazoom') == 0 and m.get('silent') is True and m.get('pointer') == 'none'
                            and m.get('connect') is False and m.get('symbols') is True
                            and isinstance(m.get('svg_w'), (int, float)) and m['svg_w'] > 0 and m['svg_w'] == m.get('box_w'),
                            '%s %s: mini chart interactive, joined over gaps or not sized to its tile: %r' % (r['id'], c, m))
            require(ts['CIIC-EMPTY'].get('nodata') and ts['CIIC-EMPTY'].get('grid', 0) == 0
                    and (ts['CIIC-EMPTY'].get('mini') or {}).get('engine') is None, '%s: empty series not shown as no data' % r['id'])
            heights = {c: (ts[c].get('mini') or {}).get('height') or 0 for c in expect if expect[c][2]}
            require(all(heights[c] >= 40 for c in heights) and all(heights['CIIC-M'] > heights[c] for c in heights if c != 'CIIC-M'),
                    '%s: main and column mini charts do not keep their heights: %r' % (r['id'], heights))
            initial, final = r.get('lazy_initial') or {}, r.get('lazy_final') or {}
            drawn_tiles = sum(1 for c, m in metrics.items() if m.get('company') == 'A' and m.get('facts'))
            require(final.get('total') == final.get('ready') == drawn_tiles and isinstance(initial.get('ready'), int)
                    and 1 <= initial['ready'] <= drawn_tiles and initial.get('total') == drawn_tiles,
                    '%s: mini charts not all initialised: %r -> %r' % (r['id'], initial, final))
            if r['width'] < INFOCENTER_MOBILE:
                require(initial['ready'] < drawn_tiles, '%s: mini charts initialised before they come into view: %r' % (r['id'], initial))
            requests = r.get('module_requests')
            require(isinstance(requests, list) and len(requests) == 1 and loopback_host(requests[0])
                    and INFOCENTER_MODULE in requests[0], '%s: ECharts module not loaded exactly once from Moodle: %r' % (r['id'], requests))
            require(ts['CIIC-DEP'].get('single') == 1, '%s: single point without note' % r['id'])
            require(ts['CIIC-M'].get('points') == 21 and ts['CIIC-M'].get('gaps') == 1,
                    '%s: missing week not kept as a gap in the chart data' % r['id'])
            require(metrics['CIIC-M']['periods'][-1] in (ts['CIIC-M'].get('value') or ''), '%s: last value period missing' % r['id'])

    def layout():
        for r in run()[0]:
            ov = r.get('overview') or {}
            require(isinstance(ov.get('page_scroll'), int) and isinstance(ov.get('page_client'), int)
                    and ov['page_scroll'] <= ov['page_client'] <= r['width'],
                    '%s: page scrolls horizontally (%r > %r)' % (r['id'], ov.get('page_scroll'), ov.get('page_client')))
            boxes = [c.get('box') or {} for c in ov.get('columns') or []]
            require(len(boxes) == 3 and all(isinstance(b.get('x'), int) and isinstance(b.get('r'), int) and b['x'] >= 0
                                            and b['r'] <= r['width'] for b in boxes),
                    '%s: a division column is outside the viewport: %r' % (r['id'], boxes))
            size = r.get('resize') or {}
            before, after = size.get('mini_before') or {}, size.get('mini_after') or {}
            require(isinstance(before.get('svg_w'), (int, float)) and before['svg_w'] > 0 and before['svg_w'] == before.get('box_w')
                    and isinstance(after.get('svg_w'), (int, float)) and 0 < after['svg_w'] < before['svg_w']
                    and after['svg_w'] == after.get('box_w'), '%s: mini chart does not follow a resize: %r' % (r['id'], size))
            if r['width'] < INFOCENTER_MOBILE:
                require(boxes[0]['y'] < boxes[1]['y'] < boxes[2]['y'], '%s: columns not stacked on the phone' % r['id'])
            else:
                require(boxes[0]['y'] == boxes[1]['y'] == boxes[2]['y'] and boxes[0]['x'] < boxes[1]['x'] < boxes[2]['x'],
                        '%s: divisions not side by side on the desktop' % r['id'])

    def chart_open():
        rows, metrics = run()
        p = metrics['CIIC-M']['periods']; facts = metrics['CIIC-M']['facts']
        for r in rows:
            s = (r.get('main_chart') or {}).get('open') or {}
            require(s.get('open') is True and (s.get('title') or '').startswith('CIIC-M — ') and s.get('focus_in_chart') is True,
                    '%s: chart dialog not opened with focus on the chart' % r['id'])
            require(s.get('window') == '13' and s.get('start') == p[-13] and s.get('end') == p[-1] and s.get('span') == 13
                    and s.get('selected') == p[-1],
                    '%s: 13-period window wrong: %r' % (r['id'], {k: s.get(k) for k in ('start', 'end', 'span', 'selected')}))
            require(s.get('gaps') == 1 and s.get('factlines') == 2 and s.get('dots') == 13,
                    '%s: gap not drawn as a gap: %r' % (r['id'], (s.get('gaps'), s.get('factlines'), s.get('dots'))))
            require(s.get('planlines', 0) >= 1 and s.get('trendlines') == 1 and s.get('trend_dash') and s.get('legend') == 4,
                    '%s: plan, dashed trend or legend missing' % r['id'])
            visible = facts[-13:]
            require(isinstance(s.get('lo'), (int, float)) and isinstance(s.get('hi'), (int, float))
                    and 0 < s['lo'] <= min(visible) and s['hi'] >= max(visible) + 3,
                    '%s: value axis does not fit the visible range: %r' % (r['id'], (s.get('lo'), s.get('hi'))))
            require(p[-1] in (s.get('readout') or '') and p[-1] in (s.get('texts') or []),
                    '%s: exact value on the selected date or period axis missing' % r['id'])
            require('↑' in (s.get('meta') or ''), '%s: trend text missing in the chart' % r['id'])
            drawn(s, '%s open' % r['id'])

    def chart_controls():
        rows, metrics = run()
        p = metrics['CIIC-M']['periods']
        for r in rows:
            m = r.get('main_chart') or {}

            def g(k):
                return m.get(k) or {}
            w = g('window26')
            require(w.get('window') == '26' and w.get('start') == p[0] and w.get('end') == p[-1] and w.get('span') == 20
                    and w.get('dots') == 20, '%s: 26-period window wrong' % r['id'])
            require(g('zoomin').get('span', 99) < 20, '%s: zoom in did not narrow the window' % r['id'])
            require((g('panleft').get('start') or 'z') < (g('zoomin').get('start') or '')
                    and (g('panright').get('start') or '') > (g('panleft').get('start') or 'z')
                    and g('zoomout').get('span', 0) > g('panright').get('span', 99),
                    '%s: zoom/pan buttons do not change the window' % r['id'])
            for key in ('reset', 'key_reset', 'reset2'):
                require((g(key).get('start'), g(key).get('end'), g(key).get('span')) == (p[0], p[-1], 20),
                        '%s: %s does not restore the window' % (r['id'], key))
            lefts = [g('left%d' % k).get('selected') for k in range(1, 6)]
            require(lefts == [p[-2], p[-3], p[-4], p[-5], p[-6]],
                    '%s: arrow keys do not step over real periods (gap skipped): %r' % (r['id'], lefts))
            require(all(g('left%d' % k).get('selected') in (g('left%d' % k).get('readout') or '') for k in range(1, 6)),
                    '%s: readout does not follow the selected date' % r['id'])
            require(g('key_plus').get('span', 99) < 20 and (g('key_shift_left').get('start') or 'z') < (g('key_plus').get('start') or ''),
                    '%s: keyboard zoom/pan failed' % r['id'])
            require(g('key_home').get('selected') == p[0] and g('key_end').get('selected') == p[-1], '%s: Home/End failed' % r['id'])
            require(g('wheel_in').get('span', 99) < 20 and (g('drag_right').get('start') or 'z') < (g('wheel_in').get('start') or ''),
                    '%s: mouse wheel zoom or drag pan failed' % r['id'])
            require(g('pinch_out').get('span', 99) < 20, '%s: two-pointer pinch did not zoom' % r['id'])
            require(g('pinch_out').get('pinch_mode') == ('touch' if r['width'] < INFOCENTER_MOBILE else 'pointer'),
                    '%s: pinch not exercised through the expected input: %r' % (r['id'], g('pinch_out').get('pinch_mode')))
            require(g('click').get('selected') == p[-4] and p[-4] in (g('click').get('readout') or ''),
                    '%s: mouse click did not select the date: %r' % (r['id'], g('click').get('selected')))
            for key in ('window26', 'zoomin', 'panleft', 'panright', 'zoomout', 'reset', 'left1', 'left5', 'key_plus',
                        'key_shift_left', 'key_reset', 'key_home', 'key_end', 'click', 'wheel_in', 'drag_right', 'reset2',
                        'pinch_out', 'resized', 'restored'):
                drawn(g(key), '%s %s' % (r['id'], key))
            require(0 < g('resized').get('svg_w', 0) < g('restored').get('svg_w', 0)
                    and (g('resized').get('start'), g('resized').get('end')) == (p[0], p[-1]),
                    '%s: large chart does not follow a resize' % r['id'])
            c = g('closed')
            require(c.get('open') is False and (c.get('focus_tile') or '').startswith('CIIC-M — '),
                    '%s: Escape did not close or focus not returned to the tile' % r['id'])
            disposed(c, '%s main chart' % r['id'])
            again = r.get('reopen') or {}
            require(again.get('open') is True and (again.get('title') or '').startswith('CIIC-M — ') and again.get('window') == '13'
                    and (again.get('start'), again.get('end'), again.get('span'), again.get('selected')) == (p[-13], p[-1], 13, p[-1]),
                    '%s: re-opened chart does not start from a fresh 13-period view' % r['id'])
            drawn(again, '%s reopen' % r['id'])
            disposed(again.get('closed') or {}, '%s reopen' % r['id'])
            require(((again.get('closed') or {}).get('focus_tile') or '').startswith('CIIC-M — '),
                    '%s: focus not returned after re-opening' % r['id'])

    def chart_shapes():
        rows, _ = run()
        for r in rows:
            ch = r.get('charts') or {}
            flat, neg, dep, empty, pos = (ch.get(k) or {} for k in ('CIIC-FLAT', 'CIIC-NEG', 'CIIC-DEP', 'CIIC-EMPTY', 'CIIC-POS'))
            require(isinstance(flat.get('lo'), (int, float)) and isinstance(flat.get('hi'), (int, float))
                    and 500 < flat['lo'] < 1000 < flat['hi'] <= 1500 and flat.get('dots') == 6,
                    '%s: flat series axis not padded around the value: %r' % (r['id'], (flat.get('lo'), flat.get('hi'))))
            require(isinstance(neg.get('lo'), (int, float)) and isinstance(neg.get('hi'), (int, float)) and neg['lo'] <= -6
                    and neg['hi'] >= 4 and '0' in (neg.get('texts') or []) and neg.get('dots') == 6,
                    '%s: negative values or the real zero not on the axis' % r['id'])
            require(dep.get('dots') == 1 and dep.get('factlines') == 0 and dep.get('trendlines') == 0,
                    '%s: single point drawn with a line' % r['id'])
            nodata = (tiles(r.get('overview') or {}).get('CIIC-EMPTY') or {}).get('nodata') or ''
            require(empty.get('empty') is True and empty.get('dots') == 0 and nodata and empty.get('readout') == nodata,
                    '%s: empty series not shown as no data in the chart' % r['id'])
            require('↓' in (pos.get('meta') or '') and pos.get('dots') == 8, '%s: falling metric chart wrong' % r['id'])
            require(len(ch) == 5 and all((v.get('closed') or {}).get('open') is False for v in ch.values()),
                    '%s: a chart did not open or close' % r['id'])
            for k in ('CIIC-FLAT', 'CIIC-NEG', 'CIIC-DEP', 'CIIC-POS'):
                drawn(ch[k], '%s %s' % (r['id'], k))
            for k, v in ch.items():
                disposed(v.get('closed') or {}, '%s %s' % (r['id'], k))
            require((neg.get('opt') or {}).get('zero_line') is True, '%s: real zero not marked on the negative chart' % r['id'])
            require(empty.get('instance') is False and empty.get('empty_text') == nodata and empty.get('texts') == [],
                    '%s: empty series drew a chart instead of "no data"' % r['id'])
            box = ((r.get('main_chart') or {}).get('open') or {}).get('box') or {}
            require(isinstance(box.get('x'), int) and isinstance(box.get('r'), int) and box['x'] >= 0 and box['r'] <= r['width'],
                    '%s: chart dialog wider than the viewport: %r' % (r['id'], box))

    def isolation_and_errors():
        rows, _ = run()
        foreign = obs.get('foreign') or {}
        a, b = foreign.get('company_a') or {}, foreign.get('company_b') or {}
        require(a.get('tiles') == 0 and a.get('a_codes_in_body') == [] and a.get('a_divisions_in_body') == [],
                'company B user sees company A information centre: %r' % (a,))
        require([n.split(' — ')[0] for n in b.get('names') or [] if n.startswith('CIIC')] == ['CIIC-B']
                and b.get('a_codes_in_body') == [] and b.get('http') == 200, 'company B page not limited to company B: %r' % (b,))
        require((obs.get('cleanup') or {}).get('clean') is True and not obs.get('cleanup_error'), 'fixture not cleaned up')
        for r in rows:
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, '%s: browser errors: %r' % (r['id'], violations[:3]))
            require(r.get('server_errors') == [], '%s: HTTP 5xx: %r' % (r['id'], r.get('server_errors')))

    return {'infocenter_board_order_and_registry_bindings': board, 'infocenter_mini_charts_and_trend_text': mini,
            'infocenter_layout_1440_and_375': layout, 'infocenter_chart_axes_gap_plan_trend': chart_open,
            'infocenter_chart_window_zoom_pan_keyboard_touch': chart_controls,
            'infocenter_chart_flat_negative_single_empty': chart_shapes,
            'infocenter_company_isolation_and_browser_errors': isolation_and_errors}


PLAN_CASES = [('owned', 1440, 'click'), ('legacy', 320, 'keyboard')]


def plan_discussion_checks(obs, exitcode=None):
    """Battle Plan discussion, Communication Center E2 (plan_discussion.py, owner decision Q9). Verdicts come from DB rows
    and rendered observations; the producer's PASS alone is never sufficient."""
    def run():
        require(exitcode == 0, 'plan discussion browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'plan discussion run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('mode')) for r in rows] == PLAN_CASES,
                'owned/legacy x desktop/mobile x click/keyboard cases required')
        ids = obs.get('ids') or {}
        require(all(isinstance(ids.get(k), int) and ids[k] > 0 for k in ('MA', 'EA', 'RA', 'EB', 'VA'))
                and len(set(ids.values())) == 5, 'fixture ids missing')
        for r in rows:
            texts = r.get('texts')
            require(isinstance(texts, list) and len(texts) == 2 and len(set(texts)) == 2 and all(isinstance(t, str) and t for t in texts),
                    'case texts missing')
            require(isinstance(r.get('question'), str) and r['question'], 'question id missing')
        return rows, ids

    def served(d, sha):
        return isinstance(d, dict) and d.get('http') == 200 and d.get('sha256') is not None and d.get('sha256') == sha

    def refused(d):
        return isinstance(d, dict) and d.get('http') in DENY_CODES and d.get('sha256') is None

    def roundtrip():
        rows, ids = run()
        for r in rows:
            require(isinstance(r.get('tab_link'), int) and r['tab_link'] >= 1, 'Discussion tab missing on the plan card')
            require((r.get('card_owner') or {}).get('question') == 1, 'plan card does not show the posted message')
            target = r.get('reply_target') or {}
            require(target.get('count') == 1 and r['texts'][0] in (target.get('text') or ''), 'reply target not shown')
            m = r.get('reply') or {}
            require(m.get('reply_to') == r['question'] and m.get('body') == r['texts'][1] and m.get('status') == 'active'
                    and m.get('authorid') == ids['VA'], 'plan reply not linked to the original')
            require(any(r['texts'][0] in q for q in r.get('card_quote') or []), 'quote missing on the plan card')
            link = (r.get('reader') or {}).get('open_plan') or {}
            href = urlparse(link.get('href') or '')
            query = dict(parse_qsl(href.query))
            require(link.get('text') == 'Open plan' and href.path == '/local/kbacompany/battle_plan.php'
                    and query.get('tab') == 'discussion' and query.get('plan'), 'Open plan link missing in the Center')

    def reader():
        rows, ids = run()
        ma = ids['MA']
        for i, r in enumerate(rows):
            v = r.get('reader') or {}
            require(v.get('card_question') is True and v.get('card_answer') is True and v.get('card_note') is True,
                    'plan reader does not read the card discussion')
            require(served(v.get('download'), r.get('file_sha256')), 'plan reader cannot download the discussion file')
            require(v.get('center_question') == 1 and v.get('center_join') == 1 and v.get('center_composer') == 0,
                    'Center must show the plan thread read-only with an explicit join')
            p = v.get('parts_after_reading') or {}
            require(isinstance(p.get('active'), list) and ma not in p['active'] and ma not in (p.get('reads') or []),
                    'reading joined the plan reader or created read state')
            require(ids['VA'] in p['active'], 'plan reviewer is not a participant')
            if i == len(rows) - 1:
                j = v.get('parts_after_join') or {}
                require(ma in (j.get('active') or []) and v.get('composer_after_join') == 1, 'explicit join did not work')

    def reasons():
        texts = obs.get('reasons')
        keys = ('plan_unavailable', 'company_unavailable', 'thread_permission', 'invalid_sesskey')
        require(isinstance(texts, dict) and set(texts) == set(keys)
                and all(isinstance(texts[k], str) and texts[k].strip() for k in keys)
                and len({texts[k] for k in keys}) == len(keys), 'expected refusal texts missing')
        target = obs.get('urls') or {}
        require(all(isinstance(target.get(k), str) and target[k] for k in ('company', 'plan')), 'plan target missing')
        return texts, target

    def shown(response, text):
        found = (response or {}).get('alerts')
        return isinstance(found, list) and any(isinstance(a, str) and text in a for a in found)

    def participants(snapshot):
        require(isinstance(snapshot, dict) and set(snapshot) == {'active', 'reads'}
                and all(isinstance(snapshot[k], list) and all(isinstance(u, int) and not isinstance(u, bool) for u in snapshot[k])
                        for k in snapshot), 'participant observation missing')
        return snapshot

    def refusals():
        rows, ids = run()
        texts, target = reasons()
        back = {'uuid': target['company'], 'plan': target['plan'], 'view': 'detail', 'tab': 'discussion'}
        # Who is refused and why (owner decision Q9): RA may not open the plan, EB is outside the company. Each refusal
        # must be the product's own answer for that reason, not any non-success page.
        expected = {'RA': {'post': texts['plan_unavailable'], 'join_http': (200,), 'join': texts['thread_permission']},
                    'EB': {'post': texts['company_unavailable'], 'join_http': (403, 404), 'join': texts['company_unavailable']}}
        for r in rows:
            before = participants(r.get('parts_before_refusals'))
            require(ids['EA'] in before['active'] and ids['VA'] in before['active'],
                    'plan participants not established before the refusals')
            count = r.get('messages_before_refusals')
            require(isinstance(count, int) and not isinstance(count, bool) and count >= 2, 'message count before refusals missing')
            d = r.get('denied') or {}
            require(set(d) == {'RA', 'EB'}, 'denied users missing')
            for label, x in d.items():
                want = expected[label]
                require(x.get('card_text') is False and x.get('center_text') is False, label + ' read the plan discussion')
                require(refused(x.get('download')), label + ' downloaded the plan discussion file')
                post = x.get('post') or {}
                require(post.get('http') == 303 and x.get('post_http') == 303, label + ' post refusal is not the redirect back to the plan')
                exact_redirect(post.get('location'), PLAN_PATH, back)
                followed = post.get('followed') or {}
                require(followed.get('http') in (200, 403, 404) and shown(followed, want['post']),
                        label + ' post refusal does not show the expected reason')
                join = x.get('join') or {}
                require(join.get('http') in want['join_http'] and x.get('join_http') == join.get('http')
                        and join.get('location') == '' and x.get('join_location') == '',
                        label + ' join refusal has an unexpected status or redirect: %r' % (join.get('http'),))
                require(shown(join, want['join']), label + ' join refusal does not show the expected reason')
                require(participants(x.get('parts')) == before and ids[label] not in before['active'],
                        label + ' refusal changed the participants')
                require(x.get('messages') == count, label + ' stored a plan message')
            f = r.get('forged') or {}
            require(f.get('http') in DENY_CODES and f.get('location') == '' and shown(f, texts['invalid_sesskey']),
                    'forged sesskey was not refused as an invalid session key: %r' % (f.get('http'),))
            require(f.get('messages') == count and participants(f.get('parts')) == before,
                    'forged sesskey changed messages or participants')
        rv = obs.get('revocation') or {}
        sha = rows[-1].get('file_sha256')
        require((rv.get('before') or {}).get('text') is True and served((rv.get('before') or {}).get('download'), sha),
                'revocation precondition not met')
        require(rv.get('revoked') is True and rv.get('reopened') is True, 'revocation not applied/reverted')
        require((rv.get('after') or {}).get('text') is False and refused((rv.get('after') or {}).get('download')),
                'revoked member still reads the plan thread or downloads its file')
        require((rv.get('restored') or {}).get('text') is True and served((rv.get('restored') or {}).get('download'), sha),
                'access not restored after reopen')

    def unchanged():
        run()
        before, after = obs.get('plan_before'), obs.get('plan_after')
        require(isinstance(before, dict) and isinstance(before.get('plan'), dict) and before.get('plan'),
                'plan snapshot missing')
        require(before == after, 'a discussion message changed the plan')

    def browser_events():
        rows, ids = run()
        for r in rows:
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx in the plan discussion: %r' % (r.get('server_errors'),))

    return {'plan_discussion_card_and_center': roundtrip, 'plan_discussion_reader_not_joined': reader,
            'plan_discussion_refusals_and_revocation': refusals, 'plan_discussion_plan_unchanged': unchanged,
            'plan_discussion_no_browser_errors': browser_events}


UX_NAV_STEPS = [('section', 'discussions'), ('section', 'notifications'), ('section', 'messages'),
                ('filter', 'unread'), ('filter', 'mentions'), ('filter', 'all'),
                ('section', 'discussions'), ('filter', 'unread'), ('filter', 'mentions'), ('filter', 'all')]


def exact_center_link(url, expected):
    """A Communication Center URL: loopback origin, exact path, exactly the expected parameters (no duplicates)."""
    require(isinstance(url, str) and url, 'Center link missing')
    parsed = urlparse(local_url(url))
    require(parsed.path == CENTER_PATH and not parsed.params and not parsed.fragment, 'Center link targets a wrong route: %r' % url)
    try:
        pairs = parse_qsl(parsed.query, keep_blank_values=True, strict_parsing=True)
    except ValueError:
        pairs = None
    require(pairs is not None and len(pairs) == len(dict(pairs)), 'Center link has malformed/duplicate params: %r' % url)
    require(dict(pairs) == expected, 'Center link parameters %r != %r' % (dict(pairs), expected))


def ux_navigation(steps, company):
    """Clicked navigation: each step lands on the exact URL with the expected active section/filter and content."""
    require(isinstance(steps, list) and len(steps) == len(UX_NAV_STEPS), 'UX navigation steps missing')
    section = 'messages'
    for step, (kind, key) in zip(steps, UX_NAV_STEPS):
        require(isinstance(step, dict) and step.get('kind') == kind and step.get('key') == key, 'UX navigation order differs')
        require(step.get('http') == 200, 'UX navigation %s=%s HTTP %r' % (kind, key, step.get('http')))
        if kind == 'section':
            section = key
            landed = {'uuid': company, 'tab': key}
        else:
            require(section in ('messages', 'discussions'), 'filter clicked outside a list section')
            landed = {'uuid': company, 'tab': section, 'filter': key}
        exact_center_link(step.get('url'), landed)
        require(step.get('active_sections') == 1, 'exactly one active section expected')
        exact_center_link(step.get('active_section'), {'uuid': company, 'tab': section})
        if section == 'notifications':
            require(step.get('notifications') == 1 and step.get('layout') == 0 and step.get('filters') == 0
                    and step.get('active_filters') == 0, 'notifications section content wrong')
        else:
            require(step.get('notifications') == 0 and step.get('layout') == 1 and step.get('filters') == 1
                    and step.get('active_filters') == 1, '%s section content wrong' % section)
            exact_center_link(step.get('active_filter'), {'uuid': company, 'tab': section,
                                                          'filter': key if kind == 'filter' else 'all'})
        require(step.get('overflow') is False, 'horizontal overflow after navigation')


def communication_ux_checks(obs, exitcode=None):
    """Fail closed on incomplete real Moodle UX-1 evidence, independently of producer status."""
    def verify():
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS'
                and not obs.get('error'), 'UX browser run missing/failed')
        rows = obs.get('cases')
        expected = {(s, w) for s in ['owned', 'legacy'] for w in [320, 375, 390, 768, 1440]}
        require(isinstance(rows, list) and len(rows) == 10, 'UX matrix incomplete')
        require({(r.get('shell'), r.get('width')) for r in rows} == expected, 'UX matrix mismatch')
        def luminance(color):
            match = re.fullmatch(r'rgb\((\d+),\s*(\d+),\s*(\d+)\)', color)
            require(match is not None, 'opaque computed color missing')
            rgb = [int(x) / 255 for x in match.groups()]
            require(all(0 <= v <= 1 for v in rgb), 'invalid color')
            linear = [v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4 for v in rgb]
            return sum(v * w for v, w in zip(linear, [0.2126, 0.7152, 0.0722]))
        for row in rows:
            require(row.get('http') == 200 and row.get('notifications_http') == 200, 'unexpected HTTP status')
            require(row.get('sections') == 3, 'expected exactly three primary sections')
            company = obs.get('companyuuid')
            require(isinstance(company, str) and company, 'UX company context missing')
            exact_center_link(row.get('default_section'), {'uuid': company, 'tab': 'messages'})
            exact_center_link(row.get('default_filter'), {'uuid': company, 'tab': 'messages', 'filter': 'all'})
            ux_navigation(row.get('navigation'), company)
            for field in ['no_meetings', 'no_bulk', 'thread_available', 'panel_open', 'panel_closed',
                          'focus_restored', 'notification_screen', 'no_conversation_area']:
                require(row.get(field) is True, 'UX missing/failed ' + field)
            for field in ['overflow', 'thread_overflow', 'notifications_overflow']:
                require(row.get(field) is False, 'overflow or missing measurement: ' + field)
            require(row.get('server_errors') == [], 'server error evidence missing or HTTP500')
            require(isinstance(row.get('browser_events'), list), 'browser events missing')
            require(not browser_event_violations(row['browser_events']), 'unexpected browser/network errors')
            colors = row.get('colors')
            require(isinstance(colors, list) and len(colors) == 3, 'computed chip colors missing')
            for pair in colors:
                a, b = luminance(pair['foreground']), luminance(pair['background'])
                require((max(a, b) + 0.05) / (min(a, b) + 0.05) >= 4.5, 'chip contrast below4.5')

    def geometry():
        """#145/#152: rendered geometry, not DOM presence. The 'New conversation' primary action is a full labelled button
        in the card header of every section (the old 32/34px trigger clipped its label and covered the section tabs);
        no visible control label is clipped; the list search is wide; the Center stylesheet is applied."""
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS'
                and not obs.get('error'), 'UX browser run missing/failed')
        rows = obs.get('cases')
        require(isinstance(rows, list) and len(rows) == 10, 'UX matrix incomplete')

        def number(value, what):
            require(isinstance(value, (int, float)) and not isinstance(value, bool), 'geometry %s missing' % what)
            return value
        for row in rows:
            width = row.get('width')
            sections = row.get('geometry')
            require(isinstance(sections, dict) and set(sections) == {'messages', 'discussions', 'notifications'},
                    'geometry for all three sections missing (%r/%r)' % (row.get('shell'), width))
            for section, g in sections.items():
                where = '%s/%s/%s' % (row.get('shell'), width, section)
                require(isinstance(g, dict) and g.get('root') is True, 'Center root missing: ' + where)
                require(g.get('css') is True, 'Communication Center stylesheet not applied: ' + where)
                require(g.get('overflow') is False, 'horizontal overflow: ' + where)
                require(g.get('clipped') == [], 'clipped control label %r: %s' % (g.get('clipped'), where))
                a = g.get('action')
                require(isinstance(a, dict), '"New conversation" action not visible: ' + where)
                require(a.get('count') == 1, 'exactly one "New conversation" action expected: ' + where)
                label = a.get('label')
                require(isinstance(label, str) and len(label.replace('+', '').strip()) >= 4,
                        '"New conversation" has no readable label: ' + where)
                require(number(a.get('width'), 'width') >= 96 and number(a.get('height'), 'height') >= 32,
                        '"New conversation" is not a full button (%rx%r): %s' % (a.get('width'), a.get('height'), where))
                require(number(a.get('scroll_width'), 'scroll width') <= number(a.get('client_width'), 'client width') + 1,
                        '"New conversation" label clipped: ' + where)
                require(a.get('in_header') is True, '"New conversation" outside the card header: ' + where)
                require(a.get('overlaps_tabs') is False, '"New conversation" overlaps the section tabs: ' + where)
                require(a.get('in_form') is True, '"New conversation" lost its POST/sesskey form: ' + where)
                search = g.get('search')
                if section == 'notifications':
                    require(search is None, 'notifications must not show the list search: ' + where)
                    continue
                require(search is not None or width < 900, 'list search not visible on a wide screen: ' + where)
                if search is not None:
                    require(isinstance(search, dict), 'search geometry malformed: ' + where)
                    box, side = number(search.get('width'), 'search width'), number(search.get('side_width'), 'side width')
                    field = number(search.get('input_width'), 'search input width')
                    require(box >= 200 and box >= 0.85 * side and field >= box - 64,
                            'list search too narrow (%r of %r, input %r): %s' % (box, side, field, where))
    def frame():
        """Fixed frame like a messenger: at 900px height the page itself does not scroll (document and the window-bounded
        page container), the block ends inside the window, the list and the messages are the scroll containers and the
        open conversation shows its composer on screen. Both shells, all five widths, list and open conversation, and the
        open conversation again after a block above the page was there during a fit and then went away (frame_shift)."""
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS'
                and not obs.get('error'), 'UX browser run missing/failed')
        rows = obs.get('cases')
        require(isinstance(rows, list) and len(rows) == 10, 'UX matrix incomplete')

        def integer(value):
            return isinstance(value, int) and not isinstance(value, bool)
        for row in rows:
            for state in ['frame_list', 'frame_thread', 'frame_shift']:
                where = '%s/%s/%s' % (row.get('shell'), row.get('width'), state)
                f = row.get(state)
                require(isinstance(f, dict), 'frame measurement missing: ' + where)
                require(f.get('framed') is True, 'Center is not framed: ' + where)
                for key in ['page_excess', 'layout_bottom', 'view']:
                    require(integer(f.get(key)), 'frame %s missing: %s' % (key, where))
                require(f['view'] >= 600, 'frame measured on a short window: ' + where)
                require(f['page_excess'] <= 1, 'the page scrolls by %rpx: %s' % (f['page_excess'], where))
                require(0 < f['layout_bottom'] <= f['view'] + 1, 'block ends outside the window: ' + where)
                require(f.get('list_overflow') in ('auto', 'scroll'), 'list is not a scroll container: ' + where)
                feed = f.get('feed_overflow')
                require(feed in ('auto', 'scroll') or (state == 'frame_list' and feed is None),
                        'messages are not a scroll container: ' + where)
                if state != 'frame_list':
                    c = f.get('compose_bottom')
                    require(integer(c) and 0 < c <= f['view'] + 1, 'composer not on screen: ' + where)
    def stable():
        """The open conversation holds still with no input (Codex task in #145, owner's recording): the block, the
        history, the composer and Send keep their place and size frame after frame, with a real scrollbar; the window
        width does not flip (a scrollbar coming and going); the newest message is shown whole above the composer; reading
        older messages keeps its scroll position. Both shells, 1440 and 375 px, a short and a long conversation."""
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS'
                and not obs.get('error'), 'UX browser run missing/failed')
        rows = obs.get('stability')
        require(isinstance(rows, list) and len(rows) == 8, 'stability matrix incomplete')
        seen = set()
        for row in rows:
            where = '%s/%s/%s' % (row.get('shell'), row.get('width'), row.get('kind'))
            seen.add((row.get('shell'), row.get('width'), row.get('kind')))
            require(row.get('http') == 200, 'conversation HTTP failure: ' + where)
            require(row.get('server_errors') == [], 'server error evidence missing or HTTP500: ' + where)
            require(isinstance(row.get('browser_events'), list), 'browser events missing: ' + where)
            require(not browser_event_violations(row['browser_events']), 'unexpected browser/network errors: ' + where)
            for state, frames in [('bottom', 120), ('reading', 60)]:
                s = row.get(state)
                at = '%s/%s' % (where, state)
                require(isinstance(s, dict), 'stability samples missing: ' + at)
                require(s.get('frames') == frames, 'too few frames sampled: ' + at)
                for key in ['layout_span', 'feed_span', 'compose_span', 'send_span', 'scroll_span', 'width_span',
                            'changes', 'view', 'send_bottom', 'feed_top', 'feed_bottom']:
                    require(isinstance(s.get(key), int) and not isinstance(s.get(key), bool),
                            'stability %s missing: %s' % (key, at))
                require(s['width_span'] == 0, 'window width flips by %rpx (scrollbar comes and goes): %s'
                        % (s['width_span'], at))
                for key, label in [('layout_span', 'block'), ('feed_span', 'history'), ('compose_span', 'composer'),
                                   ('send_span', 'Send button')]:
                    require(s[key] == 0, '%s moves/resizes by %rpx with no input: %s' % (label, s[key], at))
                require(s['scroll_span'] == 0, 'history scroll position drifts by %rpx: %s' % (s['scroll_span'], at))
                require(s['changes'] == 0, 'geometry changed in %r of %r frames: %s' % (s['changes'], frames, at))
                require(0 < s['send_bottom'] <= s['view'], 'Send button not on screen: ' + at)
            b = row['bottom']
            require(isinstance(b.get('messages'), int) and b['messages'] >= (2 if row.get('kind') == 'short' else 20),
                    'conversation not rendered: ' + where)
            require(b.get('at_bottom') is True, 'conversation did not open at the newest message: ' + where)
            require(isinstance(b.get('last_top'), int) and isinstance(b.get('last_bottom'), int)
                    and isinstance(b.get('feed_pad'), int) and 0 <= b['feed_pad'] <= 40,
                    'newest message geometry missing: ' + where)
            # Its lower edge is on screen above the composer; it is shown whole when it fits the history's visible
            # area (a message taller than that can only show its end, like in any messenger).
            require(b['feed_top'] < b['last_bottom'] <= b['feed_bottom'] + 1,
                    'newest message cut at the bottom (%r > %r): %s' % (b['last_bottom'], b['feed_bottom'], where))
            fits = b['last_bottom'] - b['last_top'] <= b['feed_bottom'] - b['feed_top'] - b['feed_pad']
            require(not fits or b['last_top'] >= b['feed_top'] - 1,
                    'newest message not shown whole (top %r above %r): %s' % (b['last_top'], b['feed_top'], where))
        require(seen == {(m, w, k) for m in ('owned', 'legacy') for w in (1440, 375) for k in ('short', 'long')},
                'stability matrix incomplete')
    return {'communication_ux1_layout_and_navigation': verify, 'communication_ux2_primary_action_geometry': geometry,
            'communication_ux3_fixed_frame': frame, 'communication_ux4_stable_thread': stable}


BELL_WIDTHS = (1440, 768, 320)
FEED_PATH = '/local/kbacompany/communication_notifications_feed.php'


# The no-storage tabs plant this fault on purpose (notification_bell.py NO_STORAGE); its echo is the only event
# accepted from that context.
BELL_PLANTED_STORAGE_ECHO = 'SecurityError: localStorage blocked by the test'


def notification_bell_checks(obs, exitcode=None):
    """Top bar bell + opt-in sound (fail closed). Evidence: notification_bell.py. A counted play() call is a request for a
    signal, not proof of audible sound; the owner's manual listening check is outside this contract."""
    def run():
        require(exitcode == 0 and isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error')
                and not obs.get('cleanup_error'), 'bell browser run missing/failed')
        return obs

    def step(name):
        run()
        s = (obs.get('steps') or {}).get(name)
        require(isinstance(s, dict) and s, 'missing bell observation: ' + name)
        return s

    def shown(b):
        require(isinstance(b, dict) and isinstance(b.get('ids'), list), 'bell state not recorded')
        return b

    def badge_matches(b, server, label=''):
        b = shown(b)
        unread = (server or {}).get('unread')
        require(isinstance(unread, int) and unread >= 0, '%s: server count missing' % label)
        require(b.get('unread') == str(unread), '%s: badge %r != server %r' % (label, b.get('unread'), unread))
        require(b.get('badge') == ('' if unread == 0 else (str(unread) if unread <= 99 else '99+')),
                '%s: visible badge %r wrong' % (label, b.get('badge')))
        if unread:
            require(isinstance(b.get('label'), str) and str(unread) in b['label'] and b.get('title') == b['label'],
                    '%s: tooltip/label lacks the counted unit' % label)

    def plays(b, n, label):
        require(shown(b).get('plays') == n, '%s: %r play() calls, expected %d' % (label, b.get('plays'), n))

    def new_event_center_and_bell():
        i, p, s, c = step('initial'), step('panel'), step('new_sound_off'), step('center_lists_new')
        require(i['n0'] in shown(i['bell'])['ids'], 'existing notification missing from the bell')
        badge_matches(i['bell'], i['server'], 'initial')
        require(s['n1'] in shown(s['bell'])['ids'] and s['bell']['ids'][0] == s['n1'], 'new notification not first in bell')
        badge_matches(s['bell'], s['server'], 'after new')
        require(s['server']['unread'] == i['server']['unread'] + 1, 'server count did not grow by exactly one')
        require(c.get('n1_form') == 1 and c.get('title_shown', 0) >= 1, 'new notification missing in the Center')
        m = p.get('metrics') or {}
        exact_center_link(m.get('allhref'), {'uuid': obs.get('companyuuid'), 'tab': 'notifications'})
        t = step('thread_open')
        require(t['uuid'] in shown(t['bell'])['ids'], 'thread notification missing from the bell')
        exact_center_link(t.get('href'), {'uuid': obs.get('companyuuid'), 'thread': t.get('thread')})
        require(t.get('http') == 200, 'opening the notification object: HTTP %r' % t.get('http'))
        landed = urlparse(local_url(t.get('landed') or ''))
        q = dict(parse_qsl(landed.query))
        require(landed.path == CENTER_PATH and q.get('uuid') == obs.get('companyuuid') and q.get('thread') == t.get('thread'),
                'bell link did not land on its conversation: %r' % t.get('landed'))

    def read_rules_unchanged():
        i, p, c = step('initial'), step('panel'), step('count_only')
        before, after = i['server_before'].get('read'), i['server'].get('read')
        require(isinstance(before, dict) and before and before == after, 'loading the bell changed read state')
        require(p['server'].get('read') == after, 'opening the panel changed read state')
        require(after.get(i['n0']) == 0, 'fixture notification not unread at start')
        require(c['server']['read'].get(c['n1']) == 1, 'Center "mark as read" no longer works')
        require(shown(c['bell'])['read'].get(c['n1']) == '1', 'bell does not reflect the read state')
        badge_matches(c['bell'], c['server'], 'after read in Center')

    def isolation_roles_and_revocation():
        s = step('isolation')
        eb, ma, ea = shown(s['eb_bell']), shown(s['ma_bell']), shown(s['ea_bell'])
        a_ids = set(s.get('ea_ids') or [])
        require(len(a_ids) >= 8, 'company A id set incomplete')
        require(s['nb'] in eb['ids'] and not (set(eb['ids']) & a_ids), 'company B bell shows A rows or misses its own')
        badge_matches(eb, s['eb_server'], 'company B')
        require(s['nb'] not in ea['ids'], 'company A bell shows a company B row')
        require(s.get('foreign_http') == 200 and s.get('foreign', {}).get('ok') is False
                and set(s['foreign']) <= {'ok', 'state', 'user'}, 'foreign company feed not refused neutrally')
        require(not (set(ma['ids']) & a_ids), "manager sees another user's notifications")
        require(s.get('ma_http') == 200 and s.get('ma', {}).get('ok') is True
                and not ({x.get('id') for x in s['ma'].get('items', [])} & a_ids), "manager API returns another user's rows")
        t = step('thread_revoked')
        require(t.get('active') == 0, 'participation not revoked')
        tb = shown(t['bell'])
        require(t['uuid'] not in tb['ids'] and t['thread'] not in (tb.get('text') or '') and t['title'] not in (tb.get('text') or ''),
                'revoked thread still shown')
        badge_matches(tb, t['server'], 'after thread revocation')
        plays(tb, 0, 'thread revocation')
        r = step('company_revoked')
        rv = shown(r['revoked'])
        require(r['revoked_server'].get('unread') is None, 'company access not revoked')
        require(rv['ids'] == [] and rv['badge'] == '' and rv['state'] == 'unavailable' and r['n11'] not in (rv.get('text') or ''),
                'bell still shows rows after company revocation')
        plays(rv, 0, 'company revocation')
        rs = shown(r['restored'])
        require(r['n11'] in rs['ids'], 'rows not back after reopen')
        badge_matches(rs, r['server'], 'after reopen')
        plays(rs, 0, 'reopen (new observation)')
        a = step('account_switch')
        ab = shown(a['bell'])
        require(ab['ids'] == [] and ab['badge'] == '' and ab['state'] == 'unavailable', 'open tab kept the previous account')
        plays(ab, 0, 'account switch')
        require(a.get('more_polls') == 0, 'stopped bell kept polling for another account')
        require(a.get('ra_bell_count') == 1, 'new account page has no bell')

    def claim_identity_and_concurrency():
        """Server claims (separate PHP processes on the real Moodle DB): dedup by notification identity, not max id."""
        cl = step('claims')
        companies = sorted((cl.get('dual') or {}).get('companies') or [])
        require(companies == sorted([obs.get('companyuuid'), obs.get('companyb')]), 'claims: dual-company account not in A and B')

        def outcome(run, n):
            require(isinstance(run, dict) and isinstance(run.get('plays'), list) and len(run['plays']) == n
                    and all(isinstance(x, bool) for x in run['plays']), 'claims: run evidence missing')
            return run['plays']
        x = cl.get('cross') or {}
        require(int(x['a']['id']) < int(x['b']['id']), 'claims: fixture order wrong (company A row must have the lower id)')
        require(outcome(x.get('first_b'), 1) == [True], 'claims: first company B claim did not play')
        require(outcome(x.get('then_a'), 1) == [True], 'older fresh notification suppressed (other company)')
        require(outcome(x.get('again_a'), 1) == [False], 'claims: the same notification played twice')
        require(outcome(x.get('a_in_b'), 1) == [False], 'claims: a row was claimable outside its company')
        o = cl.get('order') or {}
        require(int(o['c1']['id']) < int(o['c2']['id']), 'claims: fixture order wrong (same company)')
        require(outcome(o.get('first_c2'), 1) == [True], 'claims: newer row did not play')
        require(outcome(o.get('then_c1'), 1) == [True], 'older fresh notification suppressed (same company)')
        ps = cl.get('parallel_same') or {}
        require(outcome(ps, 8).count(True) == 1, 'concurrent claims of one notification: %r signals' % ps.get('plays'))
        pc = cl.get('parallel_companies') or {}
        pcp = outcome(pc, 8)
        require(pcp[:4].count(True) == 1 and pcp[4:].count(True) == 1,
                'concurrent claims in two companies: %r (expected one per notification)' % pcp)
        for name, run in (('parallel_same', ps), ('parallel_companies', pc)):
            rows = run.get('results') or []
            require(len({r.get('pid') for r in rows}) == len(rows), '%s: claims did not run in separate processes' % name)
            require(isinstance(run.get('inflight_at_first_end'), int) and run['inflight_at_first_end'] >= 2,
                    '%s: concurrency not demonstrated (claims did not overlap)' % name)
        b = cl.get('batch') or {}
        require(outcome(b.get('first'), 1) == [True], 'claims: batch did not play')
        require(outcome(b.get('repeat'), 4) == [False] * 4, 'claims: a claimed batch played again %r' % b['repeat']['plays'])

    def late_claim():
        """Review finding 1: a late claim answer must not play after the sound was turned off (or off and on)."""
        lc = step('late_claim')
        for key, pref, confirms in (('off', '0', 0), ('off_on', '1', 1)):
            r = lc.get(key) or {}
            ans = r.get('answers') or []
            require(len(ans) == 1 and ans[0].get('ok') is True and ans[0].get('play') is True,
                    'late claim %s: precondition missing (server did not grant play to the held claim)' % key)
            require(r.get('pref_before_release') == pref, 'late claim %s: stored preference %r' % (key, r.get('pref_before_release')))
            bf, af = r.get('before') or {}, r.get('after') or {}
            require(all(isinstance(d.get(k), int) for d in (bf, af) for k in ('signals', 'confirms', 'plays')),
                    'late claim %s: counters missing' % key)
            require(af['signals'] == bf['signals'], 'stale claim played after disable (%s)' % key)
            require(af['confirms'] - bf['confirms'] == confirms and af['plays'] - bf['plays'] == confirms,
                    'stale claim played after disable (%s): play() calls not explained by button confirmations' % key)
            require(r.get('id') in shown(r.get('bell'))['ids'], 'late claim %s: row missing from the bell' % key)
        n = lc.get('normal') or {}
        bf, af = n.get('before') or {}, n.get('after') or {}
        require(isinstance(bf.get('signals'), int) and af.get('signals') == bf['signals'] + 1 and af.get('plays') == bf.get('plays', 0) + 1
                and af.get('confirms') == bf.get('confirms'), 'new notification with sound on no longer signals after a late claim')

    def sound_signal_rules():
        claim_identity_and_concurrency()
        late_claim()
        plays(step('initial')['bell'], 0, 'initial load')
        plays(step('new_sound_off')['bell'], 0, 'sound off')
        plays(step('repeat_poll')['bell'], 0, 'repeated polling')
        c = step('count_only')
        plays(c['bell'], 0, 'count-only change')
        b = step('batch')
        require(set(b['ids']) <= set(shown(b['bell'])['ids']), 'batch rows missing')
        badge_matches(b['bell'], b['server'], 'batch')
        plays(b['bell'], 1, 'batch of three')
        t = step('tabs')
        require(all(t['n5'] in shown(x)['ids'] for x in t['bells']), 'second tab missed the row')
        require(isinstance(t.get('plays'), list) and len(t['plays']) == 2 and sum(t['plays']) == 1, 'two tabs: %r signals' % t.get('plays'))
        require(t.get('storage') == ['available', 'available'], 'two tabs: normal storage not confirmed %r' % t.get('storage'))
        n = step('tabs_nostorage')
        require(n.get('storage') == ['SecurityError', 'SecurityError'],
                'no-storage tabs: localStorage was not actually unavailable %r' % n.get('storage'))
        require(isinstance(n.get('bells'), list) and len(n['bells']) == 2
                and all(n['n6'] in shown(x)['ids'] and x.get('state') != 'unavailable' for x in n['bells']),
                'no-storage tabs: row missing or bell unavailable')
        require(isinstance(n.get('plays'), list) and len(n['plays']) == 2 and sum(n['plays']) == 1,
                'no-storage tabs: %r signals' % n.get('plays'))
        oc = shown(n.get('other_context'))
        require(n['n6'] in oc['ids'], 'other browser context missed the row')
        plays(oc, 0, 'same row in another browser context')
        plays(step('reload')['bell'], 0, 'reload')
        r = step('reconnect')
        require(r.get('polls_while_offline') == 0, 'polled while offline')
        require(set(r['ids']) <= set(shown(r['bell'])['ids']), 'rows after reconnect missing')
        plays(r['bell'], 1, 'reconnect')
        d = step('disable')
        plays(d['bell'], 0, 'after disable')
        require(d['n9'] in shown(d['bell'])['ids'], 'row missing after disable')
        th = step('thread_open')
        plays(th['bell'], 1, 'new thread notification')

    def sound_preference():
        e = step('enable')
        require(step('initial')['server'].get('pref') in ('0', ''), 'sound not off by default')
        require(step('initial')['bell'].get('sound') == 'off', 'bell did not start with sound off')
        require(e.get('plays_after_enable') == 1 and e.get('plays_after_test') == 2, 'enable/test did not request playback')
        require(e['server'].get('pref') == '1' and e['bell'].get('sound') == 'on', 'enable not stored or not shown')
        require(e.get('other_account_pref') in ('0', ''), 'preference leaked to another account')
        a = step('autoplay_refused')
        plays(a['blocked'], 1, 'refused playback attempt')
        require(a['blocked'].get('sound') == 'blocked' and a['after_test'].get('sound') == 'on', 'autoplay refusal not handled')
        c = step('csrf')
        require(c.get('wrong_http') == 200 and c.get('wrong', {}).get('ok') is False and c['wrong'].get('state') == 'sesskey',
                'wrong sesskey not refused')
        require(c.get('get_http') == 200 and c.get('get_ok') is True and c.get('pref') == '1', 'preference changed without POST+sesskey')
        d = step('disable')
        require(d.get('pref') == '0' and d['bell'].get('sound') == 'off', 'disable not stored or not shown')

    def panel_layout_keyboard():
        run()
        rows = obs.get('widths')
        require(isinstance(rows, list) and [r.get('width') for r in rows] == list(BELL_WIDTHS), 'bell width matrix incomplete')
        for r in rows:
            w = r['width']
            require(r.get('toggle_visible') is True, '%d: bell not visible' % w)
            closed = r.get('closed') or {}
            require(closed.get('open') is False and closed.get('hscroll') is False, '%d: closed state wrong/overflow' % w)
            for key in ('open_keyboard', 'open_click'):
                m = r.get(key) or {}
                require(m.get('open') is True and m.get('expanded') == 'true', '%d: %s did not open' % (w, key))
                require(m.get('position') == 'fixed' and m.get('overflowy') in ('auto', 'scroll'), '%d: panel not floating/scrollable' % w)
                require(0 <= m['left'] and m['right'] <= m['vw'] and 0 <= m['top'] and m['bottom'] <= m['vh'],
                        '%d: panel outside the viewport %r' % (w, m))
                require(m.get('hscroll') is False, '%d: horizontal overflow with panel open' % w)
                require(m.get('maintop') == closed.get('maintop') and m.get('docheight') == closed.get('docheight'),
                        '%d: opening the panel shifted the page' % w)
            require(r['open_keyboard'].get('active') == 'panel', '%d: focus not moved into the panel' % w)
            require(r.get('tab_inside') == 'inside', '%d: Tab left the panel' % w)
            esc = r.get('after_escape') or {}
            require(esc.get('open') is False and esc.get('active') == 'toggle', '%d: Escape did not close/restore focus' % w)
            outline = r.get('focus_outline')
            require(isinstance(outline, list) and outline[0] != 'none' and outline[1] >= 2, '%d: focus ring missing' % w)
            out = r.get('after_outside') or {}
            require(out.get('open') is False and out.get('expanded') == 'false', '%d: outside click did not close' % w)

    def single_bell():
        for name in ('initial', 'reload'):
            b = shown(step(name)['bell'])
            require(b.get('bells') == 1 and b.get('moodlebells') == 0, '%s: expected exactly one bell' % name)
            # The hidden panel must not contribute page headings (it hid the page title for h1/h2 lookups).
            require(b.get('headings') == 0, '%s: bell contains %r h1-h6 headings' % (name, b.get('headings')))
        require(step('legacy').get('bells') == 0, 'legacy shell got a second (BMS) bell')

    def no_browser_errors():
        run()
        require(obs.get('server_errors') == [], 'server errors: %r' % (obs.get('server_errors') or [])[:3])
        page_error_check(obs, 'notification bell')()
        ev = (step('tabs_nostorage') or {}).get('events')
        require(isinstance(ev, list), 'no-storage context events not recorded')
        events = [e for e in ev if not (isinstance(e, dict) and e.get('source') == 'console' and e.get('type') == 'error'
                                        and e.get('text') == BELL_PLANTED_STORAGE_ECHO)]
        violations = browser_event_violations(events)
        require(not violations, 'no-storage context: unexpected browser events %r' % violations[:3])

    return {'notification_bell_new_event_center_and_bell': new_event_center_and_bell,
            'notification_bell_read_rules_unchanged': read_rules_unchanged,
            'notification_bell_isolation_roles_and_revocation': isolation_roles_and_revocation,
            'notification_bell_sound_signal_rules': sound_signal_rules,
            'notification_bell_sound_preference': sound_preference,
            'notification_bell_panel_layout_keyboard': panel_layout_keyboard,
            'notification_bell_single_bell': single_bell,
            'notification_bell_no_browser_errors': no_browser_errors}


# ---- Adaptive BMS24 navigation (docs/ui/ADAPTIVE_NAVIGATION.md): owned drawer <= 860 px, sidebar >= 861 px.
ADAPTIVE_NAV_BREAKPOINT = 860
# (id, shell, actor, lang, width, height, device scale factor, JavaScript)
ADAPTIVE_NAV_CASES = [
    ('owned-320-ru', 'owned', 'MA', 'ru', 320, 640, 1, True), ('owned-375-ru', 'owned', 'MA', 'ru', 375, 812, 1, True),
    ('owned-390-en', 'owned', 'MA', 'en', 390, 844, 1, True), ('owned-768-ru', 'owned', 'MA', 'ru', 768, 1024, 1, True),
    ('owned-860-ru', 'owned', 'MA', 'ru', 860, 900, 1, True), ('owned-861-ru', 'owned', 'MA', 'ru', 861, 900, 1, True),
    ('owned-1024-ru', 'owned', 'MA', 'ru', 1024, 768, 1, True), ('owned-1440-en', 'owned', 'MA', 'en', 1440, 900, 1, True),
    ('owned-390x480-ru', 'owned', 'MA', 'ru', 390, 480, 1, True), ('owned-844x390-ru', 'owned', 'MA', 'ru', 844, 390, 1, True),
    ('owned-zoom200-ru', 'owned', 'MA', 'ru', 720, 450, 2, True), ('owned-375-employee-ru', 'owned', 'EA', 'ru', 375, 812, 1, True),
    ('legacy-375-ru', 'legacy', 'MA', 'ru', 375, 812, 1, True), ('legacy-1440-en', 'legacy', 'MA', 'en', 1440, 900, 1, True),
    ('nojs-375-ru', 'owned', 'MA', 'ru', 375, 812, 1, False), ('nojs-1440-en', 'owned', 'MA', 'en', 1440, 900, 1, False),
]
ADAPTIVE_NAV_ROUTES = ['communication_center.php', 'daily_report.php', 'battle_plan.php', 'tasks.php']
ADAPTIVE_NAV_STRESS = {'label': 'Согласование очень длинного названия раздела управления подразделениями', 'count': '9999'}
ADAPTIVE_NAV_MIN_TARGET = 44
ADAPTIVE_NAV_MARKER = 'BMS24 adaptive navigation draft'


def adaptive_nav_checks(obs, exitcode=None):
    """Adaptive navigation (adaptive_nav.py). Every verdict is recomputed from geometry/state observations; the producer's
    PASS only means the run completed. Narrow = owned shell with JavaScript at <= 860 CSS px."""
    def run():
        require(exitcode == 0, 'adaptive navigation browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'adaptive navigation run incomplete')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('id'), r.get('shell'), r.get('actor'), r.get('lang'), r.get('width'), r.get('height'),
                                             r.get('dpr'), r.get('js')) for r in rows] == [tuple(c) for c in ADAPTIVE_NAV_CASES],
                'adaptive navigation case list differs')
        for r in rows:
            require(not r.get('error'), '%s: %s' % (r['id'], r.get('error')))
            require(r.get('language_installed') is True and r.get('http') == 200, '%s: page or language not served' % r['id'])
            lay = r.get('layout') or {}
            require(lay.get('owned') is (r['shell'] == 'owned') and lay.get('inner') == r['width'],
                    '%s: shell mode or viewport not active: %r' % (r['id'], (lay.get('owned'), lay.get('inner'))))
        return rows

    def narrow_rows():
        rows = [r for r in run() if r['shell'] == 'owned' and r['js'] and r['width'] <= ADAPTIVE_NAV_BREAKPOINT]
        require(len(rows) == 9, 'narrow owned cases missing')
        return rows

    def wide_rows():
        rows = [r for r in run() if r['shell'] == 'owned' and r['js'] and r['width'] > ADAPTIVE_NAV_BREAKPOINT]
        require(len(rows) == 3, 'wide owned cases missing')
        return rows

    def fits(box, width, height=None):
        return (isinstance(box, dict) and all(isinstance(box.get(k), int) for k in ('x', 'y', 'r', 'b'))
                and box['x'] >= 0 and box['r'] <= width and (height is None or (box['y'] >= 0 and box['b'] <= height + 1)))

    def no_hscroll(scroll, client, width, where):
        require(isinstance(scroll, int) and isinstance(client, int) and 0 < client <= width and scroll <= client,
                '%s scrolls horizontally: scrollWidth %r > clientWidth %r' % (where, scroll, client))

    def desktop():
        for r in wide_rows():
            lay = r['layout']; side = lay.get('sidebar') or {}
            require(lay.get('sidebar_visible') is True and side.get('current_visible') is True, '%s: sidebar or current item hidden' % r['id'])
            require((lay.get('button') or {}).get('visible') is False and lay.get('summary_visible') is False
                    and lay.get('drawer_visible') is False and lay.get('drawer_focusables') == 0,
                    '%s: hidden mobile menu is rendered or focusable' % r['id'])
            cur = [g for g in side.get('groups') or [] if g.get('current')]
            require(cur and all(g.get('open') and g.get('visible') == g.get('items') for g in cur),
                    '%s: group of the current page not open with its items: %r' % (r['id'], cur))

    def button():
        for r in narrow_rows():
            b = (r.get('before_open') or {}).get('button') or {}
            require(b.get('tag') == 'BUTTON' and b.get('type') == 'button' and b.get('visible') is True and b.get('hidden_attr') is False,
                    '%s: menu button missing or not a visible <button>: %r' % (r['id'], b))
            require(b.get('w', 0) >= ADAPTIVE_NAV_MIN_TARGET and b.get('h', 0) >= ADAPTIVE_NAV_MIN_TARGET, '%s: menu button below 44x44' % r['id'])
            require(b.get('expanded') == 'false' and b.get('controls') == 'bms24-drawer' and b.get('controls_exists') is True
                    and isinstance(b.get('name'), str) and len(b['name']) >= 3, '%s: aria-expanded/aria-controls/name wrong: %r' % (r['id'], b))
            require(r.get('open_method') == 'button' and (r.get('drawer') or {}).get('expanded') == 'true',
                    '%s: menu not opened by the button or aria-expanded not true' % r['id'])
            require((r.get('before_open') or {}).get('summary_visible') is False, '%s: no-JS summary still shown with JS' % r['id'])
        for r in run():
            require((r.get('layout') or {}).get('menu_roles') == 0 and (r.get('drawer') or {}).get('menu_roles', 0) == 0,
                    '%s: role=menu used for site navigation' % r['id'])

    def modal():
        for r in narrow_rows():
            d = r.get('drawer') or {}
            require(d.get('visible') is True and d.get('role') == 'dialog' and d.get('modal') == 'true' and len(d.get('name') or '') >= 3
                    and d.get('id') == 'bms24-drawer',
                    '%s: drawer is not a named modal dialog: %r' % (r['id'], {k: d.get(k) for k in ('role', 'modal', 'name', 'id')}))
            require(fits(d.get('box'), r['width'], r['height']) and d['box']['y'] == 0 and d['box']['b'] >= r['height'] - 1,
                    '%s: drawer not within the viewport at full height: %r' % (r['id'], d.get('box')))
            bd = d.get('backdrop') or {}
            require(bd.get('visible') is True and bd.get('x') == 0 and bd.get('y') == 0 and bd.get('w', 0) >= r['width'] - 1
                    and bd.get('h', 0) >= r['height'] - 1, '%s: backdrop does not cover the viewport: %r' % (r['id'], bd))
            require(d.get('focus_inside') is True, '%s: focus not moved into the drawer' % r['id'])
            fw, bw = r.get('tab_forward') or [], r.get('tab_backward') or []
            require(len(fw) >= 4 and all(x is True for x in fw) and len(bw) == 4 and all(x is True for x in bw),
                    '%s: Tab/Shift+Tab left the open drawer: %r %r' % (r['id'], fw, bw))
            require(d.get('main_inert') is True and d.get('sidebar_inert') is True, '%s: page behind the drawer is not inert' % r['id'])
            if r['id'] == 'owned-375-ru':
                require(r.get('bell_handoff') == {'open_before_menu': True, 'closed_after_menu': True},
                        'opening the navigation drawer must close the visible bell panel')
            cl = d.get('close') or {}
            require(cl.get('tag') == 'BUTTON' and cl.get('visible') is True and len(cl.get('name') or '') >= 3
                    and cl.get('w', 0) >= ADAPTIVE_NAV_MIN_TARGET and cl.get('h', 0) >= ADAPTIVE_NAV_MIN_TARGET,
                    '%s: explicit 44x44 close button missing: %r' % (r['id'], cl))

    def overflow():
        for r in run():
            lay = r.get('layout') or {}
            no_hscroll(lay.get('scroll'), lay.get('client'), r['width'], '%s page' % r['id'])
        for r in narrow_rows():
            d = r.get('drawer') or {}
            no_hscroll(d.get('doc_scroll'), d.get('client'), r['width'], '%s page with open drawer' % r['id'])
            require(d.get('outside') == [], '%s: menu content sticks out of the drawer: %r' % (r['id'], d.get('outside')))
            sc = d.get('scrollers')
            require(isinstance(sc, list) and sc, '%s: drawer scroll container not recorded' % r['id'])
            for s in sc:
                require(isinstance(s.get('sw'), int) and isinstance(s.get('cw'), int) and s['sw'] <= s['cw'] + 1,
                        '%s: drawer scrolls horizontally: %r' % (r['id'], s))
            st = r.get('stress') or {}
            require(st.get('done') is True and st.get('outside') == [] and fits(st.get('box'), r['width']),
                    '%s: long label/large counter overflows the drawer: %r' % (r['id'], st))
            last = r.get('last_item') or {}
            no_hscroll(last.get('doc_scroll'), last.get('client'), r['width'], '%s page with all groups open' % r['id'])
        for r in wide_rows():
            require((r['layout'].get('sidebar') or {}).get('outside') == [], '%s: sidebar content sticks out' % r['id'])

    def groups_current():
        for r in narrow_rows():
            d = r.get('drawer') or {}
            cur = d.get('current') or {}
            require(cur.get('visible') is True and cur.get('open') is True and cur.get('path') == 'tasks.php',
                    '%s: current page item not visible in an open group: %r' % (r['id'], cur))
            gs = d.get('groups') or []
            require(gs and all(g.get('visible') == g.get('items') for g in gs if g.get('open')),
                    '%s: an open group hides its items: %r' % (r['id'], [g for g in gs if g.get('open')]))
            alls = r.get('groups_all_open') or []
            require(alls and len(alls) == len(gs) and all(g.get('open') and g.get('items', 0) >= 1 and g.get('visible') == g.get('items')
                                                          for g in alls),
                    '%s: a group cannot be opened or shows no items: %r' % (r['id'], alls))

    def labels_counts():
        for r in narrow_rows():
            reps = list((r.get('drawer') or {}).get('labels') or [])
            require(len(reps) >= 4, '%s: menu labels not recorded' % r['id'])
            st = ((r.get('stress') or {}).get('report')) or {}
            require((st.get('text') or '').startswith(ADAPTIVE_NAV_STRESS['label'].split()[0])
                    and (st.get('count') or {}).get('text') == ADAPTIVE_NAV_STRESS['count'] and (st.get('count') or {}).get('visible') is True,
                    '%s: stress label not measured: %r' % (r['id'], st))
            reps.append(st)
            for x in reps:
                require(x.get('broken') == [], '%s: word broken across lines in %r: %r' % (r['id'], x.get('text'), x.get('broken')))
                cnt = x.get('count')
                if cnt and cnt.get('visible'):
                    require(cnt.get('clipped') is False and cnt.get('inside') is True, '%s: counter clipped in %r' % (r['id'], x.get('text')))
        for r in wide_rows():
            reps = (r['layout'].get('sidebar') or {}).get('labels') or []
            require(len(reps) >= 4, '%s: sidebar labels not recorded' % r['id'])
            for x in reps:
                require(x.get('broken') == [], '%s: sidebar word broken in %r' % (r['id'], x.get('text')))

    def tap_targets():
        for r in narrow_rows():
            ts = (r.get('drawer') or {}).get('targets') or []
            require(len(ts) >= 4, '%s: drawer targets not recorded' % r['id'])
            small = [t for t in ts if t.get('w', 0) < ADAPTIVE_NAV_MIN_TARGET or t.get('h', 0) < ADAPTIVE_NAV_MIN_TARGET]
            require(not small, '%s: tap targets below 44x44: %r' % (r['id'], small[:4]))
        for r in wide_rows():
            ts = [t for t in (r['layout'].get('sidebar') or {}).get('targets') or [] if t.get('text')]
            small = [t for t in ts if t.get('h', 0) < ADAPTIVE_NAV_MIN_TARGET or t.get('w', 0) < ADAPTIVE_NAV_MIN_TARGET]
            require(len(ts) >= 4 and not small, '%s: sidebar menu items/group headers below 44 px: %r' % (r['id'], small[:4]))

    def scroll_lock():
        for r in narrow_rows():
            d, before = r.get('drawer') or {}, r.get('before_open') or {}
            require(d.get('html_overflow') == 'hidden', '%s: page behind the drawer is still scrollable' % r['id'])
            w = r.get('wheel') or {}
            require(isinstance(w.get('before'), int) and w.get('after') == w['before'], '%s: page scrolled under the drawer: %r' % (r['id'], w))
            require(isinstance(before.get('main'), dict) and d.get('main') == before.get('main') and d.get('client') == before.get('client'),
                    '%s: opening the drawer moved or resized the page: %r -> %r' % (r['id'], before.get('main'), d.get('main')))

    def closing(state, r, label):
        require(isinstance(state, dict), '%s: %s close not performed' % (r['id'], label))
        require(state.get('drawer_visible') is False and state.get('backdrop_visible') is False and state.get('drawer_focusables') == 0,
                '%s: drawer/backdrop left after %s close' % (r['id'], label))
        require(state.get('expanded') == 'false' and state.get('active') == 'menubutton',
                '%s: focus/aria-expanded not restored after %s: %r' % (r['id'], label, (state.get('expanded'), state.get('active'))))
        require(isinstance(state.get('scrolly'), int) and state.get('scrolly') == state.get('expected_scrolly')
                and state.get('html_overflow') != 'hidden' and state.get('main_inert') is False,
                '%s: scroll position or scroll lock not restored after %s: %r' % (r['id'], label, state))
        require(state.get('field') == ADAPTIVE_NAV_MARKER, '%s: typed page data lost after %s' % (r['id'], label))

    def close_methods():
        exposed = 0
        for r in narrow_rows():
            require(r.get('field_present') is True, '%s: page field for the data check missing' % r['id'])
            closing(r.get('close_escape'), r, 'Escape')
            closing(r.get('close_button'), r, 'close button')
            if r.get('backdrop_exposed') is True:
                exposed += 1
                closing(r.get('close_backdrop'), r, 'backdrop')
            else:
                require(r.get('backdrop_exposed') is False and r['width'] < 360,
                        '%s: drawer must leave the backdrop exposed above 359 px' % r['id'])
        require(exposed >= 8, 'backdrop close exercised in too few cases: %d' % exposed)

    def last_item():
        for r in narrow_rows():
            last = r.get('last_item') or {}
            require(last.get('found') is True and last.get('hit') is True and isinstance(last.get('top'), int) and last['top'] >= 0
                    and isinstance(last.get('bottom'), int) and last['bottom'] <= r['height'],
                    '%s: last menu item not reachable by vertical scrolling: %r' % (r['id'], last))

    def resize():
        rows = {r['id']: r for r in run()}
        for cid, wide in (('owned-375-ru', 1440), ('owned-861-ru', 861)):
            z = rows[cid].get('resize') or {}
            require(z.get('opened') == 'button' and z.get('open') is True, '%s: drawer not opened before resizing' % cid)
            tw = z.get('to_wide') or {}
            require(tw.get('inner') == wide and tw.get('drawer_visible') is False and tw.get('backdrop_visible') is False
                    and tw.get('html_overflow') != 'hidden' and tw.get('main_inert') is False and tw.get('sidebar_visible') is True
                    and tw.get('drawer_focusables') == 0 and isinstance(tw.get('scroll'), int) and tw['scroll'] <= wide,
                    '%s: overlay or scroll lock left after widening: %r' % (cid, tw))
            bn = z.get('back_narrow') or {}
            require(bn.get('drawer_visible') is False and (bn.get('button') or {}).get('visible') is True
                    and (bn.get('button') or {}).get('expanded') == 'false' and bn.get('html_overflow') != 'hidden'
                    and bn.get('backdrop_visible') is False, '%s: menu state wrong after narrowing again: %r' % (cid, bn))
            require(z.get('reopen') == 'button' and z.get('reopen_visible') is True and (z.get('reclosed') or {}).get('drawer_visible') is False,
                    '%s: drawer cannot be reopened/closed after resizing' % cid)

    def routes():
        r = next(x for x in run() if x['id'] == 'owned-375-ru')
        steps = r.get('routes') or []
        require([s.get('target') for s in steps] == ADAPTIVE_NAV_ROUTES, 'route transitions not recorded')
        for s in steps:
            require(s.get('opened') == 'button' and s.get('links', 0) >= 1 and s.get('visible') is True and s.get('http') == 200
                    and s.get('path') == s['target'], 'menu link to %s did not open it: %r' % (s['target'], s))
            cur = s.get('current') or {}
            require(s.get('opened_after') == 'button' and cur.get('path') == s['target'] and cur.get('visible') is True and cur.get('open') is True,
                    'after opening %s the menu does not show it as current in an open group: %r' % (s['target'], cur))
            no_hscroll(s.get('doc_scroll'), s.get('client'), 375, '%s with open drawer' % s['target'])

    def employee():
        rows = {r['id']: r for r in run()}
        ma, ea = rows['owned-375-ru'], rows['owned-375-employee-ru']
        count = lambda r: sum(g.get('items', 0) for g in (r.get('groups_all_open') or []))
        require(0 < count(ea) <= count(ma), 'employee menu not rendered or larger than the manager menu: %r/%r' % (count(ea), count(ma)))

    def nojs():
        rows = {r['id']: r for r in run()}
        n, w = rows['nojs-375-ru'], rows['nojs-1440-en']
        require((n['layout'].get('button') or {}).get('visible') is False and n.get('summary_visible') is True,
                'no-JS phone: the summary fallback must be shown and the JS button hidden')
        d = n.get('drawer') or {}
        require(d.get('visible') is True and fits(d.get('box'), 375) and len(d.get('labels') or []) >= 4 and d.get('outside') == [],
                'no-JS phone: menu links not usable: %r' % {k: d.get(k) for k in ('visible', 'box', 'outside')})
        sc = d.get('scrollers')
        require(isinstance(sc, list) and sc and all(isinstance(s.get('sw'), int) and s['sw'] <= s.get('cw', -1) + 1 for s in sc),
                'no-JS phone: menu scrolls horizontally: %r' % (sc,))
        no_hscroll(d.get('doc_scroll'), d.get('client'), 375, 'no-JS phone page with menu')
        last = n.get('last_item') or {}
        require(last.get('found') is True and last.get('hit') is True, 'no-JS phone: last menu item not reachable: %r' % (last,))
        require(w['layout'].get('sidebar_visible') is True and (w['layout'].get('sidebar') or {}).get('current_visible') is True,
                'no-JS desktop: sidebar not usable')

    def legacy():
        for r in run():
            if r['shell'] != 'legacy':
                continue
            lay = r['layout']
            require(lay.get('owned') is False and lay.get('button') is None and lay.get('drawer_visible') is False
                    and lay.get('html_overflow') != 'hidden' and lay.get('main_inert') is False,
                    '%s: owned drawer leaked into the legacy shell' % r['id'])

    def events():
        for r in run():
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, '%s: browser errors: %r' % (r['id'], violations[:3]))
            if r['js']:
                require(r.get('server_errors') == [], '%s: HTTP 5xx: %r' % (r['id'], r.get('server_errors')))

    return {'adaptive_nav_desktop_sidebar_unchanged': desktop, 'adaptive_nav_menu_button_accessible': button,
            'adaptive_nav_drawer_modal_and_focus_trap': modal, 'adaptive_nav_no_horizontal_overflow': overflow,
            'adaptive_nav_groups_open_and_current': groups_current, 'adaptive_nav_labels_wrap_and_counters': labels_counts,
            'adaptive_nav_tap_targets_44': tap_targets, 'adaptive_nav_scroll_lock_page_stable': scroll_lock,
            'adaptive_nav_close_escape_button_backdrop': close_methods, 'adaptive_nav_last_item_reachable': last_item,
            'adaptive_nav_resize_no_stale_overlay': resize, 'adaptive_nav_route_transitions': routes,
            'adaptive_nav_shorter_employee_menu': employee, 'adaptive_nav_no_js_fallback': nojs,
            'adaptive_nav_legacy_shell_unchanged': legacy, 'adaptive_nav_no_browser_errors': events}


OFFICE_CASES = [('owned', 1440, 'en'), ('owned', 375, 'ru'), ('legacy', 1440, 'en'), ('legacy', 375, 'ru')]
OFFICE_TITLES = {'en': 'Office', 'ru': 'Канцелярия'}
OFFICE_PLANNED = ['incoming', 'outgoing', 'internal', 'orders', 'contracts', 'archive']
OFFICE_ACTIVE = ['office.php', 'documents.php', 'documents.php', 'tasks.php', 'meetings.php', 'policies.php']
OFFICE_COUNTS = ['acknowledge', 'decisions', 'directives', 'documents', 'execution', 'overdue', 'review']
OFFICE_SHOWN = ['acknowledge', 'directives', 'documents', 'execution', 'overdue', 'review']


def office_workspace_checks(obs, exitcode=None):
    """DOC-W2 Office workspace (office_workspace.py): default-off switch, counters equal to the service ground truth,
    menu and planned sections, canonical section pages, manager view, company isolation, revocation, layout/errors."""
    def run():
        require(exitcode == 0, 'office workspace browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'office workspace run incomplete')
        require(isinstance(obs.get('company'), str) and len(obs['company']) >= 8, 'company reference not recorded')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('lang')) for r in rows] == OFFICE_CASES,
                'owned/legacy x 1440 EN / 375 RU cases required')
        model = obs.get('model') or {}
        for who in ('EA', 'MA', 'EB'):
            m = model.get(who)
            require(isinstance(m, dict) and sorted(m) == OFFICE_COUNTS and all(isinstance(v, int) and v >= 0 for v in m.values()),
                    'service ground truth for %s not recorded: %r' % (who, m))
        ea, eb = model['EA'], model['EB']
        require(ea['acknowledge'] >= 1 and ea['review'] >= 1 and ea['overdue'] >= 1 and ea['execution'] >= ea['overdue'],
                'seeded company A data not visible to EA in the service: %r' % (ea,))
        require(eb['overdue'] >= 1 and eb['review'] >= 1, 'seeded company B data not visible to EB in the service: %r' % (eb,))
        for r in rows:
            require(r.get('language_installed') is True, 'page language %s not active' % r.get('lang'))
            require((r.get('page') or {}).get('owned_shell') is (r['shell'] == 'owned'), 'shell mode %s not active' % r['shell'])
        return rows, model

    def shown(counts):
        return {k: v for k, v in (counts or {}).items() if k in OFFICE_SHOWN}

    def expected(m):
        return {k: m[k] for k in OFFICE_SHOWN}

    def switch_off():
        run()
        require((obs.get('switch_off') or {}).get('enabled') is False and (obs.get('switch_on') or {}).get('enabled') is True,
                'site switch not toggled off/on')
        off = obs.get('off') or {}
        require(isinstance(off.get('http'), int) and off['http'] < 500, 'switched-off route answered %r' % off.get('http'))
        require(off.get('workspace') is False and off.get('counts') == {}, 'workspace or counters shown while switched off')
        require(off.get('menu') == [] and off.get('menu_on_documents') == [], 'Office menu item present while switched off')

    def counters():
        rows, model = run()
        for r in rows:
            pg = r.get('page') or {}
            require(pg.get('http') == 200 and pg.get('workspace') is True, 'workspace not served at %s/%s' % (r['shell'], r['width']))
            require(shown(pg.get('counts')) == expected(model['EA']),
                    'counters differ from the service at %s/%s: %r vs %r' % (r['shell'], r['width'], pg.get('counts'), model['EA']))
            require(OFFICE_TITLES[r['lang']] in (pg.get('h1') or []), 'page heading must say %s' % OFFICE_TITLES[r['lang']])
            require(pg.get('create') == 0, 'employee sees the create-document action')
            require(pg.get('leaked') == [], 'foreign or private titles visible: %r' % pg.get('leaked'))

    def navigation():
        rows, _ = run()
        for r in rows:
            pg = r.get('page') or {}
            planned = pg.get('planned') or []
            require([x.get('key') for x in planned] == OFFICE_PLANNED, 'planned sections missing: %r' % planned)
            require(all(x.get('tag') == 'span' and x.get('inlink') is False and x.get('href') is None
                        and x.get('disabled') == 'true' for x in planned), 'a planned section is a link or not disabled')
            active = pg.get('active') or []
            require([x.get('path') for x in active] == OFFICE_ACTIVE and all(x.get('uuid') == obs['company'] for x in active),
                    'active sections must be the canonical pages of company A: %r' % active)
            require([x.get('current') for x in active] == ['page'] + [None] * (len(OFFICE_ACTIVE) - 1),
                    'only Overview may be current in the section menu')
            if r['shell'] == 'owned':
                menu = [x for x in pg.get('menu') or [] if x.get('visible')]
                require(len(menu) == 1 and menu[0].get('uuid') == obs['company'] and menu[0].get('current') == 'page',
                        'exactly one current Office item expected in the %s px owned menu: %r' % (r['width'], pg.get('menu')))

    def sections():
        rows, _ = run()
        landing = rows[0].get('landing')
        require(isinstance(landing, list) and [x.get('path') for x in landing] == OFFICE_ACTIVE,
                'section links not followed: %r' % landing)
        require(all(x.get('http') == 200 and not x.get('workspace_error') for x in landing),
                'a section page failed for EA: %r' % landing)

    def manager_and_isolation():
        _, model = run()
        m = obs.get('manager') or {}
        require(m.get('http') == 200 and m.get('workspace') is True and shown(m.get('counts')) == expected(model['MA']),
                'manager counters differ from the service: %r vs %r' % (m.get('counts'), model['MA']))
        require(m.get('create') == 1 and m.get('leaked') == [], 'manager view must offer "New document" and no company B data')
        x = obs.get('foreign') or {}
        require(isinstance(x.get('http'), int) and x['http'] < 500 and x.get('workspace') is False and x.get('counts') == {}
                and x.get('leaked') == [], 'company B actor reached company A workspace data: %r' % {k: x.get(k) for k in ('http', 'workspace', 'leaked')})
        b = obs.get('own_b') or {}
        require(b.get('http') == 200 and b.get('workspace') is True and shown(b.get('counts')) == expected(model['EB'])
                and b.get('leaked') == [], 'company B workspace must show B counters only: %r' % (b.get('counts'),))

    def revocation():
        run()
        rv = obs.get('revocation') or {}
        before, after = rv.get('before') or {}, rv.get('after') or {}
        require(before.get('workspace') is True and obs.get('revoked_flag') is True, 'revocation not applied after a baseline visit')
        require(isinstance(after.get('http'), int) and after['http'] < 500 and after.get('workspace') is False
                and after.get('counts') == {} and after.get('leaked') == [], 'revoked member still sees the workspace')
        require(obs.get('reopened_flag') is True, 'membership not reopened after the check')

    def layout():
        rows, _ = run()
        for r in rows:
            box = (r.get('page') or {}).get('hscroll')
            require(isinstance(box, dict) and isinstance(box.get('scroll'), int) and box.get('inner') == r['width']
                    and box['scroll'] <= box['inner'], 'office page scrolls horizontally at %s px: %r' % (r['width'], box))
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx: %r' % (r.get('server_errors'),))
        violations = browser_event_violations((obs.get('manager') or {}).get('browser_events'))
        require(not violations, 'browser errors in the manager view: %r' % violations[:3])

    return {'office_switch_off_hides_route_and_menu': switch_off, 'office_counters_equal_service_ground_truth': counters,
            'office_menu_and_planned_sections': navigation, 'office_sections_open_canonical_pages': sections,
            'office_manager_view_and_company_isolation': manager_and_isolation,
            'office_revocation_closes_workspace': revocation, 'office_layout_and_browser_errors': layout}


FINANCE_CASES = [('owned', 1440, 'en'), ('owned', 375, 'ru'), ('legacy', 1440, 'en'), ('legacy', 375, 'ru')]
FINANCE_TITLES = {'en': 'Finance', 'ru': 'Финансы'}
FINANCE_PLANNED = ['weekly', 'requests', 'council', 'calendar', 'treasury', 'reporting']
FINANCE_ACTIVE = ['finance.php', 'financial_model.php', 'financial_model.php', 'financial_statements.php',
                  'budgeting.php', 'budgeting.php', 'resource_gate.php']
FINANCE_COUNTS = ['budgetsapproved', 'budgetssubmitted', 'centers', 'gatesblocked', 'gatesready', 'scenarios', 'statements']


def finance_workspace_checks(obs, exitcode=None):
    """FIN-W2 Finance workspace (finance_workspace.py): default-off switch, counters / per-currency plan / fact markers
    equal to the service ground truth, menu and planned sections, canonical section pages, the existing financial gate,
    company isolation, revocation, layout/errors."""
    def run():
        require(exitcode == 0, 'finance workspace browser process failed or was not run')
        require(isinstance(obs, dict) and obs.get('status') == 'PASS' and not obs.get('error'), 'finance workspace run incomplete')
        require(isinstance(obs.get('company'), str) and len(obs['company']) >= 8, 'company reference not recorded')
        rows = obs.get('cases')
        require(isinstance(rows, list) and [(r.get('shell'), r.get('width'), r.get('lang')) for r in rows] == FINANCE_CASES,
                'owned/legacy x 1440 EN / 375 RU cases required')
        model = obs.get('model') or {}
        for who in ('MA', 'MB'):
            m = model.get(who) or {}
            c = m.get('counts')
            require(isinstance(c, dict) and sorted(c) == FINANCE_COUNTS and all(isinstance(v, int) and v >= 0 for v in c.values()),
                    'service ground truth for %s not recorded: %r' % (who, c))
            require(isinstance(m.get('plan'), dict) and isinstance(m.get('facts'), dict), 'plan/fact ground truth for %s missing' % who)
        ma, mb = model['MA'], model['MB']
        require(ma['counts']['budgetsapproved'] >= 2 and ma['counts']['budgetssubmitted'] >= 1 and ma['counts']['statements'] >= 1
                and ma['facts'].get('confirmed', 0) >= 1 and ma['facts'].get('none', 0) >= 1 and len(ma['plan']) >= 2,
                'seeded company A finance data not visible to MA in the service: %r' % (ma,))
        require('EUR' in mb['plan'] and 'EUR' not in ma['plan'], 'company B plan not separated from company A: %r / %r'
                % (ma['plan'], mb['plan']))
        for r in rows:
            require(r.get('language_installed') is True, 'page language %s not active' % r.get('lang'))
            require((r.get('page') or {}).get('owned_shell') is (r['shell'] == 'owned'), 'shell mode %s not active' % r['shell'])
        return rows, model

    def denied(x, what):
        require(isinstance(x.get('http'), int) and x['http'] < 500 and x.get('workspace') is False and x.get('counts') == {}
                and x.get('plan') == {} and x.get('leaked') == [], '%s reached finance data: %r'
                % (what, {k: x.get(k) for k in ('http', 'workspace', 'counts', 'leaked')}))

    def same(pg, m):
        return (pg.get('counts') == m['counts'] and pg.get('plan') == m['plan'] and pg.get('facts') == m['facts']
                and pg.get('attention') == m.get('attention'))

    def switch_off():
        run()
        require((obs.get('switch_off') or {}).get('enabled') is False and (obs.get('switch_on') or {}).get('enabled') is True,
                'site switch not toggled off/on')
        off = obs.get('off') or {}
        require(isinstance(off.get('http'), int) and off['http'] < 500, 'switched-off route answered %r' % off.get('http'))
        require(off.get('workspace') is False and off.get('counts') == {}, 'workspace or counters shown while switched off')
        require(off.get('menu') == [] and off.get('menu_on_model') == [], 'Finance menu item present while switched off')

    def figures():
        rows, model = run()
        for r in rows:
            pg = r.get('page') or {}
            require(pg.get('http') == 200 and pg.get('workspace') is True, 'workspace not served at %s/%s' % (r['shell'], r['width']))
            require(same(pg, model['MA']), 'figures differ from the service at %s/%s: %r vs %r' % (
                r['shell'], r['width'], {k: pg.get(k) for k in ('counts', 'plan', 'facts', 'attention')}, model['MA']))
            require(FINANCE_TITLES[r['lang']] in (pg.get('h1') or []), 'page heading must say %s' % FINANCE_TITLES[r['lang']])
            require(pg.get('leaked') == [], 'company B titles visible: %r' % pg.get('leaked'))
            require(len(pg.get('shown') or []) == 4, 'seeded company A objects not listed: %r' % pg.get('shown'))

    def navigation():
        rows, _ = run()
        for r in rows:
            pg = r.get('page') or {}
            planned = pg.get('planned') or []
            require([x.get('key') for x in planned] == FINANCE_PLANNED, 'planned sections missing: %r' % planned)
            require(all(x.get('tag') == 'span' and x.get('inlink') is False and x.get('href') is None
                        and x.get('disabled') == 'true' for x in planned), 'a planned section is a link or not disabled')
            active = pg.get('active') or []
            require([x.get('path') for x in active] == FINANCE_ACTIVE and all(x.get('uuid') == obs['company'] for x in active),
                    'active sections must be the canonical pages of company A: %r' % active)
            require([x.get('current') for x in active] == ['page'] + [None] * (len(FINANCE_ACTIVE) - 1),
                    'only Overview may be current in the section menu')
            if r['shell'] == 'owned':
                menu = [x for x in pg.get('menu') or [] if x.get('visible')]
                require(len(menu) == 1 and menu[0].get('uuid') == obs['company'] and menu[0].get('current') == 'page',
                        'exactly one current Finance item expected in the %s px owned menu: %r' % (r['width'], pg.get('menu')))

    def sections():
        rows, _ = run()
        landing = rows[0].get('landing')
        require(isinstance(landing, list) and [x.get('path') for x in landing] == FINANCE_ACTIVE,
                'section links not followed: %r' % landing)
        require(all(x.get('http') == 200 and not x.get('workspace_error') for x in landing),
                'a section page failed for MA: %r' % landing)

    def gate_and_isolation():
        _, model = run()
        e = obs.get('employee') or {}
        denied(e, 'employee without the financial gate')
        require(e.get('menu') == [] and e.get('menu_on_tasks') == [], 'Finance menu item shown to an employee')
        denied(obs.get('foreign') or {}, 'company B manager on company A')
        b = obs.get('own_b') or {}
        require(b.get('http') == 200 and b.get('workspace') is True and same(b, model['MB']) and b.get('leaked') == [],
                'company B workspace must show B figures only: %r' % ({k: b.get(k) for k in ('counts', 'plan', 'leaked')},))

    def revocation():
        run()
        rv = obs.get('revocation') or {}
        before, after = rv.get('before') or {}, rv.get('after') or {}
        require(before.get('workspace') is True and obs.get('revoked_flag') is True, 'revocation not applied after a baseline visit')
        denied(after, 'revoked member')
        require(obs.get('reopened_flag') is True, 'membership not reopened after the check')

    def layout():
        rows, _ = run()
        for r in rows:
            box = (r.get('page') or {}).get('hscroll')
            require(isinstance(box, dict) and isinstance(box.get('scroll'), int) and box.get('inner') == r['width']
                    and box['scroll'] <= box['inner'], 'finance page scrolls horizontally at %s px: %r' % (r['width'], box))
            violations = browser_event_violations(r.get('browser_events'))
            require(not violations, 'browser errors: %r' % violations[:3])
            require(r.get('server_errors') == [], 'HTTP 5xx: %r' % (r.get('server_errors'),))

    return {'finance_switch_off_hides_route_and_menu': switch_off, 'finance_figures_equal_service_ground_truth': figures,
            'finance_menu_and_planned_sections': navigation, 'finance_sections_open_canonical_pages': sections,
            'finance_gate_and_company_isolation': gate_and_isolation,
            'finance_revocation_closes_workspace': revocation, 'finance_layout_and_browser_errors': layout}

def required_check_names():
    """Every check a complete BROWSER report must contain (used by browser_acceptance.py and merge_gate.py)."""
    return (['moodle_install', 'fixtures_companies_A_B'] + list(task_tab_checks({})) + list(attachments_checks({}, {}))
            + list(access_checks({})) + list(oversize_checks({}, {})) + list(multiattachment_checks({}))
            + list(message_action_checks({})) + list(task_open_checks({}))
            + list(communication_center_checks({})) + list(daily_reports_nav_checks({}))
            + list(daily_reports_flow_checks({})) + list(plan_discussion_checks({})) + list(communication_ux_checks({}))
            + list(day_feed_checks({}))
            + list(infocenter_checks({}))
            + list(notification_bell_checks({}))
            + list(adaptive_nav_checks({}))
            + list(office_workspace_checks({}))
            + list(finance_workspace_checks({})))


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
