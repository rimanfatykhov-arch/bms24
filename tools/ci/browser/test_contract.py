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
          'server_file_only_direct': {'http': 303, 'location': 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A',
                                      'file_link_text': 'p02-direct-only.txt', 'downloads': ok_dl('p02-direct-only.txt')},
          'csrf_invalid_sesskey': {'http': 404}, 'double_submit': {'center_posts': 1}}
    state = {'messages': [{'body': 'RC31 P02 double verify'}, {'body': 'x'}], 'orphan_files': 0}
    return {'phase': 'verify', 'steps': st}, state


def access():
    def rows(kind):
        out = {}
        for u in URLS:
            n = u.split('/')[-1]
            out[u] = {'http': 200, 'sha256': c.SHA[n]} if kind == 'ok' else (
                {'http': 303, 'location': 'http://127.0.0.1:8080/login/index.php', 'sha256': None} if kind == 'login' else {'http': 404, 'sha256': None})
        return out
    return {'expected_urls': URLS, 'logins': {'EA': 1, 'EB': 1}, 'ea_context_id_before': 7, 'ea_context_id_after': 7,
            'revoked_flag': True, 'reopened_flag': True, 'history': {'before': 9, 'after_revoke': 9, 'after_reopen': 9},
            'phases': {'ea_before': rows('ok'), 'eb': rows('deny'), 'anon': rows('login'), 'ea_revoked': rows('deny'), 'ea_reopened': rows('ok')}}


def oversize():
    return ({'steps': {'limits': {'form_maxbytes': 65536}, 'compact_oversize': {'request_sent': True, 'error_text': 'The file exceeds the permitted upload size.'},
                       'full_oversize_client': {'post_sent': False, 'file_validation': 'The file exceeds the permitted upload size.'},
                       'full_oversize_server_direct': {'http': 200, 'error_in_page': True, 'sent_bytes': 102400}}},
            {'messages': [{'body': 'x'}], 'orphan_files': 0})


def tabs(status='PASS', drop=None):
    names = ['page_and_tabs', 'keyboard_reaches_tab', 'focus_ring_visible', 'focus_ring_contrast_3to1', 'second_tab_focus_visible', 'enter_activates_tab',
             'active_tab_distinct', 'mouse_click_navigates_without_ring', 'desktop_tabs_not_enlarged', 'mobile_focus_ring', 'mobile_no_page_hscroll', 'mobile_tap_target_44']
    return {'cases': [{'id': 'P01.ci.' + n, 'status': status} for n in names if n != drop]}


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
        self.assertEqual(len(c.access_checks(a)) - 1, len([k for k in failed(c.evaluate(c.access_checks(a))) if k != 'revocation_preserves_history']))

    def test_session_must_be_reused_and_revocation_applied(self):
        a = access(); a['logins']['EA'] = 3
        self.assertIn('revoke_in_open_session_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['ea_context_id_after'] = 8
        self.assertIn('reopen_in_open_session_allowed', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['revoked_flag'] = False
        self.assertIn('revoke_in_open_session_denied', failed(c.evaluate(c.access_checks(a))))
        a = access(); a['history']['after_revoke'] = 5
        self.assertIn('revocation_preserves_history', failed(c.evaluate(c.access_checks(a))))

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
