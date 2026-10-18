<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);
if (!is_siteadmin()) {
    throw new moodle_exception('roletemplaterolloutadminonly', 'local_kbacorporate');
}

$templateid = required_param('templateid', PARAM_INT);
$companyid = required_param('companyid', PARAM_INT);
$compareid = optional_param('compare', 0, PARAM_INT);
$template = \local_kbacorporate\local\business_roles::get_role($templateid);
if ((int)$template->companyid !== 0) {
    throw new moodle_exception('roletemplateglobalrequired', 'local_kbacorporate');
}
\local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

$baseurl = new moodle_url('/local/kbacorporate/role_template_history.php', [
    'templateid' => $templateid,
    'companyid' => $companyid,
]);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('roletemplatehistory', 'local_kbacorporate'));
$PAGE->set_heading(format_string($SITE->fullname));

$versions = \local_kbacorporate\local\business_roles::get_template_versions($templateid);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/role_templates.php', ['companyid' => $companyid]),
    '← ' . get_string('roletemplatelibrary', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('roletemplatehistoryfor', 'local_kbacorporate', format_string($template->name)));
echo html_writer::div(get_string('roletemplatehistorydesc', 'local_kbacorporate'), 'kbacorp-pagelead');

if (!$versions) {
    echo $OUTPUT->notification(get_string('roletemplatenoversions', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-version-table';
$table->head = [
    get_string('roletemplateversion', 'local_kbacorporate'),
    get_string('date'),
    get_string('roletemplateauthor', 'local_kbacorporate'),
    get_string('roletemplatechangenote', 'local_kbacorporate'),
    get_string('rolecompetencies', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($versions as $index => $version) {
    $author = get_string('roletemplateauthorunknown', 'local_kbacorporate');
    if ((int)$version->createdby > 0) {
        $user = $DB->get_record('user', ['id' => (int)$version->createdby]);
        if ($user) {
            $author = fullname($user);
        }
    }
    $actions = [];
    if (isset($versions[$index + 1])) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_template_history.php', [
            'templateid' => $templateid,
            'companyid' => $companyid,
            'compare' => (int)$version->id,
        ]), get_string('roletemplatecompareprevious', 'local_kbacorporate'));
    }
    $table->data[] = [
        (int)$version->version,
        userdate((int)$version->timecreated),
        s($author),
        $version->changenote ? s($version->changenote) : '—',
        $DB->count_records('local_kbacorp_rolevercomp', ['roleversionid' => (int)$version->id]),
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);

if ($compareid) {
    $to = $DB->get_record('local_kbacorp_rolever', [
        'id' => $compareid,
        'templateid' => $templateid,
    ], '*', MUST_EXIST);
    $previous = $DB->get_records_select('local_kbacorp_rolever',
        'templateid = :templateid AND version < :version',
        ['templateid' => $templateid, 'version' => (int)$to->version], 'version DESC', '*', 0, 1);
    $from = $previous ? reset($previous) : null;
    if ($from) {
        $diff = \local_kbacorporate\local\business_roles::compare_template_versions(
            (int)$from->id, (int)$to->id
        );
        echo $OUTPUT->heading(get_string('roletemplatecomparison', 'local_kbacorporate', (object)[
            'from' => (int)$from->version,
            'to' => (int)$to->version,
        ]), 3);
        $sections = [
            'added' => get_string('roletemplateadded', 'local_kbacorporate'),
            'changed' => get_string('roletemplatechanged', 'local_kbacorporate'),
            'removed' => get_string('roletemplateremoved', 'local_kbacorporate'),
        ];
        foreach ($sections as $property => $heading) {
            echo $OUTPUT->heading($heading . ': ' . count($diff->{$property}), 4);
            if (!$diff->{$property}) {
                continue;
            }
            $difftable = new html_table();
            $difftable->attributes['class'] = 'generaltable kbacorp-report';
            $difftable->head = [
                get_string('competencyname', 'local_kbacorporate'),
                get_string('targetlevel', 'local_kbacorporate'),
                get_string('rolepriority', 'local_kbacorporate'),
                get_string('rolerequired', 'local_kbacorporate'),
            ];
            foreach ($diff->{$property} as $item) {
                $target = (int)$item->targetlevel;
                $priority = get_string('rolepriority_' . $item->priority, 'local_kbacorporate');
                $required = !empty($item->required) ? get_string('yes') : get_string('no');
                if ($property === 'changed' && !empty($item->previous)) {
                    $target = (int)$item->previous->targetlevel . ' → ' . (int)$item->targetlevel;
                    $priority = get_string('rolepriority_' . $item->previous->priority, 'local_kbacorporate') .
                        ' → ' . get_string('rolepriority_' . $item->priority, 'local_kbacorporate');
                    $required = (!empty($item->previous->required) ? get_string('yes') : get_string('no')) .
                        ' → ' . (!empty($item->required) ? get_string('yes') : get_string('no'));
                }
                $difftable->data[] = [
                    format_string($item->shortname),
                    $target,
                    $priority,
                    $required,
                ];
            }
            echo html_writer::table($difftable);
        }
        if ($diff->removed) {
            echo $OUTPUT->notification(get_string('roletemplateremovedwarning', 'local_kbacorporate'), 'warning');
        }
    }
}

echo $OUTPUT->footer();
