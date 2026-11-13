#!/usr/bin/env python3
"""BMS24 build1870: isolated RC30 snapshot provenance and chronology tests (no Moodle DB)."""
from pathlib import Path
import copy
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
assert PHP, 'PHP required'
ENV = dict(os.environ, BMS1870_ROOT=str(ROOT))

spec = r'''
define('MOODLE_INTERNAL', true);
require getenv('BMS1870_ROOT').'/classes/local/task_rc30_integrity_service.php';
require getenv('BMS1870_ROOT').'/classes/local/task_rc30_acceptance_service.php';
$out=['checks'=>[], 'indexes'=>[]];
foreach (\local_kbacompany\local\task_rc30_integrity_service::checks() as $name=>$def) {
    $out['checks'][$name]=$def['severity'];
}
foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as $def) {
    $out['indexes'][$def[1]]=$def[0];
}
echo json_encode($out);
'''
res = subprocess.run([PHP, '-r', spec], capture_output=True, text=True,
                     env=ENV, check=True, timeout=10)
CANONICAL = json.loads(res.stdout)
assert len(CANONICAL['checks']) == 18 and len(CANONICAL['indexes']) == 4


def report(version=2026101870, timestamp=1782800000, site='https://edu.kzn.mba'):
    checks = {name: {'severity': sev, 'count': 0} for name, sev in CANONICAL['checks'].items()}
    checks['tasks_total']['count'] = 120
    return {
        'schema_version': 1, 'scope': 'all', 'read_only': True,
        'installation_digest': hashlib.sha256(site.encode('utf-8')).hexdigest(),
        'captured_at': timestamp, 'rc30_promotion_approved': False,
        'strict': False, 'installed_plugin_version': version,
        'expected_plugin_version': version, 'technical_gate': 'passed',
        'runtime': {'php': '8.1.34', 'dbfamily': 'mysql', 'db_server_version': '5.7.21',
                    'target_php': '8.1', 'target_db': 'MySQL 5.7'},
        'indexes': {name: {'table': table, 'present': True}
                    for name, table in CANONICAL['indexes'].items()},
        'integrity_checks': checks,
        'integrity_summary': {'checks': len(checks), 'errors': 0, 'warnings': 0},
        'errors': [], 'warnings': []}


