#!/usr/bin/env python3
"""STANDARD tier: focused PHPUnit on a disposable Moodle 4.1.22 (single fresh PHPUnit site, no historical upgrades).

Runs the classifier's focused tests plus the mandatory security gates, each in its own PHP process, and writes a
JSON report with PASS/FAIL/NOT RUN and timings. Disposable CI only: refuses non-local DB hosts and paths outside
RUNNER_TEMP. Env: MOODLE_ROOT, CANDIDATE_ROOT, TEST_ROOT, DBTYPE (mariadb|mysqli), DBHOST=127.0.0.1, DBPORT, DBUSER,
DBPASS. Args: --tests "a_test b_test ..." --report out.json
"""
import argparse
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
from pathlib import Path


def env(name):
    value = os.environ.get(name, '')
    if not value:
        sys.exit('missing env ' + name)
    return value


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--tests', required=True)
    ap.add_argument('--report', required=True)
    a = ap.parse_args()
    runner = Path(env('RUNNER_TEMP')).resolve()
    root = Path(env('TEST_ROOT')).resolve()
    if runner not in root.parents or env('DBHOST') != '127.0.0.1' or env('DBTYPE') not in ('mariadb', 'mysqli'):
        sys.exit('refusing non-disposable configuration')
    core = Path(env('MOODLE_ROOT')).resolve(); cand = Path(env('CANDIDATE_ROOT')).resolve()
    report = {'tier': 'STANDARD', 'dbtype': env('DBTYPE'), 'timing_s': {}, 'phpunit': {}, 'scope':
              'fresh Moodle 4.1.22 PHPUnit site with candidate local_kbacompany + local_kbacorporate; no historical upgrades'}
    tests = [t for t in a.tests.split() if t]
    logs = root / 'logs'; logs.mkdir(parents=True, exist_ok=True)

    def timed(key, fn):
        t = time.time(); r = fn(); report['timing_s'][key] = round(time.time() - t, 1); return r

    def prepare():
        for plugin in ('kbacompany', 'kbacorporate'):
            dst = core / 'local' / plugin
            if dst.exists():
                sys.exit('core checkout already contains ' + plugin)
            shutil.copytree(cand / 'local' / plugin, dst)
        (root / 'data').mkdir(mode=0o700); (root / 'phpunitdata').mkdir(mode=0o700)
        cfg = {'dbtype': env('DBTYPE'), 'dblibrary': 'native', 'dbhost': '127.0.0.1', 'dbname': 'bmsstd_site',
               'dbuser': env('DBUSER'), 'dbpass': env('DBPASS'), 'prefix': 'mdl_',
               'dboptions': {'dbpersist': False, 'dbport': int(env('DBPORT')), 'dbsocket': '', 'dbcollation': 'utf8mb4_unicode_ci'},
               'wwwroot': 'http://127.0.0.1.invalid/standard', 'dataroot': str(root / 'data'), 'admin': 'admin',
               'directorypermissions': 0o700, 'phpunit_prefix': 'pht_', 'phpunit_dataroot': str(root / 'phpunitdata'),
               'phpunit_dbname': 'bmsstd_unit', 'passwordpolicy': 0, 'noemailever': True, 'upgradekey': secrets.token_hex(16)}

        def php(v):
            if isinstance(v, bool): return 'true' if v else 'false'
            if isinstance(v, int): return str(v)
            if isinstance(v, dict): return '[' + ', '.join("'%s' => %s" % (k, php(x)) for k, x in v.items()) + ']'
            return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"
        text = "<?php\nunset($CFG);\nglobal $CFG;\n$CFG = new stdClass();\n" + ''.join(
            '$CFG->%s = %s;\n' % (k, php(v)) for k, v in cfg.items()) + "require_once(__DIR__ . '/lib/setup.php');\n"
        (core / 'config.php').write_text(text); os.chmod(core / 'config.php', 0o600)
        for db in ('bmsstd_site', 'bmsstd_unit'):
            subprocess.run(['mysql', '-h127.0.0.1', '-P' + env('DBPORT'), '-u' + env('DBUSER'), '-p' + env('DBPASS'), '-e',
                            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' % db], check=True)

    def run(args, log):
        with open(logs / log, 'w') as out:
            return subprocess.run(args, cwd=core, stdout=out, stderr=subprocess.STDOUT).returncode

    timed('prepare', prepare)
    init = timed('phpunit_init', lambda: run(['php', 'admin/tool/phpunit/cli/init.php'], 'phpunit-init.log'))
    report['phpunit']['init'] = 'PASS' if init == 0 else 'FAIL'
    t0 = time.time()
    for test in tests:
        path = core / 'local' / 'kbacompany' / 'tests' / (test + '.php')
        if init != 0 or not path.exists():
            report['phpunit'][test] = 'NOT RUN'; continue
        t = time.time()
        code = run(['php', 'vendor/bin/phpunit', '--configuration', 'phpunit.xml', '--log-junit', str(logs / (test + '-junit.xml')),
                    str(path)], test + '.log')
        text = (logs / (test + '.log')).read_text(errors='replace')
        executed = re.search(r'^OK \((\d+) tests?, (\d+) assertions?\)', text, re.M)
        # Exit 0 without executed tests and assertions is NOT a pass.
        report['phpunit'][test] = 'PASS' if (code == 0 and executed and int(executed.group(1)) > 0
                                             and int(executed.group(2)) > 0) else 'FAIL'
        report.setdefault('counts', {})[test] = executed.group(0) if executed else (
            re.findall(r'^(Tests: .*|FAILURES!|ERRORS!|No tests executed!)', text, re.M) or ['no summary'])[-1]
        report['timing_s']['test:' + test] = round(time.time() - t, 1)
        print('%s: %s' % (test, report['phpunit'][test]), flush=True)
    report['timing_s']['phpunit_tests_total'] = round(time.time() - t0, 1)
    report['status'] = 'PASS' if all(v == 'PASS' for v in report['phpunit'].values()) and tests else 'FAIL'
    Path(a.report).write_text(json.dumps(report, indent=1))
    return 0 if report['status'] == 'PASS' else 1


if __name__ == '__main__':
    sys.exit(main())
