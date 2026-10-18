<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewdepartments', $context);
$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$departments = \local_kbacorporate\local\access::get_allowed_departments($companyid, $USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/departments_overview.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('departments', 'local_kbacorporate'));
$PAGE->set_heading(get_string('departments', 'local_kbacorporate'));
echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('departmentsoverviewdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
if (!$departments) {
    echo $OUTPUT->notification(get_string('nodepartments', 'local_kbacorporate'), 'info');
} else {
    echo html_writer::start_div('kbacorp-navgrid');
    foreach ($departments as $department) {
        $visible = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, (int)$department->id);
        $managers = \local_kbacorporate\local\departments::get_department_members((int)$department->id, 'manager');
        $managernames = [];
        foreach ($managers as $manager) {
            $managernames[] = fullname((object)['firstname' => $manager->firstname, 'lastname' => $manager->lastname]);
        }
        $content = html_writer::div((string)count($visible), 'kbacorp-navcard-value') .
            html_writer::div(format_string($department->name), 'kbacorp-navcard-title') .
            html_writer::div($managernames ? s(implode(', ', $managernames)) : get_string('nomanagerassigned', 'local_kbacorporate'), 'kbacorp-navcard-text') .
            html_writer::div(get_string('open', 'local_kbacorporate') . ' →', 'kbacorp-navcard-open');
        echo html_writer::link(new moodle_url('/local/kbacorporate/employees.php', ['companyid' => $companyid, 'departmentid' => $department->id]), $content, ['class' => 'kbacorp-navcard']);
    }
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
