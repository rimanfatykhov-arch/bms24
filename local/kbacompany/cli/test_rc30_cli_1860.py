#!/usr/bin/env python3
"""Simulate Moodle CLI contract for rc30_task_integrity (no production DB)."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

plugin = Path(__file__).resolve().parents[1]
php = shutil.which('php')
if not php:
    sys.exit('PHP command not found')
with tempfile.TemporaryDirectory(prefix='bms1860-cli-') as temp:
    root = Path(temp)
    cli = root / 'local/kbacompany/cli'
    cli.mkdir(parents=True)
    lib = root / 'lib'
    lib.mkdir()
    shutil.copyfile(plugin / 'cli/rc30_task_integrity.php', cli / 'rc30_task_integrity.php')
    (root / 'config.php').write_text(r'''<?php
    define('MOODLE_INTERNAL', true);
    $CFG = (object)['libdir' => __DIR__ . '/lib'];
    require getenv('BMS1860_PLUGIN_ROOT') . '/classes/local/task_rc30_integrity_service.php';
    require getenv('BMS1860_PLUGIN_ROOT') . '/classes/local/task_rc30_cli_response_service.php';
    class xmldb_table { public function __construct($name) {} }
    class mockmanager { public function table_exists($table) {return true;} }
    class mockdb {
        public function get_manager(){ return new mockmanager(); }
        public function get_field_sql($sql, $params){
            if (strpos($sql, 't.revision < 1') !== false && getenv('BMS1860_ERROR') === '1') {return 2;}
            if (strpos($sql, "TRIM(t.product) = ''") !== false && getenv('BMS1860_WARN') === '1') {return 3;}
            return 0;
        }
    }
    $DB = new mockdb();
''')
    (lib / 'clilib.php').write_text(r'''<?php
    function cli_get_params($defaults, $short) {
        global $argv;
        $options = $defaults; $unknown = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (substr($arg, 0, 2) !== '--') {$unknown[] = $arg;continue;}
            $entry = explode('=', substr($arg, 2), 2);
            $name = $entry[0];
            if (!array_key_exists($name, $options)) {$unknown[] = $arg; continue;}
            $options[$name] = isset($entry[1]) ? $entry[1] : true;
        }
        return [$options, $unknown];
    }
''')
    env = dict(os.environ, BMS1860_PLUGIN_ROOT=str(plugin))
    company = '11111111-1111-4111-8111-111111111111'
    def run(*args, warning=False, error=False):
        flags = dict(env, BMS1860_WARN='1' if warning else '0',
                     BMS1860_ERROR='1' if error else '0')
        return subprocess.run([php, str(cli / 'rc30_task_integrity.php'), *args],
                              text=True, capture_output=True, env=flags)

    good = run(f'--company={company}', '--format=json')
    assert good.returncode == 0, (good.stdout, good.stderr)
    payload = json.loads(good.stdout)
    assert payload['gate'] == 'passed'
    assert payload['summary']['checks'] == 18
    assert payload['read_only'] is True and payload['scope'] == 'company'

    warning = run(f'--company={company}', '--format=json', warning=True)
    assert warning.returncode == 0 and json.loads(warning.stdout)['gate'] == 'warnings'
    strict = run(f'--company={company}', '--format=json', '--strict', warning=True)
    assert strict.returncode == 1 and json.loads(strict.stdout)['gate'] == 'failed'
    error = run('--all', '--format=json', error=True)
    assert error.returncode == 1 and json.loads(error.stdout)['gate'] == 'failed'

    bad_scope = run('--format=json')
    assert bad_scope.returncode == 2
    bad_format = run('--all', '--format=xml')
    assert bad_format.returncode == 2
    bad_uuid = run('--company=invalid', '--format=json')
    assert bad_uuid.returncode == 2
    unknown = run('--all', '--unknown')
    assert unknown.returncode == 2
    help_result = run('--help')
    assert help_result.returncode == 0 and '--strict' in help_result.stdout
    print('PASS 9 CLI scenarios: JSON, strict, warning, error, bad args, help')
