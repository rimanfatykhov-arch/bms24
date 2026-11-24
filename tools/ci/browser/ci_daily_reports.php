<?php
// CI-only helper for daily_reports_flow.py on the disposable Moodle (synthetic data only). Phases:
//   setup <company> <employee> <reviewer>       -> enables daily reports; approved battle plan of the employee for the
//                                                   last 14 days with the given reviewer (reviewer resolution, ADR-0009 В1)
//   state <company> <employee> <day> [marker]   -> JSON: report status, versions, decisions, discussion messages and
//                                                   notifications (read-only);
//                                                   marker = text whose presence in each version snapshot is reported
//   touch_task <company> <taskuuid>             -> changes the synthetic task title (snapshot immutability probe)
//   shell <owned|legacy>                        -> switches the BMS24 shell mode of the disposable site
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
$phase = $argv[2] ?? '';
$company = (string)($argv[3] ?? '');
$out = ['phase' => $phase];
if ($phase === 'setup') {
    $employee = (int)($argv[4] ?? 0); $reviewer = (int)($argv[5] ?? 0);
    set_config('dailyreports_enabled', 1, 'local_kbacompany');
    $DB->delete_records('local_kbacompany_bplan', ['companyuuid' => $company, 'userid' => $employee, 'createdby' => get_admin()->id,
        'statepayload' => '{"ci":"daily_reports_flow"}']);
    $today = \local_kbacompany\local\business_date_service::day();
    $start = date('Y-m-d', strtotime($today . ' -14 days'));
    $DB->insert_record('local_kbacompany_bplan', (object)['companyuuid' => $company, 'uuid' => \core\uuid::generate(),
        'userid' => $employee, 'positionuuid' => '', 'periodstart' => $start, 'periodend' => $today, 'status' => 'approved',
        'reviewerid' => $reviewer, 'observerid' => 0, 'templateuuid' => '', 'templaterevision' => 0, 'normuuid' => '',
        'normrevision' => 0, 'statepayload' => '{"ci":"daily_reports_flow"}', 'createdby' => get_admin()->id, 'approvedby' => 0,
        'submittedat' => 0, 'approvedat' => 0, 'closedat' => 0, 'timecreated' => time(), 'timemodified' => time()]);
    // A task due today, so that today's report snapshot contains it (immutability probe).
    \core\session\manager::set_user(\core_user::get_user($reviewer, '*', MUST_EXIST));
    $title = 'CI daily report task ' . substr(sha1((string)microtime(true)), 0, 10);
    $task = \local_kbacompany\local\task_service::create($company, $reviewer, ['title' => $title, 'description' => '',
        'product' => 'Synthetic', 'assigneeid' => $employee, 'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal',
        'stream' => 'operate', 'startdate' => '', 'duedate' => $today, 'planminutes' => 0, 'parentuuid' => '']);
    $out += ['enabled' => (bool)get_config('local_kbacompany', 'dailyreports_enabled'), 'today' => $today,
        'task' => $task, 'task_title' => $title];
} else if ($phase === 'state') {
    $employee = (int)($argv[4] ?? 0); $day = (string)($argv[5] ?? '');
    $report = $DB->get_record('local_kbacompany_dreport', ['companyuuid' => $company, 'userid' => $employee, 'reportday' => $day]);
    $out['report'] = $report ? ['uuid' => (string)$report->uuid, 'status' => (string)$report->status] : null;
    $out['versions'] = []; $out['decisions'] = []; $out['discussion'] = [];
    if ($report) {
        foreach ($DB->get_records('local_kbacompany_dreportver', ['companyuuid' => $company, 'reportuuid' => $report->uuid],
                'versionno ASC') as $v) {
            $out['versions'][] = ['uuid' => (string)$v->uuid, 'no' => (int)$v->versionno, 'state' => (string)$v->state,
                'done' => (string)$v->done, 'reviewerid' => (int)$v->reviewerid,
                'snapshot_sha' => hash('sha256', (string)$v->autosnapshot), 'contenthash' => (string)$v->contenthash,
                'snapshot_has_marker' => ($argv[6] ?? '') !== '' && strpos((string)$v->autosnapshot, (string)$argv[6]) !== false];
        }
        foreach ($DB->get_records('local_kbacompany_dreportrev', ['companyuuid' => $company, 'reportuuid' => $report->uuid],
                'id ASC') as $r) {
            $out['decisions'][] = ['versionuuid' => (string)$r->versionuuid, 'decision' => (string)$r->decision,
                'rating' => (string)$r->rating, 'reviewerid' => (int)$r->reviewerid];
        }
        // R3: messages of the report discussion threads (one canonical thread expected), oldest first.
        foreach ($DB->get_records('local_kbacompany_commthread', ['companyuuid' => $company, 'contextkind' => 'dailyreport',
                'contextuuid' => $report->uuid], 'id ASC') as $t) {
            foreach ($DB->get_records('local_kbacompany_commmsg', ['companyuuid' => $company, 'threaduuid' => $t->uuid],
                    'id ASC') as $m) {
                $out['discussion'][] = ['thread' => (string)$t->uuid, 'authorid' => (int)$m->authorid, 'body' => (string)$m->body];
            }
        }
    }
    $out['notifications'] = [];
    foreach ($DB->get_records_select('local_kbacompany_commnotif', 'companyuuid = :c AND ' .
            $DB->sql_like('kind', ':k'), ['c' => $company, 'k' => 'dailyreport_%'], 'id ASC') as $n) {
        $out['notifications'][] = ['userid' => (int)$n->userid, 'kind' => (string)$n->kind, 'title' => (string)$n->title];
    }
} else if ($phase === 'touch_task') {
    $task = (string)($argv[4] ?? '');
    $DB->set_field('local_kbacompany_task', 'title', 'CI changed after submission ' . time(),
        ['companyuuid' => $company, 'uuid' => $task]);
    $out['touched'] = $task;
} else if ($phase === 'shell' && in_array($argv[3] ?? '', ['owned', 'legacy'], true)) {
    set_config('bms24_shell_mode', $argv[3], 'local_kbacompany');
    $out['mode'] = get_config('local_kbacompany', 'bms24_shell_mode');
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
