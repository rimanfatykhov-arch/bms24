<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\organization_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$organization = organization_service::organization_context($uuid, (int)$USER->id);
$company = $organization['company'];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/organization.php', ['uuid' => $company['uuid']]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('organizationtitle', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', get_string('organizationtitle', 'local_kbacompany'));
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $company['uuid']]),
    '← ' . get_string('backtocompany', 'local_kbacompany')
), 'mb-3');
echo local_kbacompany_organization_tabs($uuid, 'people',
    \local_kbacompany\local\product_service::can_manage($uuid, (int)$USER->id));
echo $OUTPUT->heading(get_string('organizationtitlefor', 'local_kbacompany', format_string($company['name'])));
echo html_writer::tag('p', get_string('organizationintro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacompany/business_roles.php', ['uuid' => $company['uuid']]),
    get_string('openbusinessroles', 'local_kbacompany'),
    ['class' => 'btn btn-secondary']
), 'mb-3');

$summarylabels = [
    'org_units' => get_string('orgunits', 'local_kbacompany'),
    'positions' => get_string('positions', 'local_kbacompany'),
    'employees' => get_string('employees', 'local_kbacompany'),
    'without_org_unit' => get_string('withoutorgunit', 'local_kbacompany'),
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($summarylabels as $key => $label) {
    $value = (int)$organization['summary'][$key];
    echo html_writer::div(
        html_writer::div((string)$value, 'kbacompany-summary-value') .
        html_writer::div($label, 'kbacompany-summary-label'),
        'kbacompany-summary-card'
    );
}
echo html_writer::end_div();

if (empty($organization['source_status']['departments_available'])) {
    echo $OUTPUT->notification(get_string('orgunitsourceunavailable', 'local_kbacompany'), 'warning');
}
if (empty($organization['source_status']['position_profile_field'])) {
    echo $OUTPUT->notification(get_string('positionfieldunavailable', 'local_kbacompany'), 'info');
}

echo $OUTPUT->heading(get_string('orgunits', 'local_kbacompany'), 3);
$unittable = new html_table();
$unittable->head = [
    get_string('orgunitname', 'local_kbacompany'),
    get_string('orgunitcode', 'local_kbacompany'),
    get_string('employees', 'local_kbacompany'),
    get_string('managers', 'local_kbacompany'),
];
foreach ($organization['org_units'] as $unit) {
    $unittable->data[] = [
        format_string($unit['name']),
        s($unit['code'] !== '' ? $unit['code'] : '—'),
        (string)$unit['employee_count'],
        (string)$unit['manager_count'],
    ];
}
echo $unittable->data ? html_writer::table($unittable) :
    $OUTPUT->notification(get_string('noorgunits', 'local_kbacompany'), 'info');

echo $OUTPUT->heading(get_string('positions', 'local_kbacompany'), 3);
$positiontable = new html_table();
$positiontable->head = [get_string('positionname', 'local_kbacompany'), get_string('employees', 'local_kbacompany')];
foreach ($organization['positions'] as $position) {
    $positiontable->data[] = [format_string($position['name']), (string)$position['employees']];
}
echo $positiontable->data ? html_writer::table($positiontable) :
    $OUTPUT->notification(get_string('nopositions', 'local_kbacompany'), 'info');

$unitnames = [];
foreach ($organization['org_units'] as $unit) {
    $unitnames[$unit['uuid']] = $unit['name'];
}
echo $OUTPUT->heading(get_string('employeeassignments', 'local_kbacompany'), 3);
$employeetable = new html_table();
$employeetable->head = [
    get_string('employee', 'local_kbacompany'),
    get_string('positionname', 'local_kbacompany'),
    get_string('orgunits', 'local_kbacompany'),
];
foreach ($organization['employees'] as $employee) {
    $units = [];
    foreach ($employee['org_assignments'] as $assignment) {
        $name = $unitnames[$assignment['org_unit_uuid']] ?? get_string('unknownorgunit', 'local_kbacompany');
        if ($assignment['role'] === 'manager') {
            $name .= ' — ' . get_string('manager', 'local_kbacompany');
        }
        $units[] = format_string($name);
    }
    $employeetable->data[] = [
        s($employee['full_name']),
        $employee['position'] !== null ? format_string($employee['position']) :
            html_writer::span(get_string('positionunknown', 'local_kbacompany'), 'text-muted'),
        $units ? implode(html_writer::empty_tag('br'), $units) :
            html_writer::span(get_string('withoutorgunit', 'local_kbacompany'), 'text-muted'),
    ];
}
echo $employeetable->data ? html_writer::table($employeetable) :
    $OUTPUT->notification(get_string('noemployees', 'local_kbacompany'), 'info');

echo $OUTPUT->notification(get_string('organizationreadonly', 'local_kbacompany'), 'info');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
