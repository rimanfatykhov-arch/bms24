<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ai_bridge_service;
use local_kbacompany\local\company_context_domain_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$item = optional_param('item', '', PARAM_RAW_TRIMMED);
$revision = optional_param('revision', 0, PARAM_INT);
$screen = optional_param('screen', '', PARAM_ALPHANUMEXT);
$data = company_context_domain_service::context($uuid, (int)$USER->id);
$canmanage = !empty($data['can_manage']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'save') {
        $saved = company_context_domain_service::save_philosophy($uuid, (int)$USER->id, [
            'purpose' => required_param('purpose', PARAM_RAW_TRIMMED),
            'mission' => required_param('mission', PARAM_RAW_TRIMMED),
            'vision' => required_param('vision', PARAM_RAW_TRIMMED),
            'horizon' => optional_param('horizon', '', PARAM_RAW_TRIMMED),
            'notes' => optional_param('notes', '', PARAM_RAW_TRIMMED),
        ], optional_param('item', '', PARAM_RAW_TRIMMED), optional_param('revision', 0, PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid, 'item' => $saved]),
            get_string('companycontext_saved', 'local_kbacompany'));
    } else if ($action === 'publish') {
        company_context_domain_service::publish_philosophy($uuid, (int)$USER->id,
            required_param('item', PARAM_RAW_TRIMMED), required_param('revision', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid, 'item' => required_param('item', PARAM_RAW_TRIMMED)]),
            get_string('companycontext_published', 'local_kbacompany'));
    } else if ($action === 'archive') {
        company_context_domain_service::archive_philosophy($uuid, (int)$USER->id,
            required_param('item', PARAM_RAW_TRIMMED), required_param('revision', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid]),
            get_string('companycontext_archived', 'local_kbacompany'));
    } else if ($action === 'saveprinciple') {
        $philosophyuuid = required_param('philosophyuuid', PARAM_RAW_TRIMMED);
        $companyphilosophy = company_context_domain_service::philosophy($uuid, (int)$USER->id, $philosophyuuid);
        company_context_domain_service::save_principle($uuid, (int)$USER->id, [
            'philosophyuuid' => $philosophyuuid,
            'philosophyrevision' => (int)$companyphilosophy['revision'],
            'title' => required_param('title', PARAM_RAW_TRIMMED),
            'formulation' => required_param('formulation', PARAM_RAW_TRIMMED),
            'rationale' => optional_param('rationale', '', PARAM_RAW_TRIMMED),
            'sortorder' => optional_param('sortorder', 0, PARAM_INT),
        ], optional_param('principle', '', PARAM_RAW_TRIMMED), optional_param('principlerevision', 0, PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid, 'item' => $philosophyuuid]),
            get_string('companycontext_saved', 'local_kbacompany'));
    } else if ($action === 'publishprinciple') {
        company_context_domain_service::publish_principle($uuid, (int)$USER->id,
            required_param('principle', PARAM_RAW_TRIMMED), required_param('principlerevision', PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid, 'item' => required_param('item', PARAM_RAW_TRIMMED)]),
            get_string('companycontext_published', 'local_kbacompany'));
    }
}

$record = $item !== '' ? company_context_domain_service::philosophy($uuid, (int)$USER->id, $item, $revision) : null;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('companycontext_philosophy', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'philosophy', get_string('companycontext_philosophy', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'philosophy');

echo html_writer::start_div('d-flex justify-content-between align-items-start mb-4');
echo html_writer::div($OUTPUT->heading(get_string('companycontext_philosophy', 'local_kbacompany') . ': ' .
    format_string($data['company']['name']), 2, 'mb-1') . html_writer::tag('p',
    get_string('companycontext_philosophy_intro', 'local_kbacompany'), ['class' => 'text-muted mb-0']));
if ($canmanage && $screen === '' && !$record) {
    echo html_writer::link(new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid, 'screen' => 'edit']),
        get_string('companycontext_create_philosophy', 'local_kbacompany'), ['class' => 'btn btn-primary']);
}
echo html_writer::end_div();

