#!/usr/bin/env python3
"""Isolated end-to-end RC30 offline comparator tests: NO config.php or database."""
from pathlib import Path
import copy
import json
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
assert PHP, 'PHP CLI required'

with tempfile.TemporaryDirectory(prefix='bms-rc30-offline-') as tmpdir:
    tmp = Path(tmpdir)
    # Intentionally do not create Moodle config.php, core/lib or DB connection.
    cli = tmp / 'local' / 'kbacompany' / 'cli'
    services = tmp / 'local' / 'kbacompany' / 'classes' / 'local'
    cli.mkdir(parents=True)
    services.mkdir(parents=True)
    shutil.copyfile(ROOT/'cli/rc30_compare_offline.php', cli/'rc30_compare_offline.php')
    for name in ('task_rc30_report_comparison_service',
                 'task_rc30_integrity_service', 'task_rc30_acceptance_service'):
        shutil.copyfile(ROOT/'classes/local'/f'{name}.php', services/f'{name}.php')
    env = os.environ.copy()
    spec = r'''
        define('MOODLE_INTERNAL', true);
        require $argv[1]; require $argv[2];
        $x = ['checks'=>[], 'indexes'=>[]];
        foreach (\local_kbacompany\local\task_rc30_integrity_service::checks() as $n => $d) {
            $x['checks'][$n] = $d['severity'];
        }
        foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as $d) {
            $x['indexes'][$d[1]] = $d[0];
        }
        echo json_encode($x);
    '''
    proc = subprocess.run([PHP, '-r', spec, str(services/'task_rc30_integrity_service.php'),
        str(services/'task_rc30_acceptance_service.php')], capture_output=True,
        text=True, check=True, timeout=20)
    canonical = json.loads(proc.stdout)
    assert len(canonical['checks']) == 18 and len(canonical['indexes']) == 4

    def report(version=2026101870, stamp=1790000000):
        checks = {k: {'severity': v, 'count': 0} for k, v in canonical['checks'].items()}
        checks['tasks_total']['count'] = 120
        return {
            'schema_version': 1, 'scope': 'all', 'read_only': True,
            'rc30_promotion_approved': False, 'diagnostic_complete': True,
            'installation_digest': 'a'*64, 'captured_at': stamp,
            'strict': False, 'technical_gate': 'passed',
            'installed_plugin_version': version, 'expected_plugin_version': version,
            'runtime': {'php': '8.1.34', 'dbfamily': 'mysql',
                        'db_server_version': '5.7.21'},
            'indexes': {k: {'table': v, 'present': True} for k, v in canonical['indexes'].items()},
            'integrity_checks': checks,
            'integrity_summary': {'checks': 18, 'errors': 0, 'warnings': 0},
            'errors': [], 'warnings': [],
        }

    before_file = tmp/'baseline.json'
    after_file = tmp/'current.json'
    executable = cli/'rc30_compare_offline.php'
    before = report()
    after = report(version=2026101871, stamp=1790000100)
    cases = 0

    def run(before_data, after_data, *, expected=0, code=None, gate=None,
            flags=(), current_path=None):
        before_file.write_text(json.dumps(before_data), encoding='utf8')
        after_file.write_text(json.dumps(after_data), encoding='utf8')
        args = [PHP, str(executable), f'--baseline={before_file}',
                f'--current={current_path or after_file}', '--format=json', *flags]
        proc = subprocess.run(args, capture_output=True, text=True,
            timeout=20, env=env)
        assert proc.returncode == expected, (proc.returncode, proc.stdout, proc.stderr)
        assert 'edu.kzn.mba' not in proc.stdout and 'SELECT ' not in proc.stdout
        result = json.loads(proc.stdout)
        assert result['read_only'] is True and result['rc30_promotion_approved'] is False
        if code:
            assert result['comparison_complete'] is False and result['errors'] == [code], result
        else:
            assert result['comparison_complete'] is True, result
            assert result['comparison_gate'] == gate, result
        return result

    # 1-2. Real, independent files, no config, valid comparison and strict gate.
    assert run(before, after, gate='passed')['installation_verified']
    cases += 1
    assert run(before, after, gate='passed', flags=['--strict'])['chronology_verified']
    cases += 1

    # 3-4. Defect warning is nonblocking unless strict.
    bad = copy.deepcopy(after)
    bad['integrity_checks']['orphan_task_history']['count'] = 2
    bad['integrity_summary']['warnings'] = 1
    bad['warnings'] = ['data_integrity_warnings']
    bad['technical_gate'] = 'warnings'
    run(before, bad, gate='warnings')
    cases += 1
    run(before, bad, expected=1, gate='failed', flags=['--strict'])
    cases += 1

    # 5-8. Scope and evidence provenance.
    changed = copy.deepcopy(after)
    changed['installation_digest'] = 'b'*64
    run(before, changed, expected=2, code='report_installation_mismatch')
    cases += 1
    changed = copy.deepcopy(after)
    changed['captured_at'] = before['captured_at'] - 1
    run(before, changed, expected=2, code='report_time_rollback')
    cases += 1
    changed = copy.deepcopy(after)
    changed['installed_plugin_version'] = 2026101869
    changed['expected_plugin_version'] = 2026101869
    run(before, changed, expected=2, code='report_version_rollback')
    cases += 1
    changed = copy.deepcopy(after)
    changed['scope'] = 'company'
    run(before, changed, expected=2, code='report_scope_mismatch')
    cases += 1

    # 9-11. Equivalent content in distinct files is NOT another acceptance run.
    run(before, before, expected=2, code='report_duplicate_snapshot')
    cases += 1
    run(before, after, expected=2, code='report_same_file', current_path=before_file)
    cases += 1
    after_file.write_text('{not-json', encoding='utf8')
    p = subprocess.run([PHP, str(executable), f'--baseline={before_file}',
        f'--current={after_file}', '--format=json'], capture_output=True, text=True, timeout=20)
    assert p.returncode == 2 and json.loads(p.stdout)['errors'] == ['report_invalid_json']
    cases += 1

    # 12-14. Missing, oversize and symlink files fail closed.
    run(before, after, expected=2, code='report_file_unavailable',
        current_path=tmp/'missing.json')
    cases += 1
    huge = tmp/'large.json'
    huge.write_bytes(b' ' * (2 * 1024 * 1024 + 1))
    run(before, after, expected=2, code='report_file_unavailable', current_path=huge)
    cases += 1
    shortcut = tmp/'alias.json'
    shortcut.symlink_to(after_file)
    run(before, after, expected=2, code='report_file_unavailable', current_path=shortcut)
    cases += 1

    # 15-17. Malformed arguments, no network/DB, safe text-mode output.
    p = subprocess.run([PHP, str(executable), '--format=json', '--unknown'],
        capture_output=True, text=True, timeout=20)
    assert p.returncode == 2 and json.loads(p.stdout)['errors'] == ['invalid_arguments']
    cases += 1
    p = subprocess.run([PHP, str(executable), f'--baseline={before_file}',
        f'--current={after_file}', '--format=json', '--format=json'],
        capture_output=True, text=True, timeout=20)
    assert p.returncode == 2 and json.loads(p.stdout)['errors'] == ['invalid_arguments']
    cases += 1
    run(before, after, gate='passed')
    p = subprocess.run([PHP, str(executable), f'--baseline={before_file}',
        f'--current={after_file}'], capture_output=True, text=True, timeout=20)
    assert p.returncode == 0 and 'PASSED' in p.stdout and 'No data changed' in p.stdout
    cases += 1

    # 18-20. Detection of missing/edited critical diagnostic claims.
    changed = copy.deepcopy(after)
    del changed['indexes']['lastlog_ix']
    run(before, changed, expected=2, code='report_checks_missing')
    cases += 1
    changed = copy.deepcopy(after)
    changed['integrity_checks']['orphan_checklists']['count'] = 5
    run(before, changed, expected=2, code='report_summary_mismatch')
    cases += 1
    changed = copy.deepcopy(after)
    changed['runtime']['php'] = '8.4.24'
    run(before, changed, expected=2, code='report_summary_mismatch')
    cases += 1

    # 21. Offline tool can be run with only its three classes, no site or database.
    assert not (tmp/'config.php').exists()
    p = subprocess.run([PHP, str(executable), '--help'], capture_output=True,
        text=True, timeout=20)
    assert p.returncode == 0 and 'no Moodle or database' in p.stdout
    cases += 1

    print(f'PASS {cases} RC30 offline CLI cases (no Moodle installation, no DB)')
