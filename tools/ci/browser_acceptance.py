#!/usr/bin/env python3
"""BROWSER tier (RC31): HTTP + real Chromium acceptance on a disposable Moodle 4.1.22 site in GitHub Actions.

Installs Moodle with the candidate plugins (MariaDB service, no historical upgrades), creates synthetic companies A/B,
serves the site with the PHP built-in server (outbound requests from the browser are blocked by the scenarios) and runs:
task tab keyboard focus (Pilot 01), attachments in the compact task discussion and the Communication Center incl.
file-only, double submit, CSRF and size limit (Pilot 02), and protected file download for participant / other company /
anonymous / revoked / reopened. Every expectation is evaluated here; PASS/FAIL/NOT RUN + timings to a JSON report.
Env: MOODLE_ROOT, CANDIDATE_ROOT, TEST_ROOT (under RUNNER_TEMP), DBHOST=127.0.0.1, DBPORT, DBUSER, DBPASS.
"""
import json
import os
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent / 'browser'


def env(name):
    v = os.environ.get(name, '')
    if not v:
        sys.exit('missing env ' + name)
    return v


runner = Path(env('RUNNER_TEMP')).resolve(); root = Path(env('TEST_ROOT')).resolve()
if runner not in root.parents or env('DBHOST') != '127.0.0.1':
    sys.exit('refusing non-disposable configuration')
core = Path(env('MOODLE_ROOT')).resolve(); cand = Path(env('CANDIDATE_ROOT')).resolve()
evidence = root / 'evidence'; logs = root / 'logs'
for d in (evidence, logs, root / 'data'):
    d.mkdir(parents=True, exist_ok=True)
report = {'tier': 'BROWSER', 'timing_s': {}, 'checks': {}, 'scope': 'fresh Moodle 4.1.22 (MariaDB) + candidate plugins, '
          'PHP built-in server, Chromium; synthetic companies/users/files only'}
cienv = dict(os.environ, BMS_CI_BROWSER='1', EVIDENCE=str(evidence), MOODLE_ROOT=str(core))
server = None


def timed(key, fn):
    t = time.time()
    try:
        return fn()
    finally:
        report['timing_s'][key] = round(time.time() - t, 1)


def run(args, log, **kw):
    with open(logs / log, 'a') as out:
        return subprocess.run(args, cwd=core, stdout=out, stderr=subprocess.STDOUT, env=cienv, **kw).returncode


def check(name, ok, **detail):
    report['checks'][name] = {'status': 'PASS' if ok else 'FAIL', **detail}
    print('%-42s %s %s' % (name, 'PASS' if ok else 'FAIL', json.dumps(detail, ensure_ascii=False)[:180]), flush=True)


def load(name):
    p = evidence / name
    return json.loads(p.read_text()) if p.exists() else None


def start_server(extra=()):
    global server
    stop_server()
    out = open(logs / 'php-server.log', 'a')
    server = subprocess.Popen(['php', *extra, '-S', '127.0.0.1:8080', '-t', str(core)], cwd=core, stdout=out, stderr=out,
                              env=dict(cienv, PHP_CLI_SERVER_WORKERS='4'), start_new_session=True)
    for _ in range(60):
        try:
            if urllib.request.urlopen('http://127.0.0.1:8080/login/index.php', timeout=5).status == 200:
                return
        except Exception:
            time.sleep(1)
    raise RuntimeError('PHP server did not start')


