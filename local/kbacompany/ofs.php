<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\ofs_service;
use local_kbacompany\local\product_service;
use local_kbacompany\local\ofs_workspace_service;
use local_kbacompany\local\ofs_workspace_renderer;
use local_kbacompany\local\ofs_visual_designer_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$branchuuid = optional_param('branch', '', PARAM_RAW_TRIMMED);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$mode = optional_param('mode', 'current', PARAM_ALPHA);
if (!in_array($mode, ['current', 'design', 'compare', 'check'], true)) { $mode = 'current'; }
$action = optional_param('action', '', PARAM_ALPHA);
$workspace = ofs_workspace_service::context($uuid, (int)$USER->id, $object, $branchuuid);
$data = $workspace['live'];
if (!$workspace['can_manage'] && $mode !== 'current') { $mode = 'current'; }
if ($object === '' && !empty($workspace['design']['selected'])) { $object = (string)$workspace['design']['selected']->uuid; }
$pageurl = new moodle_url('/local/kbacompany/ofs.php', [
    'uuid' => $uuid, 'branch' => $data['selected_branch_uuid'], 'mode' => $mode, 'object' => $object,
]);
$error = '';

if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('productpostrequired', 'local_kbacompany');
    }
    require_sesskey();
    require_capability('local/kbacompany:manageorganization', $context);
    try {
        if ($action === 'ckp') {
            $target = explode(':', required_param('target', PARAM_RAW_TRIMMED), 2);
            product_service::create_product($uuid, (int)$USER->id, [
                'code' => required_param('code', PARAM_TEXT),
                'name' => required_param('name', PARAM_TEXT),
                'acceptance' => required_param('acceptance', PARAM_TEXT),
                'targettype' => $target[0],
                'targetuuid' => $target[1] ?? '',
            ]);
        } else if ($action === 'customer') {
            ofs_service::link_internal_customer($uuid, (int)$USER->id,
                required_param('productuuid', PARAM_RAW_TRIMMED),
                required_param('customeruuid', PARAM_RAW_TRIMMED),
                optional_param('reason', '', PARAM_TEXT));
        } else if ($action === 'uncustomer') {
            ofs_service::unlink_internal_customer($uuid, (int)$USER->id,
                required_param('relationuuid', PARAM_RAW_TRIMMED),
                required_param('revision', PARAM_INT),
                required_param('reason', PARAM_TEXT));
        } else {
            throw new moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        redirect($pageurl, get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') {
            throw $e;
        }
        $error = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('ofs_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization', get_string('ofsworkspace_title', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo local_kbacompany_organization_tabs($uuid, 'ofs', $data['can_manage_ckp']);

echo html_writer::start_div('kbacompany-ofs-workspace-header');
echo html_writer::tag('div', get_string('bmsarchitecture_eyebrow', 'local_kbacompany'), ['class' => 'kbacompany-ofs-eyebrow']);
echo $OUTPUT->heading(get_string('ofsworkspace_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']));
echo html_writer::tag('p', get_string('ofsworkspace_intro', 'local_kbacompany'), ['class' => 'lead']);
echo html_writer::start_div('kbacompany-ofs-statebar');
echo html_writer::div(
    html_writer::tag('strong', get_string('ofsworkspace_current', 'local_kbacompany')) .
    html_writer::span(get_string('ofsworkspace_applied', 'local_kbacompany'), 'kbacompany-ofs-state-badge is-live'),
    'kbacompany-ofs-state');
if (!empty($workspace['design']['selected'])) {
    $dp = $workspace['design']['selected'];
    echo html_writer::div(
        html_writer::tag('strong', get_string('ofsworkspace_project', 'local_kbacompany') . ': ' . s($dp->name)) .
        html_writer::span(get_string('design_status_' . $dp->status, 'local_kbacompany') . ' · v' . (int)$dp->revision,
            'kbacompany-ofs-state-badge is-design'),
        'kbacompany-ofs-state');
}
echo html_writer::end_div();

echo html_writer::start_div('kbacompany-ofs-mode-switch');
$modes = ['current' => 'ofsworkspace_current', 'design' => 'ofsworkspace_design'];
if ($workspace['can_manage'] && !empty($workspace['design']['selected'])) {
    $modes['compare'] = 'ofsworkspace_compare';
    $modes['check'] = 'ofsworkspace_check';
}
foreach ($modes as $key => $label) {
    $url = new moodle_url('/local/kbacompany/ofs.php', ['uuid' => $uuid, 'branch' => $data['selected_branch_uuid'],
        'mode' => $key, 'object' => $object]);
    echo html_writer::link($url, get_string($label, 'local_kbacompany'),
        ['class' => 'btn ' . ($mode === $key ? 'btn-primary' : 'btn-outline-secondary')]);
}
echo html_writer::end_div();
echo html_writer::end_div();
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }

if ($mode === 'current') {
    echo ofs_workspace_renderer::poster($workspace['live_visual']['poster'], ['state' => 'current']);
    echo html_writer::div(get_string('ofsworkspace_currenthelp', 'local_kbacompany'), 'kbacompany-ofs-workspace-help');
}

if ($mode === 'design') {
    $design = $workspace['design'];
    if ($workspace['design_error'] !== '') { echo $OUTPUT->notification(s($workspace['design_error']), 'error'); }
    if ($design['projects']) {
        $options = [];
        foreach ($design['projects'] as $project) {
            $options[$project->uuid] = $project->name . ' · ' . get_string('design_status_' . $project->status, 'local_kbacompany');
        }
        $select = new single_select(new moodle_url('/local/kbacompany/ofs.php', ['uuid' => $uuid, 'mode' => 'design']),
            'object', $options, $object, null);
        $select->label = get_string('ofsdesigner_project', 'local_kbacompany');
        echo $OUTPUT->render($select);
    }
    echo html_writer::start_div('kbacompany-ofs-toolbar');
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_templates.php', ['uuid' => $uuid]),
        get_string('ofstemplates_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/kbacompany/ofs_legacy_import.php', ['uuid' => $uuid]),
        get_string('ofslegacyimport_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
    if ($object !== '') {
        echo html_writer::link(new moodle_url('/local/kbacompany/designs.php', ['uuid' => $uuid, 'object' => $object]),
            get_string('ofsdesigner_openadvanced', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
        echo html_writer::link(new moodle_url('/local/kbacompany/ofs_export.php', ['uuid' => $uuid, 'object' => $object, 'format' => 'svg']),
            get_string('ofsdesigner_export_svg', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
    }
    echo html_writer::end_div();
    if (empty($design['poster'])) {
        echo $OUTPUT->notification(get_string('ofsdesigner_noproject', 'local_kbacompany'), 'info');
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/kbacompany/ofs_designer.php'),
            'class' => 'kbacompany-form mb-4']);
        foreach (['uuid' => $uuid, 'action' => 'create', 'sesskey' => sesskey()] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        echo html_writer::label(get_string('design_name', 'local_kbacompany'), 'ofs_create_name');
        echo html_writer::empty_tag('input', ['id' => 'ofs_create_name', 'name' => 'name', 'required' => 'required',
            'value' => get_string('ofsdesigner_defaultname', 'local_kbacompany')]);
        echo html_writer::tag('button', get_string('ofsdesigner_create', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    } else {
        echo ofs_workspace_renderer::poster($design['poster'], ['state' => 'design']);
        $project = $design['selected'];
        $revision = (int)$project->revision;
        if (!empty($design['editable'])) {
            $catalog = $design['product_catalog'];
            $companyoptions = ['' => get_string('ofsworkspace_no_canonical_product', 'local_kbacompany')];
            foreach ($catalog['company'] as $product) {
                $companyoptions[$product['uuid']] = $product['code'] . ' — ' . $product['name'];
            }
            echo $OUTPUT->heading(get_string('ofsworkspace_productbindings', 'local_kbacompany'), 3);
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/kbacompany/ofs_designer.php'),
                'class' => 'kbacompany-form kbacompany-ofs-binding-form']);
            foreach (['uuid' => $uuid, 'object' => $project->uuid, 'revision' => $revision,
                    'action' => 'visualsettings', 'sesskey' => sesskey()] as $key => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
            }
            echo html_writer::label(get_string('ofsworkspace_companyproductcanonical', 'local_kbacompany'), 'companyproductuuid');
            echo html_writer::select($companyoptions, 'companyproductuuid', (string)($design['payload']['companyproductuuid'] ?? ''), false,
                ['id' => 'companyproductuuid']);
            echo html_writer::label(get_string('ofsworkspace_legacyfallback', 'local_kbacompany'), 'companyproduct');
            echo html_writer::tag('textarea', s((string)($design['payload']['companyproduct'] ?? '')),
                ['id' => 'companyproduct', 'name' => 'companyproduct', 'rows' => 2, 'maxlength' => 4000]);
            echo html_writer::tag('button', get_string('savechanges', 'moodle'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo html_writer::end_tag('form');

            $tiers = [];
            foreach (ofs_visual_designer_service::tiers() as $tier) {
                $tiers[$tier] = get_string('ofsdesigner_tier_' . $tier, 'local_kbacompany');
            }
            echo html_writer::start_div('kbacompany-ofs-binding-grid');
            foreach (($design['payload']['nodes'] ?? []) as $node) {
                $sourceuuid = (string)($node['sourceuuid'] ?? '');
                $nodeproducts = $sourceuuid === '' ? [] : ($catalog['bytarget']['node:' . $sourceuuid] ?? []);
                $productoptions = ['' => get_string('ofsworkspace_no_canonical_product', 'local_kbacompany')];
                foreach ($nodeproducts as $product) {
                    $productoptions[$product['uuid']] = $product['code'] . ' — ' . $product['name'];
                }
                echo html_writer::start_tag('details', ['class' => 'kbacompany-ofs-binding-card']);
                echo html_writer::tag('summary', s($node['code'] . ' — ' . $node['name']));
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/kbacompany/ofs_designer.php'),
                    'class' => 'kbacompany-ofs-inline-form']);
                foreach (['uuid' => $uuid, 'object' => $project->uuid, 'revision' => $revision, 'action' => 'visualnode',
                        'node' => $node['uuid'], 'sesskey' => sesskey()] as $key => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
                }
                echo html_writer::label(get_string('design_nodename', 'local_kbacompany'), 'ofs_name_' . $node['uuid']);
                echo html_writer::empty_tag('input', ['id' => 'ofs_name_' . $node['uuid'], 'name' => 'name',
                    'value' => $node['name'], 'required' => 'required', 'maxlength' => 255]);
                echo html_writer::label(get_string('ofsdesigner_tier', 'local_kbacompany'), 'ofs_tier_' . $node['uuid']);
                echo html_writer::select($tiers, 'visualtier', $node['visualtier'], false, ['id' => 'ofs_tier_' . $node['uuid']]);
                echo html_writer::label(get_string('ofsworkspace_productcanonical', 'local_kbacompany'), 'ofs_productuuid_' . $node['uuid']);
                $selectattrs = ['id' => 'ofs_productuuid_' . $node['uuid']];
                if ($sourceuuid === '') { $selectattrs['disabled'] = 'disabled'; }
                echo html_writer::select($productoptions, 'productuuid', (string)($node['productuuid'] ?? ''), false, $selectattrs);
                if ($sourceuuid === '') {
                    echo html_writer::div(get_string('ofsworkspace_newnodeproducthelp', 'local_kbacompany'), 'text-muted small');
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'productuuid', 'value' => '']);
                }
                echo html_writer::label(get_string('ofsworkspace_legacyfallback', 'local_kbacompany'), 'ofs_product_' . $node['uuid']);
                echo html_writer::tag('textarea', s((string)($node['product'] ?? '')),
                    ['id' => 'ofs_product_' . $node['uuid'], 'name' => 'product', 'rows' => 2, 'maxlength' => 4000]);
                echo html_writer::tag('button', get_string('savechanges', 'moodle'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']);
                echo html_writer::end_tag('form');
                echo html_writer::end_tag('details');
            }
            echo html_writer::end_div();

            echo $OUTPUT->heading(get_string('ofsdesigner_adddepartment', 'local_kbacompany'), 3);
            $parentoptions = ['' => '—'];
            foreach (($design['payload']['nodes'] ?? []) as $node) { $parentoptions[$node['uuid']] = $node['code'] . ' — ' . $node['name']; }
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/kbacompany/ofs_designer.php'),
                'class' => 'kbacompany-form']);
            foreach (['uuid' => $uuid, 'object' => $project->uuid, 'revision' => $revision, 'action' => 'insert',
                    'sesskey' => sesskey()] as $key => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
            }
            echo html_writer::label(get_string('design_code', 'local_kbacompany'), 'ofs_insert_code');
            echo html_writer::empty_tag('input', ['id' => 'ofs_insert_code', 'name' => 'code', 'required' => 'required']);
            echo html_writer::label(get_string('design_nodename', 'local_kbacompany'), 'ofs_insert_name');
            echo html_writer::empty_tag('input', ['id' => 'ofs_insert_name', 'name' => 'name', 'required' => 'required']);
            echo html_writer::label(get_string('ofsdesigner_parent', 'local_kbacompany'), 'ofs_insert_parent');
            echo html_writer::select($parentoptions, 'layout_parent', '', false, ['id' => 'ofs_insert_parent']);
            echo html_writer::label(get_string('ofsworkspace_legacyfallback', 'local_kbacompany'), 'ofs_insert_product');
            echo html_writer::tag('textarea', '', ['id' => 'ofs_insert_product', 'name' => 'product', 'rows' => 2]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sortorder', 'value' => 100]);
            echo html_writer::tag('button', get_string('ofsdesigner_adddepartment', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo html_writer::end_tag('form');
        }
    }
}

if ($mode === 'compare') {
    $comparison = $workspace['comparison'];
    if (!$comparison) {
        echo $OUTPUT->notification(get_string('ofsworkspace_noproject', 'local_kbacompany'), 'info');
    } else {
        echo $OUTPUT->heading(get_string('ofsworkspace_comparetitle', 'local_kbacompany'), 3);
        echo html_writer::start_div('kbacompany-summary-grid');
        foreach (['added' => 'ofsworkspace_added', 'changed' => 'ofsworkspace_changed', 'removed' => 'ofsworkspace_removed',
                'product_warnings' => 'ofsworkspace_productwarnings'] as $key => $label) {
            echo html_writer::div(html_writer::div((string)$comparison['counts'][$key], 'kbacompany-summary-value') .
                html_writer::div(get_string($label, 'local_kbacompany'), 'kbacompany-summary-label'), 'kbacompany-summary-card');
        }
        echo html_writer::end_div();
        $table = new html_table();
        $table->head = [get_string('ofsworkspace_change', 'local_kbacompany'), get_string('design_code', 'local_kbacompany'),
            get_string('design_nodename', 'local_kbacompany'), get_string('ofsworkspace_fields', 'local_kbacompany')];
        foreach ($comparison['changes'] as $change) {
            $table->data[] = [get_string('ofsworkspace_' . $change['status'], 'local_kbacompany'), s($change['code']),
                s($change['name']), s(implode(', ', $change['fields']))];
        }
        echo $table->data ? html_writer::table($table) : $OUTPUT->notification(get_string('ofsworkspace_nochanges', 'local_kbacompany'), 'success');
    }
}

if ($mode === 'check') {
    $check = $workspace['check'];
    if (!$check) {
        echo $OUTPUT->notification(get_string('ofsworkspace_noproject', 'local_kbacompany'), 'info');
    } else {
        echo $OUTPUT->heading(get_string('ofsworkspace_checktitle', 'local_kbacompany'), 3);
        echo $OUTPUT->notification($check['ready'] ? get_string('ofsworkspace_ready', 'local_kbacompany') :
            get_string('ofsworkspace_notready', 'local_kbacompany'), $check['ready'] ? 'success' : 'warning');
        echo html_writer::start_div('kbacompany-summary-grid');
        foreach (['nodes' => 'ofsworkspace_nodes', 'canonical_products' => 'ofsworkspace_canonicalproducts',
                'structural_issues' => 'ofsworkspace_structuralissues', 'product_issues' => 'ofsworkspace_productissues',
                'changes' => 'ofsworkspace_changes'] as $key => $label) {
            echo html_writer::div(html_writer::div((string)$check['summary'][$key], 'kbacompany-summary-value') .
                html_writer::div(get_string($label, 'local_kbacompany'), 'kbacompany-summary-label'), 'kbacompany-summary-card');
        }
        echo html_writer::end_div();
        if ($check['structural_issues']) {
            echo $OUTPUT->heading(get_string('ofsworkspace_structuralissues', 'local_kbacompany'), 4);
            echo html_writer::start_tag('ul');
            foreach ($check['structural_issues'] as $issue) {
                echo html_writer::tag('li', get_string('design_' . $issue['key'], 'local_kbacompany', s($issue['label'])));
            }
            echo html_writer::end_tag('ul');
        }
        if ($check['product_issues']) {
            echo $OUTPUT->heading(get_string('ofsworkspace_productissues', 'local_kbacompany'), 4);
            echo html_writer::start_tag('ul');
            foreach ($check['product_issues'] as $issue) { echo html_writer::tag('li', s($issue['label'])); }
            echo html_writer::end_tag('ul');
        }
        if (!empty($workspace['design']['selected'])) {
            echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/designs.php', [
                'uuid' => $uuid, 'object' => $workspace['design']['selected']->uuid,
            ]), get_string('ofsworkspace_openworkflow', 'local_kbacompany'), ['class' => 'btn btn-primary']), 'mb-4');
        }
    }
}

if ($mode === 'current') {
if (count($data['branches']) > 1) {
    $options = [];
    foreach ($data['branches'] as $branch) {
        $options[$branch['uuid']] = format_string($branch['name']);
    }
    $select = new single_select(new moodle_url('/local/kbacompany/ofs.php', ['uuid' => $uuid]),
        'branch', $options, $data['selected_branch_uuid'], null);
    $select->label = get_string('branch', 'local_kbacompany');
    echo $OUTPUT->render($select);
}

$summary = [
    'org_units_with_ckp' => 'ofs_unitswithckp',
    'org_units_without_ckp' => 'ofs_unitswithoutckp',
    'functions_with_product' => 'ofs_functionswithproduct',
    'internal_interfaces' => 'ofs_interfaces',
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($summary as $key => $label) {
    echo html_writer::div(
        html_writer::div((string)$data['summary'][$key], 'kbacompany-summary-value') .
        html_writer::div(get_string($label, 'local_kbacompany'), 'kbacompany-summary-label'),
        'kbacompany-summary-card');
}
echo html_writer::end_div();

// Reusable rendering helpers.
$renderproduct = static function(array $product, bool $showcustomers = true): string {
    $body = html_writer::tag('strong', s($product['code']) . ' — ' . format_string($product['name']));
    if ($product['acceptance'] !== '') {
        $body .= html_writer::div(get_string('productacceptance', 'local_kbacompany') . ': ' .
            s($product['acceptance']), 'kbacompany-ofs-meta');
    }
    if ($product['metrics']) {
        $metricnames = array_map(static function(array $metric): string {
            return $metric['code'] . ' — ' . $metric['name'];
        }, $product['metrics']);
        $body .= html_writer::div(get_string('ofs_linkedmetrics', 'local_kbacompany') . ': ' .
            s(implode('; ', $metricnames)), 'kbacompany-ofs-meta');
    }
    if ($showcustomers && $product['customers']) {
        $names = array_column($product['customers'], 'orgunit_name');
        $body .= html_writer::div(get_string('ofs_internalcustomers', 'local_kbacompany') . ': ' .
            s(implode(', ', $names)), 'kbacompany-ofs-meta');
    }
    return html_writer::div($body, 'kbacompany-ofs-product');
};

// Company CKP.
echo $OUTPUT->heading(get_string('ofs_companyckp', 'local_kbacompany'), 3);
if ($data['company_products']) {
    foreach ($data['company_products'] as $product) {
        echo $renderproduct($product);
    }
} else {
    echo $OUTPUT->notification(get_string('ofs_nocompanyckp', 'local_kbacompany'), 'warning');
}

$targetoptions = ['company:' . $uuid => get_string('producttargetcompany', 'local_kbacompany') . ': ' . $data['company']['name']];
$productoptions = [];
$nodeoptions = [];
foreach ($data['products'] as $product) {
    $productoptions[$product['uuid']] = $product['code'] . ' — ' . $product['name'];
}
foreach ($data['nodes'] as $node) {
    $targetoptions['node:' . $node['uuid']] = get_string('producttargetnode', 'local_kbacompany') . ': ' .
        $node['code'] . ' — ' . $node['name'];
    $nodeoptions[$node['uuid']] = $node['code'] . ' — ' . $node['name'];
}
foreach ($data['positions'] as $position) {
    $targetoptions['position:' . $position['uuid']] = get_string('producttargetposition', 'local_kbacompany') . ': ' .
        $position['code'] . ' — ' . $position['name'];
}

if ($data['can_manage_ckp']) {
    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', get_string('ofs_addckp', 'local_kbacompany'));
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
    foreach (['uuid' => $uuid, 'branch' => $data['selected_branch_uuid'], 'sesskey' => sesskey(), 'action' => 'ckp'] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::label(get_string('productcode', 'local_kbacompany'), 'ofs_code');
    echo html_writer::empty_tag('input', ['id' => 'ofs_code', 'name' => 'code', 'required' => 'required']);
    echo html_writer::label(get_string('productname', 'local_kbacompany'), 'ofs_name');
    echo html_writer::empty_tag('input', ['id' => 'ofs_name', 'name' => 'name', 'required' => 'required']);
    echo html_writer::label(get_string('producttarget', 'local_kbacompany'), 'ofs_target');
    echo html_writer::select($targetoptions, 'target', '', false, ['id' => 'ofs_target', 'required' => 'required']);
    echo html_writer::label(get_string('productacceptance', 'local_kbacompany'), 'ofs_acceptance');
    echo html_writer::tag('textarea', '', ['id' => 'ofs_acceptance', 'name' => 'acceptance', 'rows' => 3,
        'required' => 'required']);
    echo html_writer::tag('button', get_string('ofs_addckp', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
}

// Product-functional OFS cards.
echo $OUTPUT->heading(get_string('ofs_units', 'local_kbacompany'), 3);
if (!$data['nodes']) {
    echo $OUTPUT->notification(get_string('emptyorganizingboard', 'local_kbacompany'), 'info');
} else {
    echo html_writer::start_div('kbacompany-board');
    foreach ($data['nodes'] as $node) {
        echo html_writer::start_div('kbacompany-board-node kbacompany-ofs-node',
            ['style' => '--kba-node-color:' . s($node['color'])]);
        echo html_writer::div(s($node['code']), 'kbacompany-board-code');
        echo html_writer::tag('h3', format_string($node['name']), ['class' => 'kbacompany-board-name']);
        echo html_writer::div(get_string('nodetype' . $node['type'], 'local_kbacompany'), 'kbacompany-board-type');
        echo html_writer::tag('strong', get_string('ofs_unitckp', 'local_kbacompany'), ['class' => 'd-block mt-3']);
        if ($node['products']) {
            foreach ($node['products'] as $product) {
                echo $renderproduct($product);
            }
        } else {
            echo html_writer::div(get_string('ofs_missingckp', 'local_kbacompany'), 'kbacompany-ofs-missing');
        }
        if ($node['internal_inputs']) {
            $inputs = array_map(static function(array $item): string {
                return $item['product_code'] . ' — ' . $item['product_name'];
            }, $node['internal_inputs']);
            echo html_writer::div(get_string('ofs_internalinputs', 'local_kbacompany') . ': ' .
                s(implode('; ', $inputs)), 'kbacompany-ofs-meta mt-2');
        }
        $withproduct = count(array_filter($node['functions'], static function(array $function): bool {
            return $function['product'] !== null;
        }));
        echo html_writer::div(get_string('ofs_functionsratio', 'local_kbacompany', (object)[
            'with' => $withproduct, 'total' => count($node['functions'])]), 'kbacompany-ofs-meta mt-2');
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/positions.php', [
            'uuid' => $uuid, 'node' => $node['uuid'],
        ]), get_string('openpositions', 'local_kbacompany')), 'mt-3');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

// Function products are derived from existing Function → Result → Product links.
echo $OUTPUT->heading(get_string('ofs_functionproducts', 'local_kbacompany'), 3);
$functiontable = new html_table();
$functiontable->head = [get_string('ac_kind_function', 'local_kbacompany'), get_string('productname', 'local_kbacompany'),
    get_string('statname', 'local_kbacompany')];
$metricnames = [];
foreach ($data['products'] as $product) {
    foreach ($product['metrics'] as $metric) {
        $metricnames[$metric['uuid']] = $metric['code'] . ' — ' . $metric['name'];
    }
}
foreach ($data['functions'] as $function) {
    $functiontable->data[] = [
        s($function['code']) . ' — ' . format_string($function['name']),
        $function['product'] ? s(($function['product']['code'] !== '' ? $function['product']['code'] . ' — ' : '') . $function['product']['name']) :
            html_writer::span(get_string('ofs_missingfunctionproduct', 'local_kbacompany'), 'text-warning'),
        $function['main_metric_uuid'] !== '' ? s($metricnames[$function['main_metric_uuid']] ?? '—') : '—',
    ];
}
echo $functiontable->data ? html_writer::table($functiontable) :
    $OUTPUT->notification(get_string('ofs_nofunctions', 'local_kbacompany'), 'info');
$functionlinks = html_writer::link(new moodle_url('/local/kbacompany/functions.php', ['uuid' => $uuid]),
    get_string('ofs_opencanonicalfunctions', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2']);
echo html_writer::div($functionlinks, 'mb-4');

// Explicit internal customer interfaces.
echo $OUTPUT->heading(get_string('ofs_interfaces', 'local_kbacompany'), 3);
$interfacetable = new html_table();
$interfacetable->head = [get_string('productname', 'local_kbacompany'), get_string('ofs_supplier', 'local_kbacompany'),
    get_string('ofs_internalcustomer', 'local_kbacompany'), get_string('actions', 'moodle')];
$productbyuuid = array_column($data['products'], null, 'uuid');
$nodebyuuid = array_column($data['nodes'], null, 'uuid');
foreach ($data['interfaces'] as $interface) {
    $product = $productbyuuid[$interface['product_uuid']] ?? null;
    $customer = $nodebyuuid[$interface['customer_orgunit_uuid']] ?? null;
    if (!$product || !$customer) {
        continue;
    }
    $actioncell = '—';
    if ($data['can_manage_interfaces']) {
        $actioncell = html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-inline-form']);
        foreach ([
            'uuid' => $uuid, 'branch' => $data['selected_branch_uuid'], 'sesskey' => sesskey(),
            'action' => 'uncustomer', 'relationuuid' => $interface['relation_uuid'],
            'revision' => $interface['revision'], 'reason' => get_string('ofs_unlinkreason', 'local_kbacompany'),
        ] as $key => $value) {
            $actioncell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        $actioncell .= html_writer::tag('button', get_string('ofs_unlinkcustomer', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
        $actioncell .= html_writer::end_tag('form');
    }
    $interfacetable->data[] = [s($product['code'] . ' — ' . $product['name']),
        s($product['supplier']['name'] ?: '—'), format_string($customer['name']), $actioncell];
}
echo $interfacetable->data ? html_writer::table($interfacetable) :
    $OUTPUT->notification(get_string('ofs_nointerfaces', 'local_kbacompany'), 'info');

if ($data['can_manage_interfaces'] && $productoptions && $nodeoptions) {
    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', get_string('ofs_linkcustomer', 'local_kbacompany'));
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
    foreach (['uuid' => $uuid, 'branch' => $data['selected_branch_uuid'], 'sesskey' => sesskey(), 'action' => 'customer'] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::label(get_string('productname', 'local_kbacompany'), 'ofs_productuuid');
    echo html_writer::select($productoptions, 'productuuid', '', false, ['id' => 'ofs_productuuid', 'required' => 'required']);
    echo html_writer::label(get_string('ofs_internalcustomer', 'local_kbacompany'), 'ofs_customeruuid');
    echo html_writer::select($nodeoptions, 'customeruuid', '', false, ['id' => 'ofs_customeruuid', 'required' => 'required']);
    echo html_writer::label(get_string('ofs_relationreason', 'local_kbacompany'), 'ofs_reason');
    echo html_writer::empty_tag('input', ['id' => 'ofs_reason', 'name' => 'reason']);
    echo html_writer::tag('button', get_string('ofs_linkcustomer', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
}

echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/processes.php', ['uuid' => $uuid]),
    get_string('process_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']), 'mb-3');
echo $OUTPUT->notification(get_string('ofs_processnotice', 'local_kbacompany'), 'info');
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
