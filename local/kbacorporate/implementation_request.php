<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT);
$auditid = required_param('auditid', PARAM_INT);
$packagecode = required_param('package', PARAM_ALPHANUMEXT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}
$package = \local_kbacorporate\local\implementation_packages::get_by_code($packagecode);
if (!$package || !$package->active) {
    throw new invalid_parameter_exception('Unknown implementation package');
}
if (\local_kbacorporate\local\implementation_programs::get_for_audit($auditid)) {
    redirect(new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]));
}
$contactname = optional_param('contactname', fullname($USER), PARAM_TEXT);
$contactemail = optional_param('contactemail', $USER->email, PARAM_EMAIL);
$contactphone = optional_param('contactphone', $USER->phone1 ?? '', PARAM_TEXT);
$customercomment = optional_param('customercomment', '', PARAM_TEXT);
$submitted = optional_param('submitrequest', 0, PARAM_BOOL);
$error = '';
if ($submitted) {
    require_sesskey();
    if (trim($contactname) === '' || trim($contactemail) === '') {
        $error = get_string('implementationrequestrequired', 'local_kbacorporate');
    } else {
        \local_kbacorporate\local\implementation_programs::request($companyid, $auditid, (int)$USER->id,
            $packagecode, compact('contactname', 'contactemail', 'contactphone', 'customercomment'));
        redirect(new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]),
            get_string('implementationprogramrequested', 'local_kbacorporate'));
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_request.php', [
    'companyid' => $companyid, 'auditid' => $auditid, 'package' => $packagecode,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('implementationrequesttitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'clients');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('implementationrequesttitle', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('implementationrequestdesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
$summary = html_writer::div(format_string($package->name), 'kbacorp-package-title');
$summary .= html_writer::div($package->price === null ? get_string('priceonrequest', 'local_kbacorporate')
    : format_float($package->price, 2) . ' ' . s($package->currency), 'kbacorp-package-price');
$summary .= html_writer::div(format_string($audit->title), 'kbacorp-package-description');
echo html_writer::div($summary, 'kbacorp-request-package');
if ($error) { echo $OUTPUT->notification($error, 'error'); }
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-request-form']);
foreach (['companyid' => $companyid, 'auditid' => $auditid, 'package' => $packagecode,
        'sesskey' => sesskey(), 'submitrequest' => 1] as $name => $value) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
$fields = [
    ['contactname', get_string('implementationcontactname', 'local_kbacorporate'), $contactname, 'text', true],
    ['contactemail', get_string('implementationcontactemail', 'local_kbacorporate'), $contactemail, 'email', true],
    ['contactphone', get_string('implementationcontactphone', 'local_kbacorporate'), $contactphone, 'tel', false],
];
foreach ($fields as [$name, $label, $value, $type, $required]) {
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', $label, ['for' => 'id_' . $name]);
    $attributes = ['type' => $type, 'id' => 'id_' . $name, 'name' => $name,
        'value' => $value, 'class' => 'form-control'];
    if ($required) {
        $attributes['required'] = 'required';
    }
    echo html_writer::empty_tag('input', $attributes);
    echo html_writer::end_div();
}
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('implementationcustomercomment', 'local_kbacorporate'),
    ['for' => 'id_customercomment']);
echo html_writer::tag('textarea', s($customercomment), ['id' => 'id_customercomment', 'name' => 'customercomment',
    'class' => 'form-control', 'rows' => 5]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('submitimplementationrequest', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
