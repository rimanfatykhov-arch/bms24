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
EXT = c.EXPECTED_BLOCKED_EXTERNAL[0]
ABORTED = [{'source': 'route_abort', 'url': EXT, 'resource_type': 'script', 'reason': c.ABORT_REASON},
           {'source': 'requestfailed', 'url': EXT, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'},
           {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector', 'url': EXT}]
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
    def test_retained_center_file_acl_checks_exact_bytes_and_refusals(self):
        a=access();u=B % (999,'cc-012345abcdef.txt')
        a['expected_urls']=list(a['expected_urls'])+[u]
        digest=c.expected_sha('cc-012345abcdef.txt')
        for phase,rows in a['phases'].items():
            rows[u]={'http':200,'sha256':digest} if phase in ['ea_before','ea_reopened'] else (
                {'http':303,'location':'http://127.0.0.1:8080/login/index.php','sha256':None} if phase=='anon' else {'http':404,'sha256':None})
        self.assertEqual([],failed(c.evaluate(c.access_checks(a))))
        for phase,check in [('ea_before','download_participant_allowed'),('ea_reopened','reopen_in_open_session_allowed')]:
            bad=copy.deepcopy(a);bad['phases'][phase][u]['sha256']='0'*64
            self.assertEqual([check],failed(c.evaluate(c.access_checks(bad))))
        bad=copy.deepcopy(a);bad['phases']['eb'][u]['sha256']=digest
        self.assertIn('download_other_company_denied',failed(c.evaluate(c.access_checks(bad))))

    def test_multiattachments_fail_closed_on_missing_failure_or_forged_observations(self):
        def good():
            return {'status': 'PASS', 'cases': [
                {'width': width, 'status': 'PASS', 'selected_first': 2, 'selected_second': 3,
                 'post_count': 1, 'message_count': 1, 'removed_file_count': 0, 'browser_errors': [],
                 'negative_controls': [{'http': code, 'url': 'http://127.0.0.1:8080/local/kbacompany/tasks.php?bmsnegative=multi-test-%d' % code,
                                        'location': 'http://127.0.0.1:8080/__bms_negative_redirect_multi-test' if code == 302 else '',
                                        'redirect_requests': [],
                                        'text_retained': True, 'files_retained': 2,
                                        'error_visible': True} for code in (500, 302)],
                 'browser_events': [{'source': 'test_injected_response', 'method': 'POST', 'status': code,
                                     'location': 'http://127.0.0.1:8080/__bms_negative_redirect_multi-test' if code == 302 else '',
                                     'url': 'http://127.0.0.1:8080/local/kbacompany/tasks.php?bmsnegative=multi-test-%d' % code}
                                    for code in (500, 302)],
                 'downloads': [{'name': 'synthetic-%d.txt' % n, 'url': B % (n, 'synthetic-%d.txt' % n),
                                'http': 200, 'sha256': c.hashlib.sha256(c.MULTI_CONTENT[n]).hexdigest()}
                               for n in (1, 3)]} for width in (320, 1440)]}
        self.assertFalse(failed(c.evaluate(c.multiattachment_checks(good(), 0))))
        for obs, code in ((None, 0), ({}, 0), (good(), 1), (good(), None)):
            self.assertTrue(failed(c.evaluate(c.multiattachment_checks(obs, code))))
        for key, value in [('post_count', 2), ('message_count', 0), ('removed_file_count', 1),
                           ('browser_errors', ['HTTP 500']), ('selected_second', 1),
                           ('negative_controls', []), ('downloads', []), ('width', 1440)]:
            obs = good(); obs['cases'][0][key] = value
            self.assertTrue(failed(c.evaluate(c.multiattachment_checks(obs, 0))), key)
        for key, value in [('http', 302), ('sha256', 'bad'), ('name', 'wrong.txt'), ('url', 'https://example.invalid/file')]:
            obs = good(); obs['cases'][0]['downloads'][0][key] = value
            self.assertTrue(failed(c.evaluate(c.multiattachment_checks(obs, 0))), key)
        self.assertIn('multiattachment_complete', c.required_check_names())

    def test_multiattachment_errors_need_exact_injection_provenance(self):
        urls = {code: 'http://127.0.0.1:8080/local/kbacompany/tasks.php?bmsnegative=multi-test-%d' % code for code in (500, 302)}
        target = 'http://127.0.0.1:8080/__bms_negative_redirect_multi-test'
        controls = [{'http': code, 'url': urls[code], 'location': target if code == 302 else '',
                     'redirect_requests': []} for code in (500, 302)]
        markers = [{'source': 'test_injected_response', 'method': 'POST', 'status': code, 'url': urls[code],
                    'location': target if code == 302 else ''} for code in (500, 302)]
        consequences = [
            {'source': 'http_error', 'method': 'POST', 'status': 500, 'url': urls[500]},
            {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: the server responded with a status of 500 (Internal Server Error)', 'url': urls[500]},
            {'source': 'requestfailed', 'failure': 'net::ERR_FAILED', 'resource_type': 'fetch', 'url': urls[302]},
            {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_FAILED', 'url': urls[302]}]
        self.assertEqual([], c.multiattachment_event_violations(markers + consequences + ABORTED, controls))
        target_console = dict(consequences[3], url=target)
        self.assertEqual([], c.multiattachment_event_violations(markers + consequences[:3] + [target_console], controls))
        self.assertTrue(c.multiattachment_event_violations(markers + consequences + [target_console], controls))
        self.assertTrue(c.multiattachment_event_violations(markers + [dict(target_console, url='http://127.0.0.1:8080/login/index.php')], controls))
        followed = copy.deepcopy(controls); followed[1]['redirect_requests'] = [target]
        self.assertTrue(c.multiattachment_event_violations(markers + [target_console], followed))
        # Missing provenance, excess consequences, unrelated requests and programming errors all fail.
        for events in [consequences, markers + consequences + [consequences[2]], markers + [markers[0]],
                       markers + [{'source': 'pageerror', 'message': 'broken'}],
                       markers + [{'source': 'console', 'type': 'error', 'text': 'broken code', 'url': urls[500]}],
                       markers + [dict(consequences[2], url='http://127.0.0.1:8080/local/kbacompany/tasks.php')],
                       markers + [dict(consequences[2], resource_type='document')],
                       markers + [dict(consequences[0], status=503)],
                       markers + [dict(consequences[2], url=[])], None]:
            self.assertTrue(c.multiattachment_event_violations(events, controls))

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
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'},
                  {'source': 'requestfailed', 'url': 'http://127.0.0.1:9/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'},
                  {'source': 'requestfailed', 'url': 'http://localhost:8080/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'}):
            with self.subTest(e=e):
                self.assertTrue(self.violations([e]))
        # even if a (forged) abort record exists for a loopback URL, it is refused and the failure stays a violation
        for u in ('http://127.0.0.1:8080/x.js', 'http://127.0.0.1:9/x.js', 'http://[::1]:8080/x.js', 'http://localhost/x.js', 'http://0.0.0.0/x.js'):
            with self.subTest(u=u):
                forged = [{'source': 'route_abort', 'url': u, 'resource_type': 'script', 'reason': c.ABORT_REASON},
                          {'source': 'requestfailed', 'url': u, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'}]
                self.assertEqual(2, len(self.violations(forged, base=[])))

    def test_unexpected_external_err_failed_fails(self):
        for e in ({'source': 'requestfailed', 'url': 'https://evil.invalid/x.js', 'resource_type': 'script', 'failure': 'net::ERR_FAILED'},
                  {'source': 'requestfailed', 'url': 'https://evil.invalid/x.js', 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector', 'url': 'https://evil.invalid/x.js'},
                  {'source': 'requestfailed', 'url': EXT, 'resource_type': 'script', 'failure': 'net::ERR_FAILED'},
                  {'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_FAILED', 'url': EXT}):
            with self.subTest(e=e):
                self.assertTrue(self.violations([e]))

    def test_similar_url_to_an_allowed_abort_fails(self):
        for u in (EXT + '&x=1', EXT.split('?')[0], EXT + '#f', EXT.replace('https:', 'http:'), EXT.replace('cdn.jsdelivr.net', 'cdn.jsdelivr.net.evil.invalid'),
                  EXT + '/', EXT.upper(), 'https://cdn.jsdelivr.net/npm/mathjax@2.7.8/MathJax.js?delayStartupUntil=configured', ''):
            with self.subTest(u=u):
                self.assertTrue(self.violations([{'source': 'requestfailed', 'url': u, 'resource_type': 'script', 'failure': 'net::ERR_BLOCKED_BY_CLIENT.Inspector'}]))
                self.assertTrue(self.violations([{'source': 'console', 'type': 'error', 'text': 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector', 'url': u}]))

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

    def test_exact_codes_unexpected_external_and_excess_consequences_fail(self):
        # the plain code and prefixes/variants are not the observed Chromium value
        for f in ('net::ERR_BLOCKED_BY_CLIENT', 'net::ERR_BLOCKED_BY_CLIENT.Inspector ', 'net::ERR_BLOCKED_BY_CLIENT.InspectorX', 'net::ERR_ABORTED'):
            self.assertTrue(self.violations([], base=[ABORTED[0], dict(ABORTED[1], failure=f)]), f)
        for t in ('Failed to load resource: net::ERR_BLOCKED_BY_CLIENT', 'Failed to load resource: net::ERR_FAILED'):
            self.assertTrue(self.violations([], base=[ABORTED[0], dict(ABORTED[2], text=t)]), t)
        # an external URL the test aborted but which is not an expected dependency is itself a violation
        other = 'https://fonts.googleapis.com/css?family=x'
        evs = [{'source': 'route_abort', 'url': other, 'resource_type': 'stylesheet', 'reason': c.ABORT_REASON},
               {'source': 'requestfailed', 'url': other, 'resource_type': 'stylesheet', 'failure': c.ALLOWED_ABORT_FAILURE}]
        self.assertEqual(2, len(c.browser_event_violations(evs)))
        # more consequences than recorded aborts of that URL
        self.assertEqual(1, len(self.violations([ABORTED[1]])))
        self.assertEqual(1, len(self.violations([ABORTED[2]])))
        self.assertEqual([], self.violations([ABORTED[0], ABORTED[1], ABORTED[2]]))
        # the real shape observed in run 43571513719 (2 aborts + 2 + 2 consequences) is clean
        self.assertEqual([], c.browser_event_violations([ABORTED[0], ABORTED[0], ABORTED[1], ABORTED[2], ABORTED[1], ABORTED[2]]))
        self.assertEqual(('https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?delayStartupUntil=configured',), c.EXPECTED_BLOCKED_EXTERNAL)

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

    def message_actions_obs(self):
        def st(body, status='active', revisions=(), attachments=1, files=1):
            return {'body': body, 'status': status, 'thread_uuid': 'T', 'revisions': list(revisions),
                    'attachments': attachments, 'stored_files': files}
        rows = []
        for shell, width, mode, tab in c.MESSAGE_ACTION_CASES:
            t = ['%s-%d-%s' % (shell, width, n) for n in ('t0', 't1', 't2', 't3')]
            rows.append({'shell': shell, 'width': width, 'mode': mode, 'tab': tab, 'texts': t, 'file_sha256': 'x',
                         'posted': st(t[0]), 'after_cancel': st(t[0]),
                         'after_edit': dict(st(t[1], revisions=['edit']), task_text='A\n' + t[1] + '\nEdited', edited_marker=True,
                                            download_http=200, download_ok=True),
                         'communication_center': {'edited_text_count': 1, 'original_text_count': 0},
                         'conflict': dict(st(t[2], revisions=['edit', 'edit']), alert=['Message changed. Refresh and retry.'], draft_kept=True),
                         'unconfirmed_delete': dict(st(t[2], revisions=['edit', 'edit']), post_requests=0),
                         'after_withdraw': dict(st('', 'withdrawn', ['edit', 'edit', 'withdraw']), task_text='A\nMessage withdrawn.',
                                                withdrawn_marker=True, old_body_visible=False, file_link_count=0,
                                                old_file_http=404, old_file_leaked=False, controls_left=0),
                         'browser_events': list(ABORTED), 'server_errors': []})
        same = st('N', attachments=0, files=0)
        neg = {k: {'http': 200 if k in ('withdraw_without_confirmation', 'other_author_same_company') else 404,
                   'location': '', 'state': dict(same)} for k in c.MESSAGE_ACTION_NEGATIVES}
        redirect = {'http': 303, 'location': 'http://127.0.0.1:8080/local/kbacompany/tasks.php?uuid=C&task=T&view=detail&tab=discussion'}
        neg.update({'unchanged_before_valid': dict(same), 'target_text': 'N', 'replay_text': 'R', 'ea_text': 'E', 'reopened': True,
                    'replay': {'first': dict(redirect), 'again': dict(redirect), 'state': st('R', revisions=['edit'], attachments=0, files=0)},
                    'revoked_member': {'http': 404, 'location': '', 'revoked': True, 'state': st('E', attachments=0, files=0)}})
        return {'status': 'PASS', 'cases': rows, 'negatives': neg}

    def test_message_actions_valid_passes_and_mutations_fail(self):
        obs = self.message_actions_obs()
        res = c.evaluate(c.message_action_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate, exitcode=0):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.message_action_checks(o, exitcode))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        fails('message_edit_cancel_unchanged', lambda o: o['cases'][0]['after_cancel'].update(revisions=['edit']))
        fails('message_edit_saved_synced_with_files', lambda o: o['cases'][1]['after_edit'].update(edited_marker=False))
        fails('message_edit_saved_synced_with_files', lambda o: o['cases'][2]['after_edit'].update(stored_files=0))
        fails('message_edit_saved_synced_with_files', lambda o: o['cases'][3]['communication_center'].update(original_text_count=1))
        for count in (0, 2):
            fails('message_edit_saved_synced_with_files', lambda o, count=count: o['cases'][0]['communication_center'].update(edited_text_count=count))
        fails('message_edit_saved_synced_with_files', lambda o: o['cases'][0]['after_edit'].update(download_ok=False))
        fails('message_edit_conflict_keeps_newer_and_draft', lambda o: o['cases'][0]['conflict'].update(body=o['cases'][0]['texts'][3]))
        fails('message_edit_conflict_keeps_newer_and_draft', lambda o: o['cases'][1]['conflict'].update(draft_kept=False))
        fails('message_withdraw_requires_confirmation', lambda o: o['cases'][2]['unconfirmed_delete'].update(post_requests=1))
        fails('message_withdraw_hides_text_and_files', lambda o: o['cases'][3]['after_withdraw'].update(old_file_http=200, old_file_leaked=True))
        fails('message_withdraw_hides_text_and_files', lambda o: o['cases'][0]['after_withdraw'].update(file_link_count=1))
        fails('message_withdraw_hides_text_and_files', lambda o: o['cases'][0]['after_withdraw'].update(revisions=['withdraw']))
        fails('message_withdraw_hides_text_and_files', lambda o: o['cases'][1]['after_withdraw'].update(attachments=0))
        fails('message_actions_no_browser_errors', lambda o: o['cases'][0]['browser_events'].append(
            {'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails('message_actions_no_browser_errors', lambda o: o['cases'][0].pop('browser_events'))
        fails('message_actions_no_browser_errors', lambda o: o['cases'][2].update(server_errors=[{'status': 500}]))
        for key in c.MESSAGE_ACTION_NEGATIVES:
            fails('message_actions_server_negatives', lambda o, key=key: o['negatives'][key]['state'].update(body='forbidden'))
            fails('message_actions_server_negatives', lambda o, key=key: o['negatives'].pop(key))
            fails('message_actions_server_negatives', lambda o, key=key: o['negatives'][key].update(http=500))
            fails('message_actions_server_negatives', lambda o, key=key: o['negatives'][key].update(http=302, location='https://example.invalid/login'))
        for status in (500, 302, 200, None):
            fails('message_withdraw_hides_text_and_files', lambda o, status=status: o['cases'][0]['after_withdraw'].update(old_file_http=status))
            fails('message_actions_server_negatives', lambda o, status=status: o['negatives']['revoked_member'].update(http=status))
        for label in ('first', 'again'):
            fails('message_actions_server_negatives', lambda o, label=label: o['negatives']['replay'][label].update(http=500))
            fails('message_actions_server_negatives', lambda o, label=label: o['negatives']['replay'][label].update(location='https://example.invalid/login'))
            fails('message_actions_server_negatives', lambda o, label=label: o['negatives']['replay'][label].update(location='http://127.0.0.1:8080/login/index.php'))
        fails('message_actions_server_negatives', lambda o: o['negatives']['replay']['state'].update(revisions=['edit', 'edit']))
        fails('message_actions_server_negatives', lambda o: o['negatives']['revoked_member']['state'].update(revisions=['edit']))
        fails('message_actions_server_negatives', lambda o: o['negatives'].update(reopened=False))
        # incomplete matrix, wrong order, producer error or non-zero exit fail every check
        for mutate in (lambda o: o['cases'].pop(), lambda o: o['cases'].reverse(), lambda o: o['cases'][0].update(texts=['a'] * 4)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.message_action_checks(o, 0))
            self.assertTrue(all(v['status'] == 'FAIL' for k, v in r.items() if k != 'message_actions_server_negatives'), r)
        for mutate, code in ((lambda o: o.update(error='boom'), 0), (lambda o: o.update(status='FAIL'), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.message_action_checks(o, code))
            self.assertTrue(all(v['status'] == 'FAIL' for v in r.values()), r)
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.message_action_checks(None, None)).values()))

    def test_multiattachment_files_in_access_set_need_exact_content(self):
        ok = 'multi-' + 'a' * 32 + '-3.txt'
        self.assertEqual(c.expected_sha(ok), c.hashlib.sha256(c.MULTI_CONTENT[3]).hexdigest())
        for bad in ('multi-' + 'a' * 32 + '-4.txt', 'multi-' + 'A' * 32 + '-1.txt', 'multi-' + 'a' * 31 + '-1.txt',
                    'x-multi-' + 'a' * 32 + '-1.txt', 'multi-' + 'a' * 32 + '-1.txt.php', 'unknown.txt', '', None):
            self.assertIsNone(c.expected_sha(bad), bad)
        self.assertEqual(c.expected_sha('p02-compact.txt'), c.SHA['p02-compact.txt'])

    def task_open_obs(self):
        rows = []
        for shell, width, lang in c.TASK_OPEN_CASES:
            text = c.TASK_OPEN_TEXT[lang]
            snap = {'task': {'status': 'new', 'revision': '1'}, 'business_log': 1, 'notifications': 0}
            rows.append({'shell': shell, 'width': width, 'lang': lang,
                         'language': {'installed': True, 'sample': 'X ' + text}, 'list': {'marker': 0, 'rows': 0},
                         'first': {'status': '200', 'rows': 1},
                         'history': {'count': 1, 'visible': True, 'html_lang': lang, 'texts': ['First MA ' + text + '\n15.11.2026 10:00']},
                         'repeat': {'reload_status': '200', 'rows': 1},
                         'hidden': {'before_activation': 0, 'status': '200', 'after_activation': 1},
                         'interval': {'idle_rows': 1, 'status': '200', 'rows': 2},
                         'concurrent': {'replies': [{'http': 200, 'result': 'recorded'}] + [{'http': 200, 'result': 'duplicate'}] * 7,
                                        'rows': 3},
                         'business': {'before': snap, 'after': copy.deepcopy(snap)},
                         'row_shape': [{'actorid': 1, 'oldstatus': '', 'newstatus': '', 'details': '{}'}],
                         'browser_events': list(ABORTED), 'server_errors': []})
        neg = {k: {'http': code, 'rows': 0} for k, code in (('wrong_sesskey', 403), ('get', 405), ('head', 405),
                                                              ('substituted_task', 403), ('company_task_mismatch', 403))}
        neg.update({k: {'http': 403, 'rows': 0, 'marker': 0, 'rows_after_page': 0, 'page_http': 404}
                    for k in ('same_company_no_access', 'other_company')})
        neg.update({'user_substitution': {'http': 200, 'result': 'recorded', 'ok': True, 'rows': [{'actorid': 11}]},
                    'revoked_member': {'http': 403, 'rows': 0, 'revoked': True}, 'reopened': True})
        return {'status': 'PASS', 'cases': rows, 'negatives': neg, 'ids': {'MA': 11, 'EA': 12},
                'readable': {'MA': True, 'EA': True, 'RA': False, 'EB': False},
                'failure': {'status': 'failed', 'title_visible': True, 'tabs': 1, 'page_errors': [], 'rows': 0}}

    def test_task_open_valid_passes_and_mutations_fail(self):
        obs = self.task_open_obs()
        res = c.evaluate(c.task_open_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.task_open_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][0]['list'].update(rows=1))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][1]['first'].update(rows=2))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][0]['history'].update(count=2))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][0]['history'].update(texts=['First MA read the task']))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][1]['history'].update(texts=['Ivan прочитал задачу']))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][1]['history'].update(texts=['Ivan opened the task']))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][1]['history'].update(html_lang='en'))
        fails('task_open_recorded_once_in_history', lambda o: o['cases'][1]['language'].update(installed=False))
        fails('task_open_deduplicated_and_concurrent', lambda o: o['cases'][0]['repeat'].update(rows=2))
        fails('task_open_deduplicated_and_concurrent', lambda o: o['cases'][1]['interval'].update(idle_rows=2))
        fails('task_open_deduplicated_and_concurrent', lambda o: o['cases'][0]['interval'].update(rows=1))
        fails('task_open_deduplicated_and_concurrent', lambda o: o['cases'][0]['concurrent'].update(rows=4))
        fails('task_open_deduplicated_and_concurrent',
              lambda o: o['cases'][1]['concurrent']['replies'].__setitem__(1, {'http': 200, 'result': 'recorded'}))
        fails('task_open_deduplicated_and_concurrent', lambda o: o['cases'][1]['concurrent']['replies'].pop())
        fails('task_open_hidden_tab_and_other_user', lambda o: o['cases'][0]['hidden'].update(before_activation=1))
        fails('task_open_hidden_tab_and_other_user', lambda o: o['cases'][1]['hidden'].update(after_activation=0))
        fails('task_open_no_business_change', lambda o: o['cases'][0]['business']['after']['task'].update(status='inprogress'))
        fails('task_open_no_business_change', lambda o: o['cases'][1]['business']['after'].update(notifications=1))
        fails('task_open_no_business_change', lambda o: o['cases'][0]['row_shape'][0].update(details='{"ip":"x"}'))
        fails('task_open_failure_keeps_card', lambda o: o['failure'].update(page_errors=['TypeError']))
        fails('task_open_failure_keeps_card', lambda o: o['failure'].update(rows=1))
        fails('task_open_no_browser_errors',
              lambda o: o['cases'][0]['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails('task_open_no_browser_errors', lambda o: o['cases'][1].update(server_errors=[{'status': 500}]))
        for key in ('wrong_sesskey', 'get', 'head', 'substituted_task', 'company_task_mismatch',
                    'same_company_no_access', 'other_company'):
            fails('task_open_server_negatives', lambda o, key=key: o['negatives'][key].update(rows=1))
            fails('task_open_server_negatives', lambda o, key=key: o['negatives'].pop(key))
        fails('task_open_server_negatives', lambda o: o['negatives']['same_company_no_access'].update(marker=1))
        fails('task_open_server_negatives', lambda o: o['negatives']['user_substitution'].update(rows=[{'actorid': 12}]))
        fails('task_open_server_negatives', lambda o: o['negatives']['revoked_member'].update(http=200))
        fails('task_open_server_negatives', lambda o: o['negatives'].update(reopened=False))
        # incomplete matrix, wrong fixture access, producer error or non-zero exit fail every check
        for mutate, code in ((lambda o: o['cases'].pop(), 0), (lambda o: o['cases'].reverse(), 0),
                             (lambda o: o['readable'].update(RA=True), 0), (lambda o: o.update(error='boom'), 0),
                             (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.task_open_checks(o, code))
            self.assertTrue(all(v['status'] == 'FAIL' for v in r.values()), r)
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.task_open_checks(None, None)).values()))

    def communication_center_obs(self):
        ids = {'MA': 11, 'EA': 12, 'RA': 13, 'EB': 14}
        texts = {'permission': 'Permission denied.', 'context': 'Object unavailable.', 'company': 'Company unavailable.',
                 'state': 'Operation unavailable.', 'sesskey': 'Invalid sesskey.'}
        def denial(kind, http=200):
            return {'http': http, 'location': '',
                    'url': 'http://127.0.0.1:8080/local/kbacompany/communication_center.php',
                    'body_sha256': 'a' * 64, 'error_texts': [texts[kind]]}
        va, sha, q, d = 15, 'f' * 64, 'q-uuid', 'd-uuid'
        cases = []
        for n, (shell, width, mode) in enumerate(c.COMM_CASES):
            t = ['EA %d question' % n, 'MA %d answer @Synthetic EA' % n, 'MA %d group note' % n]
            viewer = {'card_question': True, 'card_reply': True, 'card_reader_note': True,
                      'download': {'http': 200, 'sha256': sha}, 'center_question': 1, 'center_join': 1,
                      'center_composer': 0, 'center_reply_links': 0,
                      'parts_after_reading': {'active': [11, 12], 'owners': [12], 'reads': [11, 12]}}
            if n == len(c.COMM_CASES) - 1:
                viewer.update(parts_after_join={'active': [11, 12, 15], 'owners': [12], 'reads': [11, 12, 15]},
                              composer_after_join=1)
            created = {'active': [11, 12, 13, 15], 'owners': [11], 'reads': [11, 12, 13, 15]}
            cases.append({
                'shell': shell, 'width': width, 'mode': mode, 'texts': t, 'file_sha256': sha, 'question': q,
                'reply_target': {'count': 1, 'text': 'Replying to: Synthetic EA ' + t[0]},
                'reply': {'body': t[1], 'status': 'active', 'thread': 'T', 'reply_to': q, 'authorid': 11, 'mentions': [12]},
                'mention_notification': ['mention'],
                'card': {'quote': ['Synthetic EA\n' + t[0]], 'events': ['Task created · Synthetic MA · 15.11 10:00'],
                         'messages_after': 8, 'messages_before': 7, 'history_opened': 1},
                'center': {'question': 1, 'quote': ['Synthetic EA\n' + t[0]], 'events': [],
                           'events_oldest': ['Task created · Synthetic MA · 10:00'],
                           'open_task': {'text': 'Open task', 'href': 'http://127.0.0.1:8080/local/kbacompany/tasks.php?uuid=A&task=T'},
                           'reply_links': 1, 'composer': 1},
                'viewer': viewer,
                'denied': {label: {'center_text': False, 'card_text': False, 'download': {'http': 404, 'sha256': None},
                                   'join_http': 200, 'join_response': denial('context' if label == 'RA' else 'company'),
                                   'parts': {'active': [11, 12], 'owners': [12], 'reads': []}}
                           for label in ('RA', 'EB')},
                'direct': {'responses': [{'http': 303, 'location': 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?thread='+d, 'thread': d}] * 2, 'threads': [d]},
                'filters': {'discussions': {'task': 1, 'direct': 0}, 'messages': {'task': 0, 'direct': 1}, 'tabs': 2},
                'group': {'created': created,
                          'non_owner_remove': dict(denial('permission'), parts=dict(created)),
                          'forged_sesskey': dict(denial('sesskey', 403), parts=dict(created)),
                          'leave_task_discussion': dict(denial('state'), parts={'active': [11, 12], 'owners': [12], 'reads': []}),
                          'remove_controls': 3, 'owner_marker': True,
                          'after_remove': {'active': [11, 12, 15], 'owners': [11], 'reads': []},
                          'after_owner_leave': {'active': [12, 15], 'owners': [12], 'reads': []},
                          'new_owner_controls': 1, 'access_after': {'MA': False, 'RA': False}},
                'browser_events': list(ABORTED), 'server_errors': []})
        ok = {'http': 200, 'sha256': sha}
        return {'status': 'PASS', 'ids': ids, 'viewer_id': va, 'cases': cases, 'denial_texts': texts,
                'revocation': {'before': {'text': True, 'download': ok}, 'revoked': True,
                               'after': {'text': False, 'download': {'http': 404, 'sha256': None}},
                               'reopened': True, 'restored': {'text': True, 'download': ok}}}

    def test_communication_center_valid_passes_and_mutations_fail(self):
        obs = self.communication_center_obs()
        res = c.evaluate(c.communication_center_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.communication_center_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        v = 'comm_task_discussion_read_by_task_viewer'
        fails(v, lambda o: o['cases'][0]['viewer'].update(card_question=False))
        fails(v, lambda o: o['cases'][1]['viewer'].update(card_reader_note=False))
        fails(v, lambda o: o['cases'][0]['viewer'].update(download={'http': 404, 'sha256': None}))
        fails(v, lambda o: o['cases'][0]['viewer'].update(download={'http': 200, 'sha256': 'e' * 64}))
        fails(v, lambda o: o['cases'][0]['viewer'].update(center_composer=1))
        fails(v, lambda o: o['cases'][0]['viewer'].update(center_join=0))
        fails(v, lambda o: o['cases'][1]['viewer'].update(center_reply_links=1))
        fails(v, lambda o: o['cases'][0]['viewer']['parts_after_reading']['active'].append(15))
        fails(v, lambda o: o['cases'][0]['viewer']['parts_after_reading']['reads'].append(15))
        fails(v, lambda o: o['cases'][1]['viewer']['parts_after_join']['active'].remove(15))
        fails(v, lambda o: o['cases'][1]['viewer'].pop('composer_after_join'))
        d = 'comm_task_discussion_denied_and_revoked'
        for label in ('RA', 'EB'):
            fails(d, lambda o, label=label: o['cases'][0]['denied'][label].update(center_text=True))
            fails(d, lambda o, label=label: o['cases'][1]['denied'][label].update(card_text=True))
            fails(d, lambda o, label=label: o['cases'][0]['denied'][label].update(download={'http': 200, 'sha256': 'f' * 64}))
            fails(d, lambda o, label=label: o['cases'][0]['denied'][label].update(download={'http': 500, 'sha256': None}))
            fails(d, lambda o, label=label: o['cases'][0]['denied'][label].update(join_http=303))
            fails(d, lambda o, label=label: o['cases'][0]['denied'][label]['parts']['active'].append(o['ids'][label]))
            fails(d, lambda o, label=label: o['cases'][0]['denied'].pop(label))
        fails(d, lambda o: o['revocation']['after'].update(text=True))
        fails(d, lambda o: o['revocation']['after'].update(download={'http': 200, 'sha256': 'f' * 64}))
        fails(d, lambda o: o['revocation'].update(revoked=False))
        fails(d, lambda o: o['revocation'].update(reopened=False))
        fails(d, lambda o: o['revocation']['restored'].update(text=False))
        fails(d, lambda o: o.pop('revocation'))
        e = 'comm_task_events_inline_opened_in_history'
        fails(e, lambda o: o['cases'][0]['card'].update(events=[]))
        fails(e, lambda o: o['cases'][1]['center'].update(events_oldest=[]))
        fails(e, lambda o: o['cases'][0]['card']['events'].append('Synthetic MA opened the task'))
        fails(e, lambda o: o['cases'][0]['center']['events'].append('Opened'))
        fails(e, lambda o: o['cases'][0]['card'].update(messages_after=9))
        fails(e, lambda o: o['cases'][0]['card'].update(history_opened=0))
        r = 'comm_reply_quote_and_mention'
        fails(r, lambda o: o['cases'][0]['reply_target'].update(count=0))
        fails(r, lambda o: o['cases'][0]['reply'].update(reply_to=''))
        fails(r, lambda o: o['cases'][1]['reply'].update(mentions=[12, 13]))
        fails(r, lambda o: o['cases'][1]['reply'].update(mentions=[]))
        fails(r, lambda o: o['cases'][0].update(mention_notification=['message']))
        fails(r, lambda o: o['cases'][0]['card'].update(quote=[]))
        fails(r, lambda o: o['cases'][1]['center'].update(quote=['other']))
        dc = 'comm_direct_conversation_unique'
        fails(dc, lambda o: o['cases'][0]['direct']['responses'].__setitem__(1, {'http': 303, 'thread': 'other'}))
        fails(dc, lambda o: o['cases'][0]['direct'].update(threads=['d-uuid', 'other']))
        fails(dc, lambda o: o['cases'][1]['direct'].update(responses=[{'http': 303, 'thread': 'x'}] * 2, threads=['x']))
        fails(dc, lambda o: o['cases'][0]['direct']['responses'].__setitem__(0, {'http': 200, 'thread': 'd-uuid'}))
        for location in ('https://example.invalid/?thread=d-uuid', 'http://127.0.0.1:8080/login/index.php?thread=d-uuid',
                         'http://127.0.0.1:8080/local/kbacompany/communication_center.php?thread=other', ''):
            fails(dc, lambda o, location=location: o['cases'][0]['direct']['responses'].__setitem__(0,
                {'http': 303, 'location': location, 'thread': 'd-uuid'}))
        g = 'comm_group_remove_leave_transfer'
        fails(g, lambda o: o['cases'][0]['group']['non_owner_remove']['parts'].update(active=[11, 12, 15]))
        fails(g, lambda o: o['cases'][0]['group']['forged_sesskey'].update(http=303))
        fails(g, lambda o: o['cases'][0]['group']['leave_task_discussion']['parts'].update(active=[12]))
        fails(g, lambda o: o['cases'][1]['group'].update(remove_controls=0))
        fails(g, lambda o: o['cases'][0]['group']['after_remove'].update(active=[11, 12, 13, 15]))
        fails(g, lambda o: o['cases'][0]['group']['after_owner_leave'].update(owners=[15]))
        fails(g, lambda o: o['cases'][0]['group']['after_owner_leave'].update(active=[11, 12, 15]))
        fails(g, lambda o: o['cases'][1]['group'].update(new_owner_controls=0))
        fails(g, lambda o: o['cases'][0]['group']['access_after'].update(RA=True))
        f = 'comm_filters_and_open_task'
        fails(f, lambda o: o['cases'][0]['filters'].update(discussions={'task': 1, 'direct': 1}))
        fails(f, lambda o: o['cases'][1]['filters'].update(messages={'task': 1, 'direct': 1}))
        fails(f, lambda o: o['cases'][0]['center'].update(open_task={'text': 'Open task', 'href': 'https://example.invalid/x'}))
        fails(f, lambda o: o['cases'][0]['center'].update(open_task={'text': '', 'href': ''}))
        fails(f, lambda o: o['cases'][0]['center'].update(composer=0))
        b = 'comm_no_browser_errors'
        fails(b, lambda o: o['cases'][0]['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(b, lambda o: o['cases'][1].update(server_errors=[{'status': 500}]))
        fails(b, lambda o: o['cases'][0].pop('browser_events'))
        # incomplete matrix, missing viewer, producer error or non-zero exit fail every check
        for mutate, code in ((lambda o: o['cases'].pop(), 0), (lambda o: o['cases'].reverse(), 0),
                             (lambda o: o.update(viewer_id=11), 0), (lambda o: o.update(error='boom'), 0),
                             (lambda o: o['cases'][0].update(question=''), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            res = c.evaluate(c.communication_center_checks(o, code))
            self.assertTrue(all(x['status'] == 'FAIL' for x in res.values()), res)
        self.assertTrue(all(x['status'] == 'FAIL' for x in c.evaluate(c.communication_center_checks(None, None)).values()))

    def test_comm_negative_posts_require_real_expected_denial(self):
        for case in range(2):
            for target in ('RA', 'EB', 'non_owner_remove', 'forged_sesskey', 'leave_task_discussion'):
                def item(obs):
                    row=obs['cases'][case]
                    return row['denied'][target]['join_response'] if target in ('RA', 'EB') else row['group'][target]
                key='comm_task_discussion_denied_and_revoked' if target in ('RA', 'EB') else 'comm_group_remove_leave_transfer'
                for status in (500, 502, 302, 303, 307, 0, None, True):
                    obs=self.communication_center_obs(); item(obs)['http']=status
                    if target in ('RA','EB'):obs['cases'][case]['denied'][target]['join_http']=status
                    self.assertEqual('FAIL',c.evaluate(c.communication_center_checks(obs,0))[key]['status'],(target,status))
                for missing in ('http','location','url','body_sha256','error_texts'):
                    obs=self.communication_center_obs();item(obs).pop(missing)
                    self.assertEqual('FAIL',c.evaluate(c.communication_center_checks(obs,0))[key]['status'],(target,missing))
                for field,value in [('location','https://example.invalid/login'),('url','https://example.invalid/'),
                                    ('body_sha256',''),('error_texts',[]),('error_texts',['A different error']),
                                    ('error_texts',['Please sign in'])]:
                    obs=self.communication_center_obs();item(obs)[field]=value
                    self.assertEqual('FAIL',c.evaluate(c.communication_center_checks(obs,0))[key]['status'],(target,field,value))
        obs=self.communication_center_obs();obs.pop('denial_texts')
        res=c.evaluate(c.communication_center_checks(obs,0))
        for key in ('comm_task_discussion_denied_and_revoked','comm_group_remove_leave_transfer'):
            self.assertEqual('FAIL',res[key]['status'])
        obs=self.communication_center_obs()
        obs['cases'][0]['denied']['RA']['join_response']['error_texts']=[obs['denial_texts']['permission']]
        self.assertEqual('FAIL',c.evaluate(c.communication_center_checks(obs,0))['comm_task_discussion_denied_and_revoked']['status'])

    def daily_obs(self):
        company = 'a1b2c3d4-0000-4000-8000-00000000000a'
        order = [{'path': p, 'current': None} for p in ('bms_workspace.php', 'calendar.php', 'tasks.php')] + \
            [{'path': 'daily_report.php', 'current': 'page'}] + \
            [{'path': p, 'current': None} for p in ('recurring_tasks.php', 'battle_plan.php', 'communication_center.php')]
        cases = []
        for shell, width, lang in c.DAILY_REPORT_CASES:
            n = c.DAILY_REPORT_LABELS[lang]
            link = {'path': c.DAILY_REPORT_PATH, 'uuid': company, 'params': ['uuid'], 'visible': True}
            mobile = width < 861
            menu = {'present': False}
            if shell == 'owned':
                menu = {'present': True, 'scope_visible': True, 'items': 1, 'visible_items': 1, 'order': copy.deepcopy(order),
                        'accessible_full': 1, 'item': dict(link, text=n['nav_short'] if mobile else n['nav'], aria_current='page',
                                                           full_box={'w': 1, 'h': 1} if mobile else {'w': 105, 'h': 18},
                                                           short_box={'w': 51, 'h': 18} if mobile else {'w': 0, 'h': 0},
                                                           aria_snapshot='- link "%s":' % n['nav'],
                                                           full_span={'text': n['nav'], 'aria_hidden': False,
                                                                      'display': 'block', 'visibility': 'visible'},
                                                           short_span={'text': '', 'label': n['nav_short'], 'aria_hidden': True,
                                                                       'display': 'block' if mobile else 'none',
                                                                       'visibility': 'visible'})}
            box = {'scroll': width, 'inner': width}
            cases.append({'shell': shell, 'width': width, 'lang': lang, 'language_installed': True,
                          'tasks': {'http': 200, 'buttons': 1, 'hscroll': dict(box), 'button': dict(
                              link, text=n['button'] if width >= 576 else n['button_short'], aria_label=n['button'],
                              title=n['button'], accessible=1, aria_snapshot='- link "%s"' % n['button'])},
                          'clicked_path': c.DAILY_REPORT_PATH,
                          'report': {'http': 200, 'path': c.DAILY_REPORT_PATH, 'title': n['nav'] + ' | BMS', 'h1': [n['nav']],
                                     'current_tabs': [n['tab_my']], 'current_tab_paths': [c.DAILY_REPORT_PATH],
                                     'owned_shell': shell == 'owned', 'hscroll': dict(box), 'menu': menu,
                                     'hscroll_after_menu': dict(box)},
                          'browser_events': [], 'server_errors': []})
        return {'status': 'PASS', 'company': company, 'cases': cases, 'foreign': {'http': 200, 'tabs': 0, 'day_form': 0}}

    def test_daily_reports_nav_genuine_run_passes(self):
        res = c.evaluate(c.daily_reports_nav_checks(self.daily_obs(), 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()})
        self.assertEqual(7, len(res))
        for k in res:
            self.assertIn(k, c.required_check_names())

    def test_daily_reports_nav_regressions_fail(self):
        def owned_mobile(o):
            return o['cases'][1]

        def swap_order(o):
            m = o['cases'][0]['report']['menu']['order']
            m[2], m[3] = m[3], m[2]

        mutations = [
            ('daily_reports_menu_after_tasks_and_current', swap_order),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['order'][2].update(current='page')),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item'].update(params=['userid', 'uuid'])),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item'].update(uuid='other-company-uuid')),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: owned_mobile(o)['report']['menu']['item'].update(aria_snapshot='- link "Отчёты":')),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item'].update(aria_snapshot=None)),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: owned_mobile(o)['report']['menu']['item']['full_span'].update(aria_hidden=True)),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: owned_mobile(o)['report']['menu']['item']['full_span'].update(display='none')),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item']['short_span'].update(aria_hidden=False)),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item']['short_span'].update(text='Reports')),
            ('daily_reports_menu_after_tasks_and_current',
             lambda o: o['cases'][0]['report']['menu']['item'].update(aria_snapshot='- link "Daily reportsReports": Daily reports')),
            ('daily_reports_menu_full_and_short_labels',
             lambda o: owned_mobile(o)['report']['menu']['item'].update(text=c.DAILY_REPORT_LABELS['ru']['nav'])),
            ('daily_reports_menu_full_and_short_labels',
             lambda o: owned_mobile(o)['report']['menu']['item'].update(full_box={'w': 0, 'h': 0}, short_box={'w': 0, 'h': 0})),
            ('daily_reports_page_url_title_and_tab', lambda o: o['cases'][2]['report'].update(current_tabs=[])),
            ('daily_reports_page_url_title_and_tab', lambda o: o['cases'][3]['report'].update(h1=['Сводка моего дня'])),
            ('daily_reports_task_center_button', lambda o: o['cases'][3]['tasks']['button'].update(text='Отчёт за день')),
            ('daily_reports_task_center_button', lambda o: o['cases'][0]['tasks']['button'].update(aria_label=None)),
            ('daily_reports_task_center_button', lambda o: o['cases'][3]['tasks']['button'].update(aria_snapshot='- link "Отчёт"')),
            ('daily_reports_task_center_button', lambda o: o['cases'][2].update(clicked_path='/local/kbacompany/tasks.php')),
            ('daily_reports_task_center_button', lambda o: o['cases'][1]['tasks'].update(buttons=2)),
            ('daily_reports_no_horizontal_overflow', lambda o: o['cases'][3]['report']['hscroll'].update(scroll=420)),
            ('daily_reports_no_horizontal_overflow', lambda o: o['cases'][1]['report'].update(hscroll_after_menu=None)),
            ('daily_reports_foreign_company_denied', lambda o: o['foreign'].update(tabs=1)),
            ('daily_reports_foreign_company_denied', lambda o: o['foreign'].update(http=500)),
            ('daily_reports_no_browser_errors', lambda o: o['cases'][0]['browser_events'].append(
                {'source': 'pageerror', 'name': 'TypeError', 'message': 'x'})),
            ('daily_reports_no_browser_errors', lambda o: o['cases'][2]['server_errors'].append({'url': 'u', 'status': 500})),
        ]
        for name, mutate in mutations:
            obs = self.daily_obs()
            mutate(obs)
            with self.subTest(check=name, mutation=mutate):
                self.assertIn(name, failed(c.evaluate(c.daily_reports_nav_checks(obs, 0))))

    def test_aria_link_named_is_exact(self):
        self.assertTrue(c.aria_link_named('- link "Daily reports":\n  - /url: http://127.0.0.1:8080/x', 'Daily reports'))
        self.assertTrue(c.aria_link_named('- link "Daily reports" [current]', 'Daily reports'))
        for snap in [None, '', '- link "Daily reports Reports"', '- button "Daily reports"', '- link "Daily report"',
                     '- text: Daily reports']:
            with self.subTest(snap=snap):
                self.assertFalse(c.aria_link_named(snap, 'Daily reports'))

    def test_daily_reports_nav_incomplete_runs_fail_every_check(self):
        missing_case = self.daily_obs()
        missing_case['cases'].pop()
        wrong_shell = self.daily_obs()
        wrong_shell['cases'][2]['report']['owned_shell'] = True
        for obs, code in [(self.daily_obs(), 1), (None, None), (missing_case, 0), (wrong_shell, 0),
                          (dict(self.daily_obs(), error='boom'), 0), (dict(self.daily_obs(), company=''), 0)]:
            with self.subTest(code=code):
                res = c.evaluate(c.daily_reports_nav_checks(obs, code))
                self.assertTrue(all(v['status'] == 'FAIL' for v in res.values()))

    def daily_flow_obs(self):
        MA, EA = 11, 12
        rows = []
        for (shell, width), today, day in zip(c.DAILY_REPORTS_FLOW_CASES, (True, False), ('2026-11-16', '2026-11-15')):
            t = {k: 'DR %s %s' % (shell, k) for k in ('draft', 'tab1', 'tab2', 'submit', 'rework', 'forged')}
            v1 = {'uuid': 'v1-' + shell, 'no': 1, 'state': 'submitted', 'done': t['submit'], 'reviewerid': MA,
                  'snapshot_sha': 's', 'contenthash': 'h', 'snapshot_has_marker': today}
            v2 = dict(v1, uuid='v2-' + shell, no=2, done=t['rework'])
            notes = [{'userid': MA, 'kind': 'dailyreport_submitted', 'title': 'Report ' + day}] * 2 + \
                    [{'userid': EA, 'kind': 'dailyreport_decision', 'title': 'Decision ' + day}] * 2
            accepted = [{'versionuuid': v1['uuid'], 'decision': 'returned', 'rating': ''},
                        {'versionuuid': v2['uuid'], 'decision': 'accepted', 'rating': 'positive'}]
            row = {'shell': shell, 'width': width, 'day': day, 'today': today, 'texts': t, 'ids': {'MA': MA, 'EA': EA},
                   'relogin_draft': t['draft'], 'hscroll_form': True, 'hscroll_card': True,
                   'conflict': {'shown': 1, 'tab2_kept': True, 'tab1_saved': True},
                   'submit_http': [303] * 8, 'forged_http': [200], 'get_http': 200,
                   'after_submit': {'report': {'status': 'submitted'}, 'versions': [v1], 'decisions': []},
                   'after_forged': {'report': {'status': 'submitted'}, 'versions': [v1], 'decisions': []},
                   'return_http': [303] * 8, 'accept_http': [303] * 8, 'accept_version': v2['uuid'],
                   'after_return': {'report': {'status': 'returned'}, 'versions': [v1], 'decisions': accepted[:1]},
                   'after_rework': {'report': {'status': 'submitted'}, 'versions': [v1, v2], 'decisions': accepted[:1]},
                   'after_accept': {'report': {'status': 'accepted'}, 'versions': [v1, v2], 'decisions': accepted,
                                    'notifications': notes},
                   'after_negatives': {'report': {'status': 'accepted'}, 'versions': [v1, v2], 'decisions': copy.deepcopy(accepted)},
                   'negatives': {'EB': {'http': [200]}, 'RA': {'http': [200]}}, 'notification_links': 1,
                   'browser_events': list(ABORTED), 'server_errors': []}
            if today:
                row['after_touch'] = {'report': {'status': 'submitted'}, 'versions': [dict(v1)], 'decisions': []}
            rows.append(row)
        return {'status': 'PASS', 'setup': {'enabled': True}, 'cases': rows}

    def test_daily_reports_flow_valid_passes_and_mutations_fail(self):
        obs = self.daily_flow_obs()
        res = c.evaluate(c.daily_reports_flow_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.daily_reports_flow_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        fails('daily_report_draft_relogin_and_conflict', lambda o: o['cases'][0].update(relogin_draft=''))
        fails('daily_report_draft_relogin_and_conflict', lambda o: o['cases'][1]['conflict'].update(tab2_kept=False))
        fails('daily_report_concurrent_submit_one_version', lambda o: o['cases'][0]['after_submit']['versions'].append(
            dict(o['cases'][0]['after_submit']['versions'][0], uuid='dup')))
        fails('daily_report_concurrent_submit_one_version', lambda o: o['cases'][1].update(submit_http=[303] * 7 + [500]))
        fails('daily_report_concurrent_submit_one_version', lambda o: o['cases'][0]['after_submit']['versions'][0].update(reviewerid=0))
        fails('daily_report_csrf_and_get_rejected', lambda o: o['cases'][0].update(forged_http=[303]))
        fails('daily_report_csrf_and_get_rejected', lambda o: o['cases'][1]['after_forged']['versions'][0].update(
            done=o['cases'][1]['texts']['forged']))
        fails('daily_report_snapshot_immutable', lambda o: o['cases'][0]['after_touch']['versions'][0].update(snapshot_sha='changed'))
        fails('daily_report_snapshot_immutable', lambda o: o['cases'][0]['after_submit']['versions'][0].update(snapshot_has_marker=False))
        fails('daily_report_return_rework_accept_once', lambda o: o['cases'][0]['after_return']['decisions'].append(
            dict(o['cases'][0]['after_return']['decisions'][0])))
        fails('daily_report_return_rework_accept_once', lambda o: o['cases'][1].update(accept_version='v1-legacy'))
        fails('daily_report_return_rework_accept_once', lambda o: o['cases'][0]['after_accept']['decisions'][1].update(
            versionuuid='v1-owned'))
        fails('daily_report_return_rework_accept_once', lambda o: o['cases'][1]['after_rework']['versions'][0].update(done='rewritten'))
        fails('daily_report_notifications_once_with_link', lambda o: o['cases'][0]['after_accept']['notifications'].append(
            {'userid': 11, 'kind': 'dailyreport_submitted', 'title': 'Report 2026-11-16'}))
        fails('daily_report_notifications_once_with_link', lambda o: o['cases'][1]['after_accept']['notifications'].__setitem__(
            0, {'userid': 99, 'kind': 'dailyreport_submitted', 'title': 'Report 2026-11-15'}))
        fails('daily_report_notifications_once_with_link', lambda o: o['cases'][0].update(notification_links=0))
        fails('daily_report_foreign_and_nonreviewer_denied', lambda o: o['cases'][0]['after_negatives']['decisions'].append(
            {'versionuuid': 'v2-owned', 'decision': 'accepted', 'rating': 'negative'}))
        fails('daily_report_foreign_and_nonreviewer_denied', lambda o: o['cases'][1]['negatives']['RA'].update(http=[500]))
        fails('daily_report_layout_and_browser_errors', lambda o: o['cases'][1].update(hscroll_form=False))
        fails('daily_report_layout_and_browser_errors', lambda o: o['cases'][0]['browser_events'].append(
            {'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        for mutate, code in ((lambda o: o['cases'].pop(), 0), (lambda o: o['cases'].reverse(), 0),
                             (lambda o: o['setup'].update(enabled=False), 0), (lambda o: o.update(error='boom'), 0),
                             (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.daily_reports_flow_checks(o, code))
            self.assertTrue(all(v['status'] == 'FAIL' for v in r.values()), r)
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.daily_reports_flow_checks(None, None)).values()))

    def test_required_check_names_complete(self):
        names = c.required_check_names()
        self.assertEqual(len(names), len(set(names)))
        for k in ('access_no_browser_errors', 'attachments_no_browser_errors', 'oversize_no_browser_errors', 'task_tab_run_complete', 'task_tab_mobile_tap_target_44', 'csrf_invalid_sesskey_rejected', 'revocation_preserves_history',
                  'download_other_company_denied', 'revoke_in_open_session_denied', 'oversize_nothing_stored', 'moodle_install',
                  'message_withdraw_hides_text_and_files', 'message_actions_server_negatives',
                  'task_open_recorded_once_in_history', 'task_open_server_negatives'):
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
