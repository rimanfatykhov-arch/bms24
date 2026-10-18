<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$roleid = required_param('roleid', PARAM_INT);
$companyid = required_param('companyid', PARAM_INT);
$apply = optional_param('apply', 0, PARAM_BOOL);
$role = \local_kbacorporate\local\business_roles::get_role($roleid);
if ((int)$role->companyid !== $companyid) {
    throw new moodle_exception('invalidcompanycontext', 'local_kbacorporate');
}
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$preview = \local_kbacorporate\local\business_roles::preview_template_update($roleid);
$baseurl = new moodle_url('/local/kbacorporate/role_template_preview.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roletemplatepreview', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

if ($apply) {
    require_sesskey();
    $result = \local_kbacorporate\local\business_roles::apply_template_update($roleid);
    redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
    ]), get_string('roletemplateupdated', 'local_kbacorporate', $result));
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
    ]),
    '← ' . get_string('opencompanyrole', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('roletemplatepreviewfor', 'local_kbacorporate', format_string($role->name)));
echo html_writer::div(get_string('roletemplatepreviewversions', 'local_kbacorporate', (object)[
    'applied' => (int)$role->appliedversion,
    'available' => (int)$preview->template->templateversion,
]), 'kbacorp-role-note');

$sections = [
    'added' => get_string('roletemplateadded', 'local_kbacorporate'),
    'changed' => get_string('roletemplatechanged', 'local_kbacorporate'),
    'preserved' => get_string('roletemplatepreserved', 'local_kbacorporate'),
    'retained' => get_string('roletemplateretained', 'local_kbacorporate'),
];
foreach ($sections as $property => $heading) {
    echo $OUTPUT->heading($heading . ': ' . count($preview->{$property}), 3);
    if (!$preview->{$property}) {
        continue;
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencyname', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'),
        get_string('rolerequired', 'local_kbacorporate'),
    ];
    foreach ($preview->{$property} as $item) {
        $target = (int)$item->targetlevel;
        $priority = get_string('rolepriority_' . $item->priority, 'local_kbacorporate');
        $required = !empty($item->required) ? get_string('yes') : get_string('no');
        if (($property === 'changed' || $property === 'preserved') && !empty($item->previous)) {
            $target = (int)$item->previous->targetlevel . ' → ' . (int)$item->targetlevel;
            $priority = get_string('rolepriority_' . $item->previous->priority, 'local_kbacorporate') .
                ' → ' . get_string('rolepriority_' . $item->priority, 'local_kbacorporate');
            $required = (!empty($item->previous->required) ? get_string('yes') : get_string('no')) .
                ' → ' . (!empty($item->required) ? get_string('yes') : get_string('no'));
        }
        $table->data[] = [format_string($item->shortname), $target, $priority, $required];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->notification(get_string('roletemplatepreviewwarning', 'local_kbacorporate'), 'info');
if ((int)$preview->template->templateversion > (int)$role->appliedversion) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/role_template_preview.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
        'apply' => 1,
        'sesskey' => sesskey(),
    ]), get_string('applyroletemplateupdate', 'local_kbacorporate'), [
        'class' => 'btn btn-primary',
        'onclick' => 'return confirm(' . json_encode(
            get_string('confirmapplyroletemplateupdate', 'local_kbacorporate')
        ) . ');',
    ]);
} else {
    echo $OUTPUT->notification(get_string('roletemplatecurrent', 'local_kbacorporate'), 'success');
}
echo $OUTPUT->footer();
