#!/usr/bin/env python3
"""Isolated RC30 comparison CLI contract tests. No Moodle DB or live server."""
import copy
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
assert PHP, 'PHP CLI is required'

# Obtain the canonical 18 checks and 4 indexes from the PHP services, not a
# hand-maintained list that could silently diverge from RC30.
script = r'''
define('MOODLE_INTERNAL', true);
require getenv('BMS1869_PLUGIN_ROOT').'/classes/local/task_rc30_integrity_service.php';
require getenv('BMS1869_PLUGIN_ROOT').'/classes/local/task_rc30_acceptance_service.php';
$out = ['checks'=>[], 'indexes'=>[]];
foreach (\local_kbacompany\local\task_rc30_integrity_service::checks() as $name => $item) {
    $out['checks'][$name] = $item['severity'];
}
foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as $item) {
    $out['indexes'][$item[1]] = $item[0];
}
echo json_encode($out);
'''
env = dict(os.environ, BMS1869_PLUGIN_ROOT=str(PLUGIN))
result = subprocess.run([PHP, '-r', script], env=env, text=True,
                        capture_output=True, timeout=15, check=True)
canonical = json.loads(result.stdout)
assert len(canonical['checks']) == 18 and len(canonical['indexes']) == 4


def make_report(version=2026101867, scope='all'):
    checks = {k: {'severity': severity, 'count': 0}
              for k, severity in canonical['checks'].items()}
    checks['tasks_total']['count'] = 120
    return {'schema_version': 1, 'scope': scope, 'read_only': True,
            'installation_digest': 'c'*64, 'captured_at': 1782800000,
            'rc30_promotion_approved': False, 'strict': False,
            'installed_plugin_version': version,
            'expected_plugin_version': version,
            'technical_gate': 'passed',
            'runtime': {'php': '8.1.34', 'dbfamily': 'mysql',
                        'db_server_version': '5.7.21',
                        'target_php': '8.1', 'target_db': 'MySQL 5.7'},
            'indexes': {k: {'table': tablename, 'present': True}
                        for k, tablename in canonical['indexes'].items()},
            'integrity_checks': checks,
            'integrity_summary': {'checks': 18, 'errors': 0, 'warnings': 0},
            'errors': [], 'warnings': []}


def normalise(report):
    entries = report['integrity_checks']
    errors = []
    warnings = []
    if report['installed_plugin_version'] != report['expected_plugin_version']:
        errors.append('plugin_version_mismatch')
    errors += ['missing_index_' + name for name, item in report['indexes'].items()
               if not item['present']]
    summary = {'checks': len(entries),
               'errors': sum(1 for x in entries.values()
                             if x['severity'] == 'error' and x['count'] > 0),
               'warnings': sum(1 for x in entries.values()
                               if x['severity'] == 'warning' and x['count'] > 0)}
    if summary['errors']:
        errors.append('data_integrity_errors')
    if summary['warnings']:
        warnings.append('data_integrity_warnings')
    if entries.get('tasks_total', {}).get('count') == 0:
        warnings.append('no_tasks_in_scope')
    runtime = report['runtime']
    if not runtime['php'].startswith('8.1.'):
        warnings.append('php81_target_not_verified')
    if runtime['dbfamily'] != 'mysql' or not (
            runtime['db_server_version'] or '').startswith('5.7.'):
        warnings.append('mysql57_target_not_verified')
    report['integrity_summary'] = summary
    report['errors'] = errors
    report['warnings'] = warnings
    report['technical_gate'] = ('failed' if errors or (report['strict'] and warnings)
                                else 'warnings' if warnings else 'passed')


