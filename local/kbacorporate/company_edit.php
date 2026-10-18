<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);
$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/company_edit.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? get_string('editcompany', 'local_kbacorporate') : get_string('addcompany', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$form = new \local_kbacorporate\form\company_form();
if ($id) {
    $company = $DB->get_record('local_kbacorp_company', ['id' => $id], '*', MUST_EXIST);
    $company->traininghoursday = round(((int)$company->trainingminutesday) / 60, 2);
    $weekdays = array_filter(array_map('intval', explode(',', (string)$company->trainingweekdays)));
    foreach ($weekdays as $day) {
        if ($day >= 1 && $day <= 7) {
            $company->{'trainingday' . $day} = 1;
        }
    }
    for ($day = 1; $day <= 7; $day++) {
        if (!isset($company->{'trainingday' . $day})) {
            $company->{'trainingday' . $day} = 0;
        }
    }
    $form->set_data($company);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/companies.php'));
} else if ($data = $form->get_data()) {
    \local_kbacorporate\local\repository::save_company($data);
    redirect(new moodle_url('/local/kbacorporate/companies.php'), get_string('saved', 'local_kbacorporate'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading($id ? get_string('editcompany', 'local_kbacorporate') : get_string('addcompany', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
