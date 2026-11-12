<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managemembers', $context);
$companyid = required_param('companyid', PARAM_INT);
\local_kbacorporate\local\access::require_not_revoked($companyid, (int)$USER->id);
$delete = optional_param('delete', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);

if ($delete && confirm_sesskey()) {
    \local_kbacorporate\local\repository::delete_member($delete, $companyid);
    redirect(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid]), get_string('deleted', 'local_kbacorporate'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('members', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\member_form(null, ['companyid' => $companyid]);
if ($data = $form->get_data()) {
    \local_kbacorporate\local\repository::add_member($companyid, (int)$data->userid, $data->membertype);
    redirect(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid]), get_string('saved', 'local_kbacorporate'));
}

$members = $DB->get_records_sql(
    "SELECT m.id, m.membertype, m.isemployee, m.ismanager, m.isdepartmentmanager, m.ishr, m.timecreated, u.id AS userid, u.firstname, u.lastname, u.email
       FROM {local_kbacorp_member} m
       JOIN {user} u ON u.id = m.userid
      WHERE m.companyid = :companyid
   ORDER BY u.lastname, u.firstname",
    ['companyid' => $companyid]
);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading(get_string('members', 'local_kbacorporate') . ': ' . format_string($company->name));
$form->display();
$table = new html_table();
$table->head = [get_string('user', 'local_kbacorporate'), get_string('email', 'local_kbacorporate'), get_string('membertype', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($members as $member) {
    $user = (object)['firstname' => $member->firstname, 'lastname' => $member->lastname];
    $deleteurl = new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid, 'delete' => $member->id, 'sesskey' => sesskey()]);
    $actions = [];
    if (!empty($member->isemployee) && has_capability('local/kbacorporate:managerolecompetencies', $context)) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
            'companyid' => $companyid,
            'userid' => $member->userid,
        ]), get_string('businessroles', 'local_kbacorporate'));
    }
    $actions[] = html_writer::link($deleteurl, get_string('delete', 'local_kbacorporate'), [
        'onclick' => "return confirm(" . json_encode(get_string('confirmdeletemember', 'local_kbacorporate')) . ");"
    ]);
    $table->data[] = [
        fullname($user),
        s($member->email),
        implode(', ', array_filter([
            !empty($member->isemployee) ? get_string('employee', 'local_kbacorporate') : '',
            !empty($member->ismanager) ? get_string('manager', 'local_kbacorporate') : '',
            !empty($member->isdepartmentmanager) ? get_string('departmentmanager', 'local_kbacorporate') : '',
            !empty($member->ishr) ? get_string('hrrole', 'local_kbacorporate') : '',
        ])),
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'), get_string('companies', 'local_kbacorporate'), 'get');
echo $OUTPUT->footer();