with tempfile.TemporaryDirectory(prefix='bms1870-') as dirname:
    tmp = Path(dirname)
    cli = tmp/'local/kbacompany/cli'
    cli.mkdir(parents=True)
    lib = tmp/'lib'; lib.mkdir()
    shutil.copyfile(ROOT/'cli/rc30_compare_reports.php', cli/'rc30_compare_reports.php')
    (tmp/'config.php').write_text('''<?php
        define('MOODLE_INTERNAL', true);
        $CFG=(object)['libdir'=>__DIR__.'/lib'];
        foreach (['task_rc30_integrity_service','task_rc30_acceptance_service',
                 'task_rc30_report_comparison_service'] as $service) {
            require getenv('BMS1870_ROOT').'/classes/local/'.$service.'.php';
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
    prior=tmp/'prior.json'; later=tmp/'later.json'
    count=0

    def check(before, after, *, expected=0, gate=None, code=None, flags=(), same_file=False):
        global count
        prior.write_text(json.dumps(before)); later.write_text(json.dumps(after))
        cmd = [PHP, str(cli/'rc30_compare_reports.php'),
               f'--baseline={prior}', f'--current={prior if same_file else later}',
               '--format=json', *flags]
        p=subprocess.run(cmd, capture_output=True, text=True, env=ENV, timeout=20)
        assert p.returncode == expected, (p.returncode, p.stdout, p.stderr)
        assert 'https://edu.kzn.mba' not in p.stdout
        data=json.loads(p.stdout)
        if code:
            assert data['errors'] == [code] and data['comparison_complete'] is False, data
        else:
            assert data['comparison_complete'] and data['comparison_gate'] == gate, data
        count+=1
        return data

    before=report(timestamp=1782800000)
    after=report(timestamp=1782800100)
    good=check(before,after,gate='passed')
    assert good['installation_verified'] and good['chronology_verified']
    assert good['provenance_warnings'] == []
    check(before,after,gate='passed',flags=('--strict',))
    wrong=report(timestamp=1782800100,site='https://staging.kzn.mba')
    check(before,wrong,expected=2,code='report_installation_mismatch')
    check(before,report(timestamp=1782799999),expected=2,code='report_time_rollback')
    check(before,after,expected=2,code='report_same_file',same_file=True)
    stale=copy.deepcopy(before); del stale['installation_digest']
    result=check(stale,after,gate='warnings')
    assert result['provenance_warnings'] == ['legacy_installation_unverified']
    check(stale,after,gate='failed',expected=1,flags=('--strict',))
    stale=copy.deepcopy(before); del stale['captured_at']
    result=check(stale,after,gate='warnings')
    assert result['provenance_warnings'] == ['legacy_chronology_unverified']
    check(stale,after,gate='failed',expected=1,flags=('--strict',))
    stale=copy.deepcopy(before); del stale['captured_at']; del stale['installation_digest']
    result=check(stale,after,gate='warnings')
    assert len(result['provenance_warnings']) == 2
    check(stale,after,gate='failed',expected=1,flags=('--strict',))
    invalid=copy.deepcopy(after); invalid['captured_at']='2026-10-28'
    check(before,invalid,expected=2,code='report_invalid_schema')
    invalid=copy.deepcopy(after); invalid['installation_digest']='sensitive domain'
    check(before,invalid,expected=2,code='report_invalid_schema')
    equal=report(timestamp=1782800000)
    check(before,equal,gate='passed')

    # Live assess() API with fake database is READ-ONLY and emits no domain.
    php = r'''
        define('MOODLE_INTERNAL', true);
        define('XMLDB_INDEX_NOTUNIQUE', 0);
        class xmldb_table {public function __construct($n) {}}
        class xmldb_index {public function __construct($n,$k,$f) {}}
        class FakeManager {
            public function table_exists($table) {return true;}
            public function index_exists($table,$index) {return true;}
        }
        class FakeDB {
            public function get_indexes($table, $usecache = true) {
                $result = [];
                foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as [$target, $name, $fields]) {
                    if ($target === $table) {$result[$name] = ['unique' => false, 'columns' => $fields];}
                }
                return $result;
            }
            public function get_manager() {return new FakeManager();}
            public function get_field_sql($sql,$params) {
                if (strpos($sql,'WHERE 1=1')!==false) {return 120;}
                return 0;
            }
            public function get_dbfamily() {return 'mysql';}
            public function get_server_info() {return ['version'=>'5.7.21'];}
        }
        foreach (['task_rc30_integrity_service','task_rc30_acceptance_service'] as $service) {
            require getenv('BMS1870_ROOT').'/classes/local/'.$service.'.php';
        }
        $result=\local_kbacompany\local\task_rc30_acceptance_service::assess(
            new FakeDB(), 2026101870, '', false, 'https://EDU.KZN.MBA/');
        echo json_encode($result);
    '''
    started=time.time()
    res=subprocess.run([PHP,'-r',php],capture_output=True,text=True,env=ENV,
                       timeout=15,check=True)
    obj=json.loads(res.stdout)
    assert obj['installation_digest'] == hashlib.sha256(
        b'https://edu.kzn.mba').hexdigest()
    assert int(started)-2 <= obj['captured_at'] <= int(time.time())+2
    assert obj['integrity_checks']['tasks_total']['count'] == 120
    assert 'edu.kzn.mba' not in res.stdout
    count+=1
    print(f'PASS {count} RC30 provenance and chronology cases (isolated, no Moodle DB)')
