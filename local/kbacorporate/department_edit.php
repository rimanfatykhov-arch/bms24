<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedepartments', $context);
$companyid = required_param('companyid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
$department = $id ? $DB->get_record('local_kbacorp_department', ['id' => $id, 'companyid' => $companyid], '*', MUST_EXIST) : null;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/department_edit.php', ['companyid' => $companyid, 'id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('department', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$form = new \local_kbacorporate\form\department_form(null, ['companyid' => $companyid]);
if ($department) {
    $form->set_data($department);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $companyid]));
}
if ($data = $form->get_data()) {
    \local_kbacorporate\local\repository::save_department($data);
    redirect(new moodle_url('/local/kbacorporate/departments.php', ['companyid' => $companyid]), get_string('saved', 'local_kbacorporate'));
}
echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading($department ? get_string('editdepartment', 'local_kbacorporate') : get_string('adddepartment', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
