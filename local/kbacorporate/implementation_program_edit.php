<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationprograms', $context);
$companyid = required_param('companyid', PARAM_INT);
$auditid = required_param('auditid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$program = \local_kbacorporate\local\implementation_programs::get_for_audit($auditid);
if (!$program) { $program = \local_kbacorporate\local\implementation_programs::request($companyid, $auditid, $USER->id); }
if (optional_param('sendbitrix', 0, PARAM_BOOL)) {
    require_sesskey();
    \local_kbacorporate\local\bitrix_programs::create_deal($program);
    redirect(new moodle_url('/local/kbacorporate/implementation_program_edit.php', ['companyid' => $companyid,
        'auditid' => $auditid]), get_string('bitrixprogramdealsent', 'local_kbacorporate'));
}
$package = optional_param('package', $program->package, PARAM_ALPHANUMEXT);
$commercialstatus = optional_param('commercialstatus', $program->commercialstatus, PARAM_ALPHA);
$status = optional_param('status', $program->status, PARAM_ALPHA);
$curatorid = optional_param('curatorid', (int)$program->curatorid, PARAM_INT);
$contactname = optional_param('contactname', (string)$program->contactname, PARAM_TEXT);
$contactemail = optional_param('contactemail', (string)$program->contactemail, PARAM_EMAIL);
$contactphone = optional_param('contactphone', (string)$program->contactphone, PARAM_TEXT);
$customercomment = optional_param('customercomment', (string)$program->customercomment, PARAM_TEXT);
$customerresponse = optional_param('customerresponse', (string)$program->customerresponse, PARAM_TEXT);
$bitrixdealid = optional_param('bitrixdealid', (string)$program->bitrixdealid, PARAM_TEXT);
$curatorusers = get_users_by_capability($context, 'local/kbacorporate:manageimplementationprograms',
    'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email',
    'u.lastname ASC, u.firstname ASC');
$curatorusers = $curatorusers ?: [];
if (!isset($curatorusers[(int)$USER->id])) {
    $curatorusers[(int)$USER->id] = $USER;
}
$curatoroptions = [0 => get_string('notassigned', 'local_kbacorporate')];
foreach ($curatorusers as $curatoruser) {
    $curatoroptions[(int)$curatoruser->id] = fullname($curatoruser) . ' — ' . $curatoruser->email;
}
if ($program->curatorid && !isset($curatoroptions[(int)$program->curatorid])) {
    $savedcurator = $DB->get_record('user', ['id' => (int)$program->curatorid]);
    if ($savedcurator) {
        $curatoroptions[(int)$savedcurator->id] = fullname($savedcurator) . ' — ' . $savedcurator->email;
    }
}
if (optional_param('save', 0, PARAM_BOOL)) {
    require_sesskey();
    $statuschanged = $commercialstatus !== (string)$program->commercialstatus || $status !== (string)$program->status;
    if (!\local_kbacorporate\local\implementation_packages::get_by_code($package) ||
            !in_array($commercialstatus, ['requested', 'awaitingpayment', 'paid', 'cancelled'], true) ||
            !in_array($status, ['draft', 'active', 'paused', 'completed', 'cancelled'], true) ||
            !isset($curatoroptions[$curatorid])) {
        throw new invalid_parameter_exception('Invalid program state');
    }
    $program->package = $package; $program->commercialstatus = $commercialstatus;
    $program->status = $status; $program->curatorid = $curatorid;
    $program->contactname = trim($contactname) ?: null;
    $program->contactemail = trim($contactemail) ?: null;
    $program->contactphone = trim($contactphone) ?: null;
    $program->customercomment = trim($customercomment) ?: null;
    $program->customerresponse = trim($customerresponse) ?: null;
    $program->bitrixdealid = trim($bitrixdealid) ?: null;
    $program->timemodified = time();
    if ($commercialstatus === 'paid' && !$program->activatedat) {
        $program->activatedby = $USER->id; $program->activatedat = time();
    }
    if ($statuschanged) {
        $program->statuschangedby = (int)$USER->id;
        $program->statuschangedat = time();
    }
    $DB->update_record('local_kbacorp_impl_program', $program);
    if ($statuschanged) {
        \local_kbacorporate\local\implementation_request_notifier::send_status_changed($program);
    }
    redirect(new moodle_url('/local/kbacorporate/implementation_plan.php', ['companyid' => $companyid,
        'auditid' => $auditid]), get_string('implementationprogramsaved', 'local_kbacorporate'));
}
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_program_edit.php',
    ['companyid' => $companyid, 'auditid' => $auditid])); $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editimplementationprogram', 'local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'clients');
