<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$id = required_param('id', PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$tool = $DB->get_record_select('local_kbacorp_bms_tool',
    'id = :id AND companyid IN (0, :companyid)', ['id' => $id, 'companyid' => $companyid], '*', MUST_EXIST);
$criteria = \local_kbacorporate\local\implementation_audits::get_criteria($id);
if ($criteria && empty($tool->currentversionid)) {
    \local_kbacorporate\local\implementation_audits::ensure_versioned($id, $USER->id);
    $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $id], '*', MUST_EXIST);
}
$isglobal = (int)$tool->companyid === 0;
$auditcount = $DB->count_records('local_kbacorp_impl_audit', ['companyid' => $companyid, 'toolid' => $id]);
$activecount = count(array_filter($criteria, static function($criterion) { return !empty($criterion->active); }));
$requiredcount = count(array_filter($criteria, static function($criterion) {
    return !empty($criterion->active) && !empty($criterion->required);
}));
$competenciesbycriterion = \local_kbacorporate\local\implementation_audits::get_tool_competencies($id);
$competencymapcount = array_sum(array_map('count', $competenciesbycriterion));
$sections = [];
foreach ($criteria as $criterion) {
    $sectionkey = trim((string)$criterion->sectioncode) !== '' ? (string)$criterion->sectioncode : '__general';
    if (!isset($sections[$sectionkey])) {
        $sections[$sectionkey] = (object)[
            'name' => trim((string)$criterion->sectionname) !== '' ? (string)$criterion->sectionname :
                get_string('bmsgeneralsection', 'local_kbacorporate'),
            'criteria' => [],
        ];
    }
    $sections[$sectionkey]->criteria[] = $criterion;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_tool.php', ['id' => $id, 'companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(format_string($tool->name));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tools.php',
    ['companyid' => $companyid]), '← ' . get_string('bmstoollibrary', 'local_kbacorporate')),
    'kbacorp-backlink');
echo html_writer::start_div('kbacorp-bms-tool-hero');
echo html_writer::start_div('kbacorp-bms-tool-hero-meta');
echo html_writer::span($isglobal ? get_string('bmstoolscopeglobal', 'local_kbacorporate') :
    get_string('bmstoolscopecompany', 'local_kbacorporate'), 'kbacorp-bms-tool-scope');
echo html_writer::span(empty($tool->active) ? get_string('inactive', 'local_kbacorporate') :
    get_string('active', 'local_kbacorporate'), 'kbacorp-bms-tool-status ' .
    (empty($tool->active) ? 'is-inactive' : 'is-active'));
echo html_writer::span(empty($tool->currentversion) ? get_string('bmstoolversionunpublished', 'local_kbacorporate') :
    get_string('bmstoolversioncurrent', 'local_kbacorporate', 'v' . (int)$tool->currentversion),
    'kbacorp-bms-version-badge');
if (!empty($tool->hasdraft)) {
    echo html_writer::span(get_string('bmstooldraftchanges', 'local_kbacorporate'),
        'kbacorp-bms-version-badge is-draft');
}
echo html_writer::end_div();
echo $OUTPUT->heading(format_string($tool->name), 2);
echo html_writer::div(s($tool->code), 'kbacorp-bms-tool-code');
if (trim((string)$tool->description) !== '') {
    echo html_writer::div(format_text($tool->description, FORMAT_PLAIN), 'kbacorp-pagelead');
}
echo html_writer::end_div();

if ($isglobal) {
    echo $OUTPUT->notification(get_string('bmstoolreadonlydesc', 'local_kbacorporate'), 'info');
}
if ($tool->templatecode) {
    echo $OUTPUT->notification(get_string('audittemplateinfo', 'local_kbacorporate', (object)[
        'version' => s($tool->sourceversion), 'scale' => (int)$tool->scalemax,
    ]), 'info');
}
if (!empty($tool->hasdraft)) {
    echo $OUTPUT->notification(get_string('bmstooldraftauditwarning', 'local_kbacorporate'), 'warning');
}

