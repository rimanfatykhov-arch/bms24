<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$companyid = required_param('companyid', PARAM_INT);
$cloneid = optional_param('clone', 0, PARAM_INT);
$applyid = optional_param('apply', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

$baseurl = new moodle_url('/local/kbacorporate/role_templates.php', ['companyid' => $companyid]);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roletemplatelibrary', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

if ($cloneid) {
    require_sesskey();
    $roleid = \local_kbacorporate\local\business_roles::create_from_template($cloneid, $companyid);
    redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
    ]), get_string('rolecreatedfromtemplatenotice', 'local_kbacorporate'));
}

if ($applyid) {
    require_sesskey();
    $role = \local_kbacorporate\local\business_roles::get_role($applyid);
    if ((int)$role->companyid !== $companyid) {
        throw new moodle_exception('invalidcompanycontext', 'local_kbacorporate');
    }
    $result = \local_kbacorporate\local\business_roles::apply_template_update($applyid);
    redirect($baseurl, get_string('roletemplateupdated', 'local_kbacorporate', $result));
}

$templates = \local_kbacorporate\local\business_roles::get_templates(true);
$copies = [];
foreach (\local_kbacorporate\local\business_roles::get_template_copies($companyid) as $copy) {
    $copies[(int)$copy->templateid] = $copy;
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
    '← ' . get_string('businessroles', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('roletemplatelibrary', 'local_kbacorporate'));
echo html_writer::div(get_string('roletemplatelibrarydesc', 'local_kbacorporate'), 'kbacorp-pagelead');

if (is_siteadmin()) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_edit.php', [
        'returncompanyid' => $companyid,
        'scope' => 'global',
    ]), get_string('addroletemplate', 'local_kbacorporate'), 'get');
}

if (!$templates) {
    echo $OUTPUT->notification(get_string('noroletemplates', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-template-table';
$table->head = [
    get_string('businessrolename', 'local_kbacorporate'),
    get_string('businessrolecode', 'local_kbacorporate'),
    get_string('roletemplateversion', 'local_kbacorporate'),
    get_string('rolecompetencies', 'local_kbacorporate'),
    get_string('companyroletemplateversion', 'local_kbacorporate'),
    get_string('roletemplatestatus', 'local_kbacorporate'),
    get_string('status'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($templates as $template) {
    $templateid = (int)$template->id;
    $profileurl = new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $templateid,
        'companyid' => $companyid,
    ]);
    $actions = [html_writer::link($profileurl, get_string('viewroletemplate', 'local_kbacorporate'))];
    $companyversion = '—';
    $templatestatus = get_string('roletemplatenotused', 'local_kbacorporate');
    if (!empty($copies[$templateid])) {
        $copy = $copies[$templateid];
        $companyversion = (int)$copy->appliedversion;
        $isoutdated = (int)$template->templateversion > (int)$copy->appliedversion;
        $templatestatus = $isoutdated ? get_string('roletemplateoutdated', 'local_kbacorporate') :
            get_string('roletemplatecurrent', 'local_kbacorporate');
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
            'roleid' => (int)$copy->id,
            'companyid' => $companyid,
        ]), get_string('opencompanyrole', 'local_kbacorporate'));
        if ($isoutdated) {
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_preview.php', [
                'roleid' => (int)$copy->id,
                'companyid' => $companyid,
            ]), get_string('roletemplatepreview', 'local_kbacorporate'));
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_templates.php', [
                'companyid' => $companyid,
                'apply' => (int)$copy->id,
                'sesskey' => sesskey(),
            ]), get_string('applyroletemplateupdate', 'local_kbacorporate'), [
                'onclick' => 'return confirm(' . json_encode(
                    get_string('confirmapplyroletemplateupdate', 'local_kbacorporate')
                ) . ');',
            ]);
        }
    } else if (!empty($template->active)) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_templates.php', [
            'companyid' => $companyid,
            'clone' => $templateid,
            'sesskey' => sesskey(),
        ]), get_string('createrolefromtemplate', 'local_kbacorporate'));
    }
    if (is_siteadmin()) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_history.php', [
            'templateid' => $templateid,
            'companyid' => $companyid,
        ]), get_string('roletemplatehistory', 'local_kbacorporate'));
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_rollout.php', [
            'templateid' => $templateid,
            'companyid' => $companyid,
        ]), get_string('roletemplateusage', 'local_kbacorporate'));
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_edit.php', [
            'id' => $templateid,
            'returncompanyid' => $companyid,
        ]), get_string('edit'));
    }
    $table->data[] = [
        html_writer::link($profileurl, format_string($template->name)),
        s($template->code),
        (int)$template->templateversion,
        $DB->count_records('local_kbacorp_rolecomp', ['roleid' => $templateid]),
        $companyversion,
        $templatestatus,
        !empty($template->active) ? get_string('active', 'local_kbacorporate') :
            get_string('inactive', 'local_kbacorporate'),
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
