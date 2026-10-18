<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$toolid = required_param('toolid', PARAM_INT);
$criterionid = required_param('criterionid', PARAM_INT);
$removeid = optional_param('remove', 0, PARAM_INT);
$editid = optional_param('edit', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$criterion = $DB->get_record_sql(
    "SELECT c.*, t.companyid AS toolcompanyid, t.name AS toolname
       FROM {local_kbacorp_bms_crit} c
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
      WHERE c.id = :criterionid AND c.toolid = :toolid",
    ['criterionid' => $criterionid, 'toolid' => $toolid], MUST_EXIST);
if ((int)$criterion->toolcompanyid !== $companyid) {
    throw new moodle_exception('bmstoolreadonly', 'local_kbacorporate');
}

$baseparams = ['companyid' => $companyid, 'toolid' => $toolid, 'criterionid' => $criterionid];
if ($removeid) {
    require_sesskey();
    \local_kbacorporate\local\implementation_audits::remove_criterion_competency(
        $criterionid, $removeid, $companyid);
    redirect(new moodle_url('/local/kbacorporate/bms_competencies.php', $baseparams),
        get_string('deleted', 'local_kbacorporate'));
}

$competencyrecords = $DB->get_records_sql(
    "SELECT c.id, c.shortname, c.idnumber, f.shortname AS frameworkname
       FROM {competency} c
  LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
   ORDER BY f.shortname ASC, c.shortname ASC");
$competencyoptions = [];
foreach ($competencyrecords as $competency) {
    if (preg_match('/^BMS-D\d+$/i', (string)$competency->idnumber)) {
        continue;
    }
    $competencyoptions[(int)$competency->id] =
        ($competency->frameworkname ? '[' . format_string($competency->frameworkname) . '] ' : '') .
        ($competency->idnumber ? s($competency->idnumber) . ' — ' : '') . format_string($competency->shortname);
}
$editmapping = null;
if ($editid) {
    $editmapping = $DB->get_record('local_kbacorp_bms_comp', ['id' => $editid, 'criterionid' => $criterionid], '*', MUST_EXIST);
}
$form = new \local_kbacorporate\form\bms_competency_form(null, $baseparams + [
    'competencies' => $competencyoptions, 'mappingid' => $editid,
]);
if ($editmapping) {
    $form->set_data((object)[
        'companyid' => $companyid, 'toolid' => $toolid, 'criterionid' => $criterionid, 'mappingid' => $editid,
        'competencyid' => $editmapping->competencyid, 'targetlevel' => $editmapping->targetlevel,
        'priority' => $editmapping->priority, 'required' => $editmapping->required,
    ]);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/bms_tool.php', ['id' => $toolid, 'companyid' => $companyid]));
} else if ($data = $form->get_data()) {
    \local_kbacorporate\local\implementation_audits::save_criterion_competency($data);
    redirect(new moodle_url('/local/kbacorporate/bms_competencies.php', $baseparams),
        get_string('saved', 'local_kbacorporate'));
}

$mappings = \local_kbacorporate\local\implementation_audits::get_criterion_competencies($criterionid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_competencies.php', $baseparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmscriterioncompetencies', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tool.php', [
    'id' => $toolid, 'companyid' => $companyid,
]), '← ' . format_string($criterion->toolname)), 'kbacorp-backlink');
echo html_writer::div(get_string('bmstoolcompetencymap', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(format_string($criterion->name), 2);
echo html_writer::tag('p', get_string('bmscriterioncompetenciesdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);

if ($mappings) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('competencyname', 'local_kbacorporate'), get_string('competencyframework',
        'local_kbacorporate'), get_string('targetlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'), get_string('required'), get_string('actions')];
    foreach ($mappings as $mapping) {
        $removeurl = new moodle_url('/local/kbacorporate/bms_competencies.php', $baseparams + [
            'remove' => $mapping->id, 'sesskey' => sesskey(),
        ]);
        $editurl = new moodle_url('/local/kbacorporate/bms_competencies.php', $baseparams + ['edit' => $mapping->id]);
        $table->data[] = [format_string($mapping->shortname), format_string($mapping->frameworkname ?: '—'),
            (int)$mapping->targetlevel, get_string('rolepriority_' . $mapping->priority, 'local_kbacorporate'),
            $mapping->required ? get_string('yes') : get_string('no'),
            html_writer::link($editurl, get_string('edit')) . ' · ' . html_writer::link($removeurl, get_string('delete'))];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
} else {
    echo $OUTPUT->notification(get_string('bmscriterioncompetenciesempty', 'local_kbacorporate'), 'info');
}
echo $OUTPUT->heading(get_string($editid ? 'edit' : 'linkcompetency', 'local_kbacorporate'), 3);
$form->display();
echo $OUTPUT->footer();
