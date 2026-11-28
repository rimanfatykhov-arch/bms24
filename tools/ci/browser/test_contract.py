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
DIRECT_LOC = 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T&filter=all'
DIRECT_LOC_PRE_UX1 = 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T'


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
                                      'ux_filters': 1,
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



PD_REASONS = {'plan_unavailable': 'Battle Plan or item is unavailable.',
              'company_unavailable': 'The company is unavailable or outside your access scope.',
              'thread_permission': 'This object or discussion is unavailable.',
              'invalid_sesskey': 'Your session has most likely timed out. Please log in again.'}
PD_BACK = 'http://127.0.0.1:8080/local/kbacompany/battle_plan.php?uuid=A&plan=P&view=detail&tab=discussion'


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
        # Accepted UX-1 schema: exactly uuid/tab=discussions/thread + filter=all; before UX-1 (replay baselines) no filter.
        direct = 'direct_multipart_new_file_stored_and_downloadable'
        o, s = attachments(); o['steps']['server_file_only_direct'].update(ux_filters=0, location=DIRECT_LOC_PRE_UX1)
        self.assertNotIn(direct, failed(c.evaluate(c.attachments_checks(o, s))))
        for change in ({'location': DIRECT_LOC_PRE_UX1}, {'ux_filters': 0}, {'location': DIRECT_LOC.replace('all', 'unread')},
                       {'location': DIRECT_LOC + '&filter=all'}, {'location': DIRECT_LOC + '&q=x'}, {'ux_filters': None},
                       {'ux_filters': True}, {'ux_filters': 2},
                       {'expected_query': {'uuid': 'A', 'tab': 'discussions', 'thread': 'T', 'filter': 'all'}},
                       {'expected_query': {'uuid': 'A', 'tab': 'discussions', 'thread': 'T', 'next': 'x'}},
                       {'expected_query': {'uuid': 'A', 'tab': 'messages', 'thread': 'T'}},
                       {'expected_query': {'uuid': '', 'tab': 'discussions', 'thread': 'T'}},
                       {'location': 'https://evil.invalid/local/kbacompany/communication_center.php?uuid=A&tab=discussions&thread=T&filter=all'}):
            with self.subTest(change=change):
                o, s = attachments(); o['steps']['server_file_only_direct'].update(change)
                self.assertIn(direct, failed(c.evaluate(c.attachments_checks(o, s))))
        o, s = attachments(); del o['steps']['server_file_only_direct']['ux_filters']
        self.assertIn(direct, failed(c.evaluate(c.attachments_checks(o, s))))
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
        MA, EA, RA, EB = 11, 12, 13, 14
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
            row = {'shell': shell, 'width': width, 'day': day, 'today': today, 'texts': t,
                   'ids': {'MA': MA, 'EA': EA, 'RA': RA, 'EB': EB},
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
                   'browser_events': list(ABORTED), 'server_errors': [],
                   'team': {'rows': [[EA, 'accepted'], [MA, 'none'], [RA, 'none']],
                            'counts': {'none': 2, 'notsent': 0, 'submitted': 0, 'returned': 0, 'accepted': 1},
                            'filters': 1, 'report_links': 1, 'hscroll': True},
                   'team_employee_filters': 0,
                   'team_scopes': {
                       'team': {'rows': [[EA, 'accepted']], 'options': ['team', 'company'], 'selected': 'team',
                                'draft_leak': False, 'hscroll': True},
                       'company': {'rows': [[EA, 'accepted'], [MA, 'none'], [RA, 'notsent']], 'options': ['team', 'company'],
                                   'selected': 'company', 'draft_leak': False, 'hscroll': True}},
                   'team_open': {'link': 1, 'url_report': True, 'versions': 2, 'draft_leak': False},
                   'summary': {'month': day[:7], 'filters': 1, 'users_all': [EA, MA, RA], 'cell_links': 1,
                               'cell': ['accepted', 'positive'], 'draft_leak': False, 'prev_month': '2026-10',
                               'users_mine': [EA], 'scope_kept': 'mine', 'hscroll': True,
                               'card': {'url_report': True, 'versions': 2}},
                   'release_denied': {'EA': {'summary': 0, 'summary_tab': 0},
                                      'EB_team': {'http': 200, 'team': 0, 'summary': 0, 'versions': 0, 'leak': False},
                                      'EB_summary': {'http': 200, 'team': 0, 'summary': 0, 'versions': 0, 'leak': False},
                                      'EB_card': {'http': 404, 'team': 0, 'summary': 0, 'versions': 0, 'leak': False}}}
            d = {k: 'DR %s discussion %s' % (shell, k) for k in ('author', 'reviewer', 'forged', 'RA', 'EB')}
            row.update({'discussion_texts': d, 'discussion_author_composer': 1, 'discussion_reviewer_sections': 1,
                        'discussion_reviewer_sees_author': True, 'discussion_injected_tags': 0,
                        'discussion_forged_http': [200], 'hscroll_discussion': True,
                        'discussion_negatives': {'EB': {'http': [200]}, 'RA': {'http': [303]}},
                        'after_discussion': {'report': {'status': 'accepted'}, 'versions': [v1, v2],
                                             'decisions': copy.deepcopy(accepted),
                                             'discussion': [{'thread': 't-' + shell, 'authorid': EA, 'body': d['author']},
                                                            {'thread': 't-' + shell, 'authorid': MA, 'body': d['reviewer']}]}})
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
        # R4 team for the day
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team'].update(rows=[]))
        fails('daily_report_team_day_view', lambda o: o['cases'][1]['team']['rows'].append([12, 'accepted']))
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team']['rows'].__setitem__(0, [12, 'submitted']))
        fails('daily_report_team_day_view', lambda o: o['cases'][1]['team']['rows'].append([14, 'none']))
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team']['rows'].pop())
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team']['rows'].__setitem__(1, [11, 'missing']))
        fails('daily_report_team_day_view', lambda o: o['cases'][1]['team']['counts'].update(none=5))
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team']['counts'].pop('notsent'))
        fails('daily_report_team_day_view', lambda o: o['cases'][1]['team'].update(filters=0))
        fails('daily_report_team_day_view', lambda o: o['cases'][0]['team'].update(report_links=0))
        fails('daily_report_team_day_view', lambda o: o['cases'][1]['team'].update(hscroll=False))
        fails('daily_report_team_day_view', lambda o: o['cases'][0].update(team_employee_filters=1))
        fails('daily_report_team_day_view', lambda o: o['cases'][1].pop('team'))
        # Release #168 + #173: scopes, opening a report, monthly summary, access and draft privacy
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][0]['team_scopes']['team'].update(options=['company']))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][1]['team_scopes']['company'].update(selected='team'))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][0]['team_scopes']['team']['rows'].append([14, 'none']))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][1]['team_scopes']['team'].update(rows=[]))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][0]['team_scopes']['company']['rows'].pop())
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][1]['team_scopes']['company'].update(draft_leak=True))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][0]['team_open'].update(url_report=False))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][1]['team_open'].update(draft_leak=True))
        fails('daily_report_team_scopes_and_open', lambda o: o['cases'][0].pop('team_scopes'))
        fails('daily_report_monthly_summary', lambda o: o['cases'][0]['summary'].update(month='2026-10'))
        fails('daily_report_monthly_summary', lambda o: o['cases'][1]['summary'].update(prev_month='2026-11'))
        fails('daily_report_monthly_summary', lambda o: o['cases'][0]['summary']['users_all'].append(14))
        fails('daily_report_monthly_summary', lambda o: o['cases'][1]['summary'].update(users_mine=[13]))
        fails('daily_report_monthly_summary', lambda o: o['cases'][0]['summary'].update(scope_kept='all'))
        fails('daily_report_monthly_summary', lambda o: o['cases'][1]['summary'].update(cell=['submitted', 'none']))
        fails('daily_report_monthly_summary', lambda o: o['cases'][0]['summary'].update(cell_links=0))
        fails('daily_report_monthly_summary', lambda o: o['cases'][1]['summary'].pop('card'))
        fails('daily_report_monthly_summary', lambda o: o['cases'][0]['summary'].update(draft_leak=True))
        fails('daily_report_monthly_summary', lambda o: o['cases'][1]['summary'].update(filters=0))
        fails('daily_report_release_access_denied', lambda o: o['cases'][0]['release_denied']['EA'].update(summary=1))
        fails('daily_report_release_access_denied', lambda o: o['cases'][1]['release_denied']['EA'].update(summary_tab=1))
        fails('daily_report_release_access_denied', lambda o: o['cases'][0]['release_denied']['EB_team'].update(team=2))
        fails('daily_report_release_access_denied', lambda o: o['cases'][1]['release_denied']['EB_summary'].update(summary=1))
        fails('daily_report_release_access_denied', lambda o: o['cases'][0]['release_denied']['EB_card'].update(versions=1))
        fails('daily_report_release_access_denied', lambda o: o['cases'][1]['release_denied']['EB_card'].update(http=500))
        fails('daily_report_release_access_denied', lambda o: o['cases'][0]['release_denied']['EB_summary'].update(leak=True))
        # R3 report discussion
        disc = 'daily_report_discussion_author_and_reviewer'
        fails(disc, lambda o: o['cases'][0].update(discussion_author_composer=0))
        fails(disc, lambda o: o['cases'][1].update(discussion_reviewer_sees_author=False))
        fails(disc, lambda o: o['cases'][0].update(discussion_reviewer_sections=2))
        fails(disc, lambda o: o['cases'][1].update(discussion_injected_tags=1))
        fails(disc, lambda o: o['cases'][0].update(discussion_forged_http=[303]))
        fails(disc, lambda o: o['cases'][1].update(discussion_forged_http=[500]))
        fails(disc, lambda o: o['cases'][0]['discussion_negatives']['RA'].update(http=[500]))
        fails(disc, lambda o: o['cases'][1]['discussion_negatives'].pop('EB'))
        fails(disc, lambda o: o['cases'][0]['after_discussion']['discussion'].append(
            {'thread': 't-owned', 'authorid': 13, 'body': o['cases'][0]['discussion_texts']['RA']}))
        fails(disc, lambda o: o['cases'][1]['after_discussion']['discussion'].append(
            {'thread': 't-legacy', 'authorid': 12, 'body': o['cases'][1]['discussion_texts']['forged']}))
        fails(disc, lambda o: o['cases'][0]['after_discussion']['discussion'].append(
            dict(o['cases'][0]['after_discussion']['discussion'][1])))
        fails(disc, lambda o: o['cases'][1]['after_discussion']['discussion'][1].update(thread='t-other'))
        fails(disc, lambda o: o['cases'][0]['after_discussion']['discussion'].reverse())
        fails(disc, lambda o: o['cases'][1]['after_discussion'].update(discussion=[]))
        fails(disc, lambda o: o['cases'][0]['after_discussion']['report'].update(status='returned'))
        fails(disc, lambda o: o['cases'][1]['after_discussion']['decisions'].append(
            {'versionuuid': 'v2-legacy', 'decision': 'accepted', 'rating': 'negative'}))
        fails(disc, lambda o: o['cases'][0].update(hscroll_discussion=False))
        fails(disc, lambda o: o['cases'][1].pop('discussion_texts'))
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

    @staticmethod
    def day_feed_obs():
        rows = []
        for shell, width in c.DAY_FEED_CASES:
            tasks = [{'task': 't%d-%s' % (n, shell), 'event': 100 + n, 'title': 'Task %d' % n, 'result': 'Result %d %s' % (n, shell)}
                     for n in (1, 2)]
            e1, e2 = 101, 102
            pre, edit = 'Hand text ' + shell, 'Unsaved edit ' + shell
            b1, b2 = ['«%s»: %s' % (t['title'], t['result']) for t in tasks]
            first = pre + '\n' + b1; second = first + '\n' + edit + '\n' + b2
            links = sorted(t['task'] for t in tasks)
            day, empty = '2026-11-17', '2026-10-14'
            label = {'visible': 'Added earlier', 'hint': 'This result was already added to the report for this day.', 'shown': True,
                     'hint_hidden': True}

            def m(d, add, added):
                return {'day': d, 'add': add, 'added': added, 'labels': [dict(label) for _ in added]}
            rows.append({
                'shell': shell, 'width': width, 'day': day, 'today': '2026-11-23', 'empty_day': empty,
                'tasks': tasks, 'pre': pre, 'edit': edit, 'default_day': '2026-11-23', 'tab_active': 1,
                'items': [{'id': t['event'], 'time': '1%d:00' % n, 'title': t['title'], 'text': t['title'] + '\n' + t['result'],
                           'task_href': 'http://127.0.0.1:8080/local/kbacompany/tasks.php?uuid=A&task=' + t['task'], 'add': 1,
                           'added': 0} for n, t in enumerate(tasks)],
                'empty': {'items': 0, 'state': 1},
                'landing': {'url': 'http://127.0.0.1:8080/local/kbacompany/daily_report.php?uuid=A&day=%s&notice=resultadded' % day,
                            'done': first, 'notice': 1},
                'after_add': {'report': {'status': 'draft'}, 'draft': {'done': first, 'revision': 2, 'links': [tasks[0]['task']]},
                              'sent': [], 'added_events': [e1]},
                'feed_after_first': m(day, [e2], [e1]), 'feed_after_reload': m(day, [e2], [e1]), 'hscroll_marks': True,
                'report_link': {'url': 'http://127.0.0.1:8080/local/kbacompany/daily_report.php?uuid=A&day=' + day, 'done': first},
                'feed_other_day': m(empty, [], []), 'feed_back_to_day': m(day, [e2], [e1]),
                'after_repeat_form': {'report': {'status': 'draft'}, 'draft': {'done': first + '\n' + edit, 'revision': 3,
                                      'links': [tasks[0]['task']]}, 'sent': [], 'added_events': [e1]},
                'after_second': {'report': {'status': 'draft'}, 'draft': {'done': second, 'revision': 4, 'links': links}, 'sent': [],
                                 'added_events': [e1, e2]},
                'feed_after_second': m(day, [], [e1, e2]), 'repeat_http': [303, 303], 'unavailable_http': 200,
                'after_repeat_feed': {'report': {'status': 'draft'}, 'draft': {'done': second, 'revision': 4, 'links': links},
                                      'sent': [], 'added_events': [e1, e2]},
                'after_negatives': {'report': {'status': 'draft'}, 'draft': {'done': second, 'revision': 4, 'links': links}, 'sent': []},
                'after_submit': {'report': {'status': 'submitted'}, 'draft': None,
                                 'sent': [{'done': second, 'revision': 4, 'links': links}]},
                'after_submit_feed': dict(m(day, [], [e1, e2]), items=2, sent_note=1),
                'forged_http': 200, 'foreign_http': {'company_a': 200, 'company_b': 200}, 'foreign_feed_items': 0,
                'hscroll_feed': True, 'hscroll_report': True, 'browser_events': list(ABORTED), 'server_errors': []})
        return {'status': 'PASS', 'cases': rows}

    def test_day_feed_valid_passes_and_mutations_fail(self):
        obs = self.day_feed_obs()
        res = c.evaluate(c.day_feed_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)
        self.assertTrue(set(res) <= set(c.required_check_names()))
        self.assertIn('day_feed_added_earlier_marks_reload_date_and_link', res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.day_feed_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        view, add, ae, dup, iso, send = ('day_feed_today_date_and_empty_day', 'day_feed_add_keeps_text_and_links_task',
                                         'day_feed_added_earlier_marks_reload_date_and_link',
                                         'day_feed_repeated_click_adds_nothing', 'day_feed_foreign_and_forged_denied',
                                         'day_feed_send_layout_and_browser_errors')
        fails(view, lambda o: o['cases'][0].update(default_day='2026-11-22'))
        fails(view, lambda o: o['cases'][1].update(tab_active=0))
        fails(view, lambda o: o['cases'][0]['items'].pop())
        fails(view, lambda o: o['cases'][1]['items'].append(dict(o['cases'][1]['items'][0], id=999)))
        fails(view, lambda o: o['cases'][0]['items'][0].update(text='Task 1'))
        fails(view, lambda o: o['cases'][0]['items'][1].update(task_href='http://127.0.0.1:8080/local/kbacompany/tasks.php'))
        fails(view, lambda o: o['cases'][1]['items'][0].update(add=0))
        fails(view, lambda o: o['cases'][0]['items'][1].update(added=1))
        fails(view, lambda o: o['cases'][0].update(empty={'items': 1, 'state': 0}))
        fails(add, lambda o: o['cases'][0]['after_add']['draft'].update(done=o['cases'][0]['tasks'][0]['result']))
        fails(add, lambda o: o['cases'][1]['after_add']['draft'].update(links=[]))
        fails(add, lambda o: o['cases'][0]['landing'].update(url='http://127.0.0.1:8080/local/kbacompany/communication_center.php'))
        fails(add, lambda o: o['cases'][1]['landing'].update(notice=0))
        fails(add, lambda o: o['cases'][0]['after_second']['draft'].update(
            done=o['cases'][0]['after_second']['draft']['done'].replace('\n' + o['cases'][0]['edit'], '')))
        # "Added earlier": exact before/after, reload, report link, date change, sent report, accessible label.
        fails(ae, lambda o: o['cases'][0]['feed_after_first'].update(add=[101, 102]))
        fails(ae, lambda o: o['cases'][1]['feed_after_first'].update(added=[]))
        fails(ae, lambda o: o['cases'][0]['feed_after_reload'].update(added=[101, 102]))
        fails(ae, lambda o: o['cases'][1]['feed_after_reload'].update(add=[]))
        fails(ae, lambda o: o['cases'][0]['feed_after_first']['labels'][0].update(hint=''))
        fails(ae, lambda o: o['cases'][1]['feed_after_reload']['labels'][0].update(visible=''))
        fails(ae, lambda o: o['cases'][0]['feed_after_first']['labels'][0].update(shown=False))
        fails(ae, lambda o: o['cases'][1]['feed_after_second']['labels'][1].update(hint_hidden=False))
        fails(ae, lambda o: o['cases'][1]['feed_after_first']['labels'].append(dict(o['cases'][1]['feed_after_first']['labels'][0])))
        fails(ae, lambda o: o['cases'][0]['report_link'].update(url='http://127.0.0.1:8080/local/kbacompany/daily_report.php?uuid=A'))
        fails(ae, lambda o: o['cases'][1]['report_link'].update(done=''))
        fails(ae, lambda o: o['cases'][0]['feed_other_day'].update(day=o['cases'][0]['day']))
        fails(ae, lambda o: o['cases'][1]['feed_back_to_day'].update(added=[]))
        fails(ae, lambda o: o['cases'][0]['feed_after_second'].update(add=[102]))
        fails(ae, lambda o: o['cases'][1]['after_submit_feed'].update(add=[101]))
        fails(ae, lambda o: o['cases'][0]['after_submit_feed'].update(added=[101]))
        fails(ae, lambda o: o['cases'][1]['after_submit_feed'].update(sent_note=0))
        fails(ae, lambda o: o['cases'][0].update(hscroll_marks=False))
        fails(dup, lambda o: o['cases'][0]['after_repeat_form']['draft'].update(
            done=o['cases'][0]['after_repeat_form']['draft']['done'] + '\n' + o['cases'][0]['tasks'][0]['result']))
        fails(dup, lambda o: o['cases'][1]['after_repeat_feed']['draft'].update(revision=5))
        fails(dup, lambda o: o['cases'][0]['after_repeat_feed']['added_events'].append(o['cases'][0]['tasks'][0]['event']))
        fails(dup, lambda o: o['cases'][1]['after_add'].update(added_events=[]))
        fails(dup, lambda o: o['cases'][0]['after_repeat_form']['draft'].update(done=o['cases'][0]['after_add']['draft']['done']))
        fails(dup, lambda o: o['cases'][1].update(repeat_http=[303]))
        fails(dup, lambda o: o['cases'][0].update(repeat_http=[303, 500]))
        fails(dup, lambda o: o['cases'][1].update(unavailable_http=303))
        fails(dup, lambda o: o['cases'][0].update(unavailable_http=500))
        fails(iso, lambda o: o['cases'][0].update(forged_http=303))
        fails(iso, lambda o: o['cases'][1]['foreign_http'].update(company_b=500))
        fails(iso, lambda o: o['cases'][0]['foreign_http'].pop('company_a'))
        fails(iso, lambda o: o['cases'][1].update(foreign_feed_items=2))
        fails(iso, lambda o: o['cases'][0]['after_negatives']['draft'].update(revision=5))
        fails(send, lambda o: o['cases'][0]['after_submit']['report'].update(status='draft'))
        fails(send, lambda o: o['cases'][1]['after_submit']['sent'].append(dict(o['cases'][1]['after_submit']['sent'][0])))
        fails(send, lambda o: o['cases'][1].update(hscroll_feed=False))
        fails(send, lambda o: o['cases'][0].update(hscroll_report=False))
        fails(send, lambda o: o['cases'][0]['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(send, lambda o: o['cases'][1]['server_errors'].append({'url': 'x', 'status': 500}))
        for mutate, code in ((lambda o: o['cases'].pop(), 0), (lambda o: o['cases'].reverse(), 0),
                             (lambda o: o['cases'][0]['tasks'].pop(), 0), (lambda o: o.update(error='boom'), 0),
                             (lambda o: o.update(status='FAIL'), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.day_feed_checks(o, code))
            self.assertTrue(all(v['status'] == 'FAIL' for v in r.values()), r)
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.day_feed_checks(None, None)).values()))

    @staticmethod
    def plan_denied(label, n):
        """Real Moodle refusals (#135 BROWSER evidence): RA may not open the plan, EB is outside the company."""
        post_reason = PD_REASONS['plan_unavailable' if label == 'RA' else 'company_unavailable']
        join = ({'http': 200, 'location': '', 'alerts': [PD_REASONS['thread_permission']]} if label == 'RA'
                else {'http': 404, 'location': '', 'alerts': [PD_REASONS['company_unavailable']]})
        return {'card_text': False, 'center_text': False, 'download': {'http': 404, 'sha256': None},
                'post': {'http': 303, 'location': PD_BACK, 'alerts': [],
                         'followed': {'http': 200 if label == 'RA' else 404, 'alerts': ['\u00d7 ' + post_reason]}},
                'post_http': 303, 'join': join, 'join_http': join['http'], 'join_location': '',
                'parts': {'active': [12, 15], 'reads': [12, 15]}, 'messages': 4 + n * 2}

    def plan_discussion_obs(self):
        ids = {'MA': 11, 'EA': 12, 'RA': 13, 'EB': 14, 'VA': 15}
        sha = 'f' * 64
        cases = []
        for n, (shell, width, mode) in enumerate(c.PLAN_CASES):
            t = ['EA %d plan question' % n, 'VA %d plan answer' % n]
            reader = {'card_question': True, 'card_answer': True, 'card_note': True, 'download': {'http': 200, 'sha256': sha},
                      'center_question': 1, 'center_join': 1, 'center_composer': 0,
                      'open_plan': {'text': 'Open plan',
                                    'href': 'http://127.0.0.1:8080/local/kbacompany/battle_plan.php?uuid=A&plan=P&view=detail&tab=discussion'},
                      'parts_after_reading': {'active': [12, 15], 'reads': [12, 15]}}
            if n == len(c.PLAN_CASES) - 1:
                reader.update(parts_after_join={'active': [11, 12, 15], 'reads': [11, 12, 15]}, composer_after_join=1)
            cases.append({'shell': shell, 'width': width, 'mode': mode, 'texts': t, 'file_sha256': sha, 'question': 'q-%d' % n,
                          'tab_link': 1, 'card_owner': {'question': 1},
                          'reply_target': {'count': 1, 'text': 'Replying to: Synthetic EA ' + t[0]},
                          'reply': {'body': t[1], 'status': 'active', 'thread': 'T', 'reply_to': 'q-%d' % n, 'authorid': 15, 'mentions': []},
                          'card_quote': ['Synthetic EA\n' + t[0]], 'reader': reader,
                          'parts_before_refusals': {'active': [12, 15], 'reads': [12, 15]},
                          'denied': {label: self.plan_denied(label, n) for label in ('RA', 'EB')},
                          'forged': {'http': 404, 'location': '', 'alerts': [PD_REASONS['invalid_sesskey']], 'messages': 4 + n * 2,
                                     'parts': {'active': [12, 15], 'reads': [12, 15]}},
                          'messages_before_refusals': 4 + n * 2,
                          'browser_events': list(ABORTED), 'server_errors': []})
        snap = {'phase': 'snapshot', 'plan': {'status': 'draft', 'userid': 12, 'reviewerid': 15, 'observerid': 0, 'timemodified': 1,
                                              'approvedby': 0, 'submittedat': 0, 'approvedat': 0, 'closedat': 0}, 'reviews': 0}
        ok = {'http': 200, 'sha256': sha}
        return {'status': 'PASS', 'ids': ids, 'cases': cases, 'plan_before': snap, 'plan_after': copy.deepcopy(snap),
                'reasons': dict(PD_REASONS), 'urls': {'company': 'A', 'plan': 'P'},
                'revocation': {'before': {'text': True, 'download': ok}, 'revoked': True,
                               'after': {'text': False, 'download': {'http': 404, 'sha256': None}},
                               'reopened': True, 'restored': {'text': True, 'download': ok}}}

    def test_plan_discussion_valid_passes_and_mutations_fail(self):
        obs = self.plan_discussion_obs()
        res = c.evaluate(c.plan_discussion_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.plan_discussion_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        rt = 'plan_discussion_card_and_center'
        fails(rt, lambda o: o['cases'][0].update(tab_link=0))
        fails(rt, lambda o: o['cases'][1]['card_owner'].update(question=0))
        fails(rt, lambda o: o['cases'][0]['reply_target'].update(count=0))
        fails(rt, lambda o: o['cases'][0]['reply'].update(reply_to=''))
        fails(rt, lambda o: o['cases'][1]['reply'].update(authorid=11))
        fails(rt, lambda o: o['cases'][0].update(card_quote=[]))
        fails(rt, lambda o: o['cases'][0]['reader'].update(open_plan={'text': 'Open plan', 'href': 'https://example.invalid/x'}))
        fails(rt, lambda o: o['cases'][1]['reader'].update(open_plan={'text': 'Open object',
              'href': 'http://127.0.0.1:8080/local/kbacompany/battle_plan.php?plan=P&tab=discussion'}))
        # Refusal status must reject fake 5xx/login redirects, not merely !=303.
        bad = 'plan_discussion_refusals_and_revocation'
        fails(bad, lambda o: o['cases'][0]['forged'].update(http=500))
        fails(bad, lambda o: o['cases'][0]['forged'].update(http=302))
        fails(bad, lambda o: o['cases'][0]['forged'].update(location='http://127.0.0.1:8080/login/index.php'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].update(join_http=500))
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].update(join_http=302))
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].update(join_location='http://127.0.0.1:8080/login/index.php'))
        # Codex #123/#135 repros: missing or emptied participant evidence must FAIL, not pass silently.
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].pop('parts'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['parts'].update(active=[]))
        fails(bad, lambda o: o['cases'][1]['denied']['EB']['parts'].update(reads=[12]))
        fails(bad, lambda o: o['cases'][0].pop('parts_before_refusals'))
        fails(bad, lambda o: o['cases'][0].update(parts_before_refusals={'active': [], 'reads': []}))
        fails(bad, lambda o: o['cases'][0]['forged'].pop('parts'))
        fails(bad, lambda o: o['cases'][0]['forged']['parts']['active'].append(13))
        # The refusal must be the expected one: an arbitrary 200 page, a wrong reason, an unexpected redirect, a missing
        # response or a 5xx is not a refusal.
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['join'].update(alerts=[]))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['join'].update(alerts=['Changes saved']))
        fails(bad, lambda o: o['cases'][0]['denied']['EB']['join'].update(http=200))
        fails(bad, lambda o: o['cases'][0]['denied']['EB'].update(join_http=500))
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].pop('join'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA'].pop('post'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post'].update(http=500))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post'].update(http=200, location=''))
        fails(bad, lambda o: o['cases'][1]['denied']['EB']['post'].update(location='http://127.0.0.1:8080/login/index.php'))
        fails(bad, lambda o: o['cases'][1]['denied']['EB']['post'].update(location='https://example.invalid' + PD_BACK[21:]))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post'].update(location=PD_BACK + '&extra=1'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post'].update(location=PD_BACK + '&tab=discussion'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post'].pop('followed'))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post']['followed'].update(http=500))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post']['followed'].update(http=302))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post']['followed'].update(http=303))
        fails(bad, lambda o: o['cases'][0]['denied']['RA']['post']['followed'].update(alerts=[PD_REASONS['company_unavailable']]))
        fails(bad, lambda o: o['cases'][0]['forged'].update(http=200))
        fails(bad, lambda o: o['cases'][0]['forged'].update(alerts=[]))
        fails(bad, lambda o: o['cases'][0]['forged'].pop('alerts'))
        fails(bad, lambda o: o.pop('reasons'))
        fails(bad, lambda o: o['reasons'].update(invalid_sesskey=''))
        fails(bad, lambda o: o['reasons'].update(thread_permission=o['reasons']['plan_unavailable']))
        fails(bad, lambda o: o.pop('urls'))
        fails(bad, lambda o: o['cases'][0].update(messages_before_refusals=None))
        rd = 'plan_discussion_reader_not_joined'
        fails(rd, lambda o: o['cases'][0]['reader'].update(card_question=False))
        fails(rd, lambda o: o['cases'][0]['reader'].update(card_note=False))
        fails(rd, lambda o: o['cases'][1]['reader'].update(download={'http': 404, 'sha256': None}))
        fails(rd, lambda o: o['cases'][0]['reader'].update(center_composer=1))
        fails(rd, lambda o: o['cases'][0]['reader'].update(center_join=0))
        fails(rd, lambda o: o['cases'][0]['reader']['parts_after_reading']['active'].append(11))
        fails(rd, lambda o: o['cases'][0]['reader']['parts_after_reading']['reads'].append(11))
        fails(rd, lambda o: o['cases'][0]['reader']['parts_after_reading']['active'].remove(15))
        fails(rd, lambda o: o['cases'][1]['reader']['parts_after_join']['active'].remove(11))
        rf = 'plan_discussion_refusals_and_revocation'
        for label in ('RA', 'EB'):
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label].update(card_text=True))
            fails(rf, lambda o, label=label: o['cases'][1]['denied'][label].update(center_text=True))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label].update(download={'http': 200, 'sha256': 'f' * 64}))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label].update(download={'http': 500, 'sha256': None}))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label].update(join_http=303))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label]['join'].update(http=303))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label]['join'].update(location=PD_BACK))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label]['parts']['active'].append(o['ids'][label]))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'][label].update(messages=5))
            fails(rf, lambda o, label=label: o['cases'][1]['denied'][label].update(post_http=500))
            fails(rf, lambda o, label=label: o['cases'][1]['denied'][label]['post'].update(http=500))
            fails(rf, lambda o, label=label: o['cases'][0]['denied'].pop(label))
        fails(rf, lambda o: o['cases'][0]['forged'].update(messages=5))
        fails(rf, lambda o: o['cases'][0]['forged'].update(http=303))
        fails(rf, lambda o: o['revocation']['after'].update(text=True))
        fails(rf, lambda o: o['revocation']['after'].update(download={'http': 200, 'sha256': 'f' * 64}))
        fails(rf, lambda o: o['revocation'].update(revoked=False))
        fails(rf, lambda o: o['revocation'].update(reopened=False))
        fails(rf, lambda o: o['revocation']['restored'].update(text=False))
        un = 'plan_discussion_plan_unchanged'
        fails(un, lambda o: o['plan_after']['plan'].update(status='submitted'))
        fails(un, lambda o: o['plan_after'].update(reviews=1))
        fails(un, lambda o: o.pop('plan_before'))
        be = 'plan_discussion_no_browser_errors'
        fails(be, lambda o: o['cases'][0]['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(be, lambda o: o['cases'][1].update(server_errors=[{'status': 500}]))
        fails(be, lambda o: o['cases'][0].pop('browser_events'))
        # incomplete matrix, missing actor, producer error or non-zero exit fail every check
        for mutate, code in ((lambda o: o['cases'].pop(), 0), (lambda o: o['cases'].reverse(), 0),
                             (lambda o: o['ids'].update(VA=11), 0), (lambda o: o.update(error='boom'), 0),
                             (lambda o: o['cases'][0].update(question=''), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.plan_discussion_checks(o, code))
            self.assertTrue(all(x['status'] == 'FAIL' for x in r.values()), r)
        self.assertTrue(all(x['status'] == 'FAIL' for x in c.evaluate(c.plan_discussion_checks(None, None)).values()))
    # ---- adaptive navigation (adaptive_nav.py)
    def nav_obs(self):
        def box(x, y, w, h):
            return {'x': x, 'y': y, 'w': w, 'h': h, 'r': x + w, 'b': y + h}

        def groups(open_all=False, items=(1, 6, 3)):
            labels = ('Главное', 'Рабочее пространство', 'Управление')
            return [{'label': l, 'open': open_all or i < 2, 'summary': box(12, 60 + i * 50, 300, 44), 'items': n,
                     'visible': n if (open_all or i < 2) else 0, 'current': i == 1} for i, (l, n) in enumerate(zip(labels, items))]

        def labels():
            return [{'text': t, 'broken': [], 'count': None} for t in ('Задачи', 'Отчёты', 'Боевой план', 'Календарь')] + \
                [{'text': 'Коммуникационный центр 3', 'broken': [], 'count': {'visible': True, 'text': '3', 'clipped': False, 'inside': True}}]

        def targets(n=6):
            return [dict(box(12, 60 + i * 48, 280, 44), text='item %d' % i) for i in range(n)]

        def layout(r, open_=False):
            narrow = r['shell'] == 'owned' and r['js'] and r['width'] <= c.ADAPTIVE_NAV_BREAKPOINT
            owned = r['shell'] == 'owned'
            wide_owned = owned and not narrow
            lay = {'owned': owned, 'inner': r['width'], 'innerh': r['height'], 'client': r['width'], 'scroll': r['width'], 'scrolly': 400,
                   'main': box(0, 74, r['width'], 2000) if narrow else box(258, 74, r['width'] - 258, 2000),
                   'sidebar_visible': wide_owned, 'summary_visible': False, 'drawer_visible': open_, 'drawer_focusables': 9 if open_ else 0,
                   'menu_roles': 0, 'button': None, 'html_overflow': 'hidden' if open_ else 'visible', 'body_overflow': 'visible',
                   'main_inert': open_, 'backdrop_visible': open_, 'active': 'menubutton' if narrow else 'BODY', 'sidebar': None,
                   'css_hrefs': ['http://127.0.0.1:8080/local/kbacompany/bms_ui/owned_shell.css?v=2026112301-nav2'] if owned else []}
            if owned:
                lay['button'] = {'tag': 'BUTTON', 'type': 'button', 'visible': narrow, 'w': 92 if narrow else 0, 'h': 44 if narrow else 0,
                                 'x': 10, 'y': 15, 'r': 102, 'b': 59, 'expanded': 'true' if open_ else 'false', 'controls': 'bms24-drawer',
                                 'controls_exists': True, 'name': 'Меню', 'hidden_attr': not r['js']}
            if wide_owned:
                lay['sidebar'] = {'groups': groups(), 'labels': labels(), 'targets': targets(), 'outside': [], 'current_visible': True}
            return lay

        def drawer(r, open_all=False):
            w = r['width'] if r['width'] < 360 else min(360, r['width'] - 48)
            return {'visible': True, 'box': box(0, 0, w, r['height']), 'inner': r['width'], 'innerh': r['height'], 'role': 'dialog',
                    'modal': 'true', 'name': 'Навигация BMS24', 'id': 'bms24-drawer',
                    'backdrop': dict(box(0, 0, r['width'], r['height']), visible=True),
                    'close': dict(box(w - 54, 10, 44, 44), visible=True, name='Закрыть меню', tag='BUTTON'),
                    'focus_inside': True, 'expanded': 'true', 'current': {'visible': True, 'open': True, 'path': 'tasks.php'},
                    'groups': groups(open_all), 'labels': labels(), 'targets': targets(), 'outside': [],
                    'scrollers': [{'cls': 'bms24-drawerbody', 'sw': w - 24, 'cw': w - 24, 'sh': 900, 'ch': r['height'] - 70,
                                   'ox': 'hidden', 'oy': 'auto'}],
                    'doc_scroll': r['width'], 'client': r['width'], 'html_overflow': 'hidden',
                    'main': box(0, 74, r['width'], 2000), 'main_inert': True, 'sidebar_inert': True, 'focusables': 9, 'menu_roles': 0}

        def closed(r, method):
            return {'method': method, 'drawer_visible': False, 'backdrop_visible': False, 'expanded': 'false', 'active': 'menubutton',
                    'scrolly': 400, 'expected_scrolly': 400, 'html_overflow': 'visible', 'main_inert': False,
                    'field': c.ADAPTIVE_NAV_MARKER, 'drawer_focusables': 0}

        cases = []
        for cid, shell, actor, lang, width, height, dpr, js in c.ADAPTIVE_NAV_CASES:
            r = {'id': cid, 'shell': shell, 'actor': actor, 'lang': lang, 'width': width, 'height': height, 'dpr': dpr, 'js': js,
                 'language_installed': True, 'http': 200, 'browser_events': []}
            r['layout'] = layout(r)
            if js:
                r['server_errors'] = []
            narrow = shell == 'owned' and js and width <= c.ADAPTIVE_NAV_BREAKPOINT
            if narrow:
                d = drawer(r)
                r.update({'field_present': True, 'before_open': layout(r), 'open_method': 'button', 'drawer': d,
                          'stress': {'done': True, 'outside': [], 'box': box(12, 300, d['box']['w'] - 24, 66), 'drawer': d['box'],
                                     'report': {'text': c.ADAPTIVE_NAV_STRESS['label'] + ' 9999', 'broken': [],
                                                'count': {'visible': True, 'text': '9999', 'clipped': False, 'inside': True}}},
                          'tab_forward': [True] * 12, 'tab_backward': [True] * 4, 'wheel': {'before': 400, 'after': 400},
                          'groups_all_open': groups(True, (1, 6, 3) if actor == 'MA' else (1, 4, 1)),
                          'last_item': {'found': True, 'text': 'Настройки', 'top': height - 60, 'bottom': height - 16, 'innerh': height,
                                        'hit': True, 'doc_scroll': width, 'client': width},
                          'close_escape': closed(r, 'escape'), 'close_button': closed(r, 'button'),
                          'backdrop_exposed': width >= 360, 'after_close': layout(r)})
                if width >= 360:
                    r['close_backdrop'] = closed(r, 'backdrop')
            if cid in ('owned-375-ru', 'owned-861-ru'):
                nw, ww = (375, 1440) if cid == 'owned-375-ru' else (860, 861)
                wide = dict(r, width=ww, shell='owned'); nar = dict(r, width=nw)
                r['resize'] = {'opened': 'button', 'open': True, 'to_wide': layout(wide), 'back_narrow': layout(nar), 'reopen': 'button',
                               'reopen_visible': True, 'reclosed': layout(nar)}
            if cid == 'owned-375-ru':
                r['bell_handoff'] = {'open_before_menu': True, 'closed_after_menu': True}
                r['routes'] = [{'target': t, 'opened': 'button', 'links': 1, 'visible': True, 'http': 200, 'path': t, 'opened_after': 'button',
                                'current': {'visible': True, 'open': True, 'path': t}, 'doc_scroll': 375, 'client': 375}
                               for t in c.ADAPTIVE_NAV_ROUTES]
            if not js:
                if width < 861:
                    d = drawer(r)
                    d.update(role=None, modal=None, backdrop=None, close=None, box=box(10, 74, 355, 600))
                    r.update(summary_visible=True, drawer=d, last_item={'found': True, 'hit': True, 'top': 600, 'bottom': 644})
                    r['layout']['summary_visible'] = True
            cases.append(r)
        stale = []
        for width, height in c.ADAPTIVE_NAV_STALE_CASES:
            # The pre-release stylesheet with the current script: the button stays hidden on desktop, the phone keeps a trigger.
            narrow = width <= c.ADAPTIVE_NAV_BREAKPOINT
            r = {'id': 'stale', 'shell': 'owned', 'js': True, 'width': width, 'height': height}
            lay = layout(r)
            lay.update(sidebar_visible=not narrow, summary_visible=narrow)
            lay['button'].update(visible=narrow)
            stale.append({'width': width, 'height': height, 'http': 200, 'hits': 1, 'sheet': {'sheets': 1, 'rules': 212, 'menubutton': 0},
                          'layout': lay, 'browser_events': []})
        return {'status': 'PASS', 'company': 'a1b2c3d4-0000-4000-8000-00000000000a', 'cases': cases,
                'stale_css': {'fixture_sha256': c.ADAPTIVE_NAV_STALE_CSS_SHA256, 'cases': stale}}

    def nav_case(self, obs, cid):
        return next(r for r in obs['cases'] if r['id'] == cid)

    def test_adaptive_nav_genuine_run_passes(self):
        res = c.evaluate(c.adaptive_nav_checks(self.nav_obs(), 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()})
        self.assertEqual(18, len(res))
        for k in res:
            self.assertIn(k, c.required_check_names())

    def test_adaptive_nav_regressions_fail(self):
        n = self.nav_case

        def set_(cid, path, value):
            def go(o):
                t = n(o, cid)
                for key in path[:-1]:
                    t = t[key]
                t[path[-1]] = value
            return go

        def stress(o):
            n(o, 'owned-390-en')['stress']['report']['broken'] = ['подразделениями']
        def stale(width, *path_value):
            *path, value = path_value

            def go(o):
                t = next(x for x in o['stale_css']['cases'] if x['width'] == width)
                for key in path[:-1]:
                    t = t[key]
                t[path[-1]] = value
            return go
        cases = [
            # Hotfix #163: the script unhid the button and only fresh CSS hid it again next to the desktop sidebar.
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1440, 'layout', 'button', 'visible', True)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1024, 'layout', 'summary_visible', True)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1440, 'layout', 'drawer_focusables', 4)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1024, 'layout', 'sidebar_visible', False)),
            ('adaptive_nav_stale_css_no_desktop_trigger',
             lambda o: (stale(375, 'layout', 'button', 'visible', False)(o), stale(375, 'layout', 'summary_visible', False)(o))),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(375, 'layout', 'button', None)),
            # The stale stylesheet must really be the one in effect, and the fixture must be the pre-release file.
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1440, 'hits', 0)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1440, 'sheet', 'menubutton', 3)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1024, 'sheet', 'sheets', 0)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(375, 'http', 500)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1440, 'layout', 'inner', 1424)),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(1024, 'error', 'TimeoutError: page.goto')),
            ('adaptive_nav_stale_css_no_desktop_trigger', stale(375, 'browser_events', [{'source': 'pageerror', 'name': 'Error', 'message': 'x'}])),
            ('adaptive_nav_stale_css_no_desktop_trigger', lambda o: o['stale_css'].update(fixture_sha256='0' * 64)),
            ('adaptive_nav_stale_css_no_desktop_trigger', lambda o: o['stale_css']['cases'].pop()),
            ('adaptive_nav_stale_css_no_desktop_trigger', lambda o: o['stale_css'].update(error='TargetClosedError')),
            ('adaptive_nav_stale_css_no_desktop_trigger', lambda o: o.pop('stale_css')),
            ('adaptive_nav_shell_css_cache_busted', set_('owned-1440-en', ['layout', 'css_hrefs'],
                                                         ['http://127.0.0.1:8080/local/kbacompany/bms_ui/owned_shell.css'])),
            ('adaptive_nav_shell_css_cache_busted', set_('owned-375-ru', ['layout', 'css_hrefs'],
                                                         ['http://127.0.0.1:8080/local/kbacompany/bms_ui/owned_shell.css?v='])),
            ('adaptive_nav_shell_css_cache_busted', set_('nojs-375-ru', ['layout', 'css_hrefs'], [])),
            ('adaptive_nav_shell_css_cache_busted', set_('owned-768-ru', ['layout', 'css_hrefs'], None)),
            # The pre-fix markup: no button, the old <details> summary used to open the menu.
            ('adaptive_nav_menu_button_accessible', set_('owned-375-ru', ['before_open', 'button'], None)),
            ('adaptive_nav_menu_button_accessible', set_('owned-375-ru', ['open_method'], 'summary')),
            ('adaptive_nav_menu_button_accessible', set_('owned-768-ru', ['before_open', 'button', 'controls'], None)),
            ('adaptive_nav_menu_button_accessible', set_('owned-320-ru', ['before_open', 'button', 'h'], 40)),
            ('adaptive_nav_menu_button_accessible', set_('owned-1440-en', ['layout', 'menu_roles'], 1)),
            ('adaptive_nav_desktop_sidebar_unchanged', set_('owned-1024-ru', ['layout', 'drawer_focusables'], 3)),
            ('adaptive_nav_desktop_sidebar_unchanged', set_('owned-861-ru', ['layout', 'sidebar_visible'], False)),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-375-ru', ['drawer', 'role'], None)),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-375-ru', ['bell_handoff', 'closed_after_menu'], False)),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-860-ru', ['tab_forward'], [True, True, False, True])),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-390x480-ru', ['drawer', 'box', 'b'], 300)),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-844x390-ru', ['drawer', 'main_inert'], False)),
            ('adaptive_nav_drawer_modal_and_focus_trap', set_('owned-zoom200-ru', ['drawer', 'close'], None)),
            ('adaptive_nav_no_horizontal_overflow', set_('owned-320-ru', ['drawer', 'scrollers'], [{'sw': 340, 'cw': 296}])),
            ('adaptive_nav_no_horizontal_overflow', set_('owned-375-ru', ['layout', 'scroll'], 391)),
            ('adaptive_nav_no_horizontal_overflow', set_('owned-768-ru', ['drawer', 'outside'], ['bms24-count'])),
            ('adaptive_nav_no_horizontal_overflow', set_('owned-390-en', ['stress', 'outside'], ['bms24-navlabel'])),
            ('adaptive_nav_no_horizontal_overflow', set_('legacy-375-ru', ['layout', 'scroll'], 420)),
            ('adaptive_nav_groups_open_and_current', set_('owned-375-ru', ['drawer', 'current', 'open'], False)),
            ('adaptive_nav_groups_open_and_current', set_('owned-768-ru', ['drawer', 'groups', 1, 'visible'], 0)),
            ('adaptive_nav_groups_open_and_current', set_('owned-860-ru', ['groups_all_open', 2, 'visible'], 1)),
            ('adaptive_nav_labels_wrap_and_counters', stress),
            ('adaptive_nav_labels_wrap_and_counters', set_('owned-375-ru', ['drawer', 'labels', 4, 'count', 'clipped'], True)),
            ('adaptive_nav_labels_wrap_and_counters', set_('owned-1440-en', ['layout', 'sidebar', 'labels', 0, 'broken'], ['Задачи'])),
            ('adaptive_nav_tap_targets_44', set_('owned-375-ru', ['drawer', 'targets', 2, 'h'], 40)),
            ('adaptive_nav_tap_targets_44', set_('owned-1440-en', ['layout', 'sidebar', 'targets', 0, 'h'], 37)),
            ('adaptive_nav_scroll_lock_page_stable', set_('owned-375-ru', ['wheel', 'after'], 1000)),
            ('adaptive_nav_scroll_lock_page_stable', set_('owned-768-ru', ['drawer', 'html_overflow'], 'auto')),
            ('adaptive_nav_scroll_lock_page_stable', set_('owned-860-ru', ['drawer', 'client'], 845)),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-375-ru', ['close_escape', 'active'], 'BODY')),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-390-en', ['close_button', 'scrolly'], 0)),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-768-ru', ['close_backdrop', 'backdrop_visible'], True)),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-320-ru', ['close_escape', 'field'], '')),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-860-ru', ['backdrop_exposed'], False)),
            ('adaptive_nav_close_escape_button_backdrop', set_('owned-390-en', ['close_button', 'html_overflow'], 'hidden')),
            ('adaptive_nav_last_item_reachable', set_('owned-390x480-ru', ['last_item', 'hit'], False)),
            ('adaptive_nav_last_item_reachable', set_('owned-844x390-ru', ['last_item', 'bottom'], 420)),
            ('adaptive_nav_resize_no_stale_overlay', set_('owned-375-ru', ['resize', 'to_wide', 'backdrop_visible'], True)),
            ('adaptive_nav_resize_no_stale_overlay', set_('owned-375-ru', ['resize', 'to_wide', 'html_overflow'], 'hidden')),
            ('adaptive_nav_resize_no_stale_overlay', set_('owned-861-ru', ['resize', 'back_narrow', 'button', 'expanded'], 'true')),
            ('adaptive_nav_route_transitions', set_('owned-375-ru', ['routes', 2, 'path'], 'tasks.php')),
            ('adaptive_nav_route_transitions', set_('owned-375-ru', ['routes', 1, 'current', 'path'], 'tasks.php')),
            ('adaptive_nav_shorter_employee_menu', set_('owned-375-employee-ru', ['groups_all_open'], [])),
            ('adaptive_nav_no_js_fallback', set_('nojs-375-ru', ['summary_visible'], False)),
            ('adaptive_nav_no_js_fallback', set_('nojs-375-ru', ['drawer', 'scrollers'], [{'sw': 500, 'cw': 355}])),
            ('adaptive_nav_no_js_fallback', set_('nojs-1440-en', ['layout', 'sidebar_visible'], False)),
            ('adaptive_nav_legacy_shell_unchanged', set_('legacy-375-ru', ['layout', 'html_overflow'], 'hidden')),
            ('adaptive_nav_no_browser_errors', set_('owned-768-ru', ['browser_events'], [{'source': 'pageerror', 'name': 'Error', 'message': 'x'}])),
            ('adaptive_nav_no_browser_errors', set_('owned-1024-ru', ['server_errors'], [{'url': 'x', 'status': 500}])),
        ]
        for name, mutate in cases:
            with self.subTest(check=name):
                obs = self.nav_obs(); mutate(obs)
                self.assertIn(name, failed(c.evaluate(c.adaptive_nav_checks(obs, 0))))

    def test_adaptive_nav_incomplete_runs_fail_every_check(self):
        base = self.nav_obs()
        for mutate, code in ((lambda o: None, 1), (lambda o: None, None), (lambda o: o.update(status='FAIL'), 0),
                             (lambda o: o.update(error='boom'), 0), (lambda o: o['cases'].pop(), 0),
                             (lambda o: o['cases'][3].update(error='TimeoutError'), 0),
                             (lambda o: o['cases'][0].update(language_installed=False), 0),
                             (lambda o: o['cases'][5]['layout'].update(owned=False), 0),
                             (lambda o: o['cases'].reverse(), 0)):
            o = copy.deepcopy(base); mutate(o)
            r = c.evaluate(c.adaptive_nav_checks(o, code))
            self.assertTrue(all(v['status'] == 'FAIL' for v in r.values()), r)
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.adaptive_nav_checks(None, None)).values()))

    @staticmethod
    def bell_obs():
        """A genuine-looking notification_bell.py result: every bell check must PASS on it."""
        center = 'http://127.0.0.1:8080/local/kbacompany/communication_center.php'

        def bell(ids, unread, plays=0, sound='off', read=None, state='ready', text='', bells=1):
            return {'ids': list(ids), 'read': read or {i: '0' for i in ids}, 'links': {}, 'badge': str(unread) if unread else '',
                    'unread': str(unread), 'label': 'Unread notifications: %d' % unread if unread else 'Notifications',
                    'title': 'Unread notifications: %d' % unread if unread else 'Notifications', 'state': state,
                    'sound': sound, 'text': text, 'plays': plays, 'bells': bells, 'moodlebells': 0, 'headings': 0}

        def srv(unread, pref='0', **read):
            return {'unread': unread, 'read': dict({'n0': 0}, **read), 'pref': pref}

        def metrics(open_, active='other', **kw):
            m = {'open': open_, 'expanded': 'true' if open_ else 'false', 'left': 8, 'right': 312, 'top': 120, 'bottom': 600,
                 'vw': 320, 'vh': 640, 'overflowy': 'auto', 'position': 'fixed', 'scroll': 900, 'client': 480, 'maintop': 160,
                 'docheight': 1400, 'hscroll': False, 'active': active, 'allhref': center + '?uuid=A&tab=notifications'}
            m.update(kw)
            return m

        def cnt(signals, confirms, plays):
            return {'signals': signals, 'confirms': confirms, 'plays': plays, 'sound': '1', 'epoch': 7}

        def run(*plays):
            rows = [{'play': x, 't0': 10.0 + i * 0.001, 't1': 10.05 + i * 0.01, 'pid': 1000 + i, 'late': 0.001}
                    for i, x in enumerate(plays)]
            return {'results': rows, 'plays': list(plays), 'inflight_at_first_end': len(plays)}

        def width(w):
            return {'width': w, 'toggle_visible': True, 'closed': metrics(False), 'open_keyboard': metrics(True, 'panel'),
                    'tab_inside': 'inside', 'after_escape': metrics(False, 'toggle'), 'focus_outline': ['solid', 3.0],
                    'open_click': metrics(True), 'after_outside': metrics(False)}
        a_ids = ['n0', 'n1', 'n5', 'n6', 'n9', 'n11', 'b0', 'b1', 'b2']
        steps = {
            'initial': {'bell': bell(['n0'], 1), 'server': srv(1), 'server_before': srv(1), 'n0': 'n0', 'nb': 'nb'},
            'panel': {'metrics': metrics(True, 'panel'), 'bell': bell(['n0'], 1), 'server': srv(1)},
            'new_sound_off': {'n1': 'n1', 'bell': bell(['n1', 'n0'], 2), 'server': srv(2, n1=0)},
            'enable': {'plays_after_enable': 1, 'plays_after_test': 2, 'bell': bell(['n1', 'n0'], 2, 2, 'on'),
                       'server': srv(2, '1', n1=0), 'other_account_pref': '0'},
            'repeat_poll': {'bell': bell(['n1', 'n0'], 2, 0, 'on')},
            'center_lists_new': {'n1_form': 1, 'title_shown': 1},
            'count_only': {'bell': bell(['n1', 'n0'], 1, 0, 'on', {'n1': '1', 'n0': '0'}), 'server': srv(1, '1', n1=1), 'n1': 'n1'},
            'batch': {'ids': ['b0', 'b1', 'b2'], 'bell': bell(['b2', 'b1', 'b0', 'n1', 'n0'], 4, 1, 'on'), 'server': srv(4, '1')},
            'tabs': {'n5': 'n5', 'plays': [1, 0], 'bells': [bell(['n5'], 5, 1, 'on'), bell(['n5'], 5, 0, 'on')],
                     'storage': ['available', 'available']},
            'tabs_nostorage': {'n6': 'n6', 'plays': [0, 1], 'bells': [bell(['n6', 'n5'], 6, 0, 'on'), bell(['n6', 'n5'], 6, 1, 'on')],
                               'storage': ['SecurityError', 'SecurityError'], 'other_context': bell(['n6', 'n5'], 6, 0, 'on'),
                               'events': [{'source': 'console', 'type': 'error', 'text': c.BELL_PLANTED_STORAGE_ECHO, 'url': ''}] * 2},
            'late_claim': {
                'off': {'id': 'r1', 'pref_before_release': '0', 'answers': [{'ok': True, 'user': 5, 'play': True}],
                        'before': cnt(3, 2, 0), 'after': cnt(3, 2, 0), 'bell': bell(['r1'], 9, 0, 'off')},
                'off_on': {'id': 'r2', 'pref_before_release': '1', 'answers': [{'ok': True, 'user': 5, 'play': True}],
                           'before': cnt(3, 3, 1), 'after': cnt(3, 4, 2), 'bell': bell(['r2', 'r1'], 10, 2, 'on')},
                'normal': {'id': 'r3', 'before': cnt(3, 4, 2), 'after': cnt(4, 4, 3), 'bell': bell(['r3', 'r2'], 11, 3, 'on')}},
            'claims': {
                'dual': {'userid': 99, 'companies': ['A', 'B']},
                'cross': {'a': {'uuid': 'xa', 'id': 101}, 'b': {'uuid': 'xb', 'id': 102}, 'first_b': run(True),
                          'then_a': run(True), 'again_a': run(False), 'a_in_b': run(False)},
                'order': {'c1': {'uuid': 'o1', 'id': 201}, 'c2': {'uuid': 'o2', 'id': 202}, 'first_c2': run(True), 'then_c1': run(True)},
                'parallel_same': dict(run(True, *[False] * 7), id={'uuid': 'p1', 'id': 301}),
                'parallel_companies': dict(run(False, True, False, False, False, False, True, False), a={'id': 401}, b={'id': 402}),
                'batch': {'ids': ['b1', 'b2', 'b3'], 'first': run(True), 'repeat': run(False, False, False, False)}},
            'reload': {'bell': bell(['n5'], 5, 0, 'on')},
            'reconnect': {'ids': ['o0', 'o1'], 'polls_while_offline': 0, 'bell': bell(['o1', 'o0', 'n5'], 7, 1, 'on')},
            'autoplay_refused': {'n8': 'n8', 'blocked': bell(['n8'], 8, 1, 'blocked'), 'after_test': bell(['n8'], 8, 2, 'on')},
            'csrf': {'wrong_http': 200, 'wrong': {'ok': False, 'state': 'sesskey', 'user': 5}, 'get_http': 200, 'get_ok': True,
                     'pref': '1'},
            'disable': {'n9': 'n9', 'bell': bell(['n9', 'n8'], 9, 0, 'off'), 'pref': '0'},
            'thread_open': {'uuid': 'tn', 'thread': 'T', 'bell': bell(['tn', 'n9'], 10, 1, 'on'),
                            'href': center + '?uuid=A&thread=T', 'http': 200, 'landed': center + '?uuid=A&thread=T'},
            'thread_revoked': {'uuid': 'tn', 'thread': 'T', 'active': 0, 'bell': bell(['n9'], 9, 0, 'on', text='Notifications'),
                               'server': srv(9, '1'), 'title': 'BMS bell private x-t'},
            'company_revoked': {'n11': 'n11', 'revoked': bell([], 0, 0, 'on', state='unavailable'),
                                'revoked_server': {'unread': None, 'read': {}, 'pref': '1'},
                                'restored': bell(['n11', 'n9'], 10, 0, 'on'), 'server': srv(10, '1')},
            'isolation': {'eb_bell': bell(['nb'], 1), 'eb_server': {'unread': 1, 'read': {'nb': 0}, 'pref': '0'},
                          'foreign_http': 200, 'foreign': {'ok': False, 'state': 'unavailable', 'user': 7},
                          'ma_http': 200, 'ma': {'ok': True, 'items': [{'id': 'm1'}]}, 'ma_bell': bell(['m1'], 1),
                          'ea_ids': a_ids, 'ea_bell': bell(['n11', 'n9'], 10, 0, 'on'), 'nb': 'nb'},
            'legacy': {'bells': 0},
            'account_switch': {'bell': bell([], 0, 0, 'on', state='unavailable'), 'n12': 'n12', 'more_polls': 0,
                               'ra_bell_count': 1},
        }
        return {'status': 'PASS', 'companyuuid': 'A', 'companyb': 'B', 'steps': steps,
                'widths': [width(w) for w in c.BELL_WIDTHS], 'browser_events': list(ABORTED), 'server_errors': []}

    @staticmethod
    def run_(*plays):
        rows = [{'play': x, 't0': 10.0, 't1': 10.05, 'pid': 2000 + i, 'late': 0.0} for i, x in enumerate(plays)]
        return {'results': rows, 'plays': list(plays), 'inflight_at_first_end': len(plays)}

    def test_notification_bell_valid_passes_and_mutations_fail(self):
        obs = self.bell_obs()
        res = c.evaluate(c.notification_bell_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.notification_bell_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        st = lambda o, name: o['steps'][name]  # noqa: E731
        ne = 'notification_bell_new_event_center_and_bell'
        fails(ne, lambda o: st(o, 'new_sound_off')['bell']['ids'].remove('n1'))
        fails(ne, lambda o: st(o, 'new_sound_off')['bell'].update(unread='1'))        # badge != server count
        fails(ne, lambda o: st(o, 'new_sound_off')['bell'].update(label='3'))         # tooltip without unit/count
        fails(ne, lambda o: st(o, 'center_lists_new').update(n1_form=0))
        fails(ne, lambda o: st(o, 'panel')['metrics'].update(allhref='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A'))
        fails(ne, lambda o: st(o, 'thread_open').update(href='https://example.invalid/local/kbacompany/communication_center.php?uuid=A&thread=T'))
        fails(ne, lambda o: st(o, 'thread_open').update(http=500))
        fails(ne, lambda o: st(o, 'thread_open').update(landed='http://127.0.0.1:8080/login/index.php'))
        rr = 'notification_bell_read_rules_unchanged'
        fails(rr, lambda o: st(o, 'initial')['server'].update(read={'n0': 1}))      # loading marked read
        fails(rr, lambda o: st(o, 'panel')['server'].update(read={'n0': 1}))        # opening marked read
        fails(rr, lambda o: st(o, 'count_only')['server']['read'].update(n1=0))     # Center mark-read broken
        fails(rr, lambda o: st(o, 'count_only')['bell']['read'].update(n1='0'))
        iso = 'notification_bell_isolation_roles_and_revocation'
        fails(iso, lambda o: st(o, 'isolation')['eb_bell']['ids'].append('n1'))       # A row in B
        fails(iso, lambda o: st(o, 'isolation')['ea_bell']['ids'].append('nb'))       # B row in A
        fails(iso, lambda o: st(o, 'isolation').update(foreign={'ok': True, 'items': []}))
        fails(iso, lambda o: st(o, 'isolation')['foreign'].update(unread=3))          # count leaked in refusal
        fails(iso, lambda o: st(o, 'isolation')['ma']['items'].append({'id': 'n0'}))  # manager reads EA's row
        fails(iso, lambda o: st(o, 'thread_revoked')['bell']['ids'].append('tn'))
        fails(iso, lambda o: st(o, 'thread_revoked')['bell'].update(text='BMS bell private x-t'))
        fails(iso, lambda o: st(o, 'thread_revoked')['bell'].update(unread='10', badge='10'))
        fails(iso, lambda o: st(o, 'company_revoked')['revoked']['ids'].append('n11'))
        fails(iso, lambda o: st(o, 'company_revoked')['revoked'].update(badge='3'))
        fails(iso, lambda o: st(o, 'company_revoked')['revoked'].update(plays=1))
        fails(iso, lambda o: st(o, 'company_revoked')['restored'].update(plays=1))
        fails(iso, lambda o: st(o, 'account_switch')['bell']['ids'].append('n12'))
        fails(iso, lambda o: st(o, 'account_switch').update(more_polls=2))
        snd = 'notification_bell_sound_signal_rules'
        fails(snd, lambda o: st(o, 'initial')['bell'].update(plays=1))
        fails(snd, lambda o: st(o, 'new_sound_off')['bell'].update(plays=1))
        fails(snd, lambda o: st(o, 'repeat_poll')['bell'].update(plays=1))
        fails(snd, lambda o: st(o, 'count_only')['bell'].update(plays=1))
        fails(snd, lambda o: st(o, 'batch')['bell'].update(plays=3))
        fails(snd, lambda o: st(o, 'batch')['bell'].update(plays=0))
        fails(snd, lambda o: st(o, 'tabs').update(plays=[1, 1]))
        fails(snd, lambda o: st(o, 'tabs').update(storage=['SecurityError', 'available']))
        fails(snd, lambda o: o['steps'].pop('tabs_nostorage'))
        fails(snd, lambda o: st(o, 'tabs_nostorage').update(plays=[1, 1]))           # duplicate without storage (finding C)
        fails(snd, lambda o: st(o, 'tabs_nostorage').update(plays=[0, 0]))           # silenced instead of one signal
        fails(snd, lambda o: st(o, 'tabs_nostorage').update(storage=['available', 'SecurityError']))  # storage not blocked
        fails(snd, lambda o: st(o, 'tabs_nostorage')['bells'][1].update(state='unavailable'))
        fails(snd, lambda o: st(o, 'tabs_nostorage')['bells'][0]['ids'].remove('n6'))
        fails(snd, lambda o: st(o, 'tabs_nostorage')['other_context'].update(plays=1))
        # Review finding 1: a late claim answer after the sound was turned off (or off and on).
        fails(snd, lambda o: st(o, 'late_claim')['off']['after'].update(signals=4, plays=1))
        fails(snd, lambda o: st(o, 'late_claim')['off_on']['after'].update(signals=4, plays=3))
        fails(snd, lambda o: st(o, 'late_claim')['off']['after'].update(plays=1))              # unexplained play()
        fails(snd, lambda o: st(o, 'late_claim')['off']['answers'][0].update(play=False))      # race not reproduced
        fails(snd, lambda o: st(o, 'late_claim')['off'].update(answers=[]))
        fails(snd, lambda o: st(o, 'late_claim')['off'].update(pref_before_release='1'))      # not turned off in time
        fails(snd, lambda o: st(o, 'late_claim')['normal']['after'].update(signals=3, plays=2))  # normal signal lost
        fails(snd, lambda o: st(o, 'late_claim').pop('off_on'))
        # Review finding 2: dedup by identity, real concurrency.
        fails(snd, lambda o: st(o, 'claims')['cross'].update(then_a=self.run_(False)))
        fails(snd, lambda o: st(o, 'claims')['order'].update(then_c1=self.run_(False)))
        fails(snd, lambda o: st(o, 'claims')['cross'].update(again_a=self.run_(True)))
        fails(snd, lambda o: st(o, 'claims')['cross'].update(a_in_b=self.run_(True)))
        fails(snd, lambda o: st(o, 'claims')['cross']['a'].update(id=103))                     # fixture order not proven
        fails(snd, lambda o: st(o, 'claims')['parallel_same']['plays'].__setitem__(1, True))
        fails(snd, lambda o: st(o, 'claims')['parallel_same'].update(inflight_at_first_end=1))  # sequential, not parallel
        fails(snd, lambda o: [r.update(pid=1) for r in st(o, 'claims')['parallel_same']['results']])
        fails(snd, lambda o: st(o, 'claims')['parallel_companies']['plays'].__setitem__(6, False))
        fails(snd, lambda o: st(o, 'claims')['parallel_same']['plays'].pop())
        fails(snd, lambda o: st(o, 'claims')['batch']['repeat']['plays'].__setitem__(2, True))
        fails(snd, lambda o: st(o, 'claims')['dual'].update(companies=['A']))
        fails(snd, lambda o: o['steps'].pop('claims'))
        fails(snd, lambda o: st(o, 'reload')['bell'].update(plays=1))
        fails(snd, lambda o: st(o, 'reconnect').update(polls_while_offline=1))
        fails(snd, lambda o: st(o, 'reconnect')['bell'].update(plays=2))
        fails(snd, lambda o: st(o, 'disable')['bell'].update(plays=1))
        pref = 'notification_bell_sound_preference'
        fails(pref, lambda o: st(o, 'initial')['bell'].update(sound='on'))
        fails(pref, lambda o: st(o, 'initial')['server'].update(pref='1'))
        fails(pref, lambda o: st(o, 'enable').update(plays_after_enable=0))
        fails(pref, lambda o: st(o, 'enable')['server'].update(pref='0'))
        fails(pref, lambda o: st(o, 'enable').update(other_account_pref='1'))
        fails(pref, lambda o: st(o, 'autoplay_refused')['blocked'].update(sound='on'))
        fails(pref, lambda o: st(o, 'csrf').update(pref='0'))
        fails(pref, lambda o: st(o, 'csrf')['wrong'].update(ok=True))
        fails(pref, lambda o: st(o, 'disable').update(pref='1'))
        lay = 'notification_bell_panel_layout_keyboard'
        fails(lay, lambda o: o['widths'].pop())
        fails(lay, lambda o: o['widths'][2]['open_click'].update(right=330))
        fails(lay, lambda o: o['widths'][2]['open_click'].update(bottom=700))
        fails(lay, lambda o: o['widths'][1]['open_click'].update(maintop=300))     # panel pushed the page
        fails(lay, lambda o: o['widths'][0]['open_click'].update(position='static'))
        fails(lay, lambda o: o['widths'][0]['open_click'].update(overflowy='visible'))
        fails(lay, lambda o: o['widths'][2]['open_keyboard'].update(hscroll=True))
        fails(lay, lambda o: o['widths'][0]['open_keyboard'].update(active='other'))
        fails(lay, lambda o: o['widths'][0]['after_escape'].update(active='other'))
        fails(lay, lambda o: o['widths'][1]['after_outside'].update(open=True))
        fails(lay, lambda o: o['widths'][1].update(focus_outline=['none', 0]))
        one = 'notification_bell_single_bell'
        fails(one, lambda o: st(o, 'initial')['bell'].update(bells=2))
        fails(one, lambda o: st(o, 'reload')['bell'].update(moodlebells=1))
        fails(one, lambda o: st(o, 'legacy').update(bells=1))
        fails(one, lambda o: st(o, 'initial')['bell'].update(headings=1))           # hidden h2 shadows the page title
        fails(one, lambda o: st(o, 'reload')['bell'].pop('headings'))
        be = 'notification_bell_no_browser_errors'
        fails(be, lambda o: o['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(be, lambda o: o['browser_events'].append({'source': 'console', 'type': 'error', 'text': 'Failed to load resource: 403',
                                                        'url': 'http://127.0.0.1:8080' + c.FEED_PATH}))
        fails(be, lambda o: o.update(server_errors=[{'status': 500}]))
        fails(be, lambda o: o.pop('browser_events'))
        intentionally_blocked = copy.deepcopy(obs)
        st(intentionally_blocked, 'tabs_nostorage')['events'].extend(copy.deepcopy(ABORTED))
        self.assertEqual('PASS', c.evaluate(c.notification_bell_checks(intentionally_blocked, 0))[be]['status'])
        fails(be, lambda o: st(o, 'tabs_nostorage')['events'].extend(copy.deepcopy(ABORTED[1:])))
        fails(be, lambda o: st(o, 'tabs_nostorage')['events'].append(dict(ABORTED[0], url='https://example.invalid/script.js')))
        fails(be, lambda o: st(o, 'tabs_nostorage')['events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(be, lambda o: st(o, 'tabs_nostorage')['events'].append({'source': 'console', 'type': 'error', 'text': 'TypeError: x'}))
        fails(be, lambda o: st(o, 'tabs_nostorage')['events'].append({'source': 'requestfailed', 'url': 'http://127.0.0.1:8080/x'}))
        fails(be, lambda o: st(o, 'tabs_nostorage').pop('events'))
        for mutate, code in ((lambda o: o.update(error='boom'), 0), (lambda o: o.update(status='FAIL'), 0),
                             (lambda o: o.update(cleanup_error='x'), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.notification_bell_checks(o, code))
            self.assertTrue(all(x['status'] == 'FAIL' for x in r.values()), r)
        self.assertTrue(all(x['status'] == 'FAIL' for x in c.evaluate(c.notification_bell_checks(None, None)).values()))

    def office_obs(self):
        company = 'a1b2c3d4-0000-4000-8000-00000000000a'
        ea = {'acknowledge': 1, 'decisions': 0, 'directives': 0, 'documents': 3, 'execution': 2, 'overdue': 1, 'review': 1}
        ma = {'acknowledge': 0, 'decisions': 0, 'directives': 0, 'documents': 6, 'execution': 0, 'overdue': 0, 'review': 2}
        eb = {'acknowledge': 0, 'decisions': 0, 'directives': 0, 'documents': 1, 'execution': 1, 'overdue': 1, 'review': 1}
        shown = lambda m: {k: v for k, v in m.items() if k != 'decisions'}
        planned = [{'key': k, 'tag': 'span', 'inlink': False, 'href': None, 'disabled': 'true'} for k in c.OFFICE_PLANNED]
        active = [{'path': p, 'uuid': company, 'href': 'http://127.0.0.1:8080/local/kbacompany/' + p, 'current': None}
                  for p in c.OFFICE_ACTIVE]
        active[0]['current'] = 'page'
        cases = []
        for shell, width, lang in c.OFFICE_CASES:
            page = {'workspace': True, 'http': 200, 'counts': shown(ea), 'planned': copy.deepcopy(planned),
                    'active': copy.deepcopy(active), 'h1': [c.OFFICE_TITLES[lang]], 'empty': [], 'create': 0,
                    'owned_shell': shell == 'owned', 'leaked': [], 'hscroll': {'scroll': width, 'inner': width},
                    'menu': [{'uuid': company, 'current': 'page', 'visible': True}] if shell == 'owned' else []}
            case = {'shell': shell, 'width': width, 'lang': lang, 'language_installed': True, 'page': page,
                    'browser_events': [], 'server_errors': []}
            if shell == 'owned' and width == 1440:
                case['landing'] = [{'path': p, 'http': 200, 'workspace_error': False} for p in c.OFFICE_ACTIVE]
            cases.append(case)
        denied = {'workspace': False, 'http': 404, 'counts': {}, 'leaked': [], 'menu': []}
        return {'status': 'PASS', 'company': company, 'cases': cases,
                'switch_off': {'enabled': False}, 'switch_on': {'enabled': True},
                'off': dict(denied, menu_on_documents=[]), 'model': {'EA': ea, 'MA': ma, 'EB': eb},
                'manager': {'workspace': True, 'http': 200, 'counts': shown(ma), 'create': 1, 'leaked': [], 'browser_events': []},
                'foreign': dict(denied), 'own_b': {'workspace': True, 'http': 200, 'counts': shown(eb), 'leaked': []},
                'revoked_flag': True, 'reopened_flag': True,
                'revocation': {'before': {'workspace': True, 'http': 200, 'counts': shown(ea)}, 'after': dict(denied)}}

    def test_office_workspace_genuine_run_passes(self):
        res = c.evaluate(c.office_workspace_checks(self.office_obs(), 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()})
        self.assertEqual(7, len(res))
        for k in res:
            self.assertIn(k, c.required_check_names())

    def test_office_workspace_regressions_fail(self):
        mutations = [
            ('office_switch_off_hides_route_and_menu', lambda o: o['off'].update(workspace=True)),
            ('office_switch_off_hides_route_and_menu', lambda o: o['off'].update(menu_on_documents=[{'uuid': 'x'}])),
            ('office_switch_off_hides_route_and_menu', lambda o: o['switch_off'].update(enabled=True)),
            ('office_switch_off_hides_route_and_menu', lambda o: o['off'].update(http=500)),
            ('office_counters_equal_service_ground_truth', lambda o: o['cases'][1]['page']['counts'].update(review=5)),
            ('office_counters_equal_service_ground_truth', lambda o: o['cases'][2]['page']['counts'].pop('acknowledge')),
            ('office_counters_equal_service_ground_truth', lambda o: o['cases'][0]['page'].update(leaked=['OFFICE CI REVIEW B'])),
            ('office_counters_equal_service_ground_truth', lambda o: o['cases'][3]['page'].update(create=1)),
            ('office_counters_equal_service_ground_truth', lambda o: o['cases'][1]['page'].update(h1=['Office'])),
            ('office_menu_and_planned_sections', lambda o: o['cases'][0]['page']['planned'][2].update(tag='a')),
            ('office_menu_and_planned_sections', lambda o: o['cases'][0]['page']['planned'][0].update(inlink=True)),
            ('office_menu_and_planned_sections', lambda o: o['cases'][1]['page']['planned'].pop()),
            ('office_menu_and_planned_sections', lambda o: o['cases'][0]['page']['active'][3].update(uuid='other')),
            ('office_menu_and_planned_sections', lambda o: o['cases'][0]['page']['active'][2].update(current='page')),
            ('office_menu_and_planned_sections', lambda o: o['cases'][1]['page'].update(menu=[])),
            ('office_menu_and_planned_sections', lambda o: o['cases'][0]['page']['menu'][0].update(current=None)),
            ('office_sections_open_canonical_pages', lambda o: o['cases'][0]['landing'][3].update(http=403)),
            ('office_sections_open_canonical_pages', lambda o: o['cases'][0].pop('landing')),
            ('office_manager_view_and_company_isolation', lambda o: o['manager']['counts'].update(documents=1)),
            ('office_manager_view_and_company_isolation', lambda o: o['manager'].update(create=0)),
            ('office_manager_view_and_company_isolation', lambda o: o['foreign'].update(workspace=True)),
            ('office_manager_view_and_company_isolation', lambda o: o['foreign'].update(leaked=['OFFICE CI EFFECTIVE A'])),
            ('office_manager_view_and_company_isolation', lambda o: o['foreign'].update(counts={'review': 1})),
            ('office_manager_view_and_company_isolation', lambda o: o['own_b']['counts'].update(overdue=2)),
            ('office_revocation_closes_workspace', lambda o: o['revocation']['after'].update(workspace=True)),
            ('office_revocation_closes_workspace', lambda o: o.update(revoked_flag=False)),
            ('office_revocation_closes_workspace', lambda o: o.update(reopened_flag=False)),
            ('office_layout_and_browser_errors', lambda o: o['cases'][1]['page'].update(hscroll={'scroll': 420, 'inner': 375})),
            ('office_layout_and_browser_errors', lambda o: o['cases'][2].update(server_errors=[{'url': 'x', 'status': 500}])),
            ('office_layout_and_browser_errors',
             lambda o: o['cases'][3].update(browser_events=[{'source': 'pageerror', 'name': 'Error', 'message': 'boom'}])),
        ]
        for name, mutate in mutations:
            with self.subTest(check=name):
                o = self.office_obs(); mutate(o)
                res = c.evaluate(c.office_workspace_checks(o, 0))
                self.assertEqual('FAIL', res[name]['status'], res[name])

    def test_office_workspace_incomplete_runs_fail_every_check(self):
        obs = self.office_obs()
        for mutate, code in ((lambda o: o.update(error='boom'), 0), (lambda o: o.update(status='FAIL'), 0),
                             (lambda o: o['cases'].pop(), 0), (lambda o: o['model'].pop('EB'), 0),
                             (lambda o: o['model']['EA'].update(acknowledge=0), 0), (lambda o: None, 1)):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.office_workspace_checks(o, code))
            self.assertTrue(all(x['status'] == 'FAIL' for x in r.values()), r)
        self.assertTrue(all(x['status'] == 'FAIL' for x in c.evaluate(c.office_workspace_checks(None, None)).values()))

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

    @staticmethod
    def infocenter_obs():
        import datetime
        week = lambda i: (datetime.date(2026, 1, 5) + datetime.timedelta(days=7 * i)).isoformat()  # noqa: E731
        p = [week(i) for i in range(21) if i != 15]
        facts = [100 + 5 * i for i in range(21) if i != 15]
        metrics = {code: {'uuid': 'u-' + code, 'company': 'B' if code == 'CIIC-B' else 'A', 'periods': [], 'facts': []}
                   for code in c.INFOCENTER_CODES}
        metrics['CIIC-M'].update(periods=p, facts=facts)
        for code, values in (('CIIC-POS', [50, 48, 47, 45, 43, 41, 40, 38]), ('CIIC-DEP', [7]), ('CIIC-NEG', [-6, -3, 0, 2, -1, 4]),
                             ('CIIC-FLAT', [1000] * 6), ('CIIC-LOOSE', [3, 4, 6]), ('CIIC-B', [3, 4, 6])):
            metrics[code].update(periods=[week(i) for i in range(len(values))], facts=values)

        def tile(code, name, symbol, better, dots, lines, **extra):
            t = {'name': '%s — %s' % (code, name), 'value': '200 RUB · ' + p[-1], 'trend': symbol + 'Rising · better',
                 'symbol': symbol, 'better': better, 'worse': False, 'nodata': '', 'single': 0, 'dots': dots, 'lines': lines,
                 'grid': 3 if dots else 0, 'range': p[0] + p[-1] if dots else '', 'tag': 'BUTTON', 'type': 'button',
                 'aria': 'Open %s — %s' % (code, name), 'haspopup': 'dialog', 'points': dots, 'gaps': 0,
                 'mini': {'engine': 'echarts', 'instance': True, 'dots': dots, 'lines': lines, 'grid': 3, 'gaps': 0,
                          'datazoom': 0, 'connect': False, 'symbols': True, 'silent': True, 'svg': 1, 'paths': dots + lines + 5,
                          'svg_w': 300, 'box_w': 300, 'height': 72, 'pointer': 'none',
                          'attr': {'dots': dots, 'lines': lines, 'grid': 3}} if dots else
                 {'engine': None, 'dots': 0, 'lines': 0, 'grid': 0, 'datazoom': 0, 'svg': 0, 'paths': 0, 'height': 0, 'attr': None}}
            t.update(extra)
            return t
        main = tile('CIIC-M', 'CI revenue', '↑', True, 13, 2, points=21, gaps=1)
        main['mini']['height'] = 140
        groups1 = [{'kind': 'Unit', 'title': 'CIIC 1.1 CI department',
                    'tiles': [tile('CIIC-DEP', 'CI single', '·', False, 1, 0, single=1)]},
                   {'kind': 'Position', 'title': 'CIIC-P CI quality lead', 'tiles': [tile('CIIC-POS', 'CI defects', '↓', True, 8, 1)]}]
        groups2 = [{'kind': 'Division', 'title': 'CIIC 2 CI second division',
                    'tiles': [tile('CIIC-NEG', 'CI balance', '≈', False, 6, 1), tile('CIIC-FLAT', 'CI flat', '→', False, 6, 1),
                              tile('CIIC-EMPTY', 'CI empty', '·', False, 0, 0, nodata='No data')]}]

        def snap(start, end, span, selected, **extra):
            s = {'open': True, 'title': 'CIIC-M — CI revenue', 'meta': 'Unit: RUB · ↑ Rising · better', 'window': '26',
                 'visible': '20 periods', 'readout': selected + ' — Fact: 200 RUB', 'legend': 4, 'start': start, 'end': end,
                 'span': span, 'selected': selected, 'lo': 100, 'hi': 220, 'empty': False, 'gaps': 1, 'factlines': 2,
                 'planlines': 2, 'trendlines': 1, 'trend_dash': 'dashed', 'dots': 20, 'texts': [p[0], p[-1]],
                 'focus_in_chart': True, 'focus_tile': '', 'box': {'x': 8, 'r': 360}, 'engine': 'echarts', 'instance': True,
                 'empty_text': '', 'paths': 80, 'svg_w': 1000, 'canvas_w': 1000}
            s.update(extra)
            s['opt'] = dict({k: s[k] for k in c.INFOCENTER_VIEW_KEYS}, fact_connect=False, plan_connect=False, symbols=True,
                            inside=True, trend_type='dashed', series=['fact', 'plan', 'trend'], zero_line=False)
            return s

        def closed(s, **extra):
            out = dict(s, open=False, instance=False, engine=None, svg_w=0, canvas_w=0, paths=0, texts=[], opt=None)
            out.update(extra)
            return out
        full = (p[0], p[-1], 20)
        main_chart = {'open': snap(p[-13], p[-1], 13, p[-1], window='13', dots=13, lo=130, hi=210),
                      'window26': snap(*full, p[-1]), 'zoomin': snap(p[3], p[18], 15, p[-1]),
                      'panleft': snap(p[0], p[15], 15, p[-1]), 'panright': snap(p[4], p[19], 15, p[-1]),
                      'zoomout': snap(p[2], p[-1], 18, p[-1]), 'reset': snap(*full, p[-1]),
                      'key_plus': snap(p[4], p[18], 15, p[-6]), 'key_shift_left': snap(p[0], p[14], 15, p[-6]),
                      'key_reset': snap(*full, p[-1]), 'key_home': snap(*full, p[0]), 'key_end': snap(*full, p[-1]),
                      'wheel_in': snap(p[3], p[18], 15, p[-1]), 'drag_right': snap(p[0], p[15], 15, p[-1]),
                      'reset2': snap(*full, p[-1]), 'pinch_out': snap(p[5], p[15], 10, p[-1]),
                      'click': snap(*full, p[-4]), 'resized': snap(*full, p[-1], svg_w=700, canvas_w=700),
                      'restored': snap(*full, p[-1])}
        main_chart['closed'] = closed(main_chart['restored'], focus_tile='CIIC-M — CI revenue')
        for k in range(1, 6):
            main_chart['left%d' % k] = snap(*full, p[-1 - k])

        def other(meta, **extra):
            s = snap(p[14], p[19], 5, p[19], meta=meta, window='13', **extra)
            s['closed'] = closed(s)
            return s
        charts = {'CIIC-FLAT': other('→', lo=900, hi=1100, dots=6), 'CIIC-NEG': other('≈', lo=-8, hi=6, dots=6, texts=['-5', '0', '5']),
                  'CIIC-DEP': other('·', dots=1, factlines=0, trendlines=0),
                  'CIIC-EMPTY': other('·', dots=0, empty=True, readout='No data', lo=None, hi=None, instance=False,
                                      empty_text='No data', texts=[]),
                  'CIIC-POS': other('↓ Falling · better', dots=8)}
        charts['CIIC-NEG']['opt']['zero_line'] = True
        charts['CIIC-EMPTY']['opt'] = None
        reopen = snap(p[-13], p[-1], 13, p[-1], window='13', dots=13, lo=130, hi=210)
        reopen['closed'] = closed(reopen, focus_tile='CIIC-M — CI revenue')
        rows = []
        for cid, shell, width in c.INFOCENTER_CASES:
            phone = width < c.INFOCENTER_MOBILE
            boxes = [{'x': 8, 'y': 100 + 400 * i, 'r': width - 8, 'w': width - 16} if phone else
                     {'x': 20 + 400 * i, 'y': 300, 'r': 400 + 400 * i, 'w': 380} for i in range(3)]
            cols = [{'title': 'CIIC 1 CI first division', 'role': 'listitem', 'box': boxes[0], 'empty': 0, 'groups': groups1},
                    {'title': 'CIIC 2 CI second division', 'role': 'listitem', 'box': boxes[1], 'empty': 0, 'groups': groups2},
                    {'title': 'CIIC 3 CI third division', 'role': 'listitem', 'box': boxes[2], 'empty': 1, 'groups': []}]
            ov = {'main': [main], 'columns': cols, 'unbound': [tile('CIIC-LOOSE', 'CI unbound', '↑', True, 3, 1)],
                  'board_role': 'list', 'page_scroll': width, 'page_client': width, 'inner': width, 'dialog_open': False,
                  'all_tiles': 8}
            mc = copy.deepcopy(main_chart)
            mc['pinch_out']['pinch_mode'] = 'touch' if phone else 'pointer'
            rows.append({'id': cid, 'shell': shell, 'width': width, 'http': 200, 'owned': shell == 'owned',
                         'overview': copy.deepcopy(ov), 'main_chart': mc, 'charts': copy.deepcopy(charts),
                         'reopen': copy.deepcopy(reopen), 'lazy_initial': {'total': 6, 'ready': 3 if phone else 6},
                         'lazy_final': {'total': 6, 'ready': 6},
                         'module_requests': ['http://127.0.0.1:8080/lib/requirejs.php/1764000000/local_kbacompany/echarts-lazy.js'],
                         'resize': {'mini_before': {'svg_w': 400, 'box_w': 400}, 'mini_after': {'svg_w': 300, 'box_w': 300}},
                         'browser_events': list(ABORTED), 'server_errors': []})
        return {'status': 'PASS', 'fixture': {'metrics': metrics, 'divisions': ['CIIC 1', 'CIIC 2', 'CIIC 3']}, 'cases': rows,
                'foreign': {'company_a': {'http': 200, 'tiles': 0, 'names': [], 'a_codes_in_body': [], 'a_divisions_in_body': []},
                            'company_b': {'http': 200, 'tiles': 1, 'names': ['CIIC-B — CI company B only'],
                                          'a_codes_in_body': [], 'a_divisions_in_body': []}},
                'cleanup': {'phase': 'cleanup', 'clean': True}}

    def test_infocenter_valid_passes_and_mutations_fail(self):
        obs = self.infocenter_obs()
        res = c.evaluate(c.infocenter_checks(obs, 0))
        self.assertEqual({k: 'PASS' for k in res}, {k: v['status'] for k, v in res.items()}, res)
        self.assertTrue(set(res) <= set(c.required_check_names()))
        self.assertEqual(7, len(res))

        def fails(check, mutate):
            o = copy.deepcopy(obs); mutate(o)
            r = c.evaluate(c.infocenter_checks(o, 0))
            self.assertEqual('FAIL', r[check]['status'], (check, r[check]))
        board, mini, layout, axes, controls, shapes, iso = ('infocenter_board_order_and_registry_bindings',
                                                             'infocenter_mini_charts_and_trend_text', 'infocenter_layout_1440_and_375',
                                                             'infocenter_chart_axes_gap_plan_trend',
                                                             'infocenter_chart_window_zoom_pan_keyboard_touch',
                                                             'infocenter_chart_flat_negative_single_empty',
                                                             'infocenter_company_isolation_and_browser_errors')
        cols = lambda o, i=0: o['cases'][i]['overview']['columns']  # noqa: E731
        # Run integrity.
        for name in res:
            fails(name, lambda o: o.update(status='FAIL'))
            fails(name, lambda o: o['cases'].pop())
            fails(name, lambda o: o['cases'][1].update(http=500))
            fails(name, lambda o: o['cases'][2].update(owned=True))
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.infocenter_checks(obs, 1)).values()))
        self.assertTrue(all(v['status'] == 'FAIL' for v in c.evaluate(c.infocenter_checks(None, None)).values()))
        # Board: order, groups, bindings, empty division, main, unbound, company B.
        fails(board, lambda o: cols(o).reverse())
        fails(board, lambda o: cols(o, 1)[0]['groups'].reverse())
        fails(board, lambda o: cols(o, 2)[1]['groups'][0]['tiles'].pop())
        fails(board, lambda o: cols(o)[1]['groups'][0]['tiles'].append(o['cases'][0]['overview']['unbound'][0]))
        fails(board, lambda o: cols(o)[0]['groups'][1].update(kind='Unit'))
        fails(board, lambda o: cols(o)[1]['groups'][0].update(kind='Unit'))
        fails(board, lambda o: cols(o)[1]['groups'][0].update(kind=''))
        fails(board, lambda o: cols(o)[2].update(empty=0))
        fails(board, lambda o: o['cases'][0]['overview'].update(main=[]))
        fails(board, lambda o: o['cases'][1]['overview'].update(unbound=[]))
        fails(board, lambda o: o['cases'][2]['overview']['unbound'].append({'name': 'CIIC-B — CI company B only'}))
        fails(board, lambda o: o['cases'][0]['overview'].update(board_role=None))
        # Mini charts and trend text.
        fails(mini, lambda o: o['cases'][0]['overview']['main'][0].update(symbol='↓'))
        fails(mini, lambda o: o['cases'][1]['overview']['main'][0].update(better=False))
        fails(mini, lambda o: cols(o)[0]['groups'][1]['tiles'][0].update(better=False, worse=True))
        fails(mini, lambda o: cols(o, 2)[0]['groups'][0]['tiles'][0].update(trend='↑'))
        fails(mini, lambda o: cols(o)[1]['groups'][0]['tiles'][1].update(better=True))
        fails(mini, lambda o: cols(o)[1]['groups'][0]['tiles'][2].update(nodata=''))
        fails(mini, lambda o: cols(o)[0]['groups'][0]['tiles'][0].update(lines=1))
        fails(mini, lambda o: cols(o)[0]['groups'][0]['tiles'][0].update(single=0))
        fails(mini, lambda o: o['cases'][0]['overview']['main'][0].update(lines=1))
        fails(mini, lambda o: o['cases'][0]['overview']['main'][0].update(gaps=0))
        fails(mini, lambda o: o['cases'][1]['overview']['main'][0].update(range=''))
        fails(mini, lambda o: o['cases'][2]['overview']['main'][0].update(tag='DIV'))
        fails(mini, lambda o: o['cases'][0]['overview']['unbound'][0].update(aria=''))
        # Mini charts on ECharts (#192): engine, option vs published state, no zoom, not interactive, sized, heights,
        # lazy initialisation, one module request from Moodle.
        mm = lambda o, i=0: o['cases'][i]['overview']['main'][0]['mini']  # noqa: E731
        cm = lambda o, i=0: cols(o, i)[0]['groups'][1]['tiles'][0]['mini']  # noqa: E731
        fails(mini, lambda o: mm(o).update(engine=None))
        fails(mini, lambda o: mm(o, 1).update(instance=False))
        fails(mini, lambda o: cm(o, 2).update(svg=0))
        fails(mini, lambda o: cm(o).update(paths=3))
        fails(mini, lambda o: cm(o).update(attr={'dots': 7, 'lines': 1, 'grid': 3}))
        fails(mini, lambda o: mm(o).update(datazoom=1))
        fails(mini, lambda o: cm(o, 1).update(silent=False))
        fails(mini, lambda o: cm(o).update(pointer='auto'))
        fails(mini, lambda o: mm(o, 2).update(connect=True))
        fails(mini, lambda o: cm(o).update(symbols=False))
        fails(mini, lambda o: cm(o).update(svg_w=280))
        fails(mini, lambda o: mm(o).update(height=72))
        fails(mini, lambda o: cm(o, 1).update(height=20))
        fails(mini, lambda o: cols(o)[1]['groups'][0]['tiles'][2]['mini'].update(engine='echarts'))
        fails(mini, lambda o: o['cases'][1].update(lazy_initial={'total': 6, 'ready': 6}))
        fails(mini, lambda o: o['cases'][0].update(lazy_initial={'total': 6, 'ready': 0}))
        fails(mini, lambda o: o['cases'][2].update(lazy_final={'total': 6, 'ready': 5}))
        fails(mini, lambda o: o['cases'][0]['module_requests'].append(o['cases'][0]['module_requests'][0]))
        fails(mini, lambda o: o['cases'][1].update(module_requests=[]))
        fails(mini, lambda o: o['cases'][2].update(module_requests=['https://cdn.example.org/local_kbacompany/echarts-lazy.js']))
        # Layout.
        fails(layout, lambda o: o['cases'][1]['overview'].update(page_scroll=420))
        fails(layout, lambda o: cols(o, 1)[1]['box'].update(r=420))
        fails(layout, lambda o: cols(o, 2)[1]['box'].update(y=cols(o, 2)[0]['box']['y']))
        fails(layout, lambda o: cols(o)[1]['box'].update(y=900))
        fails(layout, lambda o: o['cases'][0]['resize'].update(mini_after={'svg_w': 400, 'box_w': 400}))
        fails(layout, lambda o: o['cases'][1]['resize'].update(mini_after={'svg_w': 300, 'box_w': 260}))
        fails(layout, lambda o: o['cases'][2].pop('resize'))
        # Large chart: window, gap, plan, trend, legend, axis fit, readout, focus.
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(window='26'))
        fails(axes, lambda o: o['cases'][1]['main_chart']['open'].update(start=o['fixture']['metrics']['CIIC-M']['periods'][0]))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(factlines=1))
        fails(axes, lambda o: o['cases'][2]['main_chart']['open'].update(gaps=0))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(trendlines=0))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(trend_dash=None))
        fails(axes, lambda o: o['cases'][1]['main_chart']['open'].update(planlines=0))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(lo=0))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(hi=150))
        fails(axes, lambda o: o['cases'][2]['main_chart']['open'].update(readout=''))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(focus_in_chart=False))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(meta='Unit: RUB'))
        # ECharts drawing: instance, option agrees with the published state, breaks, dashed trend, inside zoom, SVG.
        op = lambda o, i=0: o['cases'][i]['main_chart']['open']['opt']  # noqa: E731
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(engine=None))
        fails(axes, lambda o: o['cases'][1]['main_chart']['open'].update(instance=False))
        fails(axes, lambda o: o['cases'][2]['main_chart']['open'].update(opt=None))
        fails(axes, lambda o: op(o).update(start=o['fixture']['metrics']['CIIC-M']['periods'][0]))
        fails(axes, lambda o: op(o, 1).update(lo=0))
        fails(axes, lambda o: op(o).update(factlines=1))
        fails(axes, lambda o: op(o, 2).update(gaps=0))
        fails(axes, lambda o: op(o).update(selected=None))
        fails(axes, lambda o: op(o).update(fact_connect=True))
        fails(axes, lambda o: op(o, 1).update(plan_connect=True))
        fails(axes, lambda o: op(o).update(trend_type='solid'))
        fails(axes, lambda o: op(o).update(series=['fact', 'trend']))
        fails(axes, lambda o: op(o).update(inside=False))
        fails(axes, lambda o: op(o).update(symbols=False))
        fails(axes, lambda o: o['cases'][0]['main_chart']['open'].update(paths=4))
        fails(axes, lambda o: o['cases'][1]['main_chart']['open'].update(svg_w=900))
        fails(axes, lambda o: o['cases'][2]['main_chart']['open'].update(texts=[]))
        # Controls: window, buttons, keyboard over the gap, wheel, drag, pinch, reset, Escape and focus.
        fails(controls, lambda o: o['cases'][0]['main_chart']['window26'].update(span=13))
        fails(controls, lambda o: o['cases'][0]['main_chart']['zoomin'].update(span=20))
        fails(controls, lambda o: o['cases'][1]['main_chart'].update(panleft=o['cases'][1]['main_chart']['zoomin']))
        fails(controls, lambda o: o['cases'][2]['main_chart'].update(panright=o['cases'][2]['main_chart']['panleft']))
        fails(controls, lambda o: o['cases'][0]['main_chart']['reset'].update(span=15))
        fails(controls, lambda o: o['cases'][1]['main_chart']['key_reset'].update(start='2026-02-02'))
        fails(controls, lambda o: o['cases'][0]['main_chart']['left5'].update(
            selected='2026-04-20', readout='2026-04-20 — gap'))
        fails(controls, lambda o: o['cases'][0]['main_chart']['left2'].update(readout='no date'))
        fails(controls, lambda o: o['cases'][2]['main_chart']['key_plus'].update(span=20))
        fails(controls, lambda o: o['cases'][0]['main_chart'].update(key_shift_left=o['cases'][0]['main_chart']['key_plus']))
        fails(controls, lambda o: o['cases'][1]['main_chart']['key_home'].update(selected=o['fixture']['metrics']['CIIC-M']['periods'][1]))
        fails(controls, lambda o: o['cases'][0]['main_chart']['wheel_in'].update(span=20))
        fails(controls, lambda o: o['cases'][1]['main_chart'].update(drag_right=o['cases'][1]['main_chart']['wheel_in']))
        fails(controls, lambda o: o['cases'][2]['main_chart']['pinch_out'].update(span=20))
        fails(controls, lambda o: o['cases'][0]['main_chart']['closed'].update(open=True))
        fails(controls, lambda o: o['cases'][1]['main_chart']['closed'].update(focus_tile=''))
        # Click, pinch input, option agreement after every step, resize, disposal and re-opening (#192).
        mc = lambda o, i=0: o['cases'][i]['main_chart']  # noqa: E731
        fails(controls, lambda o: mc(o)['click'].update(selected=o['fixture']['metrics']['CIIC-M']['periods'][-1]))
        fails(controls, lambda o: mc(o, 1)['click'].update(readout='nothing'))
        fails(controls, lambda o: mc(o, 1)['pinch_out'].update(pinch_mode='pointer'))
        fails(controls, lambda o: mc(o)['pinch_out'].update(pinch_mode=None))
        fails(controls, lambda o: mc(o)['wheel_in']['opt'].update(span=20))
        fails(controls, lambda o: mc(o, 2)['drag_right']['opt'].update(start=o['fixture']['metrics']['CIIC-M']['periods'][3]))
        fails(controls, lambda o: mc(o, 1)['pinch_out'].update(instance=False))
        fails(controls, lambda o: mc(o)['key_plus']['opt'].update(lo=1))
        fails(controls, lambda o: mc(o)['left5']['opt'].update(selected=None))
        fails(controls, lambda o: mc(o, 2)['resized'].update(svg_w=1000, canvas_w=1000))
        fails(controls, lambda o: mc(o)['resized'].update(canvas_w=650))
        fails(controls, lambda o: mc(o)['closed'].update(instance=True))
        fails(controls, lambda o: mc(o, 1)['closed'].update(svg_w=1000))
        fails(controls, lambda o: o['cases'][0]['reopen'].update(window='26'))
        fails(controls, lambda o: o['cases'][1]['reopen'].update(start=o['fixture']['metrics']['CIIC-M']['periods'][0]))
        fails(controls, lambda o: o['cases'][2]['reopen'].update(instance=False))
        fails(controls, lambda o: o['cases'][0]['reopen']['closed'].update(instance=True))
        fails(controls, lambda o: o['cases'][1]['reopen']['closed'].update(focus_tile=''))
        fails(controls, lambda o: o['cases'][2].pop('reopen'))
        # Shapes: flat padding, negatives with zero, single point, empty, falling metric, dialog width.
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-FLAT'].update(lo=0))
        fails(shapes, lambda o: o['cases'][1]['charts']['CIIC-NEG'].update(lo=0))
        fails(shapes, lambda o: o['cases'][2]['charts']['CIIC-NEG'].update(texts=['-5', '5']))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-DEP'].update(factlines=1))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-EMPTY'].update(empty=False))
        fails(shapes, lambda o: o['cases'][1]['charts']['CIIC-EMPTY'].update(readout=''))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-POS'].update(meta='↑'))
        fails(shapes, lambda o: o['cases'][2]['charts']['CIIC-FLAT']['closed'].update(open=True))
        fails(shapes, lambda o: o['cases'][1]['charts'].pop('CIIC-NEG'))
        fails(shapes, lambda o: o['cases'][1]['main_chart']['open'].update(box={'x': 0, 'r': 500}))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-NEG']['opt'].update(zero_line=False))
        fails(shapes, lambda o: o['cases'][1]['charts']['CIIC-FLAT']['opt'].update(lo=0))
        fails(shapes, lambda o: o['cases'][2]['charts']['CIIC-DEP'].update(instance=False))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-POS']['opt'].update(dots=7))
        fails(shapes, lambda o: o['cases'][1]['charts']['CIIC-EMPTY'].update(instance=True))
        fails(shapes, lambda o: o['cases'][2]['charts']['CIIC-EMPTY'].update(empty_text=''))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-EMPTY'].update(texts=['0']))
        fails(shapes, lambda o: o['cases'][0]['charts']['CIIC-FLAT']['closed'].update(instance=True))
        fails(shapes, lambda o: o['cases'][1]['charts']['CIIC-EMPTY']['closed'].update(engine='echarts'))
        # Isolation, cleanup, browser errors.
        fails(iso, lambda o: o['foreign']['company_a'].update(tiles=1))
        fails(iso, lambda o: o['foreign']['company_a'].update(a_codes_in_body=['CIIC-M']))
        fails(iso, lambda o: o['foreign']['company_a'].update(a_divisions_in_body=['CIIC 1']))
        fails(iso, lambda o: o['foreign']['company_b']['names'].append('CIIC-M — CI revenue'))
        fails(iso, lambda o: o['foreign']['company_b'].update(names=[]))
        fails(iso, lambda o: o.update(cleanup={'clean': False}))
        fails(iso, lambda o: o['cases'][0]['browser_events'].append({'source': 'pageerror', 'name': 'Error', 'message': 'x'}))
        fails(iso, lambda o: o['cases'][2].update(server_errors=[{'url': 'u', 'status': 500}]))