def stop_server():
    global server
    if server:
        # PHP_CLI_SERVER_WORKERS forks workers: stop the whole process group, then wait until the port is free.
        try: os.killpg(server.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        try: server.wait(timeout=10)
        except Exception:
            try: os.killpg(server.pid, signal.SIGKILL)
            except ProcessLookupError: pass
    server = None
    for _ in range(30):
        with socket.socket() as sck:
            if sck.connect_ex(('127.0.0.1', 8080)) != 0:
                return
        time.sleep(1)
    raise RuntimeError('port 8080 still in use')


def browser(script, phase):
    return run([sys.executable, str(HERE / script), phase], 'browser-%s-%s.log' % (script, phase), timeout=900)


def php_state(phase):
    return run(['php', str(HERE / 'ci_state.php'), str(core), phase], 'state-%s.log' % phase, timeout=300)


try:
    def install():
        for plugin in ('kbacompany', 'kbacorporate'):
            shutil.copytree(cand / 'local' / plugin, core / 'local' / plugin)
        cfg = ("<?php\nunset($CFG);\nglobal $CFG;\n$CFG = new stdClass();\n$CFG->dbtype = 'mariadb';\n$CFG->dblibrary = 'native';\n"
               "$CFG->dbhost = '127.0.0.1';\n$CFG->dbname = 'bmsci_web';\n$CFG->dbuser = %r;\n$CFG->dbpass = %r;\n$CFG->prefix = 'mdl_';\n"
               "$CFG->dboptions = ['dbpersist' => false, 'dbport' => %d, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];\n"
               "$CFG->wwwroot = 'http://127.0.0.1:8080';\n$CFG->dataroot = %r;\n$CFG->admin = 'admin';\n$CFG->directorypermissions = 02777;\n"
               "$CFG->noemailever = true;\n$CFG->passwordpolicy = 0;\n$CFG->debugdisplay = 0;\n$CFG->cronclionly = true;\n"
               "require_once(__DIR__ . '/lib/setup.php');\n") % (env('DBUSER'), env('DBPASS'), int(env('DBPORT')), str(root / 'data'))
        (core / 'config.php').write_text(cfg.replace("'", "'"))
        subprocess.run(['mysql', '-h127.0.0.1', '-P' + env('DBPORT'), '-u' + env('DBUSER'), '-p' + env('DBPASS'), '-e',
                        'CREATE DATABASE bmsci_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'], check=True)
        return run(['php', 'admin/cli/install_database.php', '--agree-license', '--adminuser=admin',
                    '--adminpass=' + secrets.token_hex(16) + 'Aa1!', '--adminemail=ci@example.invalid',
                    '--fullname=BMS CI browser', '--shortname=BMSCIB', '--lang=en'], 'install.log', timeout=1200)
    code = timed('moodle_install', install)
    check('moodle_install', code == 0)
    if code != 0:
        raise RuntimeError('install failed')
    code = timed('fixtures', lambda: run(['php', str(HERE / 'ci_fixtures.php'), str(core)], 'fixtures.log', timeout=300))
    check('fixtures_companies_A_B', code == 0 and (evidence / 'actors.private.json').exists())
    if code != 0:
        raise RuntimeError('fixtures failed')
    timed('server_start', start_server)

    # ---- Pilot 01: Task Center tabs keyboard focus / Enter / mouse / mobile
    timed('task_tabs', lambda: browser('task_tabs.py', 'baseline'))
    tt = load('pilot01-baseline.json') or {}
    check('task_tabs_keyboard_focus', tt.get('status') == 'PASS', cases=[(c['id'].split('.')[-1], c['status']) for c in tt.get('cases', [])])

    # ---- Pilot 02: attachments in both UIs
    timed('attachments', lambda: browser('attachments.py', 'verify'))
    timed('db_state', lambda: php_state('verify'))
    a = (load('p02-browser-verify.json') or {}).get('steps', {}); st = load('p02-state-verify.json') or {'messages': []}
    msgs = st['messages']
    def files_of(prefix):
        return [f['name'] for m in msgs if m['body'].startswith(prefix) for f in m['files']]
    def dl(step, name):
        d = a.get(step, {}).get('downloads', {}).get(name, {})
        return d.get('http') == 200 and d.get('sha_ok') is True
    check('compact_has_file_control', a.get('compact_controls', {}).get('file_inputs') == 1)
    check('compact_text_and_file', a.get('compact_text_and_file', {}).get('rendered_file') is True and dl('compact_text_and_file', 'p02-compact.txt'))
    check('compact_file_only', a.get('compact_file_only', {}).get('rendered_file') is True and dl('compact_file_only', 'p02-compact-only.txt'))
    check('full_text_only', a.get('full_text_only', {}).get('rendered') is True)
    check('full_text_and_file_with_filename_feedback', bool(a.get('full_text_and_file', {}).get('filename_feedback')) and dl('full_text_and_file', 'p02-text-and-file.txt'))
    check('full_file_only', a.get('full_file_only', {}).get('rendered_file') is True and dl('full_file_only', 'p02-file-only.txt'))
    check('server_file_only_multipart', a.get('server_file_only_direct', {}).get('http') in (302, 303))
    check('double_submit_creates_one_message', sum(1 for m in msgs if m['body'] == 'RC31 P02 double verify') == 1)
    csrf = a.get('csrf_invalid_sesskey', {})
    # Only meaningful when the forged request was actually sent and the DB state was read afterwards.
    check('csrf_invalid_sesskey_rejected', csrf.get('http') is not None and bool(msgs) and
          not any(m['body'].startswith('RC31 P02 csrf') for m in msgs), http=csrf.get('http'))
    check('no_orphan_files', 'orphan_files' in st and bool(msgs) and st.get('orphan_files') == 0)
    check('attachments_execution', 'execution_error' not in (load('p02-browser-verify.json') or {'execution_error': 'missing'}),
          error=(load('p02-browser-verify.json') or {}).get('execution_error'))

    # ---- protected download ACL
    def access(phase):
        timed('access_' + phase, lambda: browser('access.py', phase)); return load('p02-access-%s.json' % phase) or {}
    before = access('ea_before'); eb = access('eb'); anon = access('anon')
    timed('revoke', lambda: php_state('revoke_ea')); revoked = access('ea_revoked')
    timed('reopen', lambda: php_state('reopen_ea')); reopened = access('ea_reopened')
    n = len(before.get('status', {}))
    check('download_participant_allowed', n > 0 and before.get('served') == n, served=before.get('served'), files=n)
    check('download_other_company_denied', eb.get('served') == 0 and all(v['http'] in (403, 404) for v in eb.get('status', {}).values()) and n > 0)
    check('download_anonymous_denied', anon.get('served') == 0 and n > 0)
    check('download_revoked_denied', revoked.get('served') == 0 and n > 0)
    check('download_reopened_allowed', reopened.get('served') == n and n > 0)

    # ---- size limit: server restarted with upload_max_filesize=64K
    timed('server_restart_64k', lambda: start_server(['-d', 'upload_max_filesize=64K', '-d', 'post_max_size=8M']))
    timed('oversize', lambda: browser('attachments.py', 'oversize'))
    timed('db_state_oversize', lambda: php_state('oversize'))
    o = (load('p02-browser-oversize.json') or {}).get('steps', {}); so = load('p02-state-oversize.json') or {'messages': []}
    check('oversize_rejected_with_message', 'exceeds' in (o.get('compact_oversize', {}).get('error_text') or ''))
    check('oversize_nothing_stored', 'compact_oversize' in o and 'orphan_files' in so and
          not any('oversize' in m['body'] for m in so['messages']) and so.get('orphan_files') == 0)
except Exception as e:
    report['error'] = '%s: %s' % (type(e).__name__, str(e)[:300])
finally:
    stop_server()
expected = ['moodle_install', 'fixtures_companies_A_B', 'task_tabs_keyboard_focus', 'compact_has_file_control', 'compact_text_and_file',
            'compact_file_only', 'full_text_only', 'full_text_and_file_with_filename_feedback', 'full_file_only', 'server_file_only_multipart',
            'double_submit_creates_one_message', 'csrf_invalid_sesskey_rejected', 'no_orphan_files', 'attachments_execution',
            'download_participant_allowed', 'download_other_company_denied', 'download_anonymous_denied', 'download_revoked_denied',
            'download_reopened_allowed', 'oversize_rejected_with_message', 'oversize_nothing_stored']
for k in expected:
    report['checks'].setdefault(k, {'status': 'NOT RUN'})
report['status'] = 'PASS' if all(report['checks'][k]['status'] == 'PASS' for k in expected) else 'FAIL'
(root / 'browser-report.json').write_text(json.dumps(report, indent=1, ensure_ascii=False))
print('BROWSER', report['status'])
sys.exit(0 if report['status'] == 'PASS' else 1)
