<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managelicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
\local_kbacorporate\local\access::require_not_revoked($companyid, (int)$USER->id);
$licenseid = required_param('licenseid', PARAM_INT);
$releaseuserid = optional_param('releaseuserid', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
$license = \local_kbacorporate\local\licenses::get_license($licenseid, $companyid);
if (!$license) {
    throw new moodle_exception('licensenotfound', 'local_kbacorporate');
}

if ($releaseuserid && confirm_sesskey()) {
    try {
        \local_kbacorporate\local\licenses::release_user($licenseid, $releaseuserid);
        redirect(new moodle_url('/local/kbacorporate/license_users.php', [
            'companyid' => $companyid,
            'licenseid' => $licenseid,
        ]), get_string('licenseseatreleased', 'local_kbacorporate'));
    } catch (moodle_exception $e) {
        redirect(new moodle_url('/local/kbacorporate/license_users.php', [
            'companyid' => $companyid,
            'licenseid' => $licenseid,
        ]), $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/license_users.php', ['companyid' => $companyid, 'licenseid' => $licenseid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('licenseallocations', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\license_user_form(null, ['license' => $license]);
if ($data = $form->get_data()) {
    try {
        $result = \local_kbacorporate\local\licenses::assign_user($licenseid, (int)$data->userid, (int)$USER->id);
        $message = !empty($result['created']) || !empty($result['changed'])
            ? get_string('licenseseatassigned', 'local_kbacorporate')
            : get_string('licenseseatalreadyassigned', 'local_kbacorporate');
        redirect(new moodle_url('/local/kbacorporate/license_users.php', [
            'companyid' => $companyid,
            'licenseid' => $licenseid,
        ]), $message);
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}
$allocations = \local_kbacorporate\local\licenses::get_license_allocations($licenseid);
$license = \local_kbacorporate\local\licenses::get_license($licenseid, $companyid);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]),
    '← ' . get_string('back', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('licenseallocations', 'local_kbacorporate') . ': ' . format_string($license->fullname));
echo html_writer::div(get_string('licenseallocationintro', 'local_kbacorporate', (object)[
    'used' => (int)$license->used,
    'capacity' => !empty($license->unlimited) ? get_string('licenseunlimited', 'local_kbacorporate') : (int)$license->seats,
]), 'kbacorp-pagelead');
$form->display();

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('employee', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('departments', 'local_kbacorporate'),
    get_string('licenseallocationstatus', 'local_kbacorporate'),
    get_string('licenseassignedat', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($allocations as $allocation) {
    $actions = [];
    if ((string)$allocation->effectivestatus === \local_kbacorporate\local\licenses::ALLOCATION_ASSIGNED) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/license_users.php', [
            'companyid' => $companyid,
            'licenseid' => $licenseid,
            'releaseuserid' => $allocation->userid,
            'sesskey' => sesskey(),
        ]), get_string('releaselicenseuser', 'local_kbacorporate'), [
            'onclick' => "return confirm(" . json_encode(get_string('confirmreleaselicenseuser', 'local_kbacorporate')) . ");",
        ]);
    }
    $table->data[] = [
        html_writer::link(new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid,
            'userid' => $allocation->userid,
        ]), s($allocation->fullname)),
        s($allocation->email),
        s($allocation->departments ?: '—'),
        s(\local_kbacorporate\local\licenses::allocation_status_label((string)$allocation->effectivestatus)),
        !empty($allocation->timeassigned) ? userdate($allocation->timeassigned) : '—',
        $actions ? implode(' · ', $actions) : '—',
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