def inbound_obs():
    """A complete, consistent RC33 inbound observation (owner D4); every mutation below must FAIL."""
    owner, author1, author2, peer = 2, 31, 32, 40

    def msg(i, author):
        return {'id': i, 'uuid': 'm-%d' % i, 'author': author}

    def rec(mid, outcome, reason='', mid_id=0, author=0):
        return {'mid': mid, 'outcome': outcome, 'reason': reason, 'message_id': mid_id, 'message_author': author}

    def binding(author, receipts, messages, holds=0):
        created = [r for r in receipts if r['outcome'] == 'created']
        return {'receipts': receipts, 'messages': messages, 'author': author, 'peer': peer, 'owner': owner,
                'holds': holds, 'notified': {m['uuid']: sorted([owner, peer]) for m in messages},
                'events': [{'entity': 'm-%d' % r['message_id'], 'leaks_external_id': False} for r in created]}

    def state(b1, b2, created):
        empty = binding(0, [], [])
        return {'companies': {'A': {'bindings': {'1': b1, '2': b2}, 'audit_create': created,
                                    'audit_create_dual_actor': created},
                              'B': {'bindings': {'1': empty, '2': copy.deepcopy(empty)}, 'audit_create': 0,
                                    'audit_create_dual_actor': 0}}, 'gate_held': False}

    n09 = [rec('n09.same', 'created', '', 1, author1)]
    m1 = [msg(1, author1)]
    s_n09 = state(binding(author1, n09, m1), binding(author2, [], []), 1)
    adp = n09 + [rec('adp.create', 'created', '', 2, author1), rec('adp.switch', 'created', '', 3, author1)] + [
        rec(mid, 'review_required', reason) for mid, reason in c.INBOUND_ADAPTER_REVIEW.items()]
    m3 = m1 + [msg(2, author1), msg(3, author1)]
    s_adapter = state(binding(author1, adp, m3, 2), binding(author2, [], []), 3)

    def race(prefix, reason, first_id, author):
        pre = [[prefix + 'pre.%d' % i, 200, 'created', ''] for i in range(5)]
        middle = [[prefix + 'mid.%d' % i, 200, 'created' if i < 7 else 'review_required', '' if i < 7 else reason]
                  for i in range(15)]
        post = [[prefix + 'post.%d' % i, 200, 'review_required', reason] for i in range(5)]
        receipts, messages, nid = [], [], first_id
        for x in pre + middle + post:
            if x[2] == 'created':
                receipts.append(rec(x[0], 'created', '', nid, author)); messages.append(msg(nid, author)); nid += 1
            else:
                receipts.append(rec(x[0], 'review_required', reason))
        return ({'pre': pre, 'middle': middle, 'post': post, 'revoker_exit': 0,
                 'revocation': {'binding': '', 'max_message_id': nid - 1}}, receipts, messages)

    rs, rs_rec, rs_msg = race('race.s.', 'sender_unverified', 10, author1)
    rm, rm_rec, rm_msg = race('race.m.', 'author_unavailable', 100, author2)
    final = state(binding(author1, adp + rs_rec, m3 + rs_msg, 2), binding(author2, rm_rec, rm_msg),
                  3 + len(rs_msg) + len(rm_msg))
    rows = ([{'state': 'committed', 'deliveries': 20, 'has_body': 0, 'reason': ''},
             {'state': 'committed', 'deliveries': 1, 'has_body': 0, 'reason': ''},
             {'state': 'rejected', 'deliveries': 1, 'has_body': 0, 'reason': 'unbound'}]
            + [{'state': 'review_required', 'deliveries': 1, 'has_body': 0, 'reason': ''} for _ in range(8)])
    hooks = {'wrong_secret': 401, 'twenty_identical': [200] * 20, 'adp.bot': 200, 'adp.held.create': 200}
    return {'status': 'PASS', 'setup_exit': 0,
            'n09': [[200, 'created', '']] + [[200, 'duplicate', '']] * 19, 'state_n09': s_n09,
            'envelopes': {k: [200, v, ''] for k, v in c.INBOUND_ENVELOPES.items()},
            'state_envelopes': copy.deepcopy(s_n09),
            'adapter': {'hooks': hooks, 'rows': rows, 'paused': False,
                        'switch_off_rows': [{'state': 'retry_wait', 'reason': 'inbound_retryable_unavailable'}]},
            'state_adapter': s_adapter, 'race_sender': rs, 'race_membership': rm, 'state_final': final}