with tempfile.TemporaryDirectory(prefix='bms1869-compare-') as temp:
    root = Path(temp)
    cli = root / 'local/kbacompany/cli'
    cli.mkdir(parents=True)
    lib = root / 'lib'
    lib.mkdir()
    shutil.copyfile(PLUGIN/'cli/rc30_compare_reports.php', cli/'rc30_compare_reports.php')
    (root/'config.php').write_text('''<?php
        define('MOODLE_INTERNAL', true);
        $CFG=(object)['libdir'=>__DIR__.'/lib'];
        foreach (['task_rc30_integrity_service','task_rc30_acceptance_service',
                 'task_rc30_report_comparison_service'] as $service) {
            require getenv('BMS1869_PLUGIN_ROOT').'/classes/local/'.$service.'.php';
        }
    ''')
    (lib/'clilib.php').write_text('''<?php
        function cli_get_params($defaults, $short) {
            global $argv;
            $opts=$defaults; $unknown=[];
            foreach (array_slice($argv, 1) as $arg) {
                if (substr($arg,0,2)!=='--') { $unknown[]=$arg; continue; }
                $parts=explode('=',substr($arg,2),2);
                if (!array_key_exists($parts[0],$opts)) { $unknown[]=$arg; continue; }
                $opts[$parts[0]]=isset($parts[1]) ? $parts[1] : true;
            }
            return [$opts,$unknown];
        }
    ''')
    path_before = root/'before.json'
    path_after = root/'after.json'
    executable = cli/'rc30_compare_reports.php'
    checks = 0

    def run(current, *, baseline=None, expected=0, args=(), code=None):
        global checks
        path_before.write_text(json.dumps(baseline if baseline is not None else make_report()))
        path_after.write_text(json.dumps(current))
        cmd = [PHP, str(executable), f'--baseline={path_before}',
               f'--current={path_after}', '--format=json', *args]
        p = subprocess.run(cmd, env=env, text=True, capture_output=True, timeout=20)
        assert p.returncode == expected, (p.returncode, p.stdout, p.stderr)
        data = json.loads(p.stdout)
        assert data['rc30_promotion_approved'] is False and data['read_only'] is True
        if code:
            assert data['errors'] == [code], data
            assert data['comparison_complete'] is False
        else:
            assert data['comparison_complete'] is True
        checks += 1
        return data

    good = make_report(2026101868)
    assert run(good)['comparison_gate'] == 'passed'
    assert run(make_report(2026101867))['comparison_gate'] == 'passed'
    bad = copy.deepcopy(good); bad['integrity_checks']['orphan_task_history']['count'] = 2
    normalise(bad)
    assert run(bad)['comparison_gate'] == 'warnings'
    assert run(bad, expected=1, args=('--strict',))['comparison_gate'] == 'failed'
    bad = copy.deepcopy(good); bad['integrity_checks']['missing_battle_plan']['count'] = 3
    normalise(bad)
    assert run(bad, expected=1)['integrity_regressions'][0]['after'] == 3
    missing = copy.deepcopy(good); missing['indexes']['lastlog_ix']['present'] = False
    normalise(missing)
    assert run(missing, expected=1)['index_regressions'] == ['lastlog_ix']
    baseline = copy.deepcopy(make_report()); baseline['integrity_checks']['missing_battle_plan']['count'] = 4
    normalise(baseline)
    assert run(good, baseline=baseline)['integrity_improvements'][0]['before'] == 4
    changed = copy.deepcopy(good); changed['runtime']['php'] = '8.4.24'
    normalise(changed)
    assert run(changed)['runtime_changes']['php']['after'] == '8.4.24'
    wrong = copy.deepcopy(good); wrong['diagnostic_complete'] = False
    run(wrong, expected=2, code='report_invalid_schema')
    wrong = copy.deepcopy(good); wrong['scope'] = 'company'
    run(wrong, expected=2, code='report_scope_mismatch')
    c1 = make_report(scope='company'); c2 = make_report(2026101868, scope='company')
    run(c2, baseline=c1, expected=2, code='report_company_unverified')
    assert run(c2, baseline=c1, args=('--confirm-same-company',))['company_scope_verified'] is False
    c1['scope_digest'] = 'a'*64; c2['scope_digest'] = 'b'*64
    run(c2, baseline=c1, expected=2, code='report_scope_mismatch')
    c2['scope_digest'] = 'a'*64
    assert run(c2, baseline=c1)['company_scope_verified'] is True
    incomplete = copy.deepcopy(good); del incomplete['integrity_checks']['missing_battle_plan']
    run(incomplete, expected=2, code='report_checks_missing')
    baseline = copy.deepcopy(make_report()); del baseline['integrity_checks']['missing_battle_plan']
    run(incomplete, baseline=baseline, expected=2, code='report_checks_missing')
    incomplete = copy.deepcopy(good); del incomplete['indexes']['lastlog_ix']
    run(incomplete, expected=2, code='report_checks_missing')
    baseline = copy.deepcopy(make_report()); del baseline['indexes']['lastlog_ix']
    run(incomplete, baseline=baseline, expected=2, code='report_checks_missing')
    corrupt = copy.deepcopy(good); corrupt['integrity_checks']['missing_battle_plan']['count'] = 1
    run(corrupt, expected=2, code='report_summary_mismatch')
    corrupt = copy.deepcopy(good); corrupt['technical_gate'] = 'failed'
    run(corrupt, expected=2, code='report_summary_mismatch')
    corrupt = copy.deepcopy(good); corrupt['runtime']['php'] = '8.4.24'
    run(corrupt, expected=2, code='report_summary_mismatch')
    corrupt = copy.deepcopy(good); corrupt['integrity_checks']['missing_battle_plan']['severity'] = 'info'
    run(corrupt, expected=2, code='report_checks_changed')
    rolled = copy.deepcopy(make_report(2026101866))
    run(rolled, expected=2, code='report_version_rollback')
    corrupt = copy.deepcopy(good); corrupt['errors'] = ['SECRET-COMPANY-UUID']
    run(corrupt, expected=2, code='report_invalid_schema')
    path_before.write_text(json.dumps(make_report()))
    path_after.write_text('not json')
    p = subprocess.run([PHP, str(executable), f'--baseline={path_before}',
                        f'--current={path_after}', '--format=json'], env=env,
                       text=True, capture_output=True, timeout=20)
    assert p.returncode == 2 and json.loads(p.stdout)['errors'] == ['report_invalid_json']
    checks += 1
    p = subprocess.run([PHP, str(executable), f'--baseline={path_before}',
                        f'--current={root/"missing.json"}', '--format=json'], env=env,
                       text=True, capture_output=True, timeout=20)
    assert p.returncode == 2 and json.loads(p.stdout)['errors'] == ['report_file_unavailable']
    checks += 1
    # build1869: a decrease in task population must require manual review even
    # when all diagnostic categories remain clean.
    baseline = make_report(2026101868)
    same = make_report(2026101869)
    result = run(same, baseline=baseline)
    assert result['task_population_change'] == {
        'before':120, 'after':120, 'delta':0,
        'decreased':False, 'review_required':False,
    }
    assert result['comparison_gate'] == 'passed'
    grown = make_report(2026101869)
    grown['integrity_checks']['tasks_total']['count'] = 152
    result = run(grown, baseline=baseline)
    assert result['task_population_change']['delta'] == 32
    assert result['comparison_gate'] == 'passed'
    reduced = make_report(2026101869)
    reduced['integrity_checks']['tasks_total']['count'] = 119
    result = run(reduced, baseline=baseline)
    assert result['task_population_change']['review_required'] is True
    assert result['comparison_gate'] == 'warnings'
    result = run(reduced, baseline=baseline, args=('--strict',), expected=1)
    assert result['comparison_gate'] == 'failed'
    reduced['integrity_checks']['tasks_total']['count'] = 1
    result = run(reduced, baseline=baseline)
    assert result['task_population_change']['delta'] == -119
    assert result['comparison_gate'] == 'warnings'
    reduced['integrity_checks']['tasks_total']['count'] = 0
    normalise(reduced)
    result = run(reduced, baseline=baseline)
    assert result['task_population_change']['delta'] == -120
    assert result['comparison_gate'] == 'warnings'
    empty = make_report(2026101868)
    empty['integrity_checks']['tasks_total']['count'] = 0
    normalise(empty)
    result = run(grown, baseline=empty)
    assert result['task_population_change']['delta'] == 152
    assert result['comparison_gate'] == 'passed'
    unchangedempty = make_report(2026101869)
    unchangedempty['integrity_checks']['tasks_total']['count'] = 0
    normalise(unchangedempty)
    result = run(unchangedempty, baseline=empty)
    assert result['task_population_change']['delta'] == 0
    assert result['comparison_gate'] == 'warnings'
    # Text mode is used by administrators working directly in the shell.
    path_before.write_text(json.dumps(make_report(2026101868)))
    textafter = make_report(2026101869)
    textafter['integrity_checks']['tasks_total']['count'] = 100
    path_after.write_text(json.dumps(textafter))
    p = subprocess.run([PHP, str(executable), f'--baseline={path_before}',
                        f'--current={path_after}', '--format=text', '--strict'],
                       env=env, text=True, capture_output=True, timeout=20)
    assert p.returncode == 1, (p.returncode, p.stdout, p.stderr)
    assert 'Tasks in scope: 120 -> 100 (change -20)' in p.stdout
    assert 'REVIEW REQUIRED: task population decreased' in p.stdout
    checks += 1
    print(f'PASS {checks} isolated CLI comparison scenarios: complete evidence, task population, strict gate, scope and runtime')
