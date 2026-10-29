<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\legacy_drawio_import_service;
use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);
$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$inspectuuid = optional_param('inspect', '', PARAM_ALPHANUMEXT);
$canmanage = product_service::can_manage($uuid, (int)$USER->id);
if (!$canmanage) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
$error = '';
$review = null;

if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('productpostrequired', 'local_kbacompany');
    }
    require_sesskey();
    try {
        if ($action === 'preview') {
            if (empty($_FILES['drawio'])) {
                throw new moodle_exception('ofslegacyimport_fileerror', 'local_kbacompany');
            }
            $review = legacy_drawio_import_service::preview_upload($uuid, (int)$USER->id, $_FILES['drawio'], [
                'driveid' => optional_param('source_drive_id', '', PARAM_ALPHANUMEXT),
                'modified' => optional_param('source_modified', '', PARAM_RAW_TRIMMED),
            ]);
            $token = $review['token'];
        } else if ($action === 'save') {
            $token = required_param('token', PARAM_ALPHANUMEXT);
            $maptype = optional_param_array('maptype', [], PARAM_ALPHANUMEXT);
            $name = optional_param_array('name', [], PARAM_TEXT);
            $product = optional_param_array('product', [], PARAM_TEXT);
            $parent = optional_param_array('parent', [], PARAM_RAW_TRIMMED);
            $companyproduct = optional_param('companyproduct', '', PARAM_TEXT);
            $templatename = required_param('templatename', PARAM_TEXT);
            $templateuuid = legacy_drawio_import_service::save_draft($uuid, (int)$USER->id, $token, $templatename, [
                'maptype' => $maptype, 'name' => $name, 'product' => $product, 'parent' => $parent,
                'companyproduct' => $companyproduct,
            ]);
            redirect(new moodle_url('/local/kbacompany/ofs_legacy_import.php', ['uuid' => $uuid]),
                get_string('ofslegacyimport_draftcreated', 'local_kbacompany', $templateuuid));
        } else if ($action === 'transition') {
            $templateuuid = required_param('templateuuid', PARAM_ALPHANUMEXT);
            $workflow = required_param('workflow', PARAM_ALPHA);
            $status = legacy_drawio_import_service::transition($uuid, (int)$USER->id, $templateuuid, $workflow);
            redirect(new moodle_url('/local/kbacompany/ofs_legacy_import.php', ['uuid' => $uuid]),
                get_string('ofslegacyimport_statuschanged', 'local_kbacompany', get_string('ofslegacyimport_status_' . $status,
                    'local_kbacompany')));
        }
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') {
            throw $e;
        }
        $error = $e->getMessage();
    }
}

