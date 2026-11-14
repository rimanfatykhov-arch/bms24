#!/usr/bin/env python3
"""Merge gate decision tests (Issue #82 examples + RC32 independent review). Pure functions; no network. Run with python and python -O."""
import copy
import hashlib
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import merge_gate as g  # noqa: E402

H, B, RUN = 'a' * 40, 'b' * 40, '4242'
EXP = {'target': 'this-pr', 'head': H, 'base': B, 'run_id': RUN}
PLANNED = ['this-pr']
WF = list(g.FULL_WORKFLOWS)


def cls(profile, files, tests=('task_service_test',), gates=('pr48_tenant_isolation_test',)):
    return {'profile': profile, 'head': H, 'base': B, 'changed_files': files,
            'focused_phpunit': list(tests) if profile != 'FAST' else [], 'security_gates': list(gates) if profile != 'FAST' else []}


def ev(tier_, report_, profile_, engine_='', **override):
    raw = json.dumps(report_).encode()
    ident = {'tier': tier_, 'target': 'this-pr', 'head': H, 'base': B, 'profile': profile_, 'engine': engine_, 'run_id': RUN,
             'run_attempt': '1', 'job': tier_.lower(), 'report_sha256': hashlib.sha256(raw).hexdigest()}
    ident.update(override)
    return {'identity': ident, 'report': report_, 'report_sha256': hashlib.sha256(raw).hexdigest()}


def browser_report(engine, checks=None):
    return {'status': 'PASS', 'dbtype': engine, 'checks': checks if checks is not None else {k: {'status': 'PASS'} for k in g.required_browser_checks()}}


def evidence(c, **kw):
    p = c['profile']
    e = {'FAST': ev('FAST', {'status': 'PASS', 'head': H, 'base': B}, p)}
    if p != 'FAST':
        e['STANDARD'] = ev('STANDARD', {'status': 'PASS', 'phpunit': dict({'init': 'PASS'}, **{t: 'PASS' for t in c['focused_phpunit'] + c['security_gates']})}, p)
        e['BROWSER'] = {en: ev('BROWSER', browser_report(en), p, en) for en in g.ENGINES}
    e.update(kw)
    return e


def jobs(planned=('this-pr',), **override):
    j = {'Plan targets': 'success', 'Merge gate': 'in_progress'}
    for t in planned:
        n = g.job_names(t)
        j[n['FAST']] = j[n['STANDARD']] = 'success'
        for name in n['BROWSER'].values():
            j[name] = 'success'
    j.update(override)
    return j


FULL_OK = {w: {'status': 'completed', 'conclusion': 'success', 'id': 1} for w in WF}


def verdict(c, e=None, j=None, planned=None, full=None, exp=None):
    return g.decide(exp or EXP, c, e if e is not None else evidence(c), j if j is not None else jobs(), planned or PLANNED, full)


