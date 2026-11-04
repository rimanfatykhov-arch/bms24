<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\people_foundation_service;
use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = people_foundation_service::context($uuid, (int)$USER->id);
$summary = $data['summary'];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/people.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('people_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'people',
    get_string('people_title', 'local_kbacompany'));

echo bms_workspace_ux_service::people_nav($uuid, 'overview');

$actions = html_writer::link(new moodle_url('/local/kbacompany/employees.php', ['uuid' => $uuid]),
    get_string('people_open_employees', 'local_kbacompany'), ['class' => 'btn btn-primary btn-sm']);
$actions .= html_writer::link(new moodle_url('/local/kbacompany/positions.php', ['uuid' => $uuid]),
    get_string('people_open_positions', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary btn-sm']);

$actions .= html_writer::link(new moodle_url('/local/kbacompany/competency_catalogs.php', ['uuid' => $uuid]),
    get_string('companycatalog_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary btn-sm']);

echo bms_workspace_ux_service::record_header(
    get_string('people_title', 'local_kbacompany'),
    get_string('people_intro_0710', 'local_kbacompany'),
    get_string('people_architecture_eyebrow', 'local_kbacompany'),
    '', $actions
);

echo html_writer::start_div('kbacompany-summary-grid');
$cards = [
    ['employees', 'people_kpi_employees'],
    ['positions', 'people_kpi_positions'],
    ['published_profiles', 'people_kpi_profiles'],
    ['attention', 'people_kpi_attention'],
];
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$summary[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($summary['attention'] > 0) {
    echo html_writer::div(get_string('people_attention_note', 'local_kbacompany', (object)[
        'assessments' => $summary['assessments_waiting'], 'feedback360' => $summary['feedback360_open'],
    ]), 'alert alert-warning py-2');
} else {
    echo html_writer::div(get_string('people_attention_clear', 'local_kbacompany'), 'alert alert-success py-2');
}

$workspaces = [
    ['people_area_structure', 'people_area_structure_desc', 'positions.php', 'people_open_positions'],
    ['people_area_competency', 'people_area_competency_desc', 'employees.php', 'people_open_employees'],
    ['people_area_development', 'people_area_development_desc', 'learning.php', 'people_open_learning'],
    ['people_area_culture', 'people_area_culture_desc', 'culture.php', 'culture_title'],
    ['people_area_transfer', 'people_area_transfer_desc', 'function_transfers.php', 'functiontransfer_title'],
    ['people_area_feedback', 'people_area_feedback_desc', 'competency_360_inbox.php', 'competency360_inbox_title'],
];
echo html_writer::start_div('row mt-3');
foreach ($workspaces as $area) {
    echo html_writer::start_div('col-xl-4 col-md-6 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body d-flex flex-column');
    echo html_writer::tag('h2', get_string($area[0], 'local_kbacompany'), ['class' => 'h5']);
    echo html_writer::tag('p', get_string($area[1], 'local_kbacompany'), ['class' => 'text-muted flex-grow-1']);
    echo html_writer::link(new moodle_url('/local/kbacompany/' . $area[2], ['uuid' => $uuid]),
        get_string($area[3], 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary align-self-start']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('people_governance_title', 'local_kbacompany'), 2, 'h4 mt-4');
echo html_writer::div(get_string('people_security_note_0710', 'local_kbacompany'), 'alert alert-info py-2');

$table = new html_table();
$table->attributes['class'] = 'table table-sm table-hover';
$table->head = [get_string('people_governance_object', 'local_kbacompany'), get_string('people_governance_count', 'local_kbacompany')];
$table->data = [
    [get_string('people_frameworks', 'local_kbacompany'), $summary['frameworks']],
    [get_string('people_governance_competencies', 'local_kbacompany'), $summary['competencies']],
    [get_string('people_roledna', 'local_kbacompany'), $summary['role_dna']],
    [get_string('people_governance_devplans', 'local_kbacompany'), $summary['development_active']],
];
echo html_writer::table($table);

\local_kbacompany\local\bms_page_shell_service::finish($shell);
