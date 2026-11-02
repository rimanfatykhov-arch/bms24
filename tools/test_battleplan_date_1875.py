#!/usr/bin/env python3
"""PHP CLI smoke tests for Gregorian, user-timezone-safe Battle Plan weeks."""
from pathlib import Path
import subprocess, sys, tempfile, json
ROOT=Path(__file__).resolve().parents[1]
SERVICE=ROOT/'local/kbacompany/classes/local/battle_plan_service.php'
PL=ROOT/'local/kbacompany'
assert SERVICE.is_file()
php=r'''<?php
define('MOODLE_INTERNAL', true);
class core_date {
    public static $testtimezone = 'UTC';
    public static function get_user_timezone_object() { return new DateTimeZone(self::$testtimezone); }
}
class moodle_exception extends Exception {
    public function __construct($key, $component = '') { parent::__construct($key); }
}
require __DIR__ . '/SERVICE_REPLACE';
use local_kbacompany\local\battle_plan_service as BP;
function check($tz, $stamp, $expectedday, $expectedmonday) {
    core_date::$testtimezone = $tz;
    $date = BP::user_today(strtotime($stamp));
    if ($date !== $expectedday) throw new Exception("date $tz: $date != $expectedday");
    $monday = BP::week_start($date);
    if ($monday !== $expectedmonday) throw new Exception("monday $tz: $monday != $expectedmonday");
}
check('Europe/Helsinki', '2026-11-01T23:30:00Z', '2026-11-02', '2026-11-02');
check('America/Los_Angeles', '2026-11-01T23:30:00Z', '2026-11-01', '2026-10-26');
check('UTC', '2026-11-01T23:30:00Z', '2026-11-01', '2026-10-26');
check('Asia/Tokyo', '2026-11-01T23:30:00Z', '2026-11-02', '2026-11-02');
check('Pacific/Kiritimati', '2026-12-31T11:30:00Z', '2027-01-01', '2026-12-28');
check('America/New_York', '2026-11-01T05:30:00Z', '2026-11-01', '2026-10-26');
check('America/New_York', '2026-11-01T07:30:00Z', '2026-11-01', '2026-10-26');
check('Europe/Helsinki', '2026-11-02T00:00:00Z', '2026-11-02', '2026-11-02');
foreach(['02.11.2026','2026-11-02 00:00','2026-02-30','2026/11/02'] as $bad) {
    $thrown = false;
    try { BP::week_start($bad); } catch (moodle_exception $ex) { $thrown = $ex->getMessage() === 'battleplan_date'; }
    if (!$thrown) throw new Exception('invalid format accepted: ' . $bad);
}
echo "BMS date smoke: 12/12 PASS\n";
'''
# Fake bootstrap kept next to service at runtime so PHP require can resolve, no Moodle needed.
with tempfile.TemporaryDirectory() as d:
    d=Path(d)
    script=d/'test.php'
    script.write_text(php.replace("__DIR__ . '/SERVICE_REPLACE'", repr(SERVICE.as_posix())),encoding='utf-8')
    # Python repr uses single-quoted PHP strings for normal filesystem paths.
    p=subprocess.run(['php',str(script)],capture_output=True,text=True)
    if p.returncode:
        print(p.stdout,p.stderr,file=sys.stderr); sys.exit(p.returncode)
    print(p.stdout.strip())
checks={
 'bms_workspace.php':3,
 'tasks.php':2,
 'implementation_project_stages.php':1,
 'classes/local/implementation_project_weekly_service.php':1,
}
for path,expected in checks.items():
    content=(PL/path).read_text(encoding='utf-8')
    if content.count('battle_plan_service::user_today()')!=expected:
        raise SystemExit(f'Bad callsites {path}')
    if "userdate(time(), '%Y-%m-%d')" in content:
        raise SystemExit(f'Unpatched locale-formatted date {path}')
print('Caller contract: 4/4 PASS')
