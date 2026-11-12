<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedepartments', $context);
$companyid = required_param('companyid', PARAM_INT);
\local_kbacorporate\local\access::require_not_revoked($companyid, (int)$USER->id);
$delete = optional_param('delete', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
if ($delete && confirm_sesskey()) {
    \local_kbacorporate\local\repository::delete_department($delete, $companyid);
    redirect(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $companyid]), get_string('deleted', 'local_kbacorporate'));
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('departments', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$departments = \local_kbacorporate\local\departments::get_company_departments($companyid, false);
echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading(get_string('departments', 'local_kbacorporate') . ': ' . format_string($company->name));
echo html_writer::div(
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/department_edit.php', ['companyid' => $companyid]), get_string('adddepartment', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'), get_string('companies', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);
$table = new html_table();
$table->head = [get_string('departmentname', 'local_kbacorporate'), get_string('departmentcode', 'local_kbacorporate'), get_string('employees', 'local_kbacorporate'), get_string('departmentmanagers', 'local_kbacorporate'), get_string('active', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($departments as $department) {
    $employees = $DB->count_records('local_kbacorp_dept_member', ['departmentid' => $department->id, 'deptrole' => 'employee']);
    $managers = $DB->count_records('local_kbacorp_dept_member', ['departmentid' => $department->id, 'deptrole' => 'manager']);
    $actions = [];
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/department_edit.php', ['companyid' => $companyid, 'id' => $department->id]), get_string('edit'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/department_members.php', ['departmentid' => $department->id]), get_string('departmentmembers', 'local_kbacorporate'));
    $deleteurl = new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $companyid, 'delete' => $department->id, 'sesskey' => sesskey()]);
    $actions[] = html_writer::link($deleteurl, get_string('delete'), ['onclick' => "return confirm(" . json_encode(get_string('confirmdeletedepartment', 'local_kbacorporate')) . ");"]);
    $table->data[] = [format_string($department->name), s($department->code ?? ''), $employees, $managers, $department->active ? get_string('yes') : get_string('no'), implode(' · ', $actions)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
