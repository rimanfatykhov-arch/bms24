<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedepartments', $context);
$departmentid = required_param('departmentid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$department = \local_kbacorporate\local\departments::get_department($departmentid);
$company = $DB->get_record('local_kbacorp_company', ['id' => $department->companyid], '*', MUST_EXIST);
if ($delete && confirm_sesskey()) {
    \local_kbacorporate\local\repository::delete_department_member($delete, $departmentid);
    redirect(new moodle_url('/local/kbacorporate/department_members.php', ['departmentid' => $departmentid]), get_string('deleted', 'local_kbacorporate'));
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/department_members.php', ['departmentid' => $departmentid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('departmentmembers', 'local_kbacorporate'));
$PAGE->set_heading(format_string($department->name));
$form = new \local_kbacorporate\form\department_member_form(null, ['departmentid' => $departmentid]);
if ($data = $form->get_data()) {
    \local_kbacorporate\local\repository::add_department_member($departmentid, (int)$data->userid, $data->deptrole);
    redirect(new moodle_url('/local/kbacorporate/department_members.php', ['departmentid' => $departmentid]), get_string('saved', 'local_kbacorporate'));
}
$members = \local_kbacorporate\local\departments::get_department_members($departmentid);
echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation((int)$company->id);
echo $OUTPUT->heading(format_string($company->name) . ' → ' . format_string($department->name));
$form->display();
$table = new html_table();
$table->head = [get_string('user', 'local_kbacorporate'), get_string('email', 'local_kbacorporate'), get_string('departmentrole', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($members as $member) {
    $u = (object)['firstname' => $member->firstname, 'lastname' => $member->lastname];
    $deleteurl = new moodle_url('/local/kbacorporate/department_members.php', ['departmentid' => $departmentid, 'delete' => $member->assignmentid, 'sesskey' => sesskey()]);
    $table->data[] = [fullname($u), s($member->email), get_string('department' . $member->deptrole, 'local_kbacorporate'), html_writer::link($deleteurl, get_string('delete'), ['onclick' => "return confirm(" . json_encode(get_string('confirmdeletedepartmentmember', 'local_kbacorporate')) . ");"])];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $company->id]), get_string('departments', 'local_kbacorporate'), 'get');
echo $OUTPUT->footer();
