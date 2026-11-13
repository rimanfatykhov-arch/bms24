"""BROWSER tier contract (RC32, Issue #82): fail-closed evaluation of browser/HTTP observations.

Pure functions, no browser/DB/network. `require()` raises RuntimeError (never `assert`), so verdicts are identical under
`python -O`. Every check needs positive evidence: missing, empty, unexpected or error observations FAIL.
Note: the loopback check below constrains what the test scripts request; it is NOT OS-level network isolation.
"""
import hashlib
from urllib.parse import urlparse

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


def require(condition, message='BROWSER condition failed'):
    if not condition:
        raise RuntimeError(message)


def local_url(url):
    """Allow only the exact disposable loopback origin; refuse before any request is made."""
    p = urlparse(url)
    require(p.scheme == ORIGIN_SCHEME and p.netloc == ORIGIN_NETLOC and not p.username and not p.password
            and p.hostname == '127.0.0.1' and p.port == 8080, 'Non-loopback request refused: %r' % url)
    return url


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
            require(_step(obs, 'server_file_only_direct').get('http') in LOGIN_REDIRECT_CODES and
                    '/local/kbacompany/communication_center.php' in (_step(obs, 'server_file_only_direct').get('location') or ''),
                    'direct POST not redirected back to the thread'),
            _rendered(_step(obs, 'server_file_only_direct'), 'p02-direct-only.txt')),
        'double_submit_creates_one_message': lambda: (
            require(isinstance(_step(obs, 'double_submit').get('center_posts'), int) and _step(obs, 'double_submit')['center_posts'] >= 1,
                    'double submit not performed'),
            require(body_count('RC31 P02 double ' + phase) == 1, 'double submit did not create exactly one message')),
        'csrf_invalid_sesskey_rejected': lambda: (
            require(_step(obs, 'csrf_invalid_sesskey').get('http') is not None, 'forged request not sent'),
            require(body_count('RC31 P02 csrf ' + phase) == 0, 'forged request created a message')),
        'no_orphan_files': lambda: (_step(obs, 'double_submit'), require(isinstance(msgs, list) and msgs, 'DB state missing'),
                                    require((state or {}).get('orphan_files') == 0, 'orphan files present')),
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
                require((r.get('location') or '').startswith('http://127.0.0.1:8080/login/index.php'), 'anonymous redirect is not to login')

    def session_reused():
        logins = acc.get('logins') or {}
        require(logins.get('EA') == 1, 'EA must log in exactly once across before/revoked/reopened')
        require(acc.get('ea_context_id_before') and acc.get('ea_context_id_before') == acc.get('ea_context_id_after'),
                'EA phases did not reuse the same browser context')

    def history_preserved():
        h = acc.get('history') or {}
        require(isinstance(h.get('before'), int) and h.get('before') > 0 and h.get('before') == h.get('after_revoke') == h.get('after_reopen'),
                'message history changed across revoke/reopen')

    return {
        'download_participant_allowed': lambda: allowed('ea_before'),
        'download_other_company_denied': lambda: denied('eb', DENY_CODES),
        'download_anonymous_redirected_to_login': lambda: denied('anon', LOGIN_REDIRECT_CODES),
        'revoke_in_open_session_denied': lambda: (session_reused(), require(acc.get('revoked_flag') is True, 'revocation not applied'),
                                                  denied('ea_revoked', DENY_CODES)),
        'reopen_in_open_session_allowed': lambda: (session_reused(), require(acc.get('reopened_flag') is True, 'reopen not applied'),
                                                   allowed('ea_reopened')),
        'revocation_preserves_history': history_preserved,
    }


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
    }


def task_tab_checks(obs):
    cases = {c.get('id', '').split('.')[-1]: c.get('status') for c in (obs or {}).get('cases', [])}
    required = ['page_and_tabs', 'keyboard_reaches_tab', 'focus_ring_visible', 'focus_ring_contrast_3to1', 'second_tab_focus_visible',
                'enter_activates_tab', 'active_tab_distinct', 'mouse_click_navigates_without_ring', 'desktop_tabs_not_enlarged',
                'mobile_focus_ring', 'mobile_no_page_hscroll', 'mobile_tap_target_44']
    return {'task_tab_' + k: (lambda k=k: require(cases.get(k) == 'PASS', 'task tab case %s: %r' % (k, cases.get(k)))) for k in required}


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
