<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompetencyaudits', $context);

$companies = array_filter(
    \local_kbacorporate\local\access::get_allowed_companies($USER->id),
    static function($company) use ($USER) {
        return \local_kbacorporate\local\access::has_full_company_access((int)$company->id, $USER->id);
    }
);
$companyid = optional_param('companyid', 0, PARAM_INT);
$defaultuserid = optional_param('userid', 0, PARAM_INT);
$deleteauditid = optional_param('deleteaudit', 0, PARAM_INT);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
$company = $companyid ? \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id) : null;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/competency_audits.php', $companyid ? ['companyid' => $companyid] : []));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('competencyaudits', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

if (!\local_kbacorporate\local\competency_audits::available()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('competencyaudits', 'local_kbacorporate'));
    echo $OUTPUT->notification(get_string('auditsunavailable', 'local_kbacorporate'), 'error');
    echo $OUTPUT->footer();
    exit;
}
if (!$companies || !$company) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('competencyaudits', 'local_kbacorporate'));
    echo $OUTPUT->notification(get_string('nocorporatecompanies', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($deleteauditid) {
    require_sesskey();
    $deleteaudit = \local_kbacorporate\local\competency_audits::get_audit($deleteauditid);
    if ((int)$deleteaudit->companyid !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    \local_kbacorporate\local\competency_audits::delete_audit($deleteauditid);
    redirect(new moodle_url('/local/kbacorporate/competency_audits.php', ['companyid' => $companyid]),
        get_string('auditdeleted', 'local_kbacorporate'));
}

$companyoptions = [];
foreach ($companies as $item) {
    $companyoptions[(int)$item->id] = format_string($item->name);
}
$employeeids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id);
$useroptions = [];
if ($employeeids) {
    list($insql, $params) = $DB->get_in_or_equal($employeeids, SQL_PARAMS_NAMED, 'employee');
    $users = $DB->get_records_select('user', "id {$insql} AND deleted = 0", $params, 'lastname, firstname');
    foreach ($users as $user) {
        $useroptions[(int)$user->id] = fullname($user) . ($user->email ? ' — ' . s($user->email) : '');
    }
}
$form = new \local_kbacorporate\form\competency_audit_form(null, [
    'companyid' => $companyid,
    'users' => $useroptions,
    'userid' => isset($useroptions[$defaultuserid]) ? $defaultuserid : 0,
]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/competency_audits.php', ['companyid' => $companyid]));
} else if ($data = $form->get_data()) {
    if (!isset($useroptions[(int)$data->userid])) {
        throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
    }
    if (!empty($data->fillfromroles)) {
        $auditid = \local_kbacorporate\local\competency_audits::create_role_audit(
            $companyid, (int)$data->userid, (string)$data->title, (string)$data->description, $USER->id
        );
    } else {
        $auditid = \local_kbacorporate\local\competency_audits::create_audit(
            $companyid, (int)$data->userid, (string)$data->title, (string)$data->description, $USER->id
        );
    }
    redirect(new moodle_url('/local/kbacorporate/competency_audit.php', ['id' => $auditid]),
        get_string('auditcreated', 'local_kbacorporate'));
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo $OUTPUT->heading(get_string('competencyaudits', 'local_kbacorporate'));
echo html_writer::div(get_string('competencyauditsdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo $OUTPUT->single_select(new moodle_url('/local/kbacorporate/competency_audits.php'), 'companyid',
    $companyoptions, $companyid, null, 'kbacorp-audit-company-select');

$audits = \local_kbacorporate\local\competency_audits::get_company_audits($companyid);
echo $OUTPUT->heading(get_string('audithistory', 'local_kbacorporate'), 3);
if (!$audits) {
    echo $OUTPUT->notification(get_string('noaudits', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-audit-table';
    $table->head = [get_string('audittitle', 'local_kbacorporate'), get_string('audittype', 'local_kbacorporate'),
        get_string('employee', 'local_kbacorporate'),
        get_string('status'), get_string('auditassessments', 'local_kbacorporate'),
        get_string('auditpending', 'local_kbacorporate'), get_string('auditmissing', 'local_kbacorporate'),
        get_string('date'), get_string('actions', 'local_kbacorporate')];
    foreach ($audits as $audit) {
        $editurl = new moodle_url('/local/kbacorporate/competency_audit_edit.php', ['id' => $audit->id]);
        $deleteurl = new moodle_url('/local/kbacorporate/competency_audits.php', [
            'companyid' => $companyid,
            'deleteaudit' => $audit->id,
            'sesskey' => sesskey(),
        ]);
        $actions = html_writer::link($editurl, get_string('editaudittitle', 'local_kbacorporate')) .
            ' · ' . html_writer::link($deleteurl, get_string('deleteaudit', 'local_kbacorporate'), [
                'onclick' => 'return confirm(' . json_encode(
                    get_string('confirmdeleteaudit', 'local_kbacorporate', format_string($audit->title))
                ) . ');',
            ]);
        $auditurl = (string)$audit->audittype === \local_kbacorporate\local\competency_audits::AUDIT_TYPE_DEVELOPMENT
            ? new moodle_url('/local/kbacorporate/competency_reassessment.php', ['id' => $audit->id])
            : new moodle_url('/local/kbacorporate/competency_audit.php', ['id' => $audit->id]);
        $table->data[] = [
            html_writer::link($auditurl, format_string($audit->title)),
            \local_kbacorporate\local\competency_audits::audit_type_label($audit->audittype),
            fullname($audit),
            \local_kbacorporate\local\competency_audits::status_label($audit->status),
            (int)$audit->itemcount,
            (int)$audit->pendingcount,
            (int)$audit->missingcount,
            userdate((int)$audit->timecreated),
            $actions,
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->heading(get_string('createaudit', 'local_kbacorporate'), 3);
$form->display();
echo $OUTPUT->footer();
