<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);
if (!is_siteadmin()) {
    throw new moodle_exception('roletemplaterolloutadminonly', 'local_kbacorporate');
}

$templateid = required_param('templateid', PARAM_INT);
$companyid = optional_param('companyid', 0, PARAM_INT);
$applyroleid = optional_param('apply', 0, PARAM_INT);
$template = \local_kbacorporate\local\business_roles::get_role($templateid);
if ((int)$template->companyid !== 0) {
    throw new moodle_exception('roletemplateglobalrequired', 'local_kbacorporate');
}

$baseparams = ['templateid' => $templateid];
if ($companyid > 0) {
    $baseparams['companyid'] = $companyid;
}
$baseurl = new moodle_url('/local/kbacorporate/role_template_rollout.php', $baseparams);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roletemplateusage', 'local_kbacorporate'));
$PAGE->set_heading(format_string($SITE->fullname));

if ($applyroleid) {
    require_sesskey();
    $role = \local_kbacorporate\local\business_roles::get_role($applyroleid);
    if ((int)$role->templateid !== $templateid || (int)$role->companyid <= 0) {
        throw new moodle_exception('rolenotfromtemplate', 'local_kbacorporate');
    }
    $result = \local_kbacorporate\local\business_roles::apply_template_update($applyroleid);
    redirect($baseurl, get_string('roletemplateupdated', 'local_kbacorporate', $result));
}

$rollout = \local_kbacorporate\local\business_roles::get_template_rollout($templateid);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
if ($companyid > 0) {
    echo html_writer::div(html_writer::link(
        new moodle_url('/local/kbacorporate/role_templates.php', ['companyid' => $companyid]),
        '← ' . get_string('roletemplatelibrary', 'local_kbacorporate')
    ), 'kbacorp-backlink');
}
echo $OUTPUT->heading(get_string('roletemplateusagefor', 'local_kbacorporate', format_string($template->name)));
echo html_writer::div(get_string('roletemplaterolloutdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::div(
    get_string('roletemplateversion', 'local_kbacorporate') . ': ' . (int)$template->templateversion,
    'kbacorp-role-note'
);

if (!$rollout) {
    echo $OUTPUT->notification(get_string('roletemplatenocompanycopies', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-rollout-table';
$table->head = [
    get_string('company', 'local_kbacorporate'),
    get_string('businessrolename', 'local_kbacorporate'),
    get_string('companyroletemplateversion', 'local_kbacorporate'),
    get_string('roletemplateversion', 'local_kbacorporate'),
    get_string('roletemplatestatus', 'local_kbacorporate'),
    get_string('status'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($rollout as $copy) {
    $actions = [html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => (int)$copy->id,
        'companyid' => (int)$copy->companyid,
    ]), get_string('opencompanyrole', 'local_kbacorporate'))];
    if (!empty($copy->updateavailable)) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_preview.php', [
            'roleid' => (int)$copy->id,
            'companyid' => (int)$copy->companyid,
        ]), get_string('roletemplatepreview', 'local_kbacorporate'));
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_rollout.php', [
            'templateid' => $templateid,
            'companyid' => $companyid,
            'apply' => (int)$copy->id,
            'sesskey' => sesskey(),
        ]), get_string('applyroletemplateupdate', 'local_kbacorporate'), [
            'onclick' => 'return confirm(' . json_encode(
                get_string('confirmapplyroletemplateupdate', 'local_kbacorporate')
            ) . ');',
        ]);
    }
    $table->data[] = [
        format_string($copy->companyname),
        format_string($copy->name),
        (int)$copy->appliedversion,
        (int)$copy->availableversion,
        !empty($copy->updateavailable) ? get_string('roletemplateoutdated', 'local_kbacorporate') :
            get_string('roletemplatecurrent', 'local_kbacorporate'),
        !empty($copy->active) ? get_string('active', 'local_kbacorporate') :
            get_string('inactive', 'local_kbacorporate'),
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
