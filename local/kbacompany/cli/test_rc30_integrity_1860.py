#!/usr/bin/env python3
"""Portable SQLite logic fixture for RC30 integrity SQL (not a MySQL acceptance test).

Run from Moodle root: python3 local/kbacompany/cli/test_rc30_integrity_1860.py
Requires php CLI and Python 3 stdlib; never connects to the production database.
"""
import json
import re
import shutil
import sqlite3
import subprocess
import sys
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
if not PHP:
    print('FAIL: php command not found')
    sys.exit(2)
php_source = r'''<?php
    define('MOODLE_INTERNAL', true);
    require $argv[1];
    echo json_encode(\local_kbacompany\local\task_rc30_integrity_service::checks($argv[2]), JSON_THROW_ON_ERROR);
'''
# php -r reads source WITHOUT <?php
proc = subprocess.run([PHP, '-r', php_source.split('<?php', 1)[1],
                       str(PLUGIN / 'classes/local/task_rc30_integrity_service.php'),
                       '11111111-1111-4111-8111-111111111111'],
                      check=True, capture_output=True, text=True)
checks = json.loads(proc.stdout)
assert len(checks) == 18
companya = '11111111-1111-4111-8111-111111111111'
companyb = '22222222-2222-4222-8222-222222222222'
con = sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE local_kbacompany_task (id INTEGER, companyuuid TEXT, uuid TEXT, parentuuid TEXT, status TEXT, revision INTEGER, product TEXT, startdate TEXT, duedate TEXT, planminutes INTEGER, factminutes INTEGER);
CREATE TABLE local_kbacompany_taskcheck (id INTEGER, companyuuid TEXT, taskuuid TEXT);
CREATE TABLE local_kbacompany_tasklog (id INTEGER, companyuuid TEXT, taskuuid TEXT);
CREATE TABLE local_kbacompany_bplan (id INTEGER, companyuuid TEXT, uuid TEXT);
CREATE TABLE local_kbacompany_bplanitem (id INTEGER, companyuuid TEXT, uuid TEXT, planuuid TEXT, taskuuid TEXT, provider TEXT, externalid TEXT);
CREATE TABLE local_kbacompany_aclrole (id INTEGER, companyuuid TEXT);
CREATE TABLE local_kbacompany_aclgrant (id INTEGER, companyuuid TEXT, roleid INTEGER);
CREATE TABLE local_kbacompany_aclassign (id INTEGER, companyuuid TEXT, roleid INTEGER, userid INTEGER);
''')
insert_task = '''INSERT INTO local_kbacompany_task
    (id,companyuuid,uuid,parentuuid,status,revision,product,startdate,duedate,planminutes,factminutes)
    VALUES(?,?,?,?,?,?,?,?,?,?,?)'''
rows = [
    (1,companya,'task-one','','open',1,'Result','','',30,20),
    (2,companya,'task-one','','open',1,'Result','','',0,0),
    (3,companya,'task-self','task-self','open',1,'Result','','',0,0),
    (4,companya,'task-negative','','open',1,'Result','','',-1,1),
    (5,companya,'task-reversed','','open',1,'Result','2026-11-10','2026-10-10',0,0),
    (6,companyb,'task-one','','open',1,'Result','','',0,0),
]
con.executemany(insert_task, rows)
con.execute('INSERT INTO local_kbacompany_bplan VALUES (1,?,?)',(companya,'plan-a'))
con.execute('INSERT INTO local_kbacompany_bplan VALUES (2,?,?)',(companyb,'plan-b'))
con.executemany('INSERT INTO local_kbacompany_bplanitem VALUES (?,?,?,?,?,?,?)', [
    (1,companya,'item-1','plan-a','task-one','bms','task-one'),
    (2,companya,'item-2','plan-a','task-one','bms','task-one'),
    (3,companya,'item-3','plan-a','task-self','bms','task-negative'),
    (4,companyb,'item-4','plan-b','task-one','bms','task-one'),
])
con.execute('INSERT INTO local_kbacompany_aclrole VALUES (1,?)',(companya,))
con.execute('INSERT INTO local_kbacompany_aclrole VALUES (2,?)',(companyb,))
con.executemany('INSERT INTO local_kbacompany_aclassign VALUES (?,?,?,?)', [
    (1,companya,1,25),(2,companya,1,25),(3,companyb,2,25),
])
expected_a = {
 'tasks_total':5, 'duplicate_task_uuid':1, 'self_parent_task':1,
 'negative_task_minutes':1, 'reversed_task_dates':1,
 'duplicate_bms_plan_items':1, 'mismatched_bms_identifiers':1,
 'duplicate_role_assignments':1,
}
for name, data in checks.items():
    assert data['params'] == {'companyuuid': companya}, name
    sql = re.sub(r'\{([^{}]+)\}', r'\1', data['sql'])
    count = con.execute(sql, data['params']).fetchone()[0]
    target = expected_a.get(name, 0)
    if count != target:
        raise AssertionError(f'{name}: expected {target}, actual {count}')
# Two companies: anomalies in A must not contaminate scans for B.
proc_b = subprocess.run([PHP, '-r', php_source.split('<?php', 1)[1],
                         str(PLUGIN / 'classes/local/task_rc30_integrity_service.php'), companyb],
                        check=True, capture_output=True, text=True)
for name, data in json.loads(proc_b.stdout).items():
    sql = re.sub(r'\{([^{}]+)\}', r'\1', data['sql'])
    count = con.execute(sql, data['params']).fetchone()[0]
    target = 1 if name == 'tasks_total' else 0
    if count != target:
        raise AssertionError(f'Company B leak: {name}, actual {count}')
print('PASS 18/18 scoped SQL checks x 2 companies; no data changes')
