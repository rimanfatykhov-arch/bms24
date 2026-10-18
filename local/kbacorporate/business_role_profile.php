<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$roleid = required_param('roleid', PARAM_INT);
$companyid = required_param('companyid', PARAM_INT);
$editcompid = optional_param('editcomp', 0, PARAM_INT);
$removecompid = optional_param('removecomp', 0, PARAM_INT);
$applytemplate = optional_param('applytemplate', 0, PARAM_BOOL);
$createtemplate = optional_param('createtemplate', 0, PARAM_BOOL);
$role = \local_kbacorporate\local\business_roles::get_role($roleid);
$isglobal = (int)$role->companyid === 0;

if (!$isglobal && (int)$role->companyid !== $companyid) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$canedit = !$isglobal || is_siteadmin();

$baseparams = ['roleid' => $roleid, 'companyid' => $companyid];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/business_role_profile.php', $baseparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(format_string($role->name));
$PAGE->set_heading(format_string($company->name));

if ($applytemplate) {
    require_sesskey();
    if ($isglobal || (int)$role->companyid !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    $result = \local_kbacorporate\local\business_roles::apply_template_update($roleid);
    redirect(
        new moodle_url('/local/kbacorporate/business_role_profile.php', $baseparams),
        get_string('roletemplateupdated', 'local_kbacorporate', $result)
    );
}

if ($createtemplate) {
    require_sesskey();
    if (!is_siteadmin() || $isglobal) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    $templateid = \local_kbacorporate\local\business_roles::create_template_from_company_role($roleid);
    redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $templateid,
        'companyid' => $companyid,
    ]), get_string('roletemplatecreatedfromcompany', 'local_kbacorporate'));
}

if ($removecompid) {
    require_sesskey();
    if (!$canedit) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    \local_kbacorporate\local\business_roles::remove_role_competency(
        $roleid,
        $removecompid,
        $isglobal ? get_string('roletemplatecompetencyremovednote', 'local_kbacorporate') : '',
        (int)$USER->id
    );
    redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', $baseparams),
        get_string('deleted', 'local_kbacorporate'));
}

$competencyrecords = $DB->get_records_sql(
    "SELECT c.id, c.shortname, c.idnumber, f.shortname AS frameworkname
       FROM {competency} c
  LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
   ORDER BY f.shortname ASC, c.shortname ASC"
);
$competencyoptions = [];
foreach ($competencyrecords as $competency) {
    if (\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$competency->idnumber)) {
        continue;
    }
    $label = ($competency->frameworkname ? '[' . format_string($competency->frameworkname) . '] ' : '') .
        ($competency->idnumber ? s($competency->idnumber) . ' — ' : '') . format_string($competency->shortname);
    $competencyoptions[(int)$competency->id] = $label;
}

$editingrecord = null;
if ($editcompid) {
    if (!$canedit) {
        throw new moodle_exception('globalrolereadonly', 'local_kbacorporate');
    }
    $editingrecord = $DB->get_record('local_kbacorp_rolecomp', [
        'id' => $editcompid,
        'roleid' => $roleid,
    ], '*', MUST_EXIST);
}
$form = null;
if ($canedit) {
    $form = new \local_kbacorporate\form\role_competency_form(null, [
        'roleid' => $roleid,
        'companyid' => $companyid,
        'competencies' => $competencyoptions,
        'editing' => (bool)$editingrecord,
        'isglobal' => $isglobal,
    ]);
    if ($editingrecord) {
        $editingrecord->rolecompetencyid = $editingrecord->id;
        $form->set_data($editingrecord);
    }
    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', $baseparams));
    } else if ($data = $form->get_data()) {
        $competencyid = 0;
        if (!empty($data->rolecompetencyid)) {
            $savedrecord = $DB->get_record('local_kbacorp_rolecomp', [
                'id' => (int)$data->rolecompetencyid,
                'roleid' => $roleid,
            ], '*', MUST_EXIST);
            $competencyid = (int)$savedrecord->competencyid;
        } else {
            $competencyid = (int)$data->competencyid;
        }
        \local_kbacorporate\local\business_roles::set_role_competency(
            $roleid,
            $competencyid,
            (int)$data->targetlevel,
            (string)$data->priority,
            !empty($data->required),
            'manual',
            (string)($data->changenote ?? ''),
            (int)$USER->id
        );
        redirect(new moodle_url('/local/kbacorporate/business_role_profile.php', $baseparams),
            get_string('saved', 'local_kbacorporate'));
    }
}

$requirements = \local_kbacorporate\local\business_roles::get_role_competencies($roleid);
$domainrequirements = array_values(array_filter($requirements, static function($requirement) {
    return \local_kbacorporate\local\business_roles::is_domain_competency_code((string)$requirement->idnumber);
}));

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
    '← ' . get_string('businessroles', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($role->name));
