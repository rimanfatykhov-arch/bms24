<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\product_service;
require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$mode = optional_param('mode', 'view', PARAM_ALPHA);
$statuuid = optional_param('stat', '', PARAM_RAW_TRIMMED);
if (!in_array($mode, ['view', 'create', 'edit'], true)) { throw new moodle_exception('indicator_invalid', 'local_kbacompany'); }
$data = indicator_registry_service::context($uuid, (int)$USER->id, $statuuid);
$canmanage = (bool)$data['can_manage'];
if (in_array($mode, ['create', 'edit'], true) && !$canmanage) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
$record = null;
if ($mode !== 'create') {
    if ($statuuid === '') { throw new moodle_exception('indicator_invalid', 'local_kbacompany'); }
    $record = $DB->get_record('local_kbacompany_stat', ['companyuuid'=>$uuid,'uuid'=>$statuuid], '*', MUST_EXIST);
}
// Enforce lifecycle permissions at route level, including direct POST to an archived metric.
if ($mode === 'edit' && !indicator_registry_service::is_metric_active($uuid, $statuuid)) {
    throw new moodle_exception('indicator_crud_archived_help', 'local_kbacompany');
}
$error = '';
$values = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    if (!in_array($mode, ['create', 'edit'], true) || !$canmanage) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
    $values = [
        'name'=>required_param('name', PARAM_TEXT), 'unit'=>required_param('unit', PARAM_TEXT),
        'periodtype'=>required_param('periodtype', PARAM_ALPHA), 'direction'=>required_param('direction', PARAM_ALPHA),
        'productuuid'=>optional_param('productuuid', '', PARAM_RAW_TRIMMED),
        'description'=>optional_param('description', '', PARAM_TEXT),
        'sourcekind'=>optional_param('sourcekind', 'manual', PARAM_ALPHA),
        'sourceconfig'=>optional_param('sourceconfig', '', PARAM_RAW_TRIMMED),
        'aggregation'=>optional_param('aggregation', 'direct', PARAM_ALPHA),
        'maincompanymetric'=>(bool)optional_param('maincompanymetric', 0, PARAM_BOOL),
    ];
    try {
        if ($mode === 'edit') {
            // Preserve the immutable Stable Code and the canonical metric UUID.
            $values['code'] = (string)$record->code;
            product_service::update_statistic($uuid, (int)$USER->id, $statuuid, $values);
        } else {
            $statuuid = product_service::create_statistic($uuid, (int)$USER->id, $values);
        }
        indicator_registry_service::save_passport($uuid, (int)$USER->id, $statuuid, $values);
        indicator_registry_service::set_main_company_metric($uuid, (int)$USER->id, $statuuid, $values['maincompanymetric']);
        redirect(new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid, 'stat'=>$statuuid]), get_string('indicator_saved','local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid,'mode'=>$mode]+($statuuid!==''?['stat'=>$statuuid]:[])));
$PAGE->set_title(get_string('indicator_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'products', get_string('indicator_title','local_kbacompany'));
$listurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'all']);
echo html_writer::link($listurl, '← '.get_string('indicator_registry_title','local_kbacompany'), ['class'=>'btn btn-outline-secondary mb-3']);
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
if ($mode === 'view') {
    // CRUD-001: record detail is a navigation hub, never an embedded edit form.
    $selected = $data['selected'];
    if (!$selected || (string)$selected['uuid'] !== $statuuid) {
        throw new moodle_exception('indicator_invalid', 'local_kbacompany');
    }
    $editurl = new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid,'mode'=>'edit','stat'=>$statuuid]);
    $inputurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'input','stat'=>$statuuid]);
    $normurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'norms','stat'=>$statuuid]);
    $treeurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'tree','stat'=>$statuuid]);
    echo html_writer::start_div('card p-4 mb-3');
    echo html_writer::tag('div', s($record->code), ['class'=>'text-muted mb-1']);
    echo $OUTPUT->heading(s($record->name), 2);
    echo html_writer::tag('p', s(get_string('indicator_crud_metric_status','local_kbacompany')).': '.
        s((string)$selected['status']), ['class'=>'text-muted']);
    if ($canmanage && indicator_registry_service::is_metric_active($uuid, $statuuid)) {
        echo html_writer::link($editurl, get_string('edit'), ['class'=>'btn btn-outline-primary mr-2 mb-2']);
    }
    if (indicator_registry_service::is_metric_active($uuid, $statuuid)) {
        echo html_writer::link($inputurl, get_string('indicator_crud_record_fact','local_kbacompany'), ['class'=>'btn btn-success mb-2']);
    } else {
        echo html_writer::div(get_string('indicator_crud_archived_help','local_kbacompany'), 'alert alert-warning');
    }
    echo html_writer::end_div();
    echo html_writer::start_div('card p-4 mb-3');
    echo $OUTPUT->heading(get_string('indicator_record_basics','local_kbacompany'), 3);
    foreach ([get_string('statunit','local_kbacompany')=>$record->unit,
              get_string('statperiod','local_kbacompany')=>get_string('stat'.$record->periodtype,'local_kbacompany'),
              get_string('statdirection','local_kbacompany')=>get_string('stat'.$record->direction,'local_kbacompany')] as $label=>$value) {
        echo html_writer::tag('p', html_writer::tag('strong',s($label).': ').s($value));
    }
    if (trim((string)$selected['description']) !== '') {
        echo html_writer::tag('p', s($selected['description']));
    }
    if ($record->productuuid) {
        foreach ($data['products'] as $product) {
            if ((string)$product->uuid === (string)$record->productuuid) {
                $producturl = new moodle_url('/local/kbacompany/product_record.php', ['uuid'=>$uuid,'product'=>$product->uuid]);
                echo html_writer::tag('p', s(get_string('indicator_record_related','local_kbacompany')).': '.
                    html_writer::link($producturl, s($product->code.' — '.$product->name)));
                break;
            }
        }
    }
    echo html_writer::end_div();
    // Each subpanel links back to the canonical operations; no second KPI engine.
    $sections = [
        [get_string('indicator_crud_panel_values','local_kbacompany'), get_string('indicator_crud_panel_values_help','local_kbacompany'), $inputurl],
        [get_string('indicator_crud_panel_norms','local_kbacompany'), get_string('indicator_crud_panel_norms_help','local_kbacompany'), $normurl],
        [get_string('indicator_crud_panel_bindings','local_kbacompany'), get_string('indicator_crud_panel_bindings_help','local_kbacompany'), $treeurl],
        [get_string('indicator_crud_panel_history','local_kbacompany'), get_string('indicator_crud_panel_history_help','local_kbacompany'), new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'input','stat'=>$statuuid,'history'=>1], 'metric-history')],
    ];
    echo html_writer::start_div('row');
    foreach ($sections as $section) {
        echo html_writer::start_div('col-md-6 mb-3');
        echo html_writer::start_div('card p-3 h-100');
        echo $OUTPUT->heading(s($section[0]), 4);
        echo html_writer::tag('p', s($section[1]), ['class'=>'text-muted']);
        echo html_writer::link($section[2], get_string('indicator_crud_panel_open','local_kbacompany'), ['class'=>'btn btn-outline-secondary']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
} else {
    if ($mode === 'edit' && !$values) {
        $values = [
            'name'=>(string)$record->name, 'unit'=>(string)$record->unit,
            'periodtype'=>(string)$record->periodtype, 'direction'=>(string)$record->direction,
            'description'=>(string)($data['selected']['description'] ?? ''),
            'productuuid'=>(string)$record->productuuid,
            'sourcekind'=>(string)($data['selected']['sourcekind'] ?? 'manual'),
            'sourceconfig'=>(string)($data['selected']['sourceconfig'] ?? ''),
            'aggregation'=>(string)($data['selected']['aggregation'] ?? 'direct'),
            'maincompanymetric'=>!empty($data['selected']['maincompany']),
        ];
    }
    echo $OUTPUT->heading($mode === 'edit' ? get_string('edit') . ': ' . s($record->name) : get_string('indicator_create_metric','local_kbacompany'));
    echo html_writer::tag('p', get_string('indicator_create_metric_help','local_kbacompany'), ['class'=>'text-muted']);
    echo html_writer::start_tag('form', ['method'=>'post','class'=>'card p-4 kbacompany-form kba-indicator-crud-form','style'=>'max-width:820px;width:100%;margin:0 auto;display:flex;flex-direction:column;gap:0;']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $field = static function(string $label,string $name,string $value='',bool $required=false,string $help='') use ($uuid): void {
        echo html_writer::start_div($name === 'name' ? 'form-group mb-4 bms-ai-field' : 'form-group mb-4');
        echo html_writer::label($label.($required?' *':''), $name);
        echo html_writer::empty_tag('input',['type'=>'text','id'=>$name,'name'=>$name,'value'=>$value,'class'=>'form-control', 'required'=>$required?'required':null,'style'=>'width:100%;max-width:100%;']);
        if ($help!=='') { echo html_writer::tag('small',$help,['class'=>'text-muted d-block mt-1']); }
        if ($name === 'name') { echo \local_kbacompany\local\bms_ai_workspace_service::field_button($uuid, 'metric', 'name', 'name'); }
        echo html_writer::end_div();
    };
    echo $OUTPUT->heading(get_string('indicator_record_basics','local_kbacompany'), 3);
    echo html_writer::div(html_writer::label(get_string('productcode','local_kbacompany'),'newmetric_code').
        html_writer::empty_tag('input',['id'=>'newmetric_code','type'=>'text','readonly'=>'readonly','value'=>$mode === 'edit' ? (string)$record->code : get_string('indicator_record_code_auto','local_kbacompany'),'class'=>'form-control']).
        html_writer::tag('small',get_string('indicator_record_code_help','local_kbacompany'),['class'=>'text-muted']), 'form-group mb-4');
    $field(get_string('statname','local_kbacompany'),'name',$values['name']??'',true,get_string('indicator_record_name_help','local_kbacompany'));
    $field(get_string('statunit','local_kbacompany'),'unit',$values['unit']??'',true);
    $options=[ 'daily'=>get_string('statdaily','local_kbacompany'), 'weekly'=>get_string('statweekly','local_kbacompany'), 'monthly'=>get_string('statmonthly','local_kbacompany'), 'quarterly'=>get_string('statquarterly','local_kbacompany'), 'annual'=>get_string('statannual','local_kbacompany') ];
    echo html_writer::div(html_writer::label(get_string('statperiod','local_kbacompany'),'periodtype').html_writer::select($options,'periodtype',$values['periodtype']??'weekly',false,['id'=>'periodtype','class'=>'form-control']), 'form-group mb-4');
    $directions=['up'=>get_string('statup','local_kbacompany'),'down'=>get_string('statdown','local_kbacompany'),'neutral'=>get_string('statneutral','local_kbacompany')];
    echo html_writer::div(html_writer::label(get_string('statdirection','local_kbacompany'),'direction').html_writer::select($directions,'direction',$values['direction']??'up',false,['id'=>'direction','class'=>'form-control']), 'form-group mb-4');
    echo html_writer::start_div('form-group mb-4 bms-ai-field');
    echo html_writer::label(get_string('indicator_description','local_kbacompany'),'description');
    echo html_writer::tag('textarea',s($values['description']??''),['id'=>'description','name'=>'description','rows'=>4,'class'=>'form-control']);
    echo \local_kbacompany\local\bms_ai_workspace_service::field_button($uuid, 'metric', 'description', 'description');
    echo html_writer::end_div();
    echo $OUTPUT->heading(get_string('indicator_record_related','local_kbacompany'),3);
    $productopts=[''=>get_string('indicator_product_none','local_kbacompany')];
    foreach($data['products'] as $p) {
        if ((string)($p->status ?? 'active') === 'active' ||
                (string)($values['productuuid'] ?? '') === (string)$p->uuid) {
            $productopts[$p->uuid] = $p->code . ' — ' . $p->name;
        }
    }
    echo html_writer::div(html_writer::label(get_string('indicator_product_optional','local_kbacompany'),'productuuid').html_writer::select($productopts,'productuuid',$values['productuuid']??'',false,['id'=>'productuuid','class'=>'form-control']),'form-group mb-4');
    echo html_writer::start_tag('details',['class'=>'mb-4']);
    echo html_writer::tag('summary',get_string('indicator_crud_advanced','local_kbacompany'));
    echo $OUTPUT->heading(get_string('indicator_record_method','local_kbacompany'),3);
    $sources=['manual'=>get_string('indicator_source_manual','local_kbacompany'),'formula'=>get_string('indicator_source_formula','local_kbacompany'),'integration'=>get_string('indicator_source_integration','local_kbacompany'),'checklist'=>get_string('indicator_source_checklist','local_kbacompany')];
    echo html_writer::div(html_writer::label(get_string('indicator_source','local_kbacompany'),'sourcekind').html_writer::select($sources,'sourcekind',$values['sourcekind']??'manual',false,['id'=>'sourcekind','class'=>'form-control']),'form-group mb-3');
    $aggregations=['direct'=>'direct','sum'=>'sum','average'=>'average','ratio'=>'ratio','last'=>'last','formula'=>'formula'];
    echo html_writer::div(html_writer::label(get_string('indicator_aggregation','local_kbacompany'),'aggregation').html_writer::select($aggregations,'aggregation',$values['aggregation']??'direct',false,['id'=>'aggregation','class'=>'form-control']),'form-group mb-3');
    echo html_writer::div(html_writer::label(get_string('indicator_source_config','local_kbacompany'),'sourceconfig').html_writer::tag('textarea',s($values['sourceconfig']??''),['id'=>'sourceconfig','name'=>'sourceconfig','rows'=>3,'class'=>'form-control']),'form-group mb-3');
    echo html_writer::end_tag('details');
    echo html_writer::div(html_writer::checkbox('maincompanymetric',1,!empty($values['maincompanymetric']),get_string('indicator_main_company_metric','local_kbacompany')),'form-group mb-4');
    echo html_writer::start_div('kba-indicator-form-actions');
    echo html_writer::link($mode === 'edit' ? new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid,'stat'=>$statuuid]) : $listurl,get_string('indicator_record_cancel','local_kbacompany'),['class'=>'btn btn-outline-secondary mr-2']);
    echo html_writer::tag('button',get_string('indicator_record_save','local_kbacompany'),['type'=>'submit','class'=>'btn btn-success']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}
if ($mode !== 'view') { \local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'metric', 'Метрика', true); }
\local_kbacompany\local\bms_page_shell_service::finish($shell);
