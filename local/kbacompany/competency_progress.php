<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\competency_progress_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$userid = required_param('userid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'reassess') {
    require_sesskey();
    $assessmentuuid = competency_progress_service::create_reassessment($uuid, $userid, (int)$USER->id);
    redirect(new moodle_url('/local/kbacompany/competency_assessment.php', [
        'uuid' => $uuid, 'userid' => $userid, 'assessment' => $assessmentuuid,
    ]), get_string('competencyprogress_reassessment_created', 'local_kbacompany'));
}

$data = competency_progress_service::context($uuid, $userid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/competency_progress.php', ['uuid' => $uuid, 'userid' => $userid]));
$PAGE->set_title(get_string('competencyprogress_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees',
    get_string('competencyprogress_title', 'local_kbacompany'));

$employeeurl = new moodle_url('/local/kbacompany/employee_view.php', ['uuid' => $uuid, 'userid' => $userid]);
$actions = '';
if ($data['can_manage'] && $data['position_profile']) {
    $actions = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
    foreach (['uuid' => $uuid, 'userid' => $userid, 'action' => 'reassess', 'sesskey' => sesskey()] as $name => $value) {
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    $actions .= html_writer::tag('button', get_string('competencyprogress_create_reassessment', 'local_kbacompany'), [
        'type' => 'submit', 'class' => 'btn btn-primary'
    ]);
    $actions .= html_writer::end_tag('form');
}
$meta = $data['position'] ? s($data['position']['name']) : get_string('employeecompetency_no_position', 'local_kbacompany');
echo bms_workspace_ux_service::record_header(
    s($data['employee']['full_name']),
    get_string('competencyprogress_intro', 'local_kbacompany'),
    get_string('competencyprogress_eyebrow', 'local_kbacompany'),
    $meta,
    $actions
);

$tabs = [
    ['label' => get_string('bms_employee_view', 'local_kbacompany'), 'url' => $employeeurl],
    ['label' => get_string('employeecompetency_title', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/employee_competencies.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('assessment_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/competency_assessment.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('competency360_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/competency_360.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('developmentplan_open', 'local_kbacompany'), 'url' => new moodle_url('/local/kbacompany/development_plan.php', ['uuid'=>$uuid,'userid'=>$userid])],
    ['label' => get_string('competencyprogress_tab', 'local_kbacompany'), 'url' => $PAGE->url, 'active' => true],
];
$tabhtml = '';
foreach ($tabs as $tab) {
    $class = 'bms-workspace-subnav-link' . (!empty($tab['active']) ? ' is-active' : '');
    $tabhtml .= html_writer::link($tab['url'], $tab['label'], ['class' => $class]);
}
echo html_writer::tag('nav', $tabhtml, ['class' => 'bms-workspace-subnav bms-employee-tabs', 'aria-label' => get_string('competencyprogress_tabs', 'local_kbacompany')]);

if ($data['recommendation']['recommended']) {
    $reasonlabels = [];
    foreach ($data['recommendation']['reasons'] as $reason) {
        $reasonlabels[] = get_string('competencyprogress_reason_' . $reason, 'local_kbacompany');
    }
    echo html_writer::div(
        html_writer::tag('strong', get_string('competencyprogress_recommended', 'local_kbacompany')) . ' ' . s(implode(' ', $reasonlabels)),
        'alert alert-warning bms-progress-recommendation'
    );
} else if (!$data['latest_assessment']) {
    echo html_writer::div(get_string('competencyprogress_no_baseline', 'local_kbacompany'), 'alert alert-info');
}

$cards = [
    [get_string('competencyprogress_approved_assessments', 'local_kbacompany'), $data['approved_assessment_count']],
    [get_string('competencyprogress_improved', 'local_kbacompany'), $data['summary']['improved']],
    [get_string('competencyprogress_stable', 'local_kbacompany'), $data['summary']['stable']],
    [get_string('competencyprogress_declined', 'local_kbacompany'), $data['summary']['declined']],
];
echo html_writer::start_div('row bms-progress-kpis mb-3');
foreach ($cards as $card) {
    echo html_writer::start_div('col-md-3 mb-2');
    echo html_writer::div(html_writer::div(s($card[0]), 'text-muted small') . html_writer::div((string)$card[1], 'h3 mb-0'), 'card card-body h-100');
    echo html_writer::end_div();
}
echo html_writer::end_div();

$table = new html_table();
$table->attributes['class'] = 'table table-sm bms-progress-table';
$table->head = [
    get_string('name'),
    get_string('positioncompetency_target_level', 'local_kbacompany'),
    get_string('competencyprogress_first_level', 'local_kbacompany'),
    get_string('competencyprogress_last_level', 'local_kbacompany'),
    get_string('competencyprogress_delta', 'local_kbacompany'),
    get_string('competencyprogress_trend', 'local_kbacompany'),
];
foreach ($data['rows'] as $row) {
    $trend = get_string('competencyprogress_trend_' . $row['trend'], 'local_kbacompany');
    $badgeclass = 'badge badge-secondary';
    if ($row['trend'] === 'improved') { $badgeclass = 'badge badge-success'; }
    if ($row['trend'] === 'declined') { $badgeclass = 'badge badge-danger'; }
    if ($row['trend'] === 'stable') { $badgeclass = 'badge badge-info'; }
    $delta = $row['delta'] === null ? '—' : (($row['delta'] > 0 ? '+' : '') . (string)$row['delta']);
    $table->data[] = [
        format_string($row['name']),
        $row['target_level'] === null ? '—' : (int)$row['target_level'],
        $row['first_level'] === null ? '—' : (int)$row['first_level'],
        $row['last_level'] === null ? '—' : (int)$row['last_level'],
        $delta,
        html_writer::span($trend, $badgeclass),
    ];
}
echo html_writer::div(html_writer::table($table), 'table-responsive card bms-progress-table-wrap');
echo html_writer::div(get_string('competencyprogress_boundary_notice', 'local_kbacompany'), 'alert alert-light border small mt-3');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
