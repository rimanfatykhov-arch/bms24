#!/usr/bin/env python3
"""False-PASS regression tests for the BROWSER contract (Issue #82 review of PR #91). Run with `python` and `python -O`."""
import copy
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import contract as c  # noqa: E402

B = 'http://127.0.0.1:8080/pluginfile.php/1/local_kbacompany/communication_attachment/%d/%s'
URLS = [B % (i, n) for i, n in enumerate(c.FILES, start=10)]


# A genuine intentional abort as produced by events.Recorder (route abort + its two expected consequences).
EXT = 'https://cdn.jsdelivr.net/npm/example@1.0.0/x.js'
ABORTED = [{'source': 'route_abort', 'url': EXT, 'resource_type': 'script', 'reason': c.ABORT_REASON},
           {'source': 'requestfailed', 'url': EXT, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'},
           {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT', 'url': EXT}]
DIRECT_LOC = 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T'


def snapshot(mutate=None):
    rows = {'local_kbacompany_commmsg': {'m-1': 'h1', 'm-2': 'h2', 'm-3': 'h3'}, 'local_kbacompany_commattach': {'a-1': 'ha'},
            'local_kbacompany_commrev': [], 'files:communication_attachment': {'file9': 'hf'}}
    if mutate:
        mutate(rows)
    return {'tables': list(c.HISTORY_TABLES), 'rows': rows, 'digest': c.history_digest(rows)}


def ok_dl(name):
    return {name: {'http': 200, 'sha256': c.SHA[name]}}


def attachments():
    st = {'compact_controls': {'file_inputs': 1},
          'compact_text_and_file': {'rendered_text_exact': 'RC31 P02 compact text verify', 'file_link_text': 'p02-compact.txt', 'downloads': ok_dl('p02-compact.txt')},
          'compact_file_only': {'file_link_text': 'p02-compact-only.txt', 'downloads': ok_dl('p02-compact-only.txt')},
          'full_text_only': {'rendered_text_exact': 'RC31 P02 full text only verify'},
          'full_text_and_file': {'filename_feedback': 'p02-text-and-file.txt · 36 B', 'rendered_text_exact': 'RC31 P02 full text and file verify',
                                 'file_link_text': 'p02-text-and-file.txt', 'downloads': ok_dl('p02-text-and-file.txt')},
          'full_file_only': {'file_link_text': 'p02-file-only.txt', 'downloads': ok_dl('p02-file-only.txt')},
          'server_file_only_direct': {'http': 303, 'location': DIRECT_LOC, 'expected_query': {'uuid': 'A', 'tab': 'discussions', 'thread': 'T'},
                                      'file_link_text': 'p02-direct-only.txt', 'downloads': ok_dl('p02-direct-only.txt')},
          'csrf_invalid_sesskey': {'http': 404, 'refusal_marker': True}, 'double_submit': {'center_posts': 1}}
    state = {'messages': [{'body': 'RC31 P02 double verify'}, {'body': 'x'}], 'orphan_files': 0}
    return {'phase': 'verify', 'steps': st, 'browser_events': list(ABORTED)}, state


def access():
    def rows(kind):
        out = {}
        for u in URLS:
            n = u.split('/')[-1]
            out[u] = {'http': 200, 'sha256': c.SHA[n]} if kind == 'ok' else (
                {'http': 303, 'location': 'http://127.0.0.1:8080/login/index.php', 'sha256': None} if kind == 'login' else {'http': 404, 'sha256': None})
        return out
    return {'expected_urls': URLS, 'logins': {'EA': 1, 'EB': 1}, 'ea_context_id_before': 7, 'ea_context_id_after': 7,
            'revoked_flag': True, 'reopened_flag': True, 'history': {'before': snapshot(), 'after_revoke': snapshot(), 'after_reopen': snapshot()},
            'phases': {'ea_before': rows('ok'), 'eb': rows('deny'), 'anon': rows('login'), 'ea_revoked': rows('deny'), 'ea_reopened': rows('ok')},
            'browser_events': {'EA': list(ABORTED), 'EB': [], 'anon': []}}


def oversize():
    return ({'steps': {'limits': {'form_maxbytes': 65536}, 'compact_oversize': {'request_sent': True, 'error_text': 'The file exceeds the permitted upload size.'},
                       'full_oversize_client': {'post_sent': False, 'file_validation': 'The file exceeds the permitted upload size.'},
                       'full_oversize_server_direct': {'http': 200, 'error_in_page': True, 'sent_bytes': 102400}}, 'browser_events': list(ABORTED)},
            {'messages': [{'body': 'x'}], 'orphan_files': 0})


def tabs(status='PASS', drop=None):
    return {'status': status, 'cases': [{'id': 'P01.ci.' + n, 'status': status} for n in c.TASK_TAB_CASES if n != drop],
            'browser_events': {'desktop': list(ABORTED), 'mobile': list(ABORTED)}}


def failed(results):
    return sorted(k for k, v in results.items() if v['status'] != 'PASS')


class ContractTest(unittest.TestCase):
    def test_complete_valid_observations_pass(self):
        o, s = attachments(); self.assertEqual([], failed(c.evaluate(c.attachments_checks(o, s))))
        self.assertEqual([], failed(c.evaluate(c.access_checks(access()))))
        o, s = oversize(); self.assertEqual([], failed(c.evaluate(c.oversize_checks(o, s))))
        self.assertEqual([], failed(c.evaluate(c.task_tab_checks(tabs()))))

    def test_missing_or_empty_observations_never_pass(self):
        for res in [c.evaluate(c.attachments_checks({}, {})), c.evaluate(c.attachments_checks(None, None)),
                    c.evaluate(c.access_checks({})), c.evaluate(c.access_checks(None)),
                    c.evaluate(c.oversize_checks({}, {})), c.evaluate(c.task_tab_checks({})), c.evaluate(c.task_tab_checks(None))]:
            self.assertTrue(res)
            self.assertEqual([], [k for k, v in res.items() if v['status'] == 'PASS'])

    def test_execution_error_fails_everything(self):
        o, s = attachments(); o['execution_error'] = 'TimeoutError'
        self.assertEqual(sorted(c.attachments_checks(o, s)), failed(c.evaluate(c.attachments_checks(o, s))))

    def test_review_mutations_text_filename_direct(self):
        cases = [('compact_text_and_file', 'rendered_text_exact', None, 'compact_text_and_file'),
                 ('full_text_and_file', 'rendered_text_exact', None, 'full_text_and_file_with_filename_feedback'),
                 ('full_text_and_file', 'file_link_text', 'other.txt', 'full_text_and_file_with_filename_feedback'),
                 ('full_text_and_file', 'filename_feedback', 'something', 'full_text_and_file_with_filename_feedback'),
                 ('full_text_only', 'rendered_text_exact', None, 'full_text_only'),
                 ('compact_file_only', 'downloads', {}, 'compact_file_only')]
        for step, key, val, check in cases:
            with self.subTest(step=step, key=key):
                o, s = attachments(); o['steps'][step][key] = val
                self.assertIn(check, failed(c.evaluate(c.attachments_checks(o, s))))

    def test_direct_302_to_login_without_new_file_fails(self):
        o, s = attachments()
        o['steps']['server_file_only_direct'] = {'http': 303, 'location': 'http://127.0.0.1:8080/login/index.php'}
        self.assertIn('direct_multipart_new_file_stored_and_downloadable', failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); o['steps']['server_file_only_direct']['downloads'] = {'p02-direct-only.txt': {'http': 200, 'sha256': c.SHA['p02-file-only.txt']}}
        self.assertIn('direct_multipart_new_file_stored_and_downloadable', failed(c.evaluate(c.attachments_checks(o, s))))

    def test_wrong_bytes_and_csrf_and_double_submit(self):
        o, s = attachments(); o['steps']['compact_text_and_file']['downloads']['p02-compact.txt']['sha256'] = '0' * 64
        self.assertIn('compact_text_and_file', failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); s['messages'].append({'body': 'RC31 P02 csrf verify'})
        self.assertIn('csrf_invalid_sesskey_rejected', failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); del o['steps']['csrf_invalid_sesskey']['http']
        self.assertIn('csrf_invalid_sesskey_rejected', failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); s['messages'].append({'body': 'RC31 P02 double verify'})
        self.assertIn('double_submit_creates_one_message', failed(c.evaluate(c.attachments_checks(o, s))))

    def test_acl_http500_empty_and_redirect_mutations(self):
        muts = {
            'download_other_company_denied': lambda a: a['phases'].__setitem__('eb', {}),
            'download_anonymous_redirected_to_login': lambda a: [r.update(http=500) for r in a['phases']['anon'].values()],
            'revoke_in_open_session_denied': lambda a: [r.update(http=500) for r in a['phases']['ea_revoked'].values()],
        }
        for check, mutate in muts.items():
            with self.subTest(check=check):
                a = access(); mutate(a); self.assertIn(check, failed(c.evaluate(c.access_checks(a))))
        a = access(); [r.update(location='http://127.0.0.1:8080/my/') for r in a['phases']['anon'].values()]
        self.assertIn('download_anonymous_redirected_to_login', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['phases']['eb'].popitem()
        self.assertIn('download_other_company_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); first = next(iter(a['phases']['eb'])); a['phases']['eb'][first] = {'http': 200, 'sha256': c.SHA[first.split('/')[-1]]}
        self.assertIn('download_other_company_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['expected_urls'] = []
        independent = ('revocation_preserves_history', 'access_no_browser_errors')  # do not depend on the URL set
        self.assertEqual(len(c.access_checks(a)) - len(independent), len([k for k in failed(c.evaluate(c.access_checks(a))) if k not in independent]))

    def test_session_must_be_reused_and_revocation_applied(self):
        a = access(); a['logins']['EA'] = 3
        self.assertIn('revoke_in_open_session_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['ea_context_id_after'] = 8
        self.assertIn('reopen_in_open_session_allowed', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['revoked_flag'] = False
        self.assertIn('revoke_in_open_session_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['history']['after_revoke'] = 5
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))

    # ---------------- RC32 independent review (Issue #82 comment 6872172812) ----------------
    def test_history_content_change_with_same_row_count_fails(self):
        def edit(rows): rows['local_kbacompany_commmsg']['m-2'] = 'changed'
        a = access(); a['history']['after_revoke'] = snapshot(edit)
        self.assertEqual(3, len(a['history']['after_revoke']['rows']['local_kbacompany_commmsg']))  # same count
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['history']['after_reopen'] = snapshot(lambda r: r['files:communication_attachment'].update(file9='other'))
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['history']['after_reopen'] = snapshot(lambda r: r.__setitem__('local_kbacompany_commrev', {'rev1': 'x'}))
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))
        # a producer cannot claim a matching digest for different rows
        a = access(); forged = snapshot(edit); forged['digest'] = snapshot()['digest']; a['history']['after_revoke'] = forged
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))
        # incomplete coverage
        a = access(); s = snapshot(); s['tables'] = s['tables'][:1]; a['history']['before'] = s
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))
        self.assertEqual((), c.HISTORY_EXPECTED_CHANGES)

    def test_csrf_server_error_or_missing_refusal_fails(self):
        for http, marker in ((500, True), (502, False), (200, True), (303, True), (404, False), (None, True)):
            with self.subTest(http=http, marker=marker):
                o, s = attachments(); o['steps']['csrf_invalid_sesskey'] = {'http': http, 'refusal_marker': marker}
                self.assertIn('csrf_invalid_sesskey_rejected', failed(c.evaluate(c.attachments_checks(o, s))))

    def test_unexpected_redirects_fail(self):
        for loc in ['http://127.0.0.1:8080/login/index.php.evil', 'http://127.0.0.1:8080/login/index.phpx', 'https://evil.invalid/login/index.php',
                    'http://127.0.0.1:8080/login/index.php?next=//evil.invalid', 'http://127.0.0.1:8080/login/index.php#x',
                    'http://127.0.0.1:8080.evil.invalid/login/index.php', 'http://u:p@127.0.0.1:8080/login/index.php', '/login/index.php', '']:
            with self.subTest(loc=loc):
                a = access(); [r.update(location=loc) for r in a['phases']['anon'].values()]
                self.assertIn('download_anonymous_redirected_to_login', failed(c.evaluate(c.access_checks(a))))
        for loc in ['https://evil.invalid/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T',
                    'http://127.0.0.1:8080/evil/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T',
                    'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=B&tab=discussions&thread=T',
                    'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T&next=x',
                    'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&uuid=A&tab=discussions&thread=T',
                    'http://127.0.0.1:8080/local/kbacompany/communication_center.php']:
            with self.subTest(loc=loc):
                o, s = attachments(); o['steps']['server_file_only_direct']['location'] = loc
                self.assertIn('direct_multipart_new_file_stored_and_downloadable', failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); del o['steps']['server_file_only_direct']['expected_query']
        self.assertIn('direct_multipart_new_file_stored_and_downloadable', failed(c.evaluate(c.attachments_checks(o, s))))
        self.assertEqual(c.exact_redirect('http://127.0.0.1:8080/login/index.php', c.LOGIN_PATH), 'http://127.0.0.1:8080/login/index.php')

    def test_task_tab_errors_execution_and_run_status_fail_everything(self):
        cases = {
            'run status FAIL': lambda t: t.__setitem__('status', 'FAIL'),
            'desktop browser error': lambda t: [x.update(status='FAIL') for x in t['cases'] if x['id'].endswith('desktop_no_browser_errors')],
            'mobile browser error': lambda t: [x.update(status='FAIL') for x in t['cases'] if x['id'].endswith('mobile_no_browser_errors')],
            'execution error row': lambda t: t['cases'].append({'id': 'P01.ci.execution', 'status': 'FAIL'}),
            'missing errors case': lambda t: t.__setitem__('cases', [x for x in t['cases'] if not x['id'].endswith('mobile_no_browser_errors')]),
            'duplicate case': lambda t: t['cases'].append(dict(t['cases'][0])),
            'other phase': lambda t: [x.update(id=x['id'].replace('P01.ci.', 'P01.baseline.')) for x in t['cases']],
            'status missing': lambda t: t.pop('status'),
        }
        for label, mutate in cases.items():
            with self.subTest(label):
                t = tabs(); mutate(t)
                res = c.evaluate(c.task_tab_checks(t))
                self.assertIn('task_tab_run_complete', failed(res))
                self.assertEqual(sorted(res), failed(res), label)  # every task tab check fails, none passes on its own
        self.assertIn('task_tab_desktop_no_browser_errors', c.task_tab_checks({}))
        self.assertIn('task_tab_mobile_no_browser_errors', c.task_tab_checks({}))

    # ---- Codex review of PR #96 (comment 6873684005): ERR_FAILED blanket exception, provenance of intentional aborts
    def violations(self, extra, base=None):
        return c.browser_event_violations(list(ABORTED if base is None else base) + list(extra))

    def test_genuine_intentional_abort_is_the_only_tolerated_error(self):
        self.assertEqual([], self.violations([]))
        self.assertEqual([], c.browser_event_violations([]))
        o, s = attachments(); self.assertEqual([], failed(c.evaluate(c.attachments_checks(o, s))))

    def test_local_err_failed_fails(self):
        for e in ({'source': 'console', 'type': 'error', 'text': 'net::ERR_FAILED http://127.0.0.1:8080/x.js', 'url': 'http://127.0.0.1:8080/x.js'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_FAILED', 'url': 'http://127.0.0.1:8080/x.js'},
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_FAILED'},
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x.js', 'resource_type': 'fetch', 'failure': 'net::ERR_ABORTED'},
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'},
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:9/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'},
                  {'source': 'requestfailed', 'url': 'http://localhost:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'}):
            with self.subTest(e=e):
                self.assertTrue(self.violations([e]))
        # even if a (forged) abort record exists for a loopback URL, it is refused and the failure stays a violation
        for u in ('http://127.0.0.1:8080/x.js', 'http://127.0.0.1:9/x.js', 'http://[::1]:8080/x.js', 'http://localhost/x.js', 'http://0.0.0.0/x.js'):
            with self.subTest(u=u):
                forged = [{'source': 'route_abort', 'url': u, 'resource_type': 'script', 'reason': c.ABORT_REASON},
                          {'source': 'requestfailed', 'url': u, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'}]
                self.assertEqual(2, len(self.violations(forged, base=[])))

    def test_unexpected_external_err_failed_fails(self):
        for e in ({'source': 'requestfailed', 'url': 'https://evil.invalid/x.js', 'resource_type': 'script', 'failure': 'net::ERR_FAILED'},
                  {'source': 'requestfailed', 'url': 'https://evil.invalid/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT', 'url': 'https://evil.invalid/x.js'},
                  {'source': 'requestfailed', 'url': EXT, 'resource_type': 'script', 'failure': 'net::ERR_FAILED'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_FAILED', 'url': EXT}):
            with self.subTest(e=e):
                self.assertTrue(self.violations([e]))

    def test_similar_url_to_an_allowed_abort_fails(self):
        for u in (EXT + '?x=1', EXT + '#f', EXT.replace('https:', 'http:'), EXT.replace('cdn.jsdelivr.net', 'cdn.jsdelivr.net.evil.invalid'),
                  EXT + '/', EXT.upper(), 'https://cdn.jsdelivr.net/npm/example@1.0.0/y.js', ''):
            with self.subTest(u=u):
                self.assertTrue(self.violations([{'source': 'requestfailed', 'url': u, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT'}]))
                self.assertTrue(self.violations([{'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT', 'url': u}]))

    def test_missing_or_contradictory_abort_provenance_fails(self):
        fail_only = [ABORTED[1], ABORTED[2]]  # consequences without the route abort record
        self.assertEqual(2, len(c.browser_event_violations(fail_only)))
        for bad_abort in (dict(ABORTED[0], reason='cdn'), dict(ABORTED[0], reason=None), dict(ABORTED[0], resource_type=''),
                          dict(ABORTED[0], url=None), dict(ABORTED[0], url='ftp://cdn.jsdelivr.net/x.js'), {'source': 'route_abort'}):
            with self.subTest(bad_abort=bad_abort):
                self.assertTrue(c.browser_event_violations([bad_abort] + fail_only))
        for evs in (None, 'x', {'a': 1}):
            self.assertTrue(c.browser_event_violations(evs))
        for legacy in ('console:Failed to load resource: net::ERR_FAILED', 'pageerror:TypeError', 1, None):
            self.assertTrue(self.violations([legacy]))
        self.assertTrue(self.violations([{'source': 'navigation', 'url': EXT}]))

    def test_console_error_and_pageerror_fail(self):
        for e in ({'source': 'pageerror', 'name': 'TypeError', 'message': 'x'},
                  {'source': 'console', 'type': 'error', 'text': 'Uncaught ReferenceError: M is not defined', 'url': 'http://127.0.0.1:8080/lib/x.js'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: the server responded with a status of 404 (Not Found)',
                   'url': 'http://127.0.0.1:8080/x.js'}):
            with self.subTest(e=e):
                self.assertTrue(self.violations([e]))

    def test_scenarios_evaluate_events_not_producer_status(self):
        bad = {'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_FAILED'}
        o, s = attachments(); o['browser_events'] = o['browser_events'] + [bad]
        self.assertEqual(['attachments_no_browser_errors'], failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = oversize(); o['browser_events'] = o['browser_events'] + [bad]
        self.assertEqual(['oversize_no_browser_errors'], failed(c.evaluate(c.oversize_checks(o, s))))
        a = access(); a['browser_events']['EB'] = [bad]
        self.assertEqual(['access_no_browser_errors'], failed(c.evaluate(c.access_checks(a))))
        for vp in ('desktop', 'mobile'):
            t = tabs(); t['browser_events'][vp] = t['browser_events'][vp] + [bad]   # producer case still says PASS
            self.assertEqual(['task_tab_%s_no_browser_errors' % vp], failed(c.evaluate(c.task_tab_checks(t))), vp)
        for mutate in (lambda o: o.pop('browser_events'), lambda o: o.__setitem__('browser_events', None), lambda o: o.__setitem__('page_errors', [])):
            o, s = attachments(); mutate(o)
            if 'browser_events' in o and o['browser_events'] is not None:
                continue
            self.assertIn('attachments_no_browser_errors', failed(c.evaluate(c.attachments_checks(o, s))))
        a = access(); del a['browser_events']['anon']
        self.assertIn('access_no_browser_errors', failed(c.evaluate(c.access_checks(a))))
        t = tabs(); del t['browser_events']['mobile']
        self.assertIn('task_tab_mobile_no_browser_errors', failed(c.evaluate(c.task_tab_checks(t))))

    def test_review_probe_and_never_raises(self):
        # the exact probe of the review: legacy string list in the old key is not evidence of a clean page
        self.assertIn('attachments_no_browser_errors', failed(c.evaluate({'k': c.page_error_check(
            {'page_errors': ['console:net::ERR_FAILED http://127.0.0.1:8080/x.js']}, 'probe')} and
            c.attachments_checks({'steps': attachments()[0]['steps'], 'page_errors': ['console:net::ERR_FAILED http://127.0.0.1:8080/x.js']}, attachments()[1]))))
        self.assertEqual('FAIL', c.evaluate({'k': c.page_error_check({'page_errors': ['console:net::ERR_FAILED http://127.0.0.1:8080/x.js']}, 'probe')})['k']['status'])
        for garbage in ([{'source': 'requestfailed', 'url': 5}], [{'source': 'route_abort', 'url': 5}], [{}], [[]]):
            self.assertTrue(c.browser_event_violations(garbage))

    def test_required_check_names_complete(self):
        names = c.required_check_names()
        self.assertEqual(len(names), len(set(names)))
        for k in ('access_no_browser_errors', 'attachments_no_browser_errors', 'oversize_no_browser_errors', 'task_tab_run_complete', 'task_tab_mobile_tap_target_44', 'csrf_invalid_sesskey_rejected', 'revocation_preserves_history',
                  'download_other_company_denied', 'revoke_in_open_session_denied', 'oversize_nothing_stored', 'moodle_install'):
            self.assertIn(k, names)

    def test_oversize_requires_sent_request_effective_limit_and_nothing_stored(self):
        for step, key, val, check in [('compact_oversize', 'request_sent', False, 'oversize_compact_request_sent_and_refused'),
                                      ('limits', 'form_maxbytes', 209715200, 'oversize_php_limit_effective'),
                                      ('full_oversize_client', 'post_sent', True, 'oversize_full_blocked_in_browser'),
                                      ('full_oversize_server_direct', 'error_in_page', False, 'oversize_direct_request_sent_and_refused')]:
            with self.subTest(key=key):
                o, s = oversize(); o['steps'][step][key] = val
                self.assertIn(check, failed(c.evaluate(c.oversize_checks(o, s))))
        o, s = oversize(); s['messages'].append({'body': 'RC31 P02 oversize2 compact'})
        self.assertIn('oversize_nothing_stored', failed(c.evaluate(c.oversize_checks(o, s))))

    def test_mobile_44_must_run(self):
        self.assertIn('task_tab_mobile_tap_target_44', failed(c.evaluate(c.task_tab_checks(tabs(drop='mobile_tap_target_44')))))
        self.assertTrue(failed(c.evaluate(c.task_tab_checks(tabs(status='FAIL')))))

    def test_origin_refused_before_request(self):
        self.assertEqual('http://127.0.0.1:8080/x?a=b', c.local_url('http://127.0.0.1:8080/x?a=b'))
        for url in ['https://127.0.0.1:8080/x', 'http://example.invalid/x', 'http://127.0.0.1:8080.example.invalid/x', '//127.0.0.1:8080/x',
                    'http://user:pass@127.0.0.1:8080/x', 'file:///tmp/x', 'http://127.0.0.1:8081/x', 'http://localhost:8080/x']:
            with self.subTest(url=url), self.assertRaises(RuntimeError):
                c.local_url(url)

    def test_require_is_not_an_assert(self):
        with self.assertRaises(RuntimeError):
            c.require(False, 'negative control')
        self.assertEqual('optimized' if not __debug__ else 'normal', 'optimized' if sys.flags.optimize else 'normal')


if __name__ == '__main__':
    unittest.main(verbosity=1)