if ($review === null && $token !== '') {
    try {
        $review = legacy_drawio_import_service::review($uuid, (int)$USER->id, $token);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$templates = legacy_drawio_import_service::list_templates($uuid, (int)$USER->id);
$inspection = null;
if ($inspectuuid !== '') {
    try { $inspection = legacy_drawio_import_service::template_data($uuid, (int)$USER->id, $inspectuuid); }
    catch (moodle_exception $e) { $error = $e->getMessage(); }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/ofs_legacy_import.php', ['uuid' => $uuid, 'token' => $token]));
$PAGE->set_title(get_string('ofslegacyimport_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = bms_page_shell_service::begin(
    $uuid, (int)$USER->id, 'organization', get_string('ofslegacyimport_title', 'local_kbacompany')
);

if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}

echo html_writer::tag('div', get_string('bmsarchitecture_eyebrow', 'local_kbacompany'), ['class' => 'kbacompany-ofs-eyebrow']);
echo $OUTPUT->heading(get_string('ofslegacyimport_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('ofslegacyimport_intro_v2', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::start_div('mb-3');
echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', ['uuid' => $uuid]),
    get_string('ofstemplates_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $uuid]),
    get_string('ofsdesigner_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();

if (!$review) {
    echo html_writer::start_div('kbacompany-ofs-import-card');
    echo html_writer::tag('h3', get_string('ofslegacyimport_uploadheading', 'local_kbacompany'));
    echo html_writer::tag('p', get_string('ofslegacyimport_uploadhelp_v2', 'local_kbacompany'));
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'kbacompany-form']);
    foreach (['uuid' => $uuid, 'action' => 'preview', 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('ofslegacyimport_file', 'local_kbacompany'), 'ofslegacy_drawio');
    echo html_writer::empty_tag('input', [
        'id' => 'ofslegacy_drawio', 'type' => 'file', 'name' => 'drawio', 'required' => 'required',
        'accept' => '.drawio,.xml,application/xml,text/xml,application/octet-stream', 'class' => 'form-control-file',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('kbacompany-ofs-import-provenance');
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('ofslegacyimport_driveid', 'local_kbacompany'), 'ofslegacy_driveid');
    echo html_writer::empty_tag('input', ['id' => 'ofslegacy_driveid', 'name' => 'source_drive_id', 'type' => 'text',
        'maxlength' => 128, 'class' => 'form-control', 'placeholder' => get_string('ofslegacyimport_optional', 'local_kbacompany')]);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('ofslegacyimport_sourcemodified', 'local_kbacompany'), 'ofslegacy_modified');
    echo html_writer::empty_tag('input', ['id' => 'ofslegacy_modified', 'name' => 'source_modified', 'type' => 'text',
        'maxlength' => 40, 'class' => 'form-control', 'placeholder' => '2021-12-02T12:45:42Z']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('ofslegacyimport_preview', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::tag('p', get_string('ofslegacyimport_readonlynotice', 'local_kbacompany'), ['class' => 'text-muted small mt-3']);
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('kbacompany-ofs-import-summary');
    echo html_writer::tag('h3', get_string('ofslegacyimport_reviewheading', 'local_kbacompany'));
    echo html_writer::tag('p', s($review['filename']) . ' · ' .
        get_string('ofslegacyimport_foundsummary_v2', 'local_kbacompany', (object)[
            'cells' => $review['cellcount'], 'vertices' => $review['vertexcount'], 'edges' => $review['edgecount'],
            'candidates' => count($review['candidates']), 'ignored' => $review['ignored'],
        ]));
    echo html_writer::tag('p', get_string('ofslegacyimport_reviewhelp_v2', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::start_div('kbacompany-ofs-import-provenance-line');
    echo html_writer::span(get_string('ofslegacyimport_checksum', 'local_kbacompany') . ': ' . s($review['digest']));
    if ($review['driveid'] !== '') {
        echo html_writer::span(get_string('ofslegacyimport_driveid', 'local_kbacompany') . ': ' . s($review['driveid']));
    }
    if ($review['modified'] !== '') {
        echo html_writer::span(get_string('ofslegacyimport_sourcemodified', 'local_kbacompany') . ': ' . s($review['modified']));
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    $normalization = (array)($review['normalization'] ?? []);
    echo html_writer::start_div('kbacompany-ofs-import-readiness');
    echo html_writer::tag('strong', get_string('ofslegacyimport_normalizationtitle', 'local_kbacompany'));
    echo html_writer::span(get_string('ofslegacyimport_businessobjects', 'local_kbacompany', (int)($normalization['businessobjects'] ?? 0)));
    echo html_writer::span(get_string('ofslegacyimport_needsreview', 'local_kbacompany', (int)($normalization['needsreview'] ?? 0)));
    echo html_writer::span(get_string('ofslegacyimport_compoundblocks', 'local_kbacompany', (int)($normalization['compoundblocks'] ?? 0)));
    echo html_writer::span(get_string('ofslegacyimport_productcoverage', 'local_kbacompany', (int)($normalization['productcoverage'] ?? 0)));
    echo html_writer::end_div();

    $typelabels = [
        'company' => get_string('ofslegacyimport_type_company', 'local_kbacompany'),
        'office' => get_string('ofslegacyimport_type_office', 'local_kbacompany'),
        'orgunit' => get_string('ofslegacyimport_type_orgunit', 'local_kbacompany'),
        'department' => get_string('ofslegacyimport_type_department', 'local_kbacompany'),
        'function' => get_string('ofslegacyimport_type_function', 'local_kbacompany'),
        'position' => get_string('ofslegacyimport_type_position', 'local_kbacompany'),
        'product' => get_string('ofslegacyimport_type_product', 'local_kbacompany'),
        'ckp' => get_string('ofslegacyimport_type_ckp', 'local_kbacompany'),
        'result' => get_string('ofslegacyimport_type_result', 'local_kbacompany'),
        'ignore' => get_string('ofslegacyimport_type_ignore', 'local_kbacompany'),
    ];
    $candidates = array_values($review['candidates']);
    $cellindex = [];
    foreach ($candidates as $index => $candidate) {
        $cellindex[$candidate['cellid']] = $index;
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-ofs-import-review',
        'id' => 'kbacompany-legacy-import-form']);
    foreach (['uuid' => $uuid, 'action' => 'save', 'token' => $review['token'], 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::start_div('kbacompany-ofs-import-draft-meta');
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('ofslegacyimport_templatename', 'local_kbacompany'), 'ofslegacy_templatename');
    echo html_writer::empty_tag('input', [
        'id' => 'ofslegacy_templatename', 'name' => 'templatename', 'type' => 'text', 'required' => 'required',
        'maxlength' => 255, 'value' => get_string('ofslegacyimport_templatename_default', 'local_kbacompany', $review['filename']),
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('ofsdesigner_companyproduct', 'local_kbacompany'), 'ofslegacy_companyproduct');
    echo html_writer::empty_tag('input', [
        'id' => 'ofslegacy_companyproduct', 'name' => 'companyproduct', 'type' => 'text', 'maxlength' => 4000,
        'value' => (string)$review['companyproduct'], 'class' => 'form-control',
        'placeholder' => get_string('ofslegacyimport_companyproducthint', 'local_kbacompany'),
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('kbacompany-ofs-import-workbench');

    // LEFT — source cell tree/list.
    echo html_writer::start_tag('aside', ['class' => 'kbacompany-ofs-import-source']);
    echo html_writer::tag('h4', get_string('ofslegacyimport_sourceblocks', 'local_kbacompany'));
    echo html_writer::start_div('kbacompany-ofs-import-filterbar');
    foreach (['all' => 'ofslegacyimport_filter_all', 'objects' => 'ofslegacyimport_filter_objects',
        'review' => 'ofslegacyimport_filter_review', 'products' => 'ofslegacyimport_filter_products'] as $filter => $labelkey) {
        echo html_writer::tag('button', get_string($labelkey, 'local_kbacompany'), [
            'type' => 'button', 'class' => 'btn btn-sm ' . ($filter === 'objects' ? 'btn-primary' : 'btn-outline-secondary'),
            'data-import-filter' => $filter,
        ]);
    }
    echo html_writer::empty_tag('input', ['type' => 'search', 'class' => 'form-control form-control-sm',
        'data-import-search' => '1', 'placeholder' => get_string('ofslegacyimport_search', 'local_kbacompany')]);
    echo html_writer::end_div();
    echo html_writer::start_div('kbacompany-ofs-import-source-list');
    foreach ($candidates as $index => $candidate) {
        $depth = min(5, count($candidate['ancestors']));
        $suggested = in_array($candidate['suggestedtype'], ['label', 'unknown'], true) ? 'ignore' : $candidate['suggestedtype'];
        $isproduct = in_array($candidate['suggestedtype'], ['ckp', 'product', 'result'], true) ? 1 : 0;
        $isobject = in_array($candidate['suggestedtype'], ['label', 'unknown'], true) ? 0 : 1;
        echo html_writer::start_tag('button', [
            'type' => 'button', 'class' => 'kbacompany-ofs-import-source-item' . ($index === 0 ? ' is-active' : ''),
            'data-import-index' => $index, 'data-import-type' => $candidate['suggestedtype'],
            'data-import-object' => $isobject, 'data-import-review' => !empty($candidate['needsreview']) ? 1 : 0,
            'data-import-product' => $isproduct,
            'data-import-search-text' => core_text::strtolower($candidate['text'] . ' ' . $candidate['name']),
            'style' => 'padding-left:' . (10 + $depth * 12) . 'px',
        ]);
        echo html_writer::span(s($candidate['name'] !== '' ? $candidate['name'] : $candidate['text']),
            'kbacompany-ofs-import-source-name');
        echo html_writer::span(s($typelabels[$suggested] ?? $suggested) . ' · ' . round($candidate['confidence'] * 100) . '%',
            'kbacompany-ofs-import-source-meta');
        echo html_writer::end_tag('button');
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('aside');

    // CENTER — legacy-layout preview.
    $bounds = $review['bounds'];
    $bw = max(1.0, (float)$bounds['width']);
    $bh = max(1.0, (float)$bounds['height']);
    echo html_writer::start_tag('section', ['class' => 'kbacompany-ofs-import-preview']);
    echo html_writer::tag('h4', get_string('ofslegacyimport_legacypreview', 'local_kbacompany'));
    echo html_writer::start_div('kbacompany-ofs-import-canvas');
    foreach ($candidates as $index => $candidate) {
        $left = max(0.0, min(99.5, (((float)$candidate['x'] - (float)$bounds['x']) / $bw) * 100));
        $top = max(0.0, min(99.5, (((float)$candidate['y'] - (float)$bounds['y']) / $bh) * 100));
        $width = max(0.6, min(100.0 - $left, ((float)$candidate['w'] / $bw) * 100));
        $height = max(0.6, min(100.0 - $top, ((float)$candidate['h'] / $bh) * 100));
        $style = 'left:' . round($left, 3) . '%;top:' . round($top, 3) . '%;width:' . round($width, 3) .
            '%;height:' . round($height, 3) . '%;';
        $isproduct = in_array($candidate['suggestedtype'], ['ckp', 'product', 'result'], true) ? 1 : 0;
        $isobject = in_array($candidate['suggestedtype'], ['label', 'unknown'], true) ? 0 : 1;
        echo html_writer::tag('button', s($candidate['name'] !== '' ? $candidate['name'] : $candidate['text']), [
            'type' => 'button', 'class' => 'kbacompany-ofs-import-preview-cell semantic-' . s($candidate['suggestedtype']) .
                ($index === 0 ? ' is-active' : '') . (!empty($candidate['needsreview']) ? ' needs-review' : ''),
            'data-import-index' => $index, 'data-import-type' => $candidate['suggestedtype'],
            'data-import-object' => $isobject, 'data-import-review' => !empty($candidate['needsreview']) ? 1 : 0,
            'data-import-product' => $isproduct,
            'data-import-search-text' => core_text::strtolower($candidate['text'] . ' ' . $candidate['name']),
            'style' => $style, 'title' => s($candidate['text']),
        ]);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('section');

    // RIGHT — mapping inspector, one panel per cell (JS shows selected one).
    echo html_writer::start_tag('aside', ['class' => 'kbacompany-ofs-import-inspector']);
    echo html_writer::tag('h4', get_string('ofslegacyimport_mappinginspector', 'local_kbacompany'));
    foreach ($candidates as $index => $candidate) {
        $suggested = in_array($candidate['suggestedtype'], ['label', 'unknown'], true) ? 'ignore' : $candidate['suggestedtype'];
        echo html_writer::start_div('kbacompany-ofs-import-inspector-panel' . ($index === 0 ? ' is-active' : ''), [
            'data-import-panel' => $index,
        ]);
        echo html_writer::tag('div', s($candidate['text']), ['class' => 'kbacompany-ofs-import-legacy-label']);
        echo html_writer::tag('p', get_string('ofslegacyimport_suggestedtype', 'local_kbacompany') . ': ' .
            s($typelabels[$suggested] ?? $suggested) . ' · ' . round($candidate['confidence'] * 100) . '%',
            ['class' => 'small text-muted']);
        $hints = (array)($candidate['normalizationhints'] ?? []);
        $hintcodes = (array)($hints['codes'] ?? []);
        if ($hintcodes) {
            echo html_writer::start_div('kbacompany-ofs-import-guidance');
            echo html_writer::tag('strong', get_string('ofslegacyimport_normalizationguidance', 'local_kbacompany'));
            if (!empty($hints['compound'])) { echo html_writer::tag('p', get_string('ofslegacyimport_compoundhint', 'local_kbacompany')); }
            if (in_array('low_confidence', $hintcodes, true)) { echo html_writer::tag('p', get_string('ofslegacyimport_lowconfidencehint', 'local_kbacompany')); }
            if (in_array('product_missing', $hintcodes, true)) { echo html_writer::tag('p', get_string('ofslegacyimport_productmissinghint', 'local_kbacompany')); }
            if (!empty($hints['rolelines'])) { echo html_writer::tag('div', get_string('ofslegacyimport_embeddedroles', 'local_kbacompany') . ': ' . s(implode(' · ', $hints['rolelines'])), ['class' => 'small']); }
            if (!empty($hints['responsibilitylines'])) { echo html_writer::tag('div', get_string('ofslegacyimport_embeddedresponsibilities', 'local_kbacompany') . ': ' . s(implode(' · ', $hints['responsibilitylines'])), ['class' => 'small']); }
            echo html_writer::end_div();
        }

        echo html_writer::start_div('form-group');
        echo html_writer::label(get_string('ofslegacyimport_mapas', 'local_kbacompany'), 'maptype_' . $index);
        $options = '';
        foreach ($typelabels as $value => $label) {
            $attrs = ['value' => $value];
            if ($value === $suggested) { $attrs['selected'] = 'selected'; }
            $options .= html_writer::tag('option', s($label), $attrs);
        }
        echo html_writer::tag('select', $options, ['id' => 'maptype_' . $index, 'name' => 'maptype[' . $index . ']',
            'class' => 'form-control form-control-sm']);
        echo html_writer::end_div();

        echo html_writer::start_div('form-group');
        echo html_writer::label(get_string('ofslegacyimport_name', 'local_kbacompany'), 'mapname_' . $index);
        echo html_writer::empty_tag('input', ['id' => 'mapname_' . $index, 'name' => 'name[' . $index . ']', 'type' => 'text',
            'maxlength' => 255, 'value' => $candidate['name'], 'class' => 'form-control form-control-sm']);
        echo html_writer::end_div();

        echo html_writer::start_div('form-group');
        echo html_writer::label(get_string('ofslegacyimport_product', 'local_kbacompany'), 'mapproduct_' . $index);
        echo html_writer::empty_tag('input', ['id' => 'mapproduct_' . $index, 'name' => 'product[' . $index . ']', 'type' => 'text',
            'maxlength' => 4000, 'value' => $candidate['suggestedproduct'], 'class' => 'form-control form-control-sm']);
        echo html_writer::end_div();

        echo html_writer::start_div('form-group');
        echo html_writer::label(get_string('ofslegacyimport_parent', 'local_kbacompany'), 'mapparent_' . $index);
        $parentoptions = html_writer::tag('option', get_string('ofslegacyimport_parent_none', 'local_kbacompany'), ['value' => '']);
        foreach ($candidates as $parentcandidate) {
            if ($parentcandidate['cellid'] === $candidate['cellid']) { continue; }
            $attrs = ['value' => $parentcandidate['cellid']];
            if ($parentcandidate['cellid'] === $candidate['suggestedparent']) { $attrs['selected'] = 'selected'; }
            $parentoptions .= html_writer::tag('option', s($parentcandidate['name'] !== '' ? $parentcandidate['name'] : $parentcandidate['text']), $attrs);
        }
        echo html_writer::tag('select', $parentoptions, ['id' => 'mapparent_' . $index, 'name' => 'parent[' . $index . ']',
            'class' => 'form-control form-control-sm']);
        echo html_writer::end_div();

        $stylemeta = [];
        foreach ($candidate['stylemeta'] as $key => $value) { $stylemeta[] = $key . '=' . $value; }
        echo html_writer::tag('div', 'ID ' . s($candidate['cellid']) . ' · x=' . round($candidate['x']) . ' y=' .
            round($candidate['y']) . ' w=' . round($candidate['w']) . ' h=' . round($candidate['h']),
            ['class' => 'small text-muted']);
        if ($stylemeta) {
            echo html_writer::tag('div', s(implode('; ', $stylemeta)), ['class' => 'small text-muted kbacompany-ofs-import-stylemeta']);
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_tag('aside');
    echo html_writer::end_div();

    echo html_writer::start_div('kbacompany-ofs-import-actions');
    echo html_writer::tag('button', get_string('ofslegacyimport_saveasdraft', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::span(get_string('ofslegacyimport_noautopublish', 'local_kbacompany'), 'text-muted small');
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    $PAGE->requires->js_init_code("(function(){var root=document.getElementById('kbacompany-legacy-import-form');if(!root){return;}var filter='objects',query='';function visible(el){var ok=filter==='all'||(filter==='objects'&&el.getAttribute('data-import-object')==='1')||(filter==='review'&&el.getAttribute('data-import-review')==='1')||(filter==='products'&&el.getAttribute('data-import-product')==='1');if(!ok){return false;}if(query){return (el.getAttribute('data-import-search-text')||'').indexOf(query)!==-1;}return true;}function select(i){root.querySelectorAll('[data-import-index]').forEach(function(el){el.classList.toggle('is-active',el.getAttribute('data-import-index')===i);});root.querySelectorAll('[data-import-panel]').forEach(function(el){el.classList.toggle('is-active',el.getAttribute('data-import-panel')===i);});}function apply(){root.querySelectorAll('[data-import-index]').forEach(function(el){el.classList.toggle('is-filter-hidden',!visible(el));});var first=root.querySelector('[data-import-index]:not(.is-filter-hidden)');if(first){select(first.getAttribute('data-import-index'));}root.querySelectorAll('[data-import-filter]').forEach(function(btn){var active=btn.getAttribute('data-import-filter')===filter;btn.classList.toggle('btn-primary',active);btn.classList.toggle('btn-outline-secondary',!active);});}root.querySelectorAll('[data-import-index]').forEach(function(el){el.addEventListener('click',function(){if(!el.classList.contains('is-filter-hidden')){select(el.getAttribute('data-import-index'));}});});root.querySelectorAll('[data-import-filter]').forEach(function(btn){btn.addEventListener('click',function(){filter=btn.getAttribute('data-import-filter');apply();});});var search=root.querySelector('[data-import-search]');if(search){search.addEventListener('input',function(){query=(search.value||'').toLowerCase().trim();apply();});}apply();})()");
}

// Persisted normalized imported templates and their controlled publication state.
if ($inspection) {
    $irecord = $inspection['record'];
    $idata = $inspection['data'];
    $iorg = $idata['orgdesign'];
    echo html_writer::start_div('kbacompany-ofs-import-normalized-preview');
    echo html_writer::tag('div', get_string('ofslegacyimport_normalizedpreview', 'local_kbacompany'),
        ['class' => 'kbacompany-ofs-template-category']);
    echo html_writer::tag('h3', format_string($irecord->name));
    echo html_writer::tag('p', get_string('ofslegacyimport_status_' . $irecord->status, 'local_kbacompany'),
        ['class' => 'badge badge-secondary status-' . s($irecord->status)]);
    echo html_writer::tag('p', get_string('ofslegacyimport_companyroot', 'local_kbacompany') . ': ' .
        s((string)($idata['companyroot']['name'] ?? '')));
    $sourcesummary = (array)($idata['normalization']['source_summary'] ?? []);
    if ($sourcesummary) {
        echo html_writer::start_div('kbacompany-ofs-import-readiness is-compact');
        echo html_writer::tag('strong', get_string('ofslegacyimport_normalizationtitle', 'local_kbacompany'));
        echo html_writer::span(get_string('ofslegacyimport_needsreview', 'local_kbacompany', (int)($sourcesummary['needsreview'] ?? 0)));
        echo html_writer::span(get_string('ofslegacyimport_compoundblocks', 'local_kbacompany', (int)($sourcesummary['compoundblocks'] ?? 0)));
        echo html_writer::end_div();
    }
    $coverednodes = 0;
    $levels = [];
    foreach ($iorg['nodes'] as $node) {
        if (trim((string)($node['product'] ?? '')) !== '') { $coverednodes++; }
        $tier = (string)($node['visualtier'] ?? '');
        if ($tier !== '') { $levels[$tier] = true; }
    }
    $coverage = count($iorg['nodes']) > 0 ? (int)round(($coverednodes / count($iorg['nodes'])) * 100) : 0;
    echo html_writer::start_div('kbacompany-ofs-import-normalized-stats');
    echo html_writer::span(get_string('ofslegacyimport_nodescount', 'local_kbacompany', count($iorg['nodes'])));
    echo html_writer::span(get_string('ofslegacyimport_positionscount', 'local_kbacompany', count($iorg['positions'])));
    echo html_writer::span(get_string('ofslegacyimport_functionscount', 'local_kbacompany', count($iorg['functions'])));
    echo html_writer::span(get_string('ofslegacyimport_structurelevels', 'local_kbacompany', count($levels)));
    echo html_writer::span(get_string('ofslegacyimport_productcoverage', 'local_kbacompany', $coverage));
    echo html_writer::end_div();
    echo html_writer::start_div('kbacompany-ofs-template-vfp');
    echo html_writer::tag('strong', get_string('ofsdesigner_companyproduct', 'local_kbacompany'));
    echo html_writer::tag('p', s((string)($iorg['companyproduct'] ?? '')));
    echo html_writer::end_div();
    echo html_writer::start_div('table-responsive');
    echo html_writer::start_tag('table', ['class' => 'table table-sm']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', get_string('ofslegacyimport_mapas', 'local_kbacompany')) .
        html_writer::tag('th', get_string('ofslegacyimport_name', 'local_kbacompany')) .
        html_writer::tag('th', get_string('ofslegacyimport_product', 'local_kbacompany'))));
    echo html_writer::start_tag('tbody');
    foreach ($iorg['nodes'] as $node) {
        echo html_writer::tag('tr', html_writer::tag('td', s((string)$node['visualtier'])) .
            html_writer::tag('td', s((string)$node['name'])) . html_writer::tag('td', s((string)$node['product'])));
    }
    foreach ($iorg['positions'] as $position) {
        echo html_writer::tag('tr', html_writer::tag('td', get_string('ofslegacyimport_type_position', 'local_kbacompany')) .
            html_writer::tag('td', s((string)$position['name'])) . html_writer::tag('td', ''));
    }
    foreach ($iorg['functions'] as $function) {
        echo html_writer::tag('tr', html_writer::tag('td', get_string('ofslegacyimport_type_function', 'local_kbacompany')) .
            html_writer::tag('td', s((string)$function['name'])) . html_writer::tag('td', s((string)$function['result'])));
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
    $warnings = (array)($idata['validation']['warnings'] ?? []);
    echo html_writer::tag('h4', get_string('ofslegacyimport_validationwarnings', 'local_kbacompany'));
    if ($warnings) {
        echo html_writer::start_tag('ul');
        foreach ($warnings as $warning) { echo html_writer::tag('li', s($warning)); }
        echo html_writer::end_tag('ul');
    } else {
        echo html_writer::tag('p', get_string('ofslegacyimport_nowarnings', 'local_kbacompany'), ['class' => 'text-muted']);
    }
    echo html_writer::tag('p', get_string('ofslegacyimport_provenance', 'local_kbacompany') . ': ' .
        s((string)$idata['provenance']['source_filename']) . ' · SHA256 ' . s((string)$idata['provenance']['source_checksum']),
        ['class' => 'small text-muted']);
    echo html_writer::end_div();
}

echo html_writer::start_div('kbacompany-ofs-import-templates');
echo html_writer::tag('h3', get_string('ofslegacyimport_savedtemplates', 'local_kbacompany'));
echo html_writer::tag('p', get_string('ofslegacyimport_savedtemplates_help', 'local_kbacompany'), ['class' => 'text-muted']);
if (!$templates) {
    echo html_writer::tag('p', get_string('ofslegacyimport_notemplates', 'local_kbacompany'), ['class' => 'text-muted']);
} else {
    echo html_writer::start_div('kbacompany-ofs-import-template-list');
    foreach ($templates as $template) {
        echo html_writer::start_tag('article', ['class' => 'kbacompany-ofs-import-template']);
        echo html_writer::start_div('kbacompany-ofs-import-template-main');
        echo html_writer::tag('h4', format_string($template->name));
        echo html_writer::tag('span', get_string('ofslegacyimport_status_' . $template->status, 'local_kbacompany'),
            ['class' => 'badge badge-secondary kbacompany-ofs-import-status status-' . s($template->status)]);
        echo html_writer::tag('div', s($template->sourcefilename) . ' · SHA256 ' . s(substr($template->sourcechecksum, 0, 16)) . '…',
            ['class' => 'small text-muted']);
        if ($template->sourcedriveid !== '') {
            echo html_writer::tag('div', get_string('ofslegacyimport_driveid', 'local_kbacompany') . ': ' . s($template->sourcedriveid),
                ['class' => 'small text-muted']);
        }
        echo html_writer::end_div();
        echo html_writer::start_div('kbacompany-ofs-import-template-actions');
        echo html_writer::link(new moodle_url('/local/kbacompany/ofs_legacy_import.php', [
            'uuid' => $uuid, 'inspect' => $template->uuid,
        ]), get_string('ofslegacyimport_inspect', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-secondary']);
        $buttons = [];
        if ($template->status === 'draft') { $buttons['review'] = 'ofslegacyimport_markreviewed'; }
        if ($template->status === 'reviewed') {
            $buttons['publish'] = 'ofslegacyimport_makeavailable';
            $buttons['return'] = 'ofslegacyimport_returntodraft';
        }
        if ($template->status === 'available') { $buttons['withdraw'] = 'ofslegacyimport_withdraw'; }
        foreach ($buttons as $workflow => $labelkey) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block ml-1']);
            foreach (['uuid' => $uuid, 'action' => 'transition', 'templateuuid' => $template->uuid,
                'workflow' => $workflow, 'sesskey' => sesskey()] as $key => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
            }
            echo html_writer::tag('button', get_string($labelkey, 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']);
            echo html_writer::end_tag('form');
        }
        if ($template->status === 'available') {
            echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', [
                'uuid' => $uuid, 'imported' => $template->uuid,
            ]), get_string('ofstemplates_preview', 'local_kbacompany'), ['class' => 'btn btn-sm btn-primary ml-1']);
        }
        echo html_writer::end_div();
        echo html_writer::end_tag('article');
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();

bms_page_shell_service::finish($bmsshell);