class MergeGateExamples(unittest.TestCase):
    """Issue #82 examples (behaviour kept from PR #94)."""

    def test_small_safe_change_passes_without_full(self):
        c = cls('FAST', ['docs/guide.md', 'local/kbacompany/lang/en/local_kbacompany.php'])
        v, r, req, _ = verdict(c)
        self.assertEqual('PASS', v, r); self.assertIn('not required', req['FULL'])
        c = cls('STANDARD', ['local/kbacompany/classes/local/report_ui_service.php'])
        v, r, req, _ = verdict(c)
        self.assertEqual('PASS', v, r); self.assertIn('not required', req['FULL'])

    def test_db_acl_file_changes_require_full(self):
        for files in (['local/kbacompany/db/install.xml'], ['local/kbacompany/db/access.php'], ['local/kbacompany/lib.php']):
            c = cls('FULL', files)
            v, r, _, _ = verdict(c)
            self.assertEqual('FAIL', v, files); self.assertTrue(any(x.startswith('FULL ') for x in r))
            self.assertEqual('PASS', verdict(c, full=FULL_OK)[0], verdict(c, full=FULL_OK)[1])

    def test_unknown_path_full_requires_all_full_workflows(self):
        c = cls('FULL', ['vendor/blob.bin'])
        part = dict(FULL_OK); part[WF[-1]] = {'status': 'missing'}
        self.assertEqual('FAIL', verdict(c, full=part)[0])
        self.assertEqual('PASS', verdict(c, full=FULL_OK)[0])

    def test_classifier_error_blocks(self):
        for bad in (None, {}, {'profile': 'FASTER', 'head': H, 'base': B}, {'profile': 'FAST'}):
            self.assertEqual('FAIL', g.decide(EXP, bad, {}, jobs(), PLANNED, None)[0])

    def test_failed_or_incomplete_browser_blocks(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        for engine, rep in (('mysqli', None), ('mysqli', {'status': 'FAIL', 'dbtype': 'mysqli', 'checks': {}}),
                            ('mysqli', browser_report('mysqli', {})),
                            ('mysqli', browser_report('mysqli', dict({k: {'status': 'PASS'} for k in g.required_browser_checks()}, moodle_install={'status': 'NOT RUN'})))):
            e = evidence(c)
            e['BROWSER'][engine] = ev('BROWSER', rep, 'STANDARD', engine) if rep is not None else {}
            self.assertEqual('FAIL', verdict(c, e)[0], rep)

    def test_standard_not_run_or_missing_blocks(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        e = evidence(c); r = copy.deepcopy(e['STANDARD']['report']); r['phpunit']['task_service_test'] = 'NOT RUN'
        e['STANDARD'] = ev('STANDARD', r, 'STANDARD'); self.assertEqual('FAIL', verdict(c, e)[0])
        e = evidence(c); e['STANDARD'] = {}; self.assertEqual('FAIL', verdict(c, e)[0])
        c0 = cls('STANDARD', ['local/kbacompany/tasks.php'], tests=(), gates=())
        self.assertEqual('FAIL', verdict(c0)[0])

    def test_full_failure_cancellation_or_unfinished_blocks(self):
        c = cls('FULL', ['local/kbacompany/db/upgrade.php'])
        for concl in ('failure', 'cancelled', None, 'skipped', 'timed_out'):
            full = copy.deepcopy(FULL_OK); full[WF[0]] = {'status': 'in_progress' if concl is None else 'completed', 'conclusion': concl}
            self.assertEqual('FAIL', verdict(c, full=full)[0], concl)


class ReviewFindings(unittest.TestCase):
    """RC32 independent review, Issue #82 comment 6872172812: each case is a false PASS on main f34ab6f."""

    # ---- A. FULL run selection
    def mk(self, rid, attempt, concl, wf=None, status='completed', head=H, base=B, pr=95, event='pull_request', path=None):
        return {'id': rid, 'run_attempt': attempt, 'status': status, 'conclusion': concl if status == 'completed' else None,
                'path': path or wf or WF[0], 'event': event, 'head_sha': head, 'name': 'x',
                'pull_requests': [{'number': pr, 'base': {'sha': base}}]}

    def test_old_attempt2_success_does_not_mask_new_attempt1_failure(self):
        runs = [self.mk(100, 2, 'success', w) for w in WF] + [self.mk(200, 1, 'failure', WF[0])]
        sel = g.select_full_runs(runs, H, B, 95)
        self.assertEqual('failure', sel[WF[0]]['conclusion']); self.assertEqual(200, sel[WF[0]]['id'])
        c = cls('FULL', ['local/kbacompany/lib.php'])
        self.assertEqual('FAIL', verdict(c, full=sel)[0])

    def test_newer_unfinished_or_cancelled_run_wins(self):
        for newer in (self.mk(300, 1, None, WF[1], status='in_progress'), self.mk(300, 1, 'cancelled', WF[1])):
            sel = g.select_full_runs([self.mk(100, 3, 'success', w) for w in WF] + [newer], H, B, 95)
            self.assertNotEqual('success', sel[WF[1]]['conclusion'])

    def test_full_runs_bound_to_path_event_head_pr_and_base(self):
        good = [self.mk(100, 1, 'success', w) for w in WF]
        self.assertTrue(all(v['conclusion'] == 'success' for v in g.select_full_runs(good, H, B, 95).values()))
        for bad in ({'base': 'c' * 40}, {'head': 'c' * 40}, {'pr': 7}, {'event': 'push'}, {'path': '.github/workflows/rc30-corporate2613.yml'}):
            runs = [self.mk(100, 1, 'success', w, **bad) for w in WF]
            self.assertTrue(all(v.get('status') == 'missing' for v in g.select_full_runs(runs, H, B, 95).values()), bad)
        # same display name, different workflow file: not accepted
        impostor = [dict(self.mk(100, 1, 'success', w), path='.github/workflows/impostor.yml', name=g.FULL_WORKFLOWS[w]) for w in WF]
        self.assertTrue(all(v.get('status') == 'missing' for v in g.select_full_runs(impostor, H, B, 95).values()))
        # retained Corporate2613 (expected FAIL history) is not part of FULL and never mixed with the repaired workflow
        self.assertNotIn('.github/workflows/rc30-corporate2613.yml', g.FULL_WORKFLOWS)
        self.assertIn('.github/workflows/rc30-corporate2613-repair.yml', g.FULL_WORKFLOWS)
        # historical replay: closed PR has an empty pull_requests list; only accepted in historical mode
        closed = [dict(self.mk(100, 1, 'success', w), pull_requests=[]) for w in WF]
        self.assertTrue(all(v.get('status') == 'missing' for v in g.select_full_runs(closed, H, B, 95).values()))
        self.assertTrue(all(v['conclusion'] == 'success' for v in g.select_full_runs(closed, H, B, 95, historical=True).values()))

    # ---- B. job outcomes
    def test_pass_report_with_failed_or_cancelled_job_blocks(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        for job in ('FAST / this-pr', 'STANDARD / this-pr', 'BROWSER / this-pr / Percona 8.0.46-37', 'Plan targets'):
            for concl in ('failure', 'cancelled', 'skipped', 'timed_out', 'in_progress'):
                v, r, _, _ = verdict(c, j=jobs(**{job: concl}))
                self.assertEqual('FAIL', v, (job, concl)); self.assertTrue(any(job in x for x in r))

    def test_missing_job_or_unreadable_api_blocks(self):
        c = cls('FAST', ['docs/a.md'])
        j = jobs(); del j['STANDARD / this-pr']
        self.assertEqual('FAIL', verdict(c, j=j)[0])
        j = jobs(); del j['BROWSER / this-pr / MariaDB 10.11.19']
        self.assertEqual('FAIL', verdict(c, j=j)[0])  # even for FAST the leg must conclude success, never skip
        self.assertEqual('FAIL', g.decide(EXP, c, evidence(c), None, PLANNED, None)[0])
        self.assertEqual('FAIL', g.decide(EXP, c, evidence(c), {}, PLANNED, None)[0])
        self.assertEqual('FAIL', g.decide(EXP, c, evidence(c), jobs(), ['replay-pr80-css'], None)[0])

    def test_negative_controls_required_replays_information_only(self):
        c = cls('FULL', ['tools/ci/merge_gate.py'])
        planned = ['this-pr', 'replay-pr80-css', 'negative-control', 'negative-browser-acl']
        j = jobs(planned)
        self.assertEqual('PASS', verdict(c, j=j, planned=planned, full=FULL_OK)[0])
        j2 = dict(j, **{'BROWSER / negative-browser-acl / MariaDB 10.11.19': 'failure'})
        self.assertEqual('FAIL', verdict(c, j=j2, planned=planned, full=FULL_OK)[0])
        j3 = dict(j, **{'FAST / replay-pr80-css': 'failure'})
        v, r, _, info = verdict(c, j=j3, planned=planned, full=FULL_OK)
        self.assertEqual('PASS', v, r); self.assertEqual('failure', info['replay_jobs_information_only']['FAST / replay-pr80-css'])

    # ---- C. identity and completeness
    def test_classification_must_match_expected_head_and_base(self):
        c = cls('FAST', ['docs/a.md'])
        self.assertEqual('FAIL', verdict(c, exp=dict(EXP, head='c' * 40))[0])
        self.assertEqual('FAIL', verdict(c, exp=dict(EXP, base='c' * 40))[0])
        self.assertEqual('FAIL', verdict(c, exp=dict(EXP, head='short'))[0])
        # everything else consistently stamped for head C; only the classification is for head H
        C = 'c' * 40
        e = evidence(c); e['FAST'] = ev('FAST', {'status': 'PASS', 'head': C, 'base': B}, 'FAST', head=C)
        self.assertEqual('PASS', g.decide(dict(EXP, head=C), dict(c, head=C), e, jobs(), PLANNED, None)[0])
        self.assertEqual('FAIL', g.decide(dict(EXP, head=C), c, e, jobs(), PLANNED, None)[0])

    def test_reports_of_another_sha_run_tier_or_engine_rejected(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        for tier, override in (('STANDARD', {'head': 'c' * 40}), ('STANDARD', {'run_id': '1'}), ('STANDARD', {'target': 'replay-pr81-attachments'}),
                               ('STANDARD', {'profile': 'FAST'}), ('FAST', {'base': 'c' * 40}), ('FAST', {'tier': 'STANDARD'})):
            e = evidence(c); e[tier] = ev(tier, e[tier]['report'], 'STANDARD', **override)
            self.assertEqual('FAIL', verdict(c, e)[0], (tier, override))
        e = evidence(c); e['BROWSER']['mariadb'] = ev('BROWSER', browser_report('mariadb'), 'STANDARD', 'mysqli')
        self.assertEqual('FAIL', verdict(c, e)[0])
        e = evidence(c); e['BROWSER']['mysqli'] = ev('BROWSER', browser_report('mariadb'), 'STANDARD', 'mysqli')
        self.assertEqual('FAIL', verdict(c, e)[0])  # report itself says another engine

    def test_report_bytes_must_match_stamp(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        e = evidence(c); e['STANDARD']['report_sha256'] = '0' * 64
        self.assertEqual('FAIL', verdict(c, e)[0])
        e = evidence(c); e['FAST']['identity']['report_sha256'] = None; e['FAST']['report_sha256'] = None
        self.assertEqual('FAIL', verdict(c, e)[0])

    def test_browser_single_unrelated_check_is_not_complete(self):
        c = cls('STANDARD', ['local/kbacompany/tasks.php'])
        e = evidence(c)
        e['BROWSER']['mariadb'] = ev('BROWSER', browser_report('mariadb', {'unrelated_check': {'status': 'PASS'}}), 'STANDARD', 'mariadb')
        v, r, _, _ = verdict(c, e)
        self.assertEqual('FAIL', v); self.assertTrue(any('missing' in x for x in r))

    def test_fast_must_match_head_and_pass(self):
        c = cls('FAST', ['docs/a.md'])
        for rep in ({'status': 'PASS', 'head': 'c' * 40, 'base': B}, {'status': 'FAIL', 'head': H, 'base': B}, None):
            e = evidence(c); e['FAST'] = ev('FAST', rep, 'FAST') if rep else {}
            self.assertEqual('FAIL', verdict(c, e)[0], rep)

    def test_low_risk_does_not_wait_for_full_but_full_profile_does(self):
        self.assertEqual('PASS', verdict(cls('FAST', ['docs/a.md']), full=None)[0])
        self.assertEqual('FAIL', verdict(cls('FULL', ['local/kbacompany/lib.php']), full=None)[0])

    def test_workflow_engine_names_match(self):
        wf = (Path(__file__).resolve().parents[2] / '.github' / 'workflows' / 'ci-tiers.yml').read_text(encoding='utf-8')
        for name in g.ENGINES.values():
            self.assertIn('name: ' + name + ',', wf)
        for dbtype in g.ENGINES:
            self.assertIn('dbtype: ' + dbtype + ',', wf)
        for path in g.FULL_WORKFLOWS:
            self.assertTrue((Path(__file__).resolve().parents[2] / path).is_file(), path)

    def test_decide_never_raises_on_garbage(self):
        c = cls('FULL', ['x'])
        for e in (None, {'FAST': 'x'}, {'BROWSER': 'x'}, {'BROWSER': {'mariadb': {'identity': {}, 'report': []}}}):
            self.assertEqual('FAIL', g.decide(EXP, c, e, jobs(), PLANNED, {'x': 'y'})[0])


if __name__ == '__main__':
    unittest.main(verbosity=1)
