<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$companyid = required_param('companyid', PARAM_INT);
$filteruserid = optional_param('userid', 0, PARAM_INT);
$unassignid = optional_param('unassign', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

if ($unassignid) {
    require_sesskey();
    \local_kbacorporate\local\business_roles::deactivate_user_role($companyid, $unassignid);
    redirect(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
        'companyid' => $companyid,
        'userid' => $filteruserid,
    ]), get_string('businessroleunassigned', 'local_kbacorporate'));
}

$members = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email
       FROM {local_kbacorp_member} m
       JOIN {user} u ON u.id = m.userid
      WHERE m.companyid = :companyid
        AND m.isemployee = 1
        AND u.deleted = 0
   ORDER BY u.lastname ASC, u.firstname ASC",
    ['companyid' => $companyid]
);
if ($filteruserid > 0 && !isset($members[$filteruserid])) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
$useroptions = [];
foreach ($members as $member) {
    $useroptions[(int)$member->id] = fullname($member) . ' — ' . $member->email;
}
$roles = \local_kbacorporate\local\business_roles::get_catalog($companyid, false);
$roleoptions = [];
foreach ($roles as $role) {
    $scope = (int)$role->companyid === 0 ? get_string('businessrolescopeglobal', 'local_kbacorporate') :
        get_string('businessrolescopecompany', 'local_kbacorporate');
    $roleoptions[(int)$role->id] = '[' . $scope . '] ' . format_string($role->name) . ' — ' . s($role->code);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
    'companyid' => $companyid,
    'userid' => $filteruserid,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('businessroleassignments', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = null;
if ($useroptions && $roleoptions) {
    $form = new \local_kbacorporate\form\business_role_assignment_form(null, [
        'companyid' => $companyid,
        'users' => $useroptions,
        'roles' => $roleoptions,
        'userid' => $filteruserid,
    ]);
    if ($data = $form->get_data()) {
        $userid = (int)$data->userid;
        if (!isset($useroptions[$userid])) {
            throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
        }
        $selectedroleids = array_values(array_unique(array_map('intval', (array)$data->roleids)));
        foreach ($selectedroleids as $roleid) {
            $roleid = (int)$roleid;
            if (!isset($roleoptions[$roleid])) {
                throw new moodle_exception('companynotallowed', 'local_kbacorporate');
            }
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($selectedroleids as $roleid) {
            \local_kbacorporate\local\business_roles::assign_user_role(
                $companyid,
                $userid,
                $roleid,
                'manual',
                '',
                true
            );
        }
        $transaction->allow_commit();
        redirect(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]), get_string('businessrolesassigned', 'local_kbacorporate'));
    }
}

$assignments = \local_kbacorporate\local\business_roles::get_company_assignments($companyid, $filteruserid);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
    '← ' . get_string('businessroles', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('businessroleassignments', 'local_kbacorporate'));
echo html_writer::div(get_string('businessroleassignmentsdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

if (!$useroptions) {
    echo $OUTPUT->notification(get_string('noemployees', 'local_kbacorporate'), 'info');
} else if (!$roleoptions) {
    echo $OUTPUT->notification(get_string('nobusinessroles', 'local_kbacorporate'), 'info');
} else {
    echo $OUTPUT->heading(get_string('assignbusinessroles', 'local_kbacorporate'), 3);
    $form->display();
}

$filteroptions = [0 => get_string('allemployees', 'local_kbacorporate')] + $useroptions;
echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_select(
    new moodle_url('/local/kbacorporate/business_role_assignments.php', ['companyid' => $companyid]),
    'userid',
    $filteroptions,
    $filteruserid,
    null,
    'kbacorp-role-user-filter'
);
echo html_writer::end_div();

if (!$assignments) {
    echo $OUTPUT->notification(get_string('nobusinessroleassignments', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-assignment-table';
    $table->head = [
        get_string('employee', 'local_kbacorporate'),
        get_string('businessrole', 'local_kbacorporate'),
        get_string('businessrolescope', 'local_kbacorporate'),
        get_string('source', 'local_kbacorporate'),
        get_string('assignedat', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($assignments as $assignment) {
        $unassignurl = new moodle_url('/local/kbacorporate/business_role_assignments.php', [
            'companyid' => $companyid,
            'userid' => $filteruserid,
            'unassign' => $assignment->id,
            'sesskey' => sesskey(),
        ]);
        $roleurl = new moodle_url('/local/kbacorporate/business_role_profile.php', [
            'companyid' => $companyid,
            'roleid' => $assignment->roleid,
        ]);
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacorporate/employee.php', [
                'companyid' => $companyid,
                'userid' => $assignment->userid,
            ]), s(fullname($assignment))) . html_writer::div(s($assignment->email), 'kbacorp-muted'),
            html_writer::link($roleurl, format_string($assignment->rolename)) .
                html_writer::div(s($assignment->code), 'kbacorp-muted'),
            (int)$assignment->rolecompanyid === 0 ? get_string('businessrolescopeglobal', 'local_kbacorporate') :
                get_string('businessrolescopecompany', 'local_kbacorporate'),
            s($assignment->source),
            !empty($assignment->effectivefrom) ? userdate((int)$assignment->effectivefrom) : '—',
            html_writer::link($unassignurl, get_string('unassignbusinessrole', 'local_kbacorporate'), [
                'onclick' => 'return confirm(' . json_encode(
                    get_string('confirmunassignbusinessrole', 'local_kbacorporate')
                ) . ');',
            ]),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