echo local_kbacorporate_company_context_banner($company);
echo $OUTPUT->heading(get_string('editimplementationprogram', 'local_kbacorporate'));
$action = new moodle_url('/local/kbacorporate/implementation_program_edit.php');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $action]);
foreach (['companyid' => $companyid, 'auditid' => $auditid, 'sesskey' => sesskey(), 'save' => 1] as $name => $value) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
$select = static function(string $name, array $options, string $selected): string {
    return html_writer::select($options, $name, $selected, false, ['class' => 'custom-select']);
};
echo html_writer::div(html_writer::tag('label', get_string('implementationpackage', 'local_kbacorporate')) .
    $select('package', \local_kbacorporate\local\implementation_packages::options(false), $program->package), 'form-group');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_packages.php'),
    get_string('editimplementationpackages', 'local_kbacorporate')), 'mb-3');
echo html_writer::div(html_writer::tag('label', get_string('commercialstatus', 'local_kbacorporate')) .
    $select('commercialstatus', ['requested' => get_string('commercialstatusrequested', 'local_kbacorporate'),
        'awaitingpayment' => get_string('commercialstatusawaitingpayment', 'local_kbacorporate'),
        'paid' => get_string('commercialstatuspaid', 'local_kbacorporate'),
        'cancelled' => get_string('commercialstatuscancelled', 'local_kbacorporate')], $program->commercialstatus), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('programstatus', 'local_kbacorporate')) .
    $select('status', ['draft' => get_string('programstatusdraft', 'local_kbacorporate'),
        'active' => get_string('programstatusactive', 'local_kbacorporate'),
        'paused' => get_string('programstatuspaused', 'local_kbacorporate'),
        'completed' => get_string('programstatuscompleted', 'local_kbacorporate'),
        'cancelled' => get_string('programstatuscancelled', 'local_kbacorporate')], $program->status), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('implementationcurator', 'local_kbacorporate')) .
    $select('curatorid', $curatoroptions, (string)$program->curatorid), 'form-group');
foreach ([
    'contactname' => get_string('implementationcontactname', 'local_kbacorporate'),
    'contactemail' => get_string('implementationcontactemail', 'local_kbacorporate'),
    'contactphone' => get_string('implementationcontactphone', 'local_kbacorporate'),
] as $fieldname => $fieldlabel) {
    echo html_writer::div(html_writer::tag('label', $fieldlabel) . html_writer::empty_tag('input', [
        'name' => $fieldname, 'value' => s((string)$program->{$fieldname}), 'class' => 'form-control',
        'type' => $fieldname === 'contactemail' ? 'email' : 'text',
    ]), 'form-group');
}
echo html_writer::div(html_writer::tag('label', get_string('implementationcustomercomment', 'local_kbacorporate')) .
    html_writer::tag('textarea', s((string)$program->customercomment), [
        'name' => 'customercomment', 'class' => 'form-control', 'rows' => 4,
    ]), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('implementationcustomerresponse', 'local_kbacorporate')) .
    html_writer::tag('textarea', s((string)$program->customerresponse), [
        'name' => 'customerresponse', 'class' => 'form-control', 'rows' => 4,
    ]) . html_writer::div(get_string('implementationcustomerresponsehelp', 'local_kbacorporate'),
        'form-text text-muted'), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('bitrixdealid', 'local_kbacorporate')) .
    html_writer::empty_tag('input', ['name' => 'bitrixdealid', 'value' => s((string)$program->bitrixdealid),
        'class' => 'form-control', 'type' => 'text']), 'form-group');
if (empty($program->bitrixdealid)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_program_edit.php',
        ['companyid' => $companyid, 'auditid' => $auditid, 'sendbitrix' => 1, 'sesskey' => sesskey()]),
        get_string('sendprogramtobitrix', 'local_kbacorporate'), 'post');
}
echo html_writer::tag('button', get_string('savechanges'), ['class' => 'btn btn-primary', 'type' => 'submit']);
echo html_writer::end_tag('form'); echo $OUTPUT->footer();
