<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$query = trim(optional_param('q', '', PARAM_TEXT));
$area = optional_param('area', '', PARAM_ALPHA);
$scope = optional_param('scope', '', PARAM_ALPHA);
$status = optional_param('status', '', PARAM_ALPHA);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

$areas = ['', 'strategy', 'finance', 'marketing', 'operations', 'people', 'management'];
if (!in_array($area, $areas, true)) { $area = ''; }
if (!in_array($scope, ['', 'company', 'global'], true)) { $scope = ''; }
if (!in_array($status, ['', 'active', 'inactive'], true)) { $status = ''; }

if ($action === 'installpointa') {
    require_sesskey();
    $toolid = \local_kbacorporate\local\point_a_template::install($companyid);
    redirect(new moodle_url('/local/kbacorporate/bms_tool.php', [
        'id' => $toolid, 'companyid' => $companyid,
    ]), get_string('pointatemplateinstalled', 'local_kbacorporate'));
}

$alltools = \local_kbacorporate\local\implementation_audits::get_tools_overview($companyid);
$needle = core_text::strtolower($query);
$tools = array_values(array_filter($alltools, static function($tool) use ($needle, $area, $scope, $status) {
    if ($area !== '' && (string)$tool->area !== $area) { return false; }
    if ($scope === 'company' && (int)$tool->companyid === 0) { return false; }
    if ($scope === 'global' && (int)$tool->companyid !== 0) { return false; }
    if ($status === 'active' && empty($tool->active)) { return false; }
    if ($status === 'inactive' && !empty($tool->active)) { return false; }
    if ($needle === '') { return true; }
    $haystack = core_text::strtolower($tool->name . ' ' . $tool->code . ' ' . $tool->description);
    return core_text::strpos($haystack, $needle) !== false;
}));

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bms_tools.php', [
    'companyid' => $companyid, 'q' => $query, 'area' => $area, 'scope' => $scope, 'status' => $status,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bmstools', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')),
    'kbacorp-backlink');
echo html_writer::start_div('kbacorp-bms-library-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('bmstoollibrary', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('bmstoollibrarydesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
echo html_writer::end_div();

$total = count($alltools);
$active = count(array_filter($alltools, static function($tool) { return !empty($tool->active); }));
$companycount = count(array_filter($alltools, static function($tool) { return (int)$tool->companyid > 0; }));
$globalcount = $total - $companycount;
echo html_writer::start_div('kbacorp-summary kbacorp-bms-tool-summary');
foreach ([
    [$total, 'bmstoolstotal', 'total'], [$active, 'bmstoolsactive', 'confirmed'],
    [$companycount, 'bmstoolscompany', 'developing'], [$globalcount, 'bmstoolsglobal', 'notstarted'],
] as [$value, $label, $accent]) {
    echo html_writer::div(html_writer::div((string)$value, 'value') .
        html_writer::div(get_string($label, 'local_kbacorporate'), 'label'),
        'kbacorp-card kbacorp-card-accent-' . $accent);
}
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-bms-library-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('bmstoolsearch', 'local_kbacorporate'), 'class' => 'form-control']);
$areaoptions = ['' => get_string('allbmsareas', 'local_kbacorporate')];
foreach (array_slice($areas, 1) as $areakey) {
    $areaoptions[$areakey] = get_string('bmsarea' . $areakey, 'local_kbacorporate');
}
echo html_writer::select($areaoptions, 'area', $area, false, ['class' => 'custom-select']);
echo html_writer::select([
    '' => get_string('allbmstoolscopes', 'local_kbacorporate'),
    'company' => get_string('bmstoolscopecompany', 'local_kbacorporate'),
    'global' => get_string('bmstoolscopeglobal', 'local_kbacorporate'),
], 'scope', $scope, false, ['class' => 'custom-select']);
echo html_writer::select([
    '' => get_string('allstatuses', 'local_kbacorporate'),
    'active' => get_string('active', 'local_kbacorporate'),
    'inactive' => get_string('inactive', 'local_kbacorporate'),
], 'status', $status, false, ['class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $companyid]),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tool_edit.php',
    ['companyid' => $companyid]), get_string('addbmstool', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_tools.php', [
    'companyid' => $companyid, 'action' => 'installpointa', 'sesskey' => sesskey(),
]), get_string('installpointatemplate', 'local_kbacorporate'), 'post');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bms_competency_io.php', [
    'companyid' => $companyid,
]), get_string('bmsjsoniomenu', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/framework.php', [
    'companyid' => $companyid,
]), get_string('bmsframeworkmanager', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

if (!$tools) {
    echo $OUTPUT->notification(get_string('bmstoollibraryempty', 'local_kbacorporate'), 'info');
} else {
    echo html_writer::start_div('kbacorp-bms-tool-grid');
    foreach ($tools as $tool) {
        $url = new moodle_url('/local/kbacorporate/bms_tool.php', [
            'id' => (int)$tool->id, 'companyid' => $companyid,
        ]);
        $scopeclass = (int)$tool->companyid === 0 ? 'global' : 'company';
        $scopelabel = get_string('bmstoolscope' . $scopeclass, 'local_kbacorporate');
        echo html_writer::start_div('kbacorp-bms-tool-card kbacorp-bms-tool-' . $scopeclass .
            (empty($tool->active) ? ' is-inactive' : ''));
        echo html_writer::start_div('kbacorp-bms-tool-card-head');
        echo html_writer::div(s($tool->code), 'kbacorp-bms-tool-code');
        echo html_writer::span($scopelabel, 'kbacorp-bms-tool-scope');
        echo html_writer::span(empty($tool->currentversion) ?
            get_string('bmstoolversionunpublished', 'local_kbacorporate') : 'v' . (int)$tool->currentversion,
            'kbacorp-bms-version-badge');
        if (!empty($tool->hasdraft)) {
            echo html_writer::span(get_string('bmstooldraftshort', 'local_kbacorporate'),
                'kbacorp-bms-version-badge is-draft');
        }
        echo html_writer::end_div();
        echo html_writer::link($url, format_string($tool->name), ['class' => 'kbacorp-bms-tool-title']);
        echo html_writer::div(get_string('bmsarea' . $tool->area, 'local_kbacorporate'),
            'kbacorp-bms-tool-area');
        if (trim((string)$tool->description) !== '') {
            echo html_writer::div(s(shorten_text(strip_tags((string)$tool->description), 180)),
                'kbacorp-bms-tool-description');
        }
        echo html_writer::start_div('kbacorp-bms-tool-metrics');
        echo html_writer::span(get_string('bmstoolcriteriacount', 'local_kbacorporate',
            (int)$tool->activecriteriacount));
        echo html_writer::span(get_string('bmstoolauditcount', 'local_kbacorporate',
            (int)$tool->auditcount));
        echo html_writer::span(get_string('bmstoolscalevalue', 'local_kbacorporate',
            (int)$tool->scalemax));
        echo html_writer::end_div();
        echo html_writer::div(empty($tool->active) ? get_string('inactive', 'local_kbacorporate') :
            get_string('active', 'local_kbacorporate'),
            'kbacorp-bms-tool-status ' . (empty($tool->active) ? 'is-inactive' : 'is-active'));
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
