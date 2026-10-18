<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$frameworkid = required_param('frameworkid', PARAM_INT);
$cleanup = optional_param('cleanupdomains', 0, PARAM_BOOL);

$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$framework = $DB->get_record('competency_framework', ['id' => $frameworkid], '*', MUST_EXIST);

if ($cleanup) {
    require_sesskey();
    $rows = $DB->get_records_sql(
        "SELECT bc.id, bc.criterionid, c.toolid
           FROM {local_kbacorp_bms_comp} bc
           JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
           JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
           JOIN {competency} comp ON comp.id = bc.competencyid
          WHERE t.companyid = :companyid
            AND comp.competencyframeworkid = :frameworkid
            AND " . $DB->sql_like('comp.idnumber', ':domainprefix', false),
        ['companyid' => $companyid, 'frameworkid' => $frameworkid, 'domainprefix' => 'BMS-D%']
    );
    $removed = 0;
    foreach ($rows as $row) {
        $compcode = $DB->get_field_sql(
            "SELECT comp.idnumber
               FROM {local_kbacorp_bms_comp} bc
               JOIN {competency} comp ON comp.id = bc.competencyid
              WHERE bc.id = :id", ['id' => $row->id]);
        if (!preg_match('/^BMS-D\\d+$/i', (string)$compcode)) {
            continue;
        }
        \local_kbacorporate\local\implementation_audits::remove_criterion_competency(
            (int)$row->criterionid, (int)$row->id, $companyid);
        $removed++;
    }
    redirect(new moodle_url('/local/kbacorporate/framework_mapping_integrity.php', [
        'companyid' => $companyid, 'frameworkid' => $frameworkid,
    ]), get_string('bmsintegritycleanupdone', 'local_kbacorporate', $removed));
}

$params = ['companyid' => $companyid, 'frameworkid' => $frameworkid];
$criterioncount = (int)$DB->count_records_sql(
    "SELECT COUNT(1)
       FROM {local_kbacorp_bms_crit} c
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
      WHERE t.companyid IN (0,:companyid) AND c.active = 1", ['companyid' => $companyid]);

$mappedcount = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id)
       FROM {local_kbacorp_bms_crit} c
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
       JOIN {local_kbacorp_bms_comp} bc ON bc.criterionid = c.id
       JOIN {competency} comp ON comp.id = bc.competencyid
      WHERE t.companyid IN (0,:companyid)
        AND c.active = 1
        AND comp.competencyframeworkid = :frameworkid
        AND NOT " . $DB->sql_like('comp.idnumber', ':domainprefix', false),
    ['companyid' => $companyid, 'frameworkid' => $frameworkid, 'domainprefix' => 'BMS-D%']);

$domainrows = $DB->get_records_sql(
    "SELECT bc.id, bc.criterionid, c.code AS criterioncode, c.name AS criterionname,
            t.code AS toolcode, comp.idnumber AS competencycode, comp.shortname AS competencyname
       FROM {local_kbacorp_bms_comp} bc
       JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
       JOIN {competency} comp ON comp.id = bc.competencyid
      WHERE t.companyid IN (0,:companyid)
        AND comp.competencyframeworkid = :frameworkid
        AND " . $DB->sql_like('comp.idnumber', ':domainprefix', false) . "
   ORDER BY t.code, c.sortorder, c.code",
    ['companyid' => $companyid, 'frameworkid' => $frameworkid, 'domainprefix' => 'BMS-D%']);
$domainrows = array_values(array_filter($domainrows, static function($row) {
    return preg_match('/^BMS-D\\d+$/i', (string)$row->competencycode);
}));

$duplicates = $DB->get_records_sql(
    "SELECT MIN(bc.id) AS id, bc.criterionid, bc.competencyid, COUNT(1) AS duplicatecount,
            c.code AS criterioncode, t.code AS toolcode, comp.idnumber AS competencycode
       FROM {local_kbacorp_bms_comp} bc
       JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
       JOIN {competency} comp ON comp.id = bc.competencyid
      WHERE t.companyid IN (0,:companyid)
        AND comp.competencyframeworkid = :frameworkid
   GROUP BY bc.criterionid, bc.competencyid, c.code, t.code, comp.idnumber
     HAVING COUNT(1) > 1",
    ['companyid' => $companyid, 'frameworkid' => $frameworkid]);

