<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\business_role_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$rolecontext = business_role_service::business_roles_context($uuid, (int)$USER->id);
$company = $rolecontext['company'];
$companyid = (int)$company['compatibility']['source_id'];
$canmanageroles = class_exists('\\local_kbacorporate\\local\\access') &&
    has_capability('local/kbacorporate:managerolecompetencies', $context) &&
    \local_kbacorporate\local\access::has_full_company_access($companyid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/business_roles.php', ['uuid' => $company['uuid']]));
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('businessrolestitle', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'roles', get_string('businessrolestitle', 'local_kbacompany'));
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $company['uuid']]),
    '← ' . get_string('backtocompany', 'local_kbacompany')
), 'mb-3');
echo $OUTPUT->heading(get_string('businessrolestitlefor', 'local_kbacompany', format_string($company['name'])));
echo html_writer::tag('p', get_string('businessrolesintro', 'local_kbacompany'), ['class' => 'lead']);

if (!$rolecontext['available']) {
    echo $OUTPUT->notification(get_string('businessrolesourceunavailable', 'local_kbacompany'), 'warning');
    \local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
}

$summarylabels = [
    'roles' => get_string('businessroles', 'local_kbacompany'),
    'assigned_employees' => get_string('employeeswithroles', 'local_kbacompany'),
    'without_role' => get_string('employeeswithoutroles', 'local_kbacompany'),
    'competency_requirements' => get_string('competencyrequirements', 'local_kbacompany'),
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($summarylabels as $key => $label) {
    echo html_writer::div(
        html_writer::div((string)(int)$rolecontext['summary'][$key], 'kbacompany-summary-value') .
        html_writer::div($label, 'kbacompany-summary-label'),
        'kbacompany-summary-card'
    );
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('businessroles', 'local_kbacompany'), 3);
$roletable = new html_table();
$roletable->head = [
    get_string('businessrole', 'local_kbacompany'),
    get_string('businessrolecode', 'local_kbacompany'),
    get_string('businessrolescope', 'local_kbacompany'),
    get_string('employees', 'local_kbacompany'),
    get_string('competencyrequirements', 'local_kbacompany'),
];
foreach ($rolecontext['roles'] as $role) {
    $roletable->data[] = [
        format_string($role['name']),
        s($role['code']),
        get_string($role['scope'] === 'global' ? 'rolescopeglobal' : 'rolescopecompany',
            'local_kbacompany'),
        (string)$role['employee_count'],
        (string)$role['competency_count'],
    ];
}
echo $roletable->data ? html_writer::table($roletable) :
    $OUTPUT->notification(get_string('nobusinessroles', 'local_kbacompany'), 'info');

echo $OUTPUT->heading(get_string('rolecompetencyprofiles', 'local_kbacompany'), 3);
$hasrequirements = false;
foreach ($rolecontext['roles'] as $role) {
    if (!$role['competency_requirements']) {
        continue;
    }
    $hasrequirements = true;
    echo html_writer::tag('h4', format_string($role['name']), ['class' => 'h5 mt-3']);
    $items = [];
    foreach ($role['competency_requirements'] as $requirement) {
        $targetlabel = $requirement['target_level'] !== null ?
            get_string('targetlevelvalue', 'local_kbacompany', (int)$requirement['target_level']) :
            get_string('targetlevelunknown', 'local_kbacompany');
        $label = ($requirement['code'] !== '' ? s($requirement['code']) . ' — ' : '') .
            format_string($requirement['name']) . ': ' . $targetlabel;
        $items[] = html_writer::tag('li', $label);
    }
    echo html_writer::tag('ul', implode('', $items), ['class' => 'kbacompany-competency-list']);
}
if (!$hasrequirements) {
    echo $OUTPUT->notification(get_string('nocompetencyrequirements', 'local_kbacompany'), 'info');
}

echo $OUTPUT->heading(get_string('employeeroleassignments', 'local_kbacompany'), 3);
$assignmenttable = new html_table();
$assignmenttable->head = [
    get_string('employee', 'local_kbacompany'),
    get_string('businessroles', 'local_kbacompany'),
];
if ($canmanageroles) {
    $assignmenttable->head[] = get_string('actions', 'moodle');
}
foreach ($rolecontext['employees'] as $employee) {
    $roles = array_map(static function(array $role): string {
        return format_string($role['name']);
    }, $employee['roles']);
    $row = [
        s($employee['full_name']),
        $roles ? implode(', ', $roles) :
            html_writer::span(get_string('noroleassigned', 'local_kbacompany'), 'text-muted'),
    ];
    if ($canmanageroles) {
        $assignmenturl = new moodle_url('/local/kbacorporate/business_role_assignments.php', [
            'companyid' => $companyid,
            'userid' => (int)$employee['user_id'],
        ]);
        $row[] = html_writer::link($assignmenturl,
            get_string($roles ? 'changeroles' : 'assignrole', 'local_kbacompany'), [
                'class' => $roles ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-primary',
            ]);
    }
    $assignmenttable->data[] = $row;
}
echo $assignmenttable->data ? html_writer::table($assignmenttable) :
    $OUTPUT->notification(get_string('noemployees', 'local_kbacompany'), 'info');

echo $OUTPUT->notification(get_string('businessrolesreadonly', 'local_kbacompany'), 'info');
\local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'role', 'Роли');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