echo html_writer::start_div('kbacorp-summary kbacorp-bms-tool-summary');
foreach ([
    [$activecount, 'bmstoolactivecriteria', 'total'], [$requiredcount, 'bmstoolrequiredcriteria', 'confirmed'],
    [count($sections), 'bmstoolsections', 'developing'], [$competencymapcount, 'bmstoolcompetencylinks', 'notstarted'],
    [$auditcount, 'bmstoolauditscompany', 'total'],
] as [$value, $label, $accent]) {
    echo html_writer::div(html_writer::div((string)$value, 'value') .
        html_writer::div(get_string($label, 'local_kbacorporate'), 'label'),
        'kbacorp-card kbacorp-card-accent-' . $accent);
}
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-bms-tool-settings');
foreach ([
    [get_string('bmsarea', 'local_kbacorporate'), get_string('bmsarea' . $tool->area, 'local_kbacorporate')],
    [get_string('bmsscalemax', 'local_kbacorporate'), '0–' . (int)$tool->scalemax],
    [get_string('bmsattentionthreshold', 'local_kbacorporate'), (string)(int)$tool->attentionthreshold],
    [get_string('bmsindexmethod', 'local_kbacorporate'), get_string(
        $tool->indexmethod === \local_kbacorporate\local\implementation_audits::INDEX_AREA_AVERAGE ?
            'bmsindexmethodareaaverage' : 'bmsindexmethodweighted', 'local_kbacorporate')],
] as [$label, $value]) {
    echo html_writer::div(html_writer::span($label) . html_writer::tag('strong', $value),
        'kbacorp-bms-tool-setting');
}
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tool_versions.php', [
    'toolid' => $id, 'companyid' => $companyid,
]), get_string('bmstoolversions', 'local_kbacorporate'), 'get');
if (!$isglobal) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tool_edit.php', [
        'id' => $id, 'companyid' => $companyid,
    ]), get_string('editbmstool', 'local_kbacorporate'), 'get');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_criterion_edit.php', [
        'toolid' => $id, 'companyid' => $companyid,
    ]), get_string('addbmscriterion', 'local_kbacorporate'), 'get');
}
if (!empty($tool->active) && $activecount > 0) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_audit_edit.php', [
        'companyid' => $companyid, 'toolid' => $id,
    ]), get_string('createauditwithtool', 'local_kbacorporate'), 'get');
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('bmscriteriaandsections', 'local_kbacorporate'), 3);
if (!$sections) {
    echo $OUTPUT->notification(get_string('bmstoolnocriteria', 'local_kbacorporate'), 'info');
} else {
    foreach ($sections as $section) {
        echo html_writer::start_div('kbacorp-bms-section-card');
        echo html_writer::div(html_writer::tag('strong', format_string($section->name)) .
            html_writer::span((string)count($section->criteria), 'kbacorp-bms-section-count'),
            'kbacorp-bms-section-head');
        $table = new html_table();
        $table->attributes['class'] = 'generaltable kbacorp-bms-criteria-table';
        $table->head = [
            get_string('bmscriterioncode', 'local_kbacorporate'),
            get_string('bmscriterionname', 'local_kbacorporate'),
            get_string('targetscore', 'local_kbacorporate'),
            get_string('bmscriterionweight', 'local_kbacorporate'),
            get_string('bmscriterioncompetencies', 'local_kbacorporate'), get_string('status'),
            get_string('actions', 'local_kbacorporate'),
        ];
        foreach ($section->criteria as $criterion) {
            $actions = '—';
            if (!$isglobal) {
                $edit = html_writer::link(new moodle_url('/local/kbacorporate/bms_criterion_edit.php', [
                    'id' => $criterion->id, 'toolid' => $id, 'companyid' => $companyid,
                ]), get_string('edit'));
                $route = html_writer::link(new moodle_url('/local/kbacorporate/bms_route_edit.php', [
                    'criterionid' => $criterion->id, 'toolid' => $id, 'companyid' => $companyid,
                ]), get_string('implementationgaproutes', 'local_kbacorporate'));
                $competenciesurl = html_writer::link(new moodle_url('/local/kbacorporate/bms_competencies.php', [
                    'criterionid' => $criterion->id, 'toolid' => $id, 'companyid' => $companyid,
                ]), get_string('managecompetencylinks', 'local_kbacorporate'));
                $actions = $edit . ' · ' . $competenciesurl . ' · ' . $route;
            }
            $name = format_string($criterion->name);
            if (!empty($criterion->required)) {
                $name .= html_writer::span(get_string('bmsmandatorycriterion', 'local_kbacorporate'), 'kbacorp-bms-required-badge');
            }
            $competencylabels = [];
            foreach ($competenciesbycriterion[(int)$criterion->id] ?? [] as $mapping) {
                $competencylabels[] = html_writer::span(format_string($mapping->shortname) .
                    ' · ' . get_string('bmscompetencytargetshort', 'local_kbacorporate', (int)$mapping->targetlevel),
                    'kbacorp-bms-competency-badge');
            }
            $table->data[] = [
                s($criterion->code), $name,
                (int)$criterion->targetscore < 0 ? '—' : (int)$criterion->targetscore,
                (int)$criterion->weight,
                $competencylabels ? implode(' ', $competencylabels) : '—',
                !empty($criterion->active) ? get_string('active', 'local_kbacorporate') :
                    get_string('inactive', 'local_kbacorporate'),
                $actions,
            ];
        }
        echo html_writer::div(html_writer::table($table), 'table-responsive');
        echo html_writer::end_div();
    }
}
echo $OUTPUT->footer();
