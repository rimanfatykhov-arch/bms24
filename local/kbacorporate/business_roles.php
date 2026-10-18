<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid > 0) {
    $company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
} else {
    $company = null;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/business_roles.php', $companyid ? ['companyid' => $companyid] : []));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('businessroles', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

echo $OUTPUT->header();
$navigationlinks = [];
if (has_capability('local/kbacorporate:viewdashboard', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/index.php',
        $companyid ? ['companyid' => $companyid] : []), '← ' . get_string('backtodashboard', 'local_kbacorporate'));
}
if ($companyid > 0 && has_capability('local/kbacorporate:viewcompetencies', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/competency_map.php', [
        'companyid' => $companyid,
    ]), get_string('competencymap', 'local_kbacorporate'));
}
$navigation = $navigationlinks ? html_writer::div(implode(
    html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks
), 'kbacorp-page-navigation', ['role' => 'navigation']) : '';
echo $navigation;
echo $OUTPUT->heading(get_string('businessroles', 'local_kbacorporate'));
echo html_writer::div(get_string('businessrolesdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

if (!\local_kbacorporate\local\business_roles::available()) {
    echo $OUTPUT->notification(get_string('businessrolesunavailable', 'local_kbacorporate'), 'error');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}
if (!$companies || !$company) {
    echo $OUTPUT->notification(get_string('nocorporatecompanies', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$companyoptions = [];
foreach ($companies as $item) {
    $companyoptions[(int)$item->id] = format_string($item->name);
}
echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_select(
    new moodle_url('/local/kbacorporate/business_roles.php'),
    'companyid',
    $companyoptions,
    $companyid,
    null,
    'kbacorp-role-company-select'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/business_role_edit.php', ['returncompanyid' => $companyid]),
    get_string('addbusinessrole', 'local_kbacorporate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/role_templates.php', ['companyid' => $companyid]),
    get_string('roletemplatelibrary', 'local_kbacorporate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/business_role_assignments.php', ['companyid' => $companyid]),
    get_string('businessroleassignments', 'local_kbacorporate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/role_learning_matrix.php', ['companyid' => $companyid]),
    get_string('rolelearningmatrix', 'local_kbacorporate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/kbacorporate/moodle_learning_plans.php', ['companyid' => $companyid]),
    get_string('moodlelearningplansintegration', 'local_kbacorporate'),
    'get'
);
echo html_writer::end_div();

$roles = \local_kbacorporate\local\business_roles::get_catalog($companyid, true);
if (!$roles) {
    echo $OUTPUT->notification(get_string('nobusinessroles', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-table';
$table->head = [
    get_string('businessrolename', 'local_kbacorporate'),
    get_string('businessrolecode', 'local_kbacorporate'),
    get_string('businessrolescope', 'local_kbacorporate'),
    get_string('rolecompetencies', 'local_kbacorporate'),
    get_string('assignedemployees', 'local_kbacorporate'),
    get_string('status'),
    get_string('source', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($roles as $role) {
    $roleid = (int)$role->id;
    $isglobal = (int)$role->companyid === 0;
    $profileurl = new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
    ]);
    $actions = [html_writer::link($profileurl, get_string('openroleprofile', 'local_kbacorporate'))];
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
    ]), get_string('roleprofileintegrity', 'local_kbacorporate'));
    if (!$isglobal || is_siteadmin()) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_edit.php', [
            'id' => $roleid,
            'returncompanyid' => $companyid,
        ]), get_string('edit'));
    }
    if (!$isglobal && is_siteadmin()) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
            'roleid' => $roleid,
            'companyid' => $companyid,
            'createtemplate' => 1,
            'sesskey' => sesskey(),
        ]), get_string('saveasglobalroletemplate', 'local_kbacorporate'), [
            'onclick' => 'return confirm(' . json_encode(
                get_string('confirmsaveasglobalroletemplate', 'local_kbacorporate')
            ) . ');',
        ]);
    }
    $table->data[] = [
        html_writer::link($profileurl, format_string($role->name)),
        s($role->code),
        $isglobal ? get_string('businessrolescopeglobal', 'local_kbacorporate') :
            get_string('businessrolescopecompany', 'local_kbacorporate'),
        $DB->count_records('local_kbacorp_rolecomp', ['roleid' => $roleid]),
        $DB->count_records('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'roleid' => $roleid,
            'active' => 1,
        ]),
        !empty($role->active) ? get_string('active', 'local_kbacorporate') :
            get_string('inactive', 'local_kbacorporate'),
        s($role->source),
        implode(' · ', $actions),
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
echo html_writer::div(get_string('globalrolesreadonlynote', 'local_kbacorporate'), 'kbacorp-role-note');
echo $navigation;
echo $OUTPUT->footer();
