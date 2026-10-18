<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\product_service;
require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$productuuid = optional_param('product', '', PARAM_RAW_TRIMMED);
$mode = optional_param('mode', 'view', PARAM_ALPHA);
if (!in_array($mode, ['view', 'create', 'edit'], true)) {
    throw new moodle_exception('productinvalidinput', 'local_kbacompany');
}
$data = product_service::context($uuid, (int)$USER->id);
$canmanage = product_service::can_manage($uuid, (int)$USER->id);
if (($mode === 'create' || $mode === 'edit') && !$canmanage) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
$record = null;
if ($mode !== 'create') {
    if ($productuuid === '') { throw new moodle_exception('productinvalidinput', 'local_kbacompany'); }
    $record = $DB->get_record('local_kbacompany_product',
        ['companyuuid' => $uuid, 'uuid' => $productuuid], '*', MUST_EXIST);
}
if ($record && (string)$record->status === 'archived' && $mode === 'edit') {
    throw new moodle_exception('productarchivedreadonly', 'local_kbacompany');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && optional_param('lifecycle', '', PARAM_ALPHA) !== '') {
    require_sesskey();
    if (!$canmanage || $mode !== 'view' || !$record) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $lifecycle = required_param('lifecycle', PARAM_ALPHA);
    if (!in_array($lifecycle, ['archive', 'restore'], true)) {
        throw new moodle_exception('productinvalidinput', 'local_kbacompany');
    }
    product_service::set_product_archived($uuid, (int)$USER->id, $productuuid, $lifecycle === 'archive');
    redirect(new moodle_url('/local/kbacompany/product_record.php',
        ['uuid' => $uuid, 'product' => $productuuid]), get_string('productsaved', 'local_kbacompany'));
}
// Separate company-scoped pickers for the three business ownership types.
$companylabel = get_string('producttargetcompany', 'local_kbacompany') . ': ' . $data['company']['name'];
$nodes = [];
$positions = [];
foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $uuid, 'active' => 1], 'code ASC') as $item) {
    $nodes[$item->uuid] = $item->code . ' — ' . $item->name;
}
foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $uuid, 'active' => 1], 'code ASC') as $item) {
    $positions[$item->uuid] = $item->code . ' — ' . $item->name;
}
$targets = ['company:' . $uuid => $companylabel];
foreach ($nodes as $id => $name) { $targets['node:' . $id] = get_string('producttargetnode', 'local_kbacompany') . ': ' . $name; }
foreach ($positions as $id => $name) { $targets['position:' . $id] = get_string('producttargetposition', 'local_kbacompany') . ': ' . $name; }
if ($record) {
    $currenttarget = $record->targettype . ':' . $record->targetuuid;
    if (!isset($targets[$currenttarget])) { $targets[$currenttarget] = get_string('producttargetunknown', 'local_kbacompany'); }
}
$error = '';
$posted = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    if (!$canmanage || !in_array($mode, ['create', 'edit'], true)) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $posted = [
        'name' => required_param('name', PARAM_TEXT),
        'acceptance' => required_param('acceptance', PARAM_TEXT),
        'targettype' => required_param('targettype', PARAM_ALPHA),
        'nodeuuid' => optional_param('nodeuuid', '', PARAM_RAW_TRIMMED),
        'positionuuid' => optional_param('positionuuid', '', PARAM_RAW_TRIMMED),
    ];
    $type = $posted['targettype'];
    if (!in_array($type, ['company', 'node', 'position'], true)) {
        throw new moodle_exception('productinvalidinput', 'local_kbacompany');
    }
    $targetuuid = $type === 'company' ? $uuid : ($type === 'node' ? $posted['nodeuuid'] : $posted['positionuuid']);
    if ($targetuuid === '') {
        throw new moodle_exception('productinvalidinput', 'local_kbacompany');
    }
    $payload = ['name' => $posted['name'], 'acceptance' => $posted['acceptance'],
        'targettype' => $type, 'targetuuid' => $targetuuid];
    try {
        if ($mode === 'create') {
            $productuuid = product_service::create_product($uuid, (int)$USER->id, $payload);
        } else {
            product_service::update_product($uuid, (int)$USER->id, $productuuid, $payload);
        }
        redirect(new moodle_url('/local/kbacompany/product_record.php',
            ['uuid' => $uuid, 'product' => $productuuid]), get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}
$pageurl = new moodle_url('/local/kbacompany/product_record.php',
    ['uuid' => $uuid, 'mode' => $mode] + ($productuuid !== '' ? ['product' => $productuuid] : []));
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('productname', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'products', get_string('productsstats', 'local_kbacompany'));
$listurl = new moodle_url('/local/kbacompany/products.php', ['uuid' => $uuid]);
echo html_writer::link($listurl, '← ' . get_string('productregistry', 'local_kbacompany'),
    ['class' => 'btn btn-outline-secondary mb-3']);
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
if ($mode === 'view') {
    echo $OUTPUT->heading(s($record->name));
    echo html_writer::tag('p', s($record->code) . ' · ' .
        get_string((string)$record->status === 'archived' ? 'productstatusarchived' : 'productstatusactive',
            'local_kbacompany'), ['class' => 'text-muted']);
    if ($canmanage && (string)$record->status === 'active') {
        echo html_writer::link(new moodle_url('/local/kbacompany/product_record.php',
            ['uuid' => $uuid, 'product' => $productuuid, 'mode' => 'edit']),
            get_string('edit'), ['class' => 'btn btn-primary mb-3']);
    }
    if ($canmanage) {
        $archived = (string)$record->status === 'archived';
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl,
            'class' => 'mb-3']);
        foreach (['sesskey' => sesskey(), 'lifecycle' => $archived ? 'restore' : 'archive'] as $k => $v) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
        }
        echo html_writer::tag('button', get_string($archived ? 'productrestore' : 'productarchive',
            'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
        echo html_writer::end_tag('form');
    }
    echo $OUTPUT->heading(get_string('productname', 'local_kbacompany'), 3);
    echo html_writer::tag('p', format_string($record->name));
    echo $OUTPUT->heading(get_string('productacceptance', 'local_kbacompany'), 3);
    echo html_writer::tag('div', format_text($record->acceptance, FORMAT_PLAIN), ['class' => 'mb-3']);
    echo $OUTPUT->heading(get_string('producttarget', 'local_kbacompany'), 3);
    echo html_writer::tag('p', s($targets[$record->targettype . ':' . $record->targetuuid]));
    echo $OUTPUT->heading(get_string('statregistry', 'local_kbacompany'), 3);
    $related = new html_table();
    $related->head = [get_string('productcode', 'local_kbacompany'), get_string('statname', 'local_kbacompany')];
    foreach ($data['statistics'] as $stat) {
        if ((string)$stat->productuuid !== $productuuid) { continue; }
        $related->data[] = [s($stat->code), html_writer::link(
            new moodle_url('/local/kbacompany/indicator_record.php', ['uuid' => $uuid, 'stat' => $stat->uuid]),
            s($stat->name))];
    }
    echo $related->data ? html_writer::table($related) :
        html_writer::tag('p', '—', ['class' => 'text-muted']);
} else {
    echo $OUTPUT->heading($mode === 'create' ? get_string('productcreate', 'local_kbacompany') :
        get_string('edit') . ': ' . s($record->name));
    $name = $posted['name'] ?? ($record->name ?? '');
    $acceptance = $posted['acceptance'] ?? ($record->acceptance ?? '');
    $selectedtype = $posted['targettype'] ?? ($record ? $record->targettype : 'company');
    $selectednode = $posted['nodeuuid'] ?? ($record && $record->targettype === 'node' ? $record->targetuuid : '');
    $selectedposition = $posted['positionuuid'] ?? ($record && $record->targettype === 'position' ? $record->targetuuid : '');
    // Do not reintroduce inactive or foreign organization records into selectable options.
    // Legacy records still display their current ownership in DetailView.
    if ($selectednode !== '' && !isset($nodes[$selectednode])) { $selectednode = ''; }
    if ($selectedposition !== '' && !isset($positions[$selectedposition])) { $selectedposition = ''; }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form kba-ckp-crud-form card p-4',
        'style' => 'max-width:980px;width:100%;margin:0 auto']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_tag('div', ['class' => 'form-group mb-4']);
    echo html_writer::label(get_string('productcode', 'local_kbacompany'), 'ckp_code');
    echo html_writer::empty_tag('input', ['id' => 'ckp_code', 'type' => 'text', 'readonly' => 'readonly',
        'class' => 'form-control', 'value' => $mode === 'create' ?
            get_string('productautocodehelp', 'local_kbacompany') : $record->code]);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'form-group mb-3 bms-ai-field']);
    echo html_writer::label(get_string('productname', 'local_kbacompany') . ' *', 'ckp_name');
    echo html_writer::tag('textarea', s($name), ['id' => 'ckp_name', 'name' => 'name', 'rows' => 5,
        'maxlength' => 255, 'required' => 'required', 'class' => 'form-control']);
    echo \local_kbacompany\local\bms_ai_workspace_service::field_button($uuid, 'ckp', 'name', 'ckp_name');
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'form-group mb-3 bms-ai-field']);
    echo html_writer::label(get_string('productacceptance', 'local_kbacompany') . ' *', 'ckp_acceptance');
    echo html_writer::tag('textarea', s($acceptance), ['id' => 'ckp_acceptance', 'name' => 'acceptance',
        'rows' => 7, 'maxlength' => 4000, 'required' => 'required', 'class' => 'form-control']);
    echo \local_kbacompany\local\bms_ai_workspace_service::field_button($uuid, 'ckp', 'acceptance', 'ckp_acceptance');
    echo html_writer::end_tag('div');
    echo $OUTPUT->heading(get_string('productcrud_org_section', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('productcrud_org_help', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::start_tag('fieldset', ['class' => 'form-group mb-4 kba-ckp-owner']);
    echo html_writer::tag('legend', get_string('producttarget', 'local_kbacompany') . ' *', ['class' => 'h5']);
    foreach (['company', 'node', 'position'] as $type) {
        echo html_writer::start_div('kba-ckp-owner-option');
        echo html_writer::empty_tag('input', [
            'type' => 'radio', 'name' => 'targettype', 'id' => 'ckp_type_' . $type,
            'value' => $type, 'checked' => $type === $selectedtype ? 'checked' : null,
            'class' => 'kba-ckp-owner-radio',
        ]);
        echo html_writer::label(get_string('producttarget' . $type, 'local_kbacompany'),
            'ckp_type_' . $type, ['class' => 'kba-ckp-owner-label']);
        echo html_writer::end_div();
    }
    echo html_writer::start_div('ckp-target-fields');
    echo html_writer::div(html_writer::tag('p', s($companylabel), ['class' => 'text-muted mb-0']),
        'ckp-company-target mb-3');
    echo html_writer::start_div('ckp-node-target form-group mb-3');
    echo html_writer::label(get_string('productcrud_select_node', 'local_kbacompany'), 'ckp_node');
    echo html_writer::select(['' => get_string('choosedots')] + $nodes, 'nodeuuid', $selectednode, false,
        ['id' => 'ckp_node', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::start_div('ckp-position-target form-group mb-3');
    echo html_writer::label(get_string('productcrud_select_position', 'local_kbacompany'), 'ckp_position');
    echo html_writer::select(['' => get_string('choosedots')] + $positions, 'positionuuid', $selectedposition, false,
        ['id' => 'ckp_position', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('fieldset');
    echo html_writer::start_tag('div', ['class' => 'kba-ckp-form-actions']);
    echo html_writer::link($record ? new moodle_url('/local/kbacompany/product_record.php',
        ['uuid' => $uuid, 'product' => $productuuid]) : $listurl, get_string('cancel'),
        ['class' => 'btn btn-outline-secondary']);
    echo html_writer::tag('button', get_string('savechanges'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('form');
    echo <<<'CKP_STYLE'
<style>
.kbacompany-form.kba-ckp-crud-form{display:flex;flex-direction:column;gap:0;width:100%;max-width:820px;margin:0 auto;box-sizing:border-box}
.kba-ckp-crud-form > .form-group,.kba-ckp-crud-form > fieldset,.kba-ckp-crud-form > h3,.kba-ckp-crud-form > p,.kba-ckp-crud-form > .kba-ckp-form-actions{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.kba-ckp-crud-form .form-group{display:flex;flex-direction:column;align-items:stretch}
.kba-ckp-crud-form .form-group > label{display:block;align-self:stretch;margin-bottom:8px;font-weight:600}
.kba-ckp-crud-form textarea,.kba-ckp-crud-form input.form-control,.kba-ckp-crud-form select.form-control{width:100%;max-width:100%;box-sizing:border-box}
.kba-ckp-crud-form .kba-ckp-form-actions{display:flex;justify-content:flex-end;align-items:center;flex-wrap:wrap;gap:12px;padding-top:16px;border-top:1px solid #e2e9e5}
.kbacompany-form.kba-ckp-crud-form .kba-ckp-owner .kba-ckp-owner-option{display:flex!important;flex-direction:row!important;justify-content:flex-start!important;align-items:center!important;gap:10px!important;width:100%!important;min-height:32px;max-width:none;margin:0 0 6px;padding:2px 0;box-sizing:border-box}
.kbacompany-form.kba-ckp-crud-form .kba-ckp-owner .kba-ckp-owner-radio{appearance:auto!important;-webkit-appearance:radio!important;position:static!important;display:inline-block!important;float:none!important;flex:0 0 18px!important;width:18px!important;min-width:18px!important;max-width:18px!important;height:18px!important;min-height:18px!important;margin:0!important;padding:0!important;vertical-align:middle;accent-color:#00843d}
.kbacompany-form.kba-ckp-crud-form .kba-ckp-owner .kba-ckp-owner-label{display:inline-block!important;position:static!important;flex:0 1 auto!important;width:auto!important;max-width:calc(100% - 30px)!important;margin:0!important;padding:0!important;align-self:center!important;text-align:left!important;white-space:normal;line-height:1.4;font-weight:500;cursor:pointer}
.kba-ckp-owner .ckp-node-target,.kba-ckp-owner .ckp-position-target{display:none}
.kba-ckp-owner .ckp-target-fields{margin:14px 0 0 0;max-width:100%}
.kbacompany-form .form-control{max-width:100%;width:100%;box-sizing:border-box}
</style>
<script>
(function(){
var group=document.querySelector('.kba-ckp-owner');if(!group){return;}
function sync(){var checked=group.querySelector('input[name="targettype"]:checked');var value=checked?checked.value:'company';
['company','node','position'].forEach(function(t){var el=group.querySelector('.ckp-'+t+'-target');if(el){el.style.display=t===value?'block':'none';}});
var node=group.querySelector('#ckp_node'),position=group.querySelector('#ckp_position');
if(node){node.required=value==='node';node.disabled=value!=='node';}
if(position){position.required=value==='position';position.disabled=value!=='position';}}
group.addEventListener('change',sync);sync();
})();
</script>
CKP_STYLE;
}
if ($mode !== 'view') { \local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'ckp', 'Ценный конечный продукт', true); }
\local_kbacompany\local\bms_page_shell_service::finish($shell);
