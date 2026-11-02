#!/usr/bin/env python3
"""End-to-end RC30 CLI output contracts (fake Moodle; no live database writes)."""
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
assert PHP is not None, 'php executable missing'

with tempfile.TemporaryDirectory(prefix='bms1866-cli-') as temp:
    root = Path(temp)
    cli = root / 'local/kbacompany/cli'
    cli.mkdir(parents=True)
    lib = root / 'lib'
    lib.mkdir()
    for name in ('rc30_acceptance.php', 'rc30_task_integrity.php'):
        shutil.copyfile(PLUGIN / 'cli' / name, cli / name)
    (root / 'config.php').write_text(r'''<?php
        define('MOODLE_INTERNAL', true);
        define('XMLDB_INDEX_NOTUNIQUE', 0);
        class coding_exception extends Exception {}
        class invalid_parameter_exception extends Exception {}
        class dml_exception extends Exception {}
        class xmldb_table {public function __construct($name) {}}
        class xmldb_index {public function __construct($name, $type, $fields) {}}
        $CFG = (object)['libdir'=>__DIR__.'/lib'];
        $pluginroot = getenv('BMS1866_PLUGIN_ROOT');
        require $pluginroot.'/classes/local/task_rc30_cli_response_service.php';
        require $pluginroot.'/classes/local/task_rc30_integrity_service.php';
        require $pluginroot.'/classes/local/task_rc30_acceptance_service.php';
        class MockIndexManager1866 {
            public function table_exists($table) {
                return getenv('BMS1866_MODE') !== 'schema';
            }
            public function index_exists($table, $index) {return true;}
        }
        class MockDB1866 {
            public function get_manager() {return new MockIndexManager1866();}
            public function get_dbfamily() {return 'mysql';}
            public function get_server_info() {return ['version'=>'5.7.21'];}
            public function get_field_sql($sql, $params = []) {
                if (getenv('BMS1866_MODE') === 'sql') {
                    throw new dml_exception('SECRET-SQL-PRODUCTION-TABLE');
                }
                if (strpos($sql, 'WHERE 1=1') !== false) {return 12;}
                return 0;
            }
        }
        function get_config($plugin, $key) {
            return \local_kbacompany\local\task_rc30_acceptance_service::EXPECTED_PLUGIN_VERSION;
        }
        $DB = new MockDB1866();
    ''')
    (lib / 'clilib.php').write_text(r'''<?php
        function cli_get_params($defaults, $short) {
            global $argv;
            $options=$defaults; $unknown=[];
            foreach (array_slice($argv,1) as $arg) {
                if (substr($arg,0,2)!=='--') { $unknown[]=$arg; continue; }
                $parts=explode('=',substr($arg,2),2);
                if (!array_key_exists($parts[0],$options)) {$unknown[]=$arg;continue;}
                $options[$parts[0]]=isset($parts[1]) ? $parts[1] : true;
            }
            return [$options,$unknown];
        }
    ''')
    company='11111111-1111-4111-8111-111111111111'
    env=dict(os.environ, BMS1866_PLUGIN_ROOT=str(PLUGIN))
    def run(tool, *args, mode='normal'):
        return subprocess.run(
            [PHP,str(cli/tool),*args],
            capture_output=True,text=True,
            env=dict(env,BMS1866_MODE=mode),timeout=15)

    checks=0
    for name, gate in [('rc30_acceptance.php','technical_gate'),
                       ('rc30_task_integrity.php','gate')]:
        def verify(args, expected_code, expected_reason=None, mode='normal'):
            global checks
            result=run(name,*args,mode=mode)
            assert result.returncode == expected_code, (name,args,result.stdout,result.stderr)
            assert 'SECRET-SQL-PRODUCTION-TABLE' not in result.stdout + result.stderr
            payload=json.loads(result.stdout)
            assert payload[gate]=='failed' if expected_code else payload[gate] in ('passed','warnings')
            if expected_reason:
                assert payload['errors']==[expected_reason],payload
                assert payload['diagnostic_complete'] is False
                assert payload['rc30_promotion_approved'] is False
                assert payload['read_only'] is True
            checks+=1
            return payload
        verify(('--format=json',),2,'invalid_arguments')
        verify(('--all','--format=json','--bad'),2,'invalid_arguments')
        verify((f'--company={company}','--all','--format=json'),2,'invalid_arguments')
        verify(('--company=invalid','--format=json'),2,'invalid_company_uuid')
        verify(('--all','--format=json'),2,'missing_rc30_table',mode='schema')
        verify(('--all','--format=json'),2,'database_query_error',mode='sql')
        ok=run(name,f'--company={company}','--format=json')
        assert ok.returncode==0,(name,ok.stderr,ok.stdout)
        assert json.loads(ok.stdout)[gate] in ('passed','warnings')
        checks+=1
        txt=run(name,'--all',mode='schema')
        assert txt.returncode==2 and 'missing_rc30_table' in txt.stderr
        assert txt.stdout==''
        checks+=1

    print(f'PASS {checks} CLI scenarios across acceptance + integrity, JSON on error, no sensitive output')