$invalidlevels = (int)$DB->count_records_sql(
    "SELECT COUNT(1)
       FROM {local_kbacorp_bms_comp} bc
       JOIN {local_kbacorp_bms_crit} c ON c.id = bc.criterionid
       JOIN {local_kbacorp_bms_tool} t ON t.id = c.toolid
       JOIN {competency} comp ON comp.id = bc.competencyid
      WHERE t.companyid IN (0,:companyid)
        AND comp.competencyframeworkid = :frameworkid
        AND (bc.targetlevel < 0 OR bc.targetlevel > 9)", $params);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/framework_mapping_integrity.php', $params));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmsintegritytitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'framework');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/framework.php', $params),
    '← ' . get_string('bmsframeworkmanager', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('bmsintegritytitle', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('bmsintegritydesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);

echo html_writer::start_div('kbacorp-summary kbacorp-bms-tool-summary');
$stats = [
    [$mappedcount . '/' . $criterioncount, 'bmsintegritymapped', $mappedcount === $criterioncount ? 'confirmed' : 'developing'],
    [count($domainrows), 'bmsintegritydomainnodes', count($domainrows) ? 'developing' : 'confirmed'],
    [count($duplicates), 'bmsintegrityduplicates', count($duplicates) ? 'developing' : 'confirmed'],
    [$invalidlevels, 'bmsintegrityinvalidlevels', $invalidlevels ? 'developing' : 'confirmed'],
];
foreach ($stats as [$value, $label, $accent]) {
    echo html_writer::div(html_writer::div((string)$value, 'value') .
        html_writer::div(get_string($label, 'local_kbacorporate'), 'label'),
        'kbacorp-card kbacorp-card-accent-' . $accent);
}
echo html_writer::end_div();

if ($mappedcount === $criterioncount && !$domainrows && !$duplicates && !$invalidlevels) {
    echo $OUTPUT->notification(get_string('bmsintegrityok', 'local_kbacorporate'), 'success');
} else {
    echo $OUTPUT->notification(get_string('bmsintegrityattention', 'local_kbacorporate'), 'warning');
}

if ($domainrows) {
    echo $OUTPUT->heading(get_string('bmsintegritydomainnodestitle', 'local_kbacorporate'), 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('bmstoolcode', 'local_kbacorporate'), get_string('bmscriterioncode', 'local_kbacorporate'),
        get_string('bmscriterionname', 'local_kbacorporate'), get_string('competencyname', 'local_kbacorporate')];
    foreach ($domainrows as $row) {
        $table->data[] = [s($row->toolcode), s($row->criterioncode), format_string($row->criterionname),
            s($row->competencycode) . ' — ' . format_string($row->competencyname)];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
    $cleanupurl = new moodle_url('/local/kbacorporate/framework_mapping_integrity.php', $params + [
        'cleanupdomains' => 1, 'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->single_button($cleanupurl, get_string('bmsintegritycleanupdomains', 'local_kbacorporate'), 'post');
    echo html_writer::tag('p', get_string('bmsintegritycleanupnote', 'local_kbacorporate'), ['class' => 'text-muted mt-2']);
}

if ($duplicates) {
    echo $OUTPUT->heading(get_string('bmsintegrityduplicatestitle', 'local_kbacorporate'), 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('bmstoolcode', 'local_kbacorporate'), get_string('bmscriterioncode', 'local_kbacorporate'),
        get_string('competencyname', 'local_kbacorporate'), get_string('bmsintegrityduplicatecount', 'local_kbacorporate')];
    foreach ($duplicates as $row) {
        $table->data[] = [s($row->toolcode), s($row->criterioncode), s($row->competencycode), (int)$row->duplicatecount];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo $OUTPUT->footer();
