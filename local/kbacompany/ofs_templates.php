<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\design_service;
use local_kbacompany\local\legacy_drawio_import_service;
use local_kbacompany\local\ofs_template_library_service;
use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);

$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$templatekey = optional_param('template', '', PARAM_ALPHANUMEXT);
$importeduuid = optional_param('imported', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$canmanage = product_service::can_manage($uuid, (int)$USER->id);

if ($action === 'create') {
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('productpostrequired', 'local_kbacompany');
    }
    require_sesskey();
    $sourcekind = required_param('sourcekind', PARAM_ALPHA);
    if ($sourcekind === 'builtin') {
        $templatekey = required_param('templatekey', PARAM_ALPHANUMEXT);
        $info = ofs_template_library_service::template_info($templatekey);
        if (!$info) {
            throw new moodle_exception('design_reference', 'local_kbacompany');
        }
        $name = optional_param('name', $info['name'], PARAM_TEXT);
        $object = design_service::command($uuid, (int)$USER->id, 'create', [
            'source' => 'library', 'templatekey' => $templatekey, 'name' => $name,
        ]);
    } else if ($sourcekind === 'imported') {
        $importeduuid = required_param('templateuuid', PARAM_ALPHANUMEXT);
        $info = legacy_drawio_import_service::available_info($uuid, (int)$USER->id, $importeduuid);
        $name = optional_param('name', $info['name'], PARAM_TEXT);
        $object = design_service::command($uuid, (int)$USER->id, 'create', [
            'source' => 'importtpl', 'sourceuuid' => $importeduuid, 'name' => $name,
        ]);
    } else {
        throw new moodle_exception('design_reference', 'local_kbacompany');
    }
    redirect(new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $uuid, 'object' => $object]),
        get_string('ofstemplates_created', 'local_kbacompany'));
}

$templates = ofs_template_library_service::templates();
$importedtemplates = [];
if ($canmanage) {
    foreach (legacy_drawio_import_service::list_templates($uuid, (int)$USER->id, 'available') as $record) {
        $importedtemplates[] = legacy_drawio_import_service::available_info($uuid, (int)$USER->id, $record->uuid);
    }
}
if ($templatekey === '' && $importeduuid === '' && $templates) {
    $templatekey = $templates[0]['key'];
}
$previewkind = '';
$preview = null;
if ($importeduuid !== '') {
    $previewkind = 'imported';
    $preview = legacy_drawio_import_service::available_info($uuid, (int)$USER->id, $importeduuid);
    $preview['management_units_list'] = [];
    $preview['departments_list'] = [];
    $preview['tier_nodes'] = [
        'investment' => [], 'strategic_office' => [], 'management' => [], 'management_unit' => [],
        'executive' => [], 'unit' => [], 'department' => [],
    ];
    foreach ($preview['orgdesign']['nodes'] as $node) {
        $tier = (string)($node['visualtier'] ?? '');
        if (isset($preview['tier_nodes'][$tier])) {
            $preview['tier_nodes'][$tier][] = (string)$node['name'];
        }
        if ($tier === 'management_unit' || $tier === 'unit') {
            $preview['management_units_list'][] = $node['name'];
        }
        if ($tier === 'department') {
            $preview['departments_list'][] = ['name' => $node['name'], 'product' => (string)($node['product'] ?? '')];
        }
    }
} else if ($templatekey !== '') {
    $previewkind = 'builtin';
    $preview = ofs_template_library_service::preview($templatekey);
    if ($preview) {
        $preview['management_units_list'] = $preview['management_units'];
        $preview['departments_list'] = $preview['departments'];
        $preview['tier_nodes'] = [
            'investment' => [get_string('ofsdesigner_tier_investment', 'local_kbacompany')],
            'strategic_office' => [get_string('ofsdesigner_tier_strategic_office', 'local_kbacompany')],
            'management' => [get_string('ofsdesigner_tier_management', 'local_kbacompany')],
            'management_unit' => $preview['management_units'],
            'executive' => [get_string('ofsdesigner_tier_executive', 'local_kbacompany')],
            'unit' => [], 'department' => [],
        ];
    }
}

