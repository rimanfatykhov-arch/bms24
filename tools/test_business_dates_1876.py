#!/usr/bin/env python3
"""Isolated PHP regression for locale-independent BMS dates and day-key callers."""
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'local/kbacompany'
SERVICE=PLUGIN/'classes/local/business_date_service.php'
TASK=PLUGIN/'classes/local/task_service.php'

PHP=r'''<?php
define('MOODLE_INTERNAL', true);
class core_date {
    public static $timezone = 'UTC';
    public static function get_user_timezone_object() { return new DateTimeZone(self::$timezone); }
}
function userdate() { throw new Exception('Display-only userdate() called for ISO business date'); }
require 'BUSINESS_SERVICE';
require 'TASK_SERVICE';
use local_kbacompany\local\business_date_service as D;
use local_kbacompany\local\task_service as T;
function assertDate($actual, $expected, $name) {
    if ($actual !== $expected) { throw new Exception("$name: $actual != $expected"); }
}
function at($utc) { return (new DateTimeImmutable($utc))->getTimestamp(); }
core_date::$timezone='Europe/Helsinki';
assertDate(D::day(at('2026-11-01T23:30:00+00:00')), '2026-11-02', 'Helsinki rollover');
assertDate(D::day(at('2026-11-01T00:15:00+00:00')), '2026-11-01', 'Helsinki Sunday');
core_date::$timezone='America/Los_Angeles';
assertDate(D::day(at('2026-11-01T23:30:00+00:00')), '2026-11-01', 'LA Sunday');
core_date::$timezone='Asia/Kolkata';
assertDate(D::day(at('2026-11-01T23:30:00+00:00')), '2026-11-02', 'Kolkata rollover');
core_date::$timezone='Europe/Helsinki';
assertDate(D::day(at('2026-12-31T23:30:00+00:00')), '2027-01-01', 'new year');
assertDate(D::yesterday(at('2026-03-29T21:30:00+00:00')), '2026-03-29', 'DST yesterday');
assertDate(D::yesterday(at('2027-01-01T07:00:00+00:00')), '2026-12-31', 'year yesterday');
core_date::$timezone='America/Los_Angeles';
assertDate(D::datetime_local(at('2026-11-01T23:30:00+00:00')), '2026-11-01T15:30', 'input datetime');
assertDate(D::datetime_local(at('2026-11-01T23:30:00+00:00'), true), '2026-11-01T15:00', 'input hour');
core_date::$timezone='Europe/Helsinki';
$dates=T::reporting_dates(at('2026-11-01T23:30:00+00:00'));
assertDate($dates['today'], '2026-11-02', 'task reporting today');
assertDate($dates['until'], '2026-11-08', 'task seven-day window');
$dates=T::reporting_dates(at('2026-12-31T23:30:00+00:00'));
assertDate($dates['until'], '2027-01-07', 'task new-year window');
core_date::$timezone='UTC';
assertDate(D::day(at('2026-11-01T23:30:00+00:00')), '2026-11-01', 'UTC no shift');
assertDate(D::datetime_local(at('2026-11-01T23:30:00+00:00')), '2026-11-01T23:30', 'UTC datetime');
echo 'BMS machine-date PHP tests: 14/14 PASS' . PHP_EOL;
'''

with tempfile.TemporaryDirectory() as d:
    f=Path(d)/'regression.php'
    src=PHP.replace('BUSINESS_SERVICE', SERVICE.as_posix()).replace('TASK_SERVICE', TASK.as_posix())
    f.write_text(src,encoding='utf-8')
    p=subprocess.run(['php','-d','zend.assertions=1',str(f)],capture_output=True,text=True)
    if p.returncode:
        print(p.stdout,p.stderr,file=sys.stderr)
        sys.exit(p.returncode)
    print(p.stdout.strip())

# Keep system dates out of locale-facing Moodle userdate() formatters permanently.
files_scanned=0
for f in PLUGIN.rglob('*.php'):
    if 'tests' in f.parts: continue
    text=f.read_text(encoding='utf-8')
    for format_string in ('%Y-%m-%d', '%Y-%m-%dT%H:%M', '%Y-%m-%dT%H:00'):
        if re.search(r"userdate\([^\n]*['\"]"+re.escape(format_string)+r"['\"]\s*\)",text):
            raise SystemExit(f'Locale-dependent machine-date format reintroduced: {f.relative_to(PLUGIN)}')
    files_scanned+=1
requirements={
    'classes/local/task_service.php':'business_date_service::day',
    'classes/local/calendar_workspace_service.php':'business_date_service::day',
    'classes/local/communication_center_ui_service.php':'business_date_service::yesterday',
    'templates/battle_plan_indicators.php':'business_date_service::day',
    'management_cycles.php':'business_date_service::day',
    'classes/local/deviation_service.php':'business_date_service::day',
    'meetings.php':'business_date_service::datetime_local',
}
for f,token in requirements.items():
    if token not in (PLUGIN/f).read_text(encoding='utf-8'):
        raise SystemExit('Required business-date caller missing: '+f)
print(f'Static date guard: {files_scanned} PHP files, {len(requirements)} critical callers PASS')