echo html_writer::div(s($role->code), 'kbacorp-role-code');
if (!empty($role->description)) {
    echo html_writer::div(format_text($role->description, FORMAT_PLAIN), 'kbacorp-pagelead');
}
echo html_writer::div(
    $isglobal ? get_string('businessrolescopeglobal', 'local_kbacorporate') :
        get_string('businessrolescopecompany', 'local_kbacorporate'),
    'kbacorp-role-scope'
);
if ($isglobal) {
    echo html_writer::div(
        get_string('roletemplateversion', 'local_kbacorporate') . ': ' . (int)$role->templateversion,
        'kbacorp-role-note'
    );
} else if (!empty($role->templateid)) {
    $template = $DB->get_record('local_kbacorp_busrole', [
        'id' => (int)$role->templateid,
        'companyid' => 0,
    ]);
    if ($template) {
        $templateinfo = get_string('rolecreatedfromtemplate', 'local_kbacorporate', (object)[
            'name' => format_string($template->name),
            'applied' => (int)$role->appliedversion,
            'available' => (int)$template->templateversion,
        ]);
        echo html_writer::div($templateinfo, 'kbacorp-role-note');
        if ((int)$template->templateversion > (int)$role->appliedversion) {
            echo $OUTPUT->notification(get_string('roletemplateupdateavailable', 'local_kbacorporate'), 'info');
            echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_profile.php', [
                'roleid' => $roleid,
                'companyid' => $companyid,
                'applytemplate' => 1,
                'sesskey' => sesskey(),
            ]), get_string('applyroletemplateupdate', 'local_kbacorporate'), 'get');
        }
    }
}

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_templates.php', [
    'companyid' => $companyid,
]), get_string('roletemplatelibrary', 'local_kbacorporate'), 'get');
if ($isglobal && is_siteadmin()) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_template_history.php', [
        'templateid' => $roleid,
        'companyid' => $companyid,
    ]), get_string('roletemplatehistory', 'local_kbacorporate'), 'get');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_template_rollout.php', [
        'templateid' => $roleid,
        'companyid' => $companyid,
    ]), get_string('roletemplateusage', 'local_kbacorporate'), 'get');
}
if ($canedit) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_edit.php', [
        'id' => $roleid,
        'returncompanyid' => $companyid,
    ]), get_string('editbusinessrole', 'local_kbacorporate'), 'get');
}
if (!$isglobal && is_siteadmin()) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid,
        'companyid' => $companyid,
        'createtemplate' => 1,
        'sesskey' => sesskey(),
    ]), get_string('saveasglobalroletemplate', 'local_kbacorporate'), [
        'class' => 'btn btn-secondary',
        'onclick' => 'return confirm(' . json_encode(
            get_string('confirmsaveasglobalroletemplate', 'local_kbacorporate')
        ) . ');',
    ]);
}
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
    'companyid' => $companyid,
]), get_string('businessroleassignments', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]), get_string('roleprofileintegrity', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_profile_bulk_edit.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]), get_string('roleprofilebulkedit', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_course_coverage.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]), get_string('rolecoursecoverage', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_course_coverage_integrity.php', [
    'roleid' => $roleid,
    'companyid' => $companyid,
]), get_string('coursecoverageintegrity', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

if (!$canedit) {
    echo $OUTPUT->notification(get_string('globalrolereadonly', 'local_kbacorporate'), 'info');
}

echo $OUTPUT->heading(get_string('rolecompetencyprofile', 'local_kbacorporate'), 3);
if ($domainrequirements) {
    $warning = get_string('roleprofiledomainwarning', 'local_kbacorporate', count($domainrequirements)) . ' ' .
        html_writer::link(new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
            'roleid' => $roleid, 'companyid' => $companyid,
        ]), get_string('roleprofileopenintegrity', 'local_kbacorporate'));
    echo $OUTPUT->notification($warning, 'warning');
}
if (!$requirements) {
    echo $OUTPUT->notification(get_string('norolecompetencies', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-profile-table';
    $table->head = [
        get_string('competencyname', 'local_kbacorporate'),
        get_string('competencyframeworkname', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'),
        get_string('rolerequired', 'local_kbacorporate'),
        get_string('source', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($requirements as $requirement) {
        $scaleid = (int)($requirement->competencyscaleid ?: $requirement->frameworkscaleid);
        $isdomain = \local_kbacorporate\local\business_roles::is_domain_competency_code((string)$requirement->idnumber);
        $actions = [];
        if ($canedit) {
            if (!$isdomain) {
                $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
                    'roleid' => $roleid,
                    'companyid' => $companyid,
                    'editcomp' => $requirement->id,
                ]), get_string('edit'));
            }
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
                'roleid' => $roleid,
                'companyid' => $companyid,
                'removecomp' => $requirement->id,
                'sesskey' => sesskey(),
            ]), get_string('delete'), [
                'onclick' => 'return confirm(' . json_encode(
                    get_string('confirmremoverolecompetency', 'local_kbacorporate')
                ) . ');',
            ]);
        }
        $competencycell = html_writer::tag('strong', format_string($requirement->shortname)) .
            ($requirement->idnumber ? html_writer::div(s($requirement->idnumber), 'kbacorp-muted') : '');
        if ($isdomain) {
            $competencycell .= html_writer::div(get_string('roleprofiledomainnode', 'local_kbacorporate'),
                'badge badge-warning');
        }
        $table->data[] = [
            $competencycell,
            format_string($requirement->frameworkname ?: '—'),
            s(\local_kbacorporate\local\competencies::level_label((int)$requirement->targetlevel, $scaleid)),
            get_string('rolepriority_' . $requirement->priority, 'local_kbacorporate'),
            !empty($requirement->required) ? get_string('yes') : get_string('no'),
            s($requirement->source),
            $actions ? implode(' · ', $actions) : '—',
        ];
    }
    echo html_writer::table($table);
}
if ($form) {
    echo $OUTPUT->heading($editingrecord ? get_string('editrolecompetency', 'local_kbacorporate') :
        get_string('addrolecompetency', 'local_kbacorporate'), 3);
    $form->display();
}
echo $OUTPUT->footer();