$urlparams = ['uuid' => $uuid];
if ($importeduuid !== '') { $urlparams['imported'] = $importeduuid; }
else if ($templatekey !== '') { $urlparams['template'] = $templatekey; }
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/ofs_templates.php', $urlparams));
$PAGE->set_title(get_string('ofstemplates_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = bms_page_shell_service::begin(
    $uuid, (int)$USER->id, 'organization', get_string('ofstemplates_title', 'local_kbacompany')
);

echo html_writer::start_div('kbacompany-ofs-template-header');
echo html_writer::tag('div', get_string('bmsarchitecture_eyebrow', 'local_kbacompany'), ['class' => 'kbacompany-ofs-eyebrow']);
echo $OUTPUT->heading(get_string('ofstemplates_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('ofstemplates_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::link(new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $uuid]),
    get_string('ofsdesigner_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
if ($canmanage) {
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_legacy_import.php', ['uuid' => $uuid]),
        get_string('ofslegacyimport_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary ml-2']);
}
echo html_writer::end_div();

echo html_writer::start_div('kbacompany-ofs-template-layout');
echo html_writer::start_div('kbacompany-ofs-template-catalog');
foreach ($templates as $item) {
    $active = $previewkind === 'builtin' && $item['key'] === $templatekey;
    echo html_writer::start_tag('article', ['class' => 'kbacompany-ofs-template-card' . ($active ? ' is-active' : '')]);
    echo html_writer::tag('div', s($item['category']), ['class' => 'kbacompany-ofs-template-category']);
    echo html_writer::tag('h3', format_string($item['name']));
    echo html_writer::tag('p', s($item['description']));
    echo html_writer::start_div('kbacompany-ofs-template-meta');
    echo html_writer::span(get_string('ofstemplates_departmentscount', 'local_kbacompany', $item['departments']));
    echo html_writer::span(get_string('ofstemplates_unitscount', 'local_kbacompany', $item['management_units']));
    echo html_writer::end_div();
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', [
        'uuid' => $uuid, 'template' => $item['key'],
    ]), get_string('ofstemplates_preview', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']);
    echo html_writer::end_tag('article');
}
foreach ($importedtemplates as $item) {
    $active = $previewkind === 'imported' && $item['uuid'] === $importeduuid;
    echo html_writer::start_tag('article', ['class' => 'kbacompany-ofs-template-card' . ($active ? ' is-active' : '')]);
    echo html_writer::tag('div', s($item['category']), ['class' => 'kbacompany-ofs-template-category']);
    echo html_writer::tag('h3', format_string($item['name']));
    echo html_writer::tag('p', s($item['description']));
    echo html_writer::start_div('kbacompany-ofs-template-meta');
    echo html_writer::span(get_string('ofstemplates_departmentscount', 'local_kbacompany', $item['departments']));
    echo html_writer::span(get_string('ofstemplates_unitscount', 'local_kbacompany', $item['management_units']));
    echo html_writer::span(get_string('ofslegacyimport_positionsmeta', 'local_kbacompany', $item['positions']));
    echo html_writer::span(get_string('ofslegacyimport_functionsmeta', 'local_kbacompany', $item['functions']));
    echo html_writer::span(get_string('ofslegacyimport_productcoverage', 'local_kbacompany', $item['product_coverage']));
    echo html_writer::end_div();
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', [
        'uuid' => $uuid, 'imported' => $item['uuid'],
    ]), get_string('ofstemplates_preview', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']);
    echo html_writer::end_tag('article');
}
echo html_writer::end_div();

