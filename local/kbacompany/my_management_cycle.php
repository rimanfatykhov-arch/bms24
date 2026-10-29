<?php
// BMS24 personal management cycle: a safe, read-only projection of canonical work.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
company_context_service::context($uuid, $actor);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    throw new moodle_exception('invalidparameter');
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/my_management_cycle.php', ['uuid'=>$uuid]));
$PAGE->set_title(get_string('pmc_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'personalcycle', get_string('pmc_title', 'local_kbacompany'));

echo html_writer::tag('h2', get_string('pmc_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('pmc_intro', 'local_kbacompany'), ['class'=>'text-muted']);

// Canonical management cycles: only cycles where this Moodle user is the owner/curator.
// No access to company-wide cycle detail without separate management_cycle RBAC.
$records = $DB->get_records_select('local_kbacompany_mcycle',
    'companyuuid = :company AND (ownerid = :owner OR curatorid = :curator)',
    ['company'=>$uuid, 'owner'=>$actor, 'curator'=>$actor],
    'periodstart DESC, id DESC', 'id,uuid,title,status,periodstart,periodend,templatecode', 0, 20);
$canviewcompanycycles = \local_kbacompany\local\access_role_service::can($uuid, $actor, 'management_cycle', 'view');
echo html_writer::tag('h3', get_string('pmc_assigned', 'local_kbacompany'));
if (!$records) {
    echo html_writer::div(get_string('pmc_noassigned', 'local_kbacompany'), 'alert alert-info');
} else {
    echo html_writer::start_div('row');
    foreach ($records as $cycle) {
        $title = s($cycle->title);
        if ($canviewcompanycycles) {
            $detail = new moodle_url('/local/kbacompany/management_cycles.php', ['uuid'=>$uuid,'cycle'=>$cycle->uuid]);
            $title = html_writer::link($detail, $title);
        }
        $description = s($cycle->periodstart.' — '.$cycle->periodend).' · '.s(get_string('mcycle_status_'.$cycle->status,'local_kbacompany'));
        echo html_writer::tag('div', html_writer::div(html_writer::tag('h4', $title).html_writer::tag('p', $description), 'card p-3 mb-3'),
            ['class'=>'col-md-6']);
    }
    echo html_writer::end_div();
}

echo html_writer::tag('h3', get_string('pmc_steps', 'local_kbacompany'));
$calendaravailable = \local_kbacompany\local\employee_work_calendar_service::owner_available($uuid, $actor, $actor);
$steps = [
    ['pmc_plan', 'pmc_plan_desc', 'battle_plan.php', ['scope'=>'my'], true],
    ['pmc_organize', 'pmc_organize_desc', 'tasks.php', ['scope'=>'my'], true],
    ['pmc_lead', 'pmc_lead_desc', 'employee_work_calendar.php', ['userid'=>$actor], $calendaravailable],
    ['pmc_control', 'pmc_control_desc', 'tasks.php', ['scope'=>'my'], true],
    ['pmc_correct', 'pmc_correct_desc', 'battle_plan.php', ['scope'=>'my'], true],
];
echo html_writer::start_div('row');
foreach ($steps as $index=>$step) {
    $body = html_writer::tag('h4', ($index + 1).'. '.get_string($step[0], 'local_kbacompany'));
    $body .= html_writer::tag('p', get_string($step[1], 'local_kbacompany'), ['class'=>'text-muted']);
    if (!empty($step[4])) {
        $link = new moodle_url('/local/kbacompany/'.$step[2], ['uuid'=>$uuid]+$step[3]);
        $body .= html_writer::link($link, get_string('pmc_open', 'local_kbacompany'), ['class'=>'btn btn-outline-success']);
    } else {
        $body .= html_writer::div(get_string('pmc_calendar_unavailable', 'local_kbacompany'),
            'alert alert-warning mb-0');
    }
    echo html_writer::tag('div', html_writer::div($body, 'card p-3 mb-3 h-100'), ['class'=>'col-md-4 mb-3']);
}
echo html_writer::end_div();
echo html_writer::div(get_string('pmc_boundary', 'local_kbacompany'), 'alert alert-info');
bms_page_shell_service::finish($shell);
