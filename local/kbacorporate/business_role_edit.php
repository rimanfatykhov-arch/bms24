<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$id = optional_param('id', 0, PARAM_INT);
$scope = optional_param('scope', 'company', PARAM_ALPHA);

// The form must keep the role scope companyid (0 for a global template) separate from
// the company used only as the return/navigation context. On POST Moodle gives request
// parameters precedence over the query string, so reusing the name `companyid` for both
// values makes a global template submit as companyid=0 and breaks access checks.
$returncompanyid = optional_param('returncompanyid', 0, PARAM_INT);
if (!$returncompanyid && isset($_GET['companyid'])) {
    $returncompanyid = clean_param($_GET['companyid'], PARAM_INT);
}

$role = $id ? \local_kbacorporate\local\business_roles::get_role($id) : null;
$rolecompanyid = $role ? (int)$role->companyid :
    (($scope === 'global' && is_siteadmin()) ? 0 : $returncompanyid);

if ($rolecompanyid === 0) {
    if (!is_siteadmin()) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    // A global role template can be edited without a company context. When a return
    // company is present, validate it and use it for the page heading/navigation.
    $company = null;
    if ($returncompanyid > 0) {
        $company = \local_kbacorporate\local\access::require_full_company_access(
            $returncompanyid,
            $USER->id
        );
    }
} else {
    if ($returncompanyid > 0 && $role && $rolecompanyid !== $returncompanyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $returncompanyid = $returncompanyid ?: $rolecompanyid;
    $company = \local_kbacorporate\local\access::require_full_company_access(
        $rolecompanyid,
        $USER->id
    );
}

$urlparams = [];
if ($returncompanyid > 0) {
    $urlparams['returncompanyid'] = $returncompanyid;
}
if ($id) {
    $urlparams['id'] = $id;
}
if (!$id && $rolecompanyid === 0) {
    $urlparams['scope'] = 'global';
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/business_role_edit.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? get_string('editbusinessrole', 'local_kbacorporate') :
    get_string('addbusinessrole', 'local_kbacorporate'));
$PAGE->set_heading($company ? format_string($company->name) : get_string('pluginname', 'local_kbacorporate'));

$form = new \local_kbacorporate\form\business_role_form(
    new moodle_url('/local/kbacorporate/business_role_edit.php', $urlparams),
    [
    'companyid' => $rolecompanyid,
    'returncompanyid' => $returncompanyid,
    'scope' => $rolecompanyid === 0 ? 'global' : 'company',
]);
if ($role) {
    $role->returncompanyid = $returncompanyid;
    $form->set_data($role);
}
if ($form->is_cancelled()) {
    if ($returncompanyid > 0) {
        $cancelpath = $rolecompanyid === 0 ? '/local/kbacorporate/role_templates.php' :
            '/local/kbacorporate/business_roles.php';
        redirect(new moodle_url($cancelpath, ['companyid' => $returncompanyid]));
    }
    redirect(new moodle_url('/local/kbacorporate/business_roles.php'));
} else if ($data = $form->get_data()) {
    $data->companyid = $rolecompanyid;
    $data->changedby = (int)$USER->id;
    $roleid = \local_kbacorporate\local\business_roles::save_role($data);
    if ($returncompanyid > 0) {
        redirect(
            new moodle_url('/local/kbacorporate/business_role_profile.php', [
                'roleid' => $roleid,
                'companyid' => $returncompanyid,
            ]),
            get_string('saved', 'local_kbacorporate')
        );
    }
    redirect(new moodle_url('/local/kbacorporate/business_roles.php'),
        get_string('saved', 'local_kbacorporate'));
}

echo $OUTPUT->header();
if ($returncompanyid > 0) {
    echo local_kbacorporate_company_dashboard_navigation($returncompanyid);
}
$backparams = $returncompanyid > 0 ? ['companyid' => $returncompanyid] : [];
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/business_roles.php', $backparams),
    '← ' . get_string('businessroles', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading($id ? get_string('editbusinessrole', 'local_kbacorporate') :
    get_string('addbusinessrole', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
