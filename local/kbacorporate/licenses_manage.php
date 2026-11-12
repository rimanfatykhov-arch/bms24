<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managelicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
\local_kbacorporate\local\access::require_not_revoked($companyid, (int)$USER->id);
$deactivate = optional_param('deactivate', 0, PARAM_INT);
$company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);

if ($deactivate && confirm_sesskey()) {
    \local_kbacorporate\local\licenses::deactivate($deactivate, $companyid);
    redirect(new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]),
        get_string('saved', 'local_kbacorporate'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/licenses_manage.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('licensesmanage', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$licenses = \local_kbacorporate\local\licenses::get_company_licenses($companyid, true);
$summary = \local_kbacorporate\local\licenses::summary($licenses);

$capacity = static function($license) {
    if (!empty($license->unlimited)) {
        return get_string('licenseunlimited', 'local_kbacorporate');
    }
    return get_string('licensecapacityformat', 'local_kbacorporate', (object)[
        'used' => (int)$license->used,
        'seats' => (int)$license->seats,
        'free' => (int)$license->available,
    ]);
};
$period = static function($license) {
    $from = !empty($license->datefrom) ? userdate($license->datefrom, get_string('strftimedateshort', 'langconfig')) : '—';
    $to = !empty($license->dateto) ? userdate($license->dateto, get_string('strftimedateshort', 'langconfig')) : '—';
    return $from . ' — ' . $to;
};

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading(get_string('licensesmanage', 'local_kbacorporate') . ': ' . format_string($company->name));
echo html_writer::div(get_string('licensesmanagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::div(
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/license_edit.php', ['companyid' => $companyid]),
        get_string('addlicense', 'local_kbacorporate'), 'get') . ' ' .
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/licenses.php', ['companyid' => $companyid]),
        get_string('openlicenseregister', 'local_kbacorporate'), 'get') . ' ' .
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'),
        get_string('companies', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);

$cards = [
    [get_string('licensepackages', 'local_kbacorporate'), $summary->licenses],
    [get_string('purchasedseats', 'local_kbacorporate'), $summary->totalseats],
    [get_string('usedseats', 'local_kbacorporate'), $summary->usedfinite],
    [get_string('freeseats', 'local_kbacorporate'), $summary->free],
    [get_string('licenseutilization', 'local_kbacorporate'), $summary->utilizationpercent === null ? '—' : format_float($summary->utilizationpercent, 1) . '%'],
    [get_string('expiringlicenses', 'local_kbacorporate'), $summary->expiringlicenses],
];
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as [$label, $value]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

if (!$licenses) {
    echo $OUTPUT->notification(get_string('licensenoitems', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('course', 'local_kbacorporate'),
    get_string('group', 'local_kbacorporate'),
    get_string('licenseperiod', 'local_kbacorporate'),
    get_string('licensecapacity', 'local_kbacorporate'),
    get_string('licensesource', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('licenseexternalref', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($licenses as $license) {
    $actions = [];
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/license_edit.php', [
        'companyid' => $companyid,
        'id' => $license->id,
    ]), get_string('edit'));
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/license_users.php', [
        'companyid' => $companyid,
        'licenseid' => $license->id,
    ]), get_string('licenseallocations', 'local_kbacorporate'));
    if ((string)$license->status === \local_kbacorporate\local\licenses::STATUS_ACTIVE) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/licenses_manage.php', [
            'companyid' => $companyid,
            'deactivate' => $license->id,
            'sesskey' => sesskey(),
        ]), get_string('deactivate', 'local_kbacorporate'), [
            'onclick' => "return confirm(" . json_encode(get_string('confirmdeactivatelicense', 'local_kbacorporate')) . ");",
        ]);
    }
    $external = trim((string)$license->externaldealid) !== '' || trim((string)$license->externalrowid) !== ''
        ? s(trim((string)$license->externaldealid . ' / ' . (string)$license->externalrowid, ' /')) : '—';
    $statusclass = 'kbacorp-status kbacorp-status-' . preg_replace('/[^a-z]/', '', (string)$license->effectivestatus);
    $table->data[] = [
        html_writer::link(new moodle_url('/course/view.php', ['id' => $license->courseid]),
            format_string($license->fullname), ['class' => 'kbacorp-course-link']),
        $license->groupid ? format_string($license->groupname) : get_string('nogroup', 'local_kbacorporate'),
        s($period($license)),
        s($capacity($license)),
        s(\local_kbacorporate\local\licenses::source_label((string)$license->source)),
        html_writer::span(s(\local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus)), $statusclass),
        $external,
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