$aistatus = \local_kbacompany\local\bms_ai_bridge_service::status();
$aienabled = !empty($aistatus['available']);

if ($screen === 'edit') {
    if (!$canmanage) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
    $values = $record ?: ['uuid'=>'','revision'=>0,'stablecode'=>'','purpose'=>'','mission'=>'','vision'=>'','horizon'=>'','notes'=>''];
    echo html_writer::start_tag('form', ['method'=>'post','class'=>'bms-record-editview']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'save']);
    if ($record) {
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'item','value'=>$record['uuid']]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'revision','value'=>$record['revision']]);
    }
    echo html_writer::start_div('card mb-3');
    echo html_writer::div(get_string('companycontext_section_identity','local_kbacompany'),'card-header font-weight-bold');
    echo html_writer::start_div('card-body');
    echo html_writer::div(html_writer::tag('label',get_string('companycontext_stablecode','local_kbacompany')) .
        html_writer::div($record ? s($record['stablecode']) : get_string('financialcrud_stablecode_auto','local_kbacompany'),'form-control bg-light text-muted'),'form-group');
    echo html_writer::div(html_writer::tag('label',get_string('companycontext_horizon','local_kbacompany'),['for'=>'ctx-horizon']) .
        html_writer::empty_tag('input',['id'=>'ctx-horizon','name'=>'horizon','type'=>'text','class'=>'form-control','value'=>$values['horizon']]),'form-group');
    echo html_writer::end_div(); echo html_writer::end_div();
    foreach ([['purpose','companycontext_purpose',5],['mission','companycontext_mission',5],['vision','companycontext_vision',7],['notes','companycontext_notes',4]] as $spec) {
        echo html_writer::start_div('card mb-3');
        if ($spec[0] !== 'notes') {
            echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
            echo html_writer::tag('span', get_string($spec[1],'local_kbacompany'), ['class'=>'font-weight-bold']);
            if ($aienabled) {
                echo html_writer::tag('button', '✨ ' . get_string('bmsai_help','local_kbacompany'), [
                    'type'=>'button','class'=>'btn btn-outline-secondary btn-sm bms-ai-help','data-field'=>$spec[0],
                    'data-target'=>'ctx-'.$spec[0]
                ]);
            } else {
                echo html_writer::tag('span', get_string('bmsai_unavailable_short', 'local_kbacompany'), ['class'=>'badge badge-light text-muted']);
            }
            echo html_writer::end_div();
        } else {
            echo html_writer::div(get_string($spec[1],'local_kbacompany'),'card-header font-weight-bold');
        }
        echo html_writer::start_div('card-body');
        echo html_writer::tag('textarea',s($values[$spec[0]]),['id'=>'ctx-'.$spec[0],'name'=>$spec[0],'rows'=>$spec[2],'class'=>'form-control','required'=>$spec[0] === 'notes' ? null : 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
    }
    echo html_writer::start_div('d-flex justify-content-end');
    echo html_writer::link(new moodle_url('/local/kbacompany/philosophy.php',$record?['uuid'=>$uuid,'item'=>$record['uuid']]:['uuid'=>$uuid]),get_string('cancel'),['class'=>'btn btn-secondary mr-2']);
    echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::end_div(); echo html_writer::end_tag('form');
    echo html_writer::div('', 'bms-ai-drawer d-none', ['id'=>'bms-ai-panel','role'=>'dialog','aria-modal'=>'true']);
} else if ($record) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex justify-content-between');
    echo html_writer::div($OUTPUT->heading(s($record['stablecode']),3,'mb-1') . html_writer::tag('span',s($record['status']),['class'=>'badge badge-secondary']));
    if ($canmanage) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/philosophy.php',['uuid'=>$uuid,'item'=>$record['uuid'],'revision'=>$record['revision'],'screen'=>'edit']),get_string('edit'),['class'=>'btn btn-primary mr-2']));
    }
    echo html_writer::end_div();
    foreach ([['companycontext_purpose','purpose'],['companycontext_mission','mission'],['companycontext_vision','vision'],['companycontext_horizon','horizon']] as $spec) {
        echo html_writer::tag('h5',get_string($spec[0],'local_kbacompany'),['class'=>'mt-4']);
        echo html_writer::div(nl2br(s((string)$record[$spec[1]])),'mb-2');
    }
    echo html_writer::end_div(); echo html_writer::end_div();
    if ($canmanage && $record['status']==='draft') {
        echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-2']);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'publish']);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'item','value'=>$record['uuid']]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'revision','value'=>$record['revision']]);
        echo html_writer::tag('button',get_string('companycontext_publish','local_kbacompany'),['type'=>'submit','class'=>'btn btn-success']);
        echo html_writer::end_tag('form');
    }
    echo $OUTPUT->heading(get_string('companycontext_principles','local_kbacompany'),3,'mt-4');
    $principles = company_context_domain_service::principles($uuid,(int)$USER->id,$record['uuid']);
    foreach ($principles as $principle) {
        echo html_writer::start_div('card mb-2'); echo html_writer::start_div('card-body');
        echo html_writer::tag('strong',s($principle['stablecode'].' — '.$principle['title']));
        echo html_writer::tag('p',nl2br(s($principle['formulation'])),['class'=>'mb-1 mt-2']);
        echo html_writer::tag('span',s($principle['status']),['class'=>'badge badge-light']);
        if ($canmanage && $principle['status']==='draft') {
            echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline ml-2']);
            foreach ([['sesskey',sesskey()],['action','publishprinciple'],['item',$record['uuid']],['principle',$principle['uuid']],['principlerevision',$principle['revision']]] as $h) echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
            echo html_writer::tag('button',get_string('companycontext_publish','local_kbacompany'),['type'=>'submit','class'=>'btn btn-link btn-sm']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div(); echo html_writer::end_div();
    }
    if ($canmanage) {
        echo html_writer::start_div('card mt-3'); echo html_writer::div(get_string('companycontext_add_principle','local_kbacompany'),'card-header font-weight-bold'); echo html_writer::start_div('card-body');
        echo html_writer::start_tag('form',['method'=>'post']);
        foreach ([['sesskey',sesskey()],['action','saveprinciple'],['philosophyuuid',$record['uuid']]] as $h) echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
        echo html_writer::div(html_writer::tag('label',get_string('companycontext_principle_title','local_kbacompany')) . html_writer::empty_tag('input',['name'=>'title','class'=>'form-control','required'=>'required']),'form-group');
        echo html_writer::div(html_writer::tag('label',get_string('companycontext_principle_formulation','local_kbacompany')) . html_writer::tag('textarea','',['name'=>'formulation','rows'=>4,'class'=>'form-control','required'=>'required']),'form-group');
        echo html_writer::div(html_writer::tag('label',get_string('companycontext_principle_rationale','local_kbacompany')) . html_writer::tag('textarea','',['name'=>'rationale','rows'=>3,'class'=>'form-control']),'form-group');
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sortorder','value'=>count($principles)+1]);
        echo html_writer::tag('button',get_string('companycontext_add_principle','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
        echo html_writer::end_tag('form'); echo html_writer::end_div(); echo html_writer::end_div();
    }
} else {
    if (!$data['philosophies']) {
        echo $OUTPUT->notification(get_string('companycontext_philosophy_empty','local_kbacompany'),'info');
    } else {
        $table = new html_table();
        $table->head=[get_string('companycontext_stablecode','local_kbacompany'),get_string('companycontext_mission','local_kbacompany'),get_string('companycontext_horizon','local_kbacompany'),get_string('status')];
        foreach ($data['philosophies'] as $row) {
            $table->data[]=[html_writer::link(new moodle_url('/local/kbacompany/philosophy.php',['uuid'=>$uuid,'item'=>$row['uuid']]),s($row['stablecode'])),shorten_text($row['mission'],140),s($row['horizon']),s($row['status'])];
        }
        echo html_writer::table($table);
    }
}

if ($screen === 'edit') {
    \local_kbacompany\local\bms_ai_ui_service::init($uuid, 'philosophy', $record);
}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
