<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managelicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
$license = $id ? \local_kbacorporate\local\licenses::get_license($id, $companyid) : null;
if ($id && !$license) {
    throw new moodle_exception('licensenotfound', 'local_kbacorporate');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/license_edit.php', ['companyid' => $companyid, 'id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? get_string('editlicense', 'local_kbacorporate') : get_string('addlicense', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\license_form(null, [
    'companyid' => $companyid,
    'license' => $license,
]);
if ($license) {
    $form->set_data($license);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]));
}
if ($data = $form->get_data()) {
    try {
        $licenseid = \local_kbacorporate\local\licenses::save_license($data);
        redirect(new moodle_url('/local/kbacorporate/license_users.php', [
            'companyid' => $companyid,
            'licenseid' => $licenseid,
        ]), get_string('saved', 'local_kbacorporate'));
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]),
    '← ' . get_string('back', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading($id ? get_string('editlicense', 'local_kbacorporate') : get_string('addlicense', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
