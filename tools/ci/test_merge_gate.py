#!/usr/bin/env python3
"""Examples required by Issue #82 for the merge gate decision (pure function; no network)."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from merge_gate import decide, FULL_WORKFLOWS  # noqa: E402

H = 'a' * 40


def cls(profile, files, tests=('task_service_test',), gates=('pr48_tenant_isolation_test',)):
    return {'profile': profile, 'head': H, 'changed_files': files,
            'focused_phpunit': list(tests) if profile != 'FAST' else [], 'security_gates': list(gates) if profile != 'FAST' else []}


FAST_OK = {'status': 'PASS', 'head': H}
def std_ok(c): return {'status': 'PASS', 'phpunit': dict({'init': 'PASS'}, **{t: 'PASS' for t in c['focused_phpunit'] + c['security_gates']})}
BR_OK = {'status': 'PASS', 'checks': {'download_other_company_denied': {'status': 'PASS'}}}
BROWSERS_OK = {'mariadb': BR_OK, 'mysqli': BR_OK}
FULL_OK = {w: 'success' for w in FULL_WORKFLOWS}


class MergeGateTest(unittest.TestCase):
    def test_small_safe_change_passes_without_full(self):
        c = cls('FAST', ['docs/guide.md', 'local/kbacompany/lang/en/local_kbacompany.php'])
        v, r, req = decide(c, FAST_OK, None, {}, None)
        self.assertEqual('PASS', v, r); self.assertIn('not required', req['FULL'])
        c = cls('STANDARD', ['local/kbacompany/classes/local/report_ui_service.php'])
        v, r, req = decide(c, FAST_OK, std_ok(c), BROWSERS_OK, None)
        self.assertEqual('PASS', v, r); self.assertIn('not required', req['FULL'])

    def test_db_acl_file_changes_require_full(self):
        for files in (['local/kbacompany/db/install.xml'], ['local/kbacompany/db/access.php'], ['local/kbacompany/lib.php']):
            c = cls('FULL', files)
            v, r, _ = decide(c, FAST_OK, std_ok(c), BROWSERS_OK, None)
            self.assertEqual('FAIL', v, files); self.assertTrue(any('FULL workflow' in x for x in r))
            self.assertEqual('PASS', decide(c, FAST_OK, std_ok(c), BROWSERS_OK, FULL_OK)[0])

    def test_unknown_path_full_requires_full_workflows(self):
        c = cls('FULL', ['vendor/blob.bin'])
        self.assertEqual('FAIL', decide(c, FAST_OK, std_ok(c), {}, {w: 'success' for w in FULL_WORKFLOWS[:-1]})[0])
        self.assertEqual('PASS', decide(c, FAST_OK, std_ok(c), {}, FULL_OK)[0])  # no local/ change: BROWSER not applicable

    def test_classifier_error_blocks(self):
        for bad in (None, {}, {'profile': 'FASTER', 'head': H}, {'profile': 'FAST'}):
            self.assertEqual('FAIL', decide(bad, FAST_OK, None, {}, None)[0])

    def test_failed_or_skipped_browser_blocks(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        for browsers in ({}, {'mariadb': BR_OK}, {'mariadb': BR_OK, 'mysqli': {'status': 'FAIL', 'checks': {}}},
                         {'mariadb': BR_OK, 'mysqli': {'status': 'PASS', 'checks': {}}},
                         {'mariadb': BR_OK, 'mysqli': {'status': 'PASS', 'checks': {'x': {'status': 'NOT RUN'}}}}):
            self.assertEqual('FAIL', decide(c, FAST_OK, std_ok(c), browsers, None)[0], browsers)

    def test_standard_not_run_cancelled_or_missing_blocks(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        s = std_ok(c); s['phpunit']['task_service_test'] = 'NOT RUN'
        self.assertEqual('FAIL', decide(c, FAST_OK, s, BROWSERS_OK, None)[0])
        self.assertEqual('FAIL', decide(c, FAST_OK, None, BROWSERS_OK, None)[0])
        s = std_ok(c); del s['phpunit']['pr48_tenant_isolation_test']
        self.assertEqual('FAIL', decide(c, FAST_OK, s, BROWSERS_OK, None)[0])
        c0 = cls('STANDARD', ['local/kbacompany/tasks.php'], tests=(), gates=())
        self.assertEqual('FAIL', decide(c0, FAST_OK, {'status': 'PASS', 'phpunit': {'init': 'PASS'}}, BROWSERS_OK, None)[0])

    def test_fast_must_match_head_and_pass(self):
        c = cls('FAST', ['docs/a.md'])
        self.assertEqual('FAIL', decide(c, {'status': 'PASS', 'head': 'b' * 40}, None, {}, None)[0])
        self.assertEqual('FAIL', decide(c, {'status': 'FAIL', 'head': H}, None, {}, None)[0])
        self.assertEqual('FAIL', decide(c, None, None, {}, None)[0])

    def test_full_failure_or_cancellation_blocks(self):
        c = cls('FULL', ['local/kbacompany/db/upgrade.php'])
        for concl in ('failure', 'cancelled', 'in_progress', 'missing', 'skipped'):
            full = dict(FULL_OK); full['PR48 isolated Moodle acceptance'] = concl
            self.assertEqual('FAIL', decide(c, FAST_OK, std_ok(c), BROWSERS_OK, full)[0], concl)


if __name__ == '__main__':
    unittest.main(verbosity=1)