if ($preview) {
    echo html_writer::start_div('kbacompany-ofs-template-preview');
    echo html_writer::tag('div', s($preview['category']), ['class' => 'kbacompany-ofs-template-category']);
    echo html_writer::tag('h2', format_string($preview['name']));
    echo html_writer::tag('p', s($preview['description']), ['class' => 'lead']);
    if ($previewkind === 'imported') {
        echo html_writer::start_div('kbacompany-ofs-import-readiness is-compact');
        echo html_writer::span(get_string('ofslegacyimport_structurelevels', 'local_kbacompany', $preview['structure_levels']));
        echo html_writer::span(get_string('ofslegacyimport_positionsmeta', 'local_kbacompany', $preview['positions']));
        echo html_writer::span(get_string('ofslegacyimport_functionsmeta', 'local_kbacompany', $preview['functions']));
        echo html_writer::span(get_string('ofslegacyimport_productcoverage', 'local_kbacompany', $preview['product_coverage']));
        echo html_writer::span(get_string('ofslegacyimport_warningscount', 'local_kbacompany', $preview['warnings']));
        echo html_writer::end_div();
    }

    $tierbands = [
        'investment' => 'ofsdesigner_tier_investment',
        'strategic_office' => 'ofsdesigner_tier_strategic_office',
        'management' => 'ofsdesigner_tier_management',
        'executive' => 'ofsdesigner_tier_executive',
    ];
    foreach ($tierbands as $tier => $labelkey) {
        $names = (array)($preview['tier_nodes'][$tier] ?? []);
        if (!$names) { continue; }
        echo html_writer::start_div('kbacompany-ofs-template-preview-band tier-' . s($tier));
        echo html_writer::tag('strong', get_string($labelkey, 'local_kbacompany'));
        if ($previewkind === 'imported') {
            echo html_writer::tag('div', implode(' · ', array_map('s', $names)),
                ['class' => 'kbacompany-ofs-template-preview-units']);
        } else if ($tier === 'strategic_office') {
            echo html_writer::span(get_string('ofstemplates_universalstrategic', 'local_kbacompany'));
        }
        echo html_writer::end_div();
    }
    $managementnames = array_merge((array)($preview['tier_nodes']['management_unit'] ?? []),
        (array)($preview['tier_nodes']['unit'] ?? []));
    if ($managementnames) {
        echo html_writer::start_div('kbacompany-ofs-template-preview-band tier-management-unit');
        echo html_writer::tag('strong', get_string('ofsdesigner_tier_management_unit', 'local_kbacompany'));
        echo html_writer::tag('div', implode(' · ', array_map('s', $managementnames)),
            ['class' => 'kbacompany-ofs-template-preview-units']);
        echo html_writer::end_div();
    }

    echo html_writer::tag('h3', get_string('ofsdesigner_departments', 'local_kbacompany'));
    echo html_writer::start_div('kbacompany-ofs-template-departments');
    foreach ($preview['departments_list'] as $department) {
        echo html_writer::start_tag('article', ['class' => 'kbacompany-ofs-template-department']);
        echo html_writer::tag('strong', format_string($department['name']));
        echo html_writer::tag('p', html_writer::tag('strong', get_string('ofsdesigner_result', 'local_kbacompany')) . ' ' .
            s($department['product']));
        echo html_writer::end_tag('article');
    }
    echo html_writer::end_div();

    echo html_writer::start_div('kbacompany-ofs-template-vfp');
    echo html_writer::tag('strong', get_string('ofsdesigner_companyproduct', 'local_kbacompany'));
    echo html_writer::tag('p', s($preview['companyproduct']));
    echo html_writer::end_div();

    if ($previewkind === 'imported' && !empty($preview['provenance'])) {
        $p = $preview['provenance'];
        echo html_writer::start_div('kbacompany-ofs-template-provenance');
        echo html_writer::tag('strong', get_string('ofslegacyimport_provenance', 'local_kbacompany'));
        echo html_writer::tag('div', s($p['source_filename']) . ' · SHA256 ' . s(substr($p['source_checksum'], 0, 16)) . '…',
            ['class' => 'small text-muted']);
        if (!empty($p['source_drive_id'])) {
            echo html_writer::tag('div', get_string('ofslegacyimport_driveid', 'local_kbacompany') . ': ' . s($p['source_drive_id']),
                ['class' => 'small text-muted']);
        }
        echo html_writer::end_div();
    }

    if ($canmanage) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-ofs-template-create']);
        foreach (['uuid' => $uuid, 'action' => 'create', 'sourcekind' => $previewkind,
            'sesskey' => sesskey()] as $k => $v) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
        }
        if ($previewkind === 'builtin') {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templatekey', 'value' => $preview['key']]);
        } else {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templateuuid', 'value' => $preview['uuid']]);
        }
        echo html_writer::label(get_string('design_name', 'local_kbacompany'), 'ofstemplate_projectname');
        echo html_writer::empty_tag('input', [
            'id' => 'ofstemplate_projectname', 'name' => 'name', 'type' => 'text', 'maxlength' => 255,
            'required' => 'required', 'value' => get_string('ofstemplates_projectname', 'local_kbacompany', $preview['name']),
        ]);
        echo html_writer::tag('button', get_string('ofstemplates_create', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::tag('p', get_string('ofstemplates_notice', 'local_kbacompany'), ['class' => 'text-muted small']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

bms_page_shell_service::finish($bmsshell);