class InboundContractTest(unittest.TestCase):
    """RC33 PR-3: the D4 inbound verdicts must fail closed on every lost invariant."""

    def test_consistent_observation_passes_and_names_are_required(self):
        r = c.evaluate(c.inbound_checks(inbound_obs(), 0))
        self.assertEqual({'PASS'}, {x['status'] for x in r.values()}, r)
        self.assertTrue(set(r) <= set(c.required_check_names()))
        self.assertEqual(6, len(r))

    def test_incomplete_or_failed_producer_fails_everything(self):
        for mutate, code in ((lambda o: o.update(error='boom'), 0), (lambda o: o.update(status='INCOMPLETE'), 0),
                             (lambda o: o.update(setup_exit=1), 0), (lambda o: None, 1), (lambda o: None, None)):
            o = inbound_obs(); mutate(o)
            r = c.evaluate(c.inbound_checks(o, code))
            self.assertTrue(all(x['status'] == 'FAIL' for x in r.values()), r)
        self.assertTrue(all(x['status'] == 'FAIL' for x in c.evaluate(c.inbound_checks(None, 0)).values()))

    def assert_fails(self, name, mutate):
        o = inbound_obs(); mutate(o)
        self.assertIn(name, failed(c.evaluate(c.inbound_checks(o, 0))))

    def test_each_lost_invariant_is_detected(self):
        n09, env, adp = ('inbound_n09_twenty_parallel_identical_one_message',
                         'inbound_n20_refusals_are_http200_envelopes_without_writes', 'inbound_fake_adapter_end_to_end_states')
        s, m = ('inbound_n21_sender_revocation_during_intake_linearized',
                'inbound_n28_membership_revocation_during_intake_linearized')
        iso = 'inbound_tenant_isolation_outbox_and_dual_actor_audit'

        def a1(o, key):
            return o[key]['companies']['A']['bindings']['1']
        cases = [
            (n09, lambda o: o['n09'].__setitem__(1, [200, 'created', ''])),
            (n09, lambda o: a1(o, 'state_n09')['messages'].append({'id': 9, 'uuid': 'm-9', 'author': 31})),
            (n09, lambda o: a1(o, 'state_n09')['notified'].__setitem__('m-1', [2, 2, 40])),
            (n09, lambda o: a1(o, 'state_n09')['events'][0].__setitem__('leaks_external_id', True)),
            (n09, lambda o: o['state_n09']['companies']['A'].__setitem__('audit_create_dual_actor', 0)),
            (env, lambda o: o['envelopes'].__setitem__('switch_off', [500, 'http_error', ''])),
            (env, lambda o: o['envelopes'].__setitem__('changed_content', [200, 'duplicate', ''])),
            (env, lambda o: a1(o, 'state_envelopes')['receipts'].append({'mid': 'n20.foreign', 'outcome': 'created'})),
            (adp, lambda o: o['adapter']['hooks'].__setitem__('wrong_secret', 200)),
            (adp, lambda o: o['adapter']['rows'][0].__setitem__('has_body', 1)),
            (adp, lambda o: o['adapter']['rows'][2].__setitem__('state', 'committed')),
            (adp, lambda o: o['adapter'].__setitem__('switch_off_rows', [])),
            (adp, lambda o: next(r for r in a1(o, 'state_adapter')['receipts'] if r['mid'] == 'adp.bot').update(
                outcome='created', message_id=5)),
            (adp, lambda o: a1(o, 'state_adapter').__setitem__('holds', 1)),
            (s, lambda o: o['race_sender']['post'][0].__setitem__(2, 'created')),
            (s, lambda o: o['race_sender'].__setitem__('revoker_exit', 1)),
            (s, lambda o: o['race_sender']['revocation'].__setitem__('max_message_id', 12)),
            (s, lambda o: a1(o, 'state_final')['messages'].append({'id': 999, 'uuid': 'm-999', 'author': 31})),
            (m, lambda o: o['race_membership']['middle'][9].__setitem__(3, 'sender_bot')),
            (m, lambda o: o['state_final']['companies']['A']['bindings']['2']['receipts'].pop()),
            (iso, lambda o: o['state_final']['companies']['B']['bindings']['1']['messages'].append({'id': 1})),
            (iso, lambda o: o['state_final'].__setitem__('gate_held', True)),
            (iso, lambda o: o['state_final']['companies']['A'].__setitem__('audit_create', 1)),
        ]
        for name, mutate in cases:
            with self.subTest(name=name):
                self.assert_fails(name, mutate)


if __name__ == '__main__':
    unittest.main(verbosity=1)
