<?php
// CI-only helper for day_feed.py on the disposable Moodle (synthetic data only). Phases:
//   setup <company> <employee> <reviewer> <day> <token> -> enables daily reports; two tasks of the employee (reviewer =
//                                                    reviewer) submitted by the employee through task_service with
//                                                    synthetic results, submit events placed on <day>; a saved draft
//                                                    of <day> with text in "Done" (the "already filled draft" case)
//   today <company> <employee>                       -> the employee's civil day (feed default)
//   state <company> <employee> <day>                -> JSON: report status, draft text/revision/links, sent versions
//   shell <owned|legacy>                            -> switches the BMS24 shell mode of the disposable site
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\daily_report_store_service;
use local_kbacompany\local\task_service;

$phase = $argv[2] ?? '';
$company = (string)($argv[3] ?? '');
$out = ['phase' => $phase];
if ($phase === 'setup') {
    $employee = (int)($argv[4] ?? 0); $reviewer = (int)($argv[5] ?? 0);
    $day = (string)($argv[6] ?? ''); $token = preg_replace('/[^a-z0-9]/', '', (string)($argv[7] ?? ''));
    set_config('dailyreports_enabled', 1, 'local_kbacompany');
    $employeeuser = \core_user::get_user($employee, '*', MUST_EXIST);
    \core\session\manager::set_user($employeeuser);
    [$from] = daily_report_service::bounds($day);
    $out['today'] = business_date_service::day();
    $out['tasks'] = [];
    foreach ([1, 2] as $n) {
        \core\session\manager::set_user(\core_user::get_user($reviewer, '*', MUST_EXIST));
        $title = 'CI day feed ' . $token . ' task ' . $n;
        $task = task_service::create($company, $reviewer, ['title' => $title, 'description' => '', 'product' => 'Synthetic',
            'assigneeid' => $employee, 'reviewerid' => $reviewer, 'observerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '', 'duedate' => $day, 'planminutes' => 0, 'parentuuid' => '']);
        \core\session\manager::set_user($employeeuser);
        $result = 'CI day feed ' . $token . ' result ' . $n;
        task_service::transition($company, $employee, $task, 1, 'start');
        task_service::transition($company, $employee, $task, 2, 'submit', ['result' => $result, 'factminutes' => 15]);
        $log = $DB->get_record('local_kbacompany_tasklog', ['companyuuid' => $company, 'taskuuid' => $task, 'action' => 'submit'],
            '*', MUST_EXIST);
        // Explicit event clock fixture (the submit itself is the real workflow): 10:00 and 11:00 of <day>.
        $DB->set_field('local_kbacompany_tasklog', 'timecreated', $from + (9 + $n) * 3600, ['id' => $log->id]);
        $out['tasks'][] = ['task' => $task, 'event' => (int)$log->id, 'title' => $title, 'result' => $result];
    }
    $pre = 'CI day feed ' . $token . ' written by hand';
    daily_report_store_service::save_draft($company, $employee, $day,
        ['planned' => '', 'done' => $pre, 'notdone' => '', 'blockers' => '', 'nextplan' => ''], [], 0);
    $out += ['enabled' => (bool)get_config('local_kbacompany', 'dailyreports_enabled'), 'day' => $day, 'pre' => $pre];
} else if ($phase === 'today') {
    // The employee's civil day (the feed default), computed in the employee's timezone.
    \core\session\manager::set_user(\core_user::get_user((int)($argv[4] ?? 0), '*', MUST_EXIST));
    $out['today'] = business_date_service::day();
} else if ($phase === 'state') {
    $employee = (int)($argv[4] ?? 0); $day = (string)($argv[5] ?? '');
    $report = $DB->get_record('local_kbacompany_dreport', ['companyuuid' => $company, 'userid' => $employee, 'reportday' => $day]);
    $out['report'] = $report ? ['status' => (string)$report->status] : null;
    $out['draft'] = null; $out['sent'] = [];
    if ($report) {
        foreach ($DB->get_records('local_kbacompany_dreportver', ['companyuuid' => $company, 'reportuuid' => $report->uuid],
                'versionno ASC') as $v) {
            $links = array_values($DB->get_fieldset_select('local_kbacompany_dreportlink', 'taskuuid',
                'companyuuid = :c AND versionuuid = :v', ['c' => $company, 'v' => $v->uuid]));
            sort($links);
            $row = ['done' => (string)$v->done, 'revision' => (int)$v->revision, 'links' => $links];
            if ((string)$v->uuid === (string)$report->draftversionuuid && $v->state === 'draft') {
                $out['draft'] = $row;
            } else if ($v->state !== 'draft') {
                $out['sent'][] = $row;
            }
        }
    }
} else if ($phase === 'shell' && in_array($argv[3] ?? '', ['owned', 'legacy'], true)) {
    set_config('bms24_shell_mode', $argv[3], 'local_kbacompany');
    $out['mode'] = get_config('local_kbacompany', 'bms24_shell_mode');
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
