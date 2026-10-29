<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\budgeting_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = budgeting_service::context($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('budgeting_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    if ($action === 'savebudget') {
        $count = min(50, max(1, optional_param('linecount', 12, PARAM_INT)));
        $lines = [];
        for ($i = 1; $i <= $count; $i++) {
            $code = optional_param('linecode_' . $i, '', PARAM_RAW_TRIMMED);
            $name = optional_param('linename_' . $i, '', PARAM_RAW_TRIMMED);
            if ($code === '' && $name === '') { continue; }
            $lines[] = [
                'linecode' => $code,
                'name' => $name,
                'linetype' => optional_param('linetype_' . $i, 'expense', PARAM_ALPHA),
                'periodstart' => optional_param('linestart_' . $i, '', PARAM_RAW_TRIMMED),
                'periodend' => optional_param('lineend_' . $i, '', PARAM_RAW_TRIMMED),
                'planamount' => optional_param('plan_' . $i, '0', PARAM_RAW_TRIMMED),
                'limitamount' => optional_param('limit_' . $i, '0', PARAM_RAW_TRIMMED),
                'ownerpositionuuid' => optional_param('owner_' . $i, '', PARAM_RAW_TRIMMED),
                'indicatoruuid' => optional_param('indicator_' . $i, '', PARAM_RAW_TRIMMED),
                'sourceinfo' => optional_param('source_' . $i, '', PARAM_RAW_TRIMMED),
            ];
        }
        $targetcompound = required_param('targetcompound', PARAM_RAW_TRIMMED);
        $targetparts = explode(':', $targetcompound, 2);
        if (count($targetparts) !== 2) { throw new moodle_exception('budgeting_reference', 'local_kbacompany'); }
        budgeting_service::save_budget($uuid, (int)$USER->id, [
            'code' => required_param('code', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'budgettype' => $targetparts[0],
            'scenariouuid' => required_param('scenariouuid', PARAM_RAW_TRIMMED),
            'centeruuid' => optional_param('centeruuid', '', PARAM_RAW_TRIMMED),
            'targetuuid' => $targetparts[1],
            'periodstart' => required_param('periodstart', PARAM_RAW_TRIMMED),
            'periodend' => required_param('periodend', PARAM_RAW_TRIMMED),
            'currency' => required_param('currency', PARAM_ALPHA),
            'approvalrule' => optional_param('approvalrule', '', PARAM_RAW_TRIMMED),
            'notes' => optional_param('notes', '', PARAM_RAW_TRIMMED),
        ], $lines, $item, $revision);
    } else if ($action === 'submitbudget') {
        budgeting_service::submit_budget($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'approvebudget') {
        budgeting_service::approve_budget($uuid, (int)$USER->id, $item, $revision,
            optional_param('decisionnote', '', PARAM_RAW_TRIMMED));
    } else if ($action === 'rejectbudget') {
        budgeting_service::reject_budget($uuid, (int)$USER->id, $item, $revision,
            required_param('decisionnote', PARAM_RAW_TRIMMED));
    } else if ($action === 'archivebudget') {
        budgeting_service::archive_budget($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'recordfact') {
        budgeting_service::record_fact($uuid, (int)$USER->id, $item, $revision,
            required_param('linecode', PARAM_RAW_TRIMMED), [
                'asofdate' => required_param('asofdate', PARAM_RAW_TRIMMED),
                'actualamount' => required_param('actualamount', PARAM_RAW_TRIMMED),
                'sourcekind' => required_param('sourcekind', PARAM_ALPHA),
                'sourceinfo' => required_param('sourceinfo', PARAM_RAW_TRIMMED),
            ]);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid]),
        get_string('budgeting_saved', 'local_kbacompany'));
}

$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$viewuuid = optional_param('viewuuid', '', PARAM_RAW_TRIMMED);
$viewrevision = optional_param('viewrevision', 0, PARAM_INT);
$editing = null; $viewing = null;
if ($edituuid !== '') { $editing = budgeting_service::budget($uuid, (int)$USER->id, $edituuid); }
if ($viewuuid !== '') { $viewing = budgeting_service::budget($uuid, (int)$USER->id, $viewuuid, $viewrevision); }

$scenarioopts = [];
foreach ($data['scenarios'] as $r) { $scenarioopts[$r['uuid']] = $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision']; }
$centeropts = ['' => get_string('budgeting_companywide', 'local_kbacompany')];
foreach ($data['centers'] as $r) { $centeropts[$r['uuid']] = $r['code'] . ' — ' . $r['name'] . ' · v' . $r['revision']; }
$positionopts = ['' => '—']; foreach ($data['positions'] as $r) { $positionopts[$r['uuid']] = $r['code'] . ' — ' . $r['name']; }
$indicatoropts = ['' => '—']; foreach ($data['indicators'] as $r) { $indicatoropts[$r['uuid']] = $r['code'] . ' — ' . $r['name']; }
$targetopts = [];
foreach ($data['budget_types'] as $type) {
    foreach ($data['targets'][$type] as $r) { $targetopts[$type][$r['uuid']] = $r['code'] . ' — ' . $r['name']; }
}
$lineopts = []; foreach ($data['line_types'] as $t) { $lineopts[$t] = get_string('budgeting_linetype_' . $t, 'local_kbacompany'); }
$factopts = []; foreach ($data['fact_source_kinds'] as $t) { $factopts[$t] = get_string('budgeting_factsource_' . $t, 'local_kbacompany'); }

$hidden = static function(string $action, array $row = null) use ($uuid): string {
    $s = html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>$action]);
    if ($row) {
        $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'item','value'=>$row['uuid']]);
        $s .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'revision','value'=>$row['revision']]);
    }
    return $s;
};
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('budgeting_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('budgeting_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('budgeting_intro', 'local_kbacompany'), ['class'=>'lead']);
echo $OUTPUT->notification(get_string('budgeting_scope_notice', 'local_kbacompany'), 'info');
echo html_writer::link(new moodle_url('/local/kbacompany/financial_model.php', ['uuid'=>$uuid]),
    get_string('budgeting_back_model', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
echo html_writer::link(new moodle_url('/local/kbacompany/financial_statements.php', ['uuid'=>$uuid]),
    get_string('financialstatement_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary mr-2 mb-3']);
echo html_writer::link(new moodle_url('/local/kbacompany/resource_gate.php', ['uuid'=>$uuid]),
    get_string('resourcegate_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary mb-3']);

echo $OUTPUT->heading(get_string('budgeting_registry', 'local_kbacompany'), 3);
if ($data['budgets']) {
    $t = new html_table();
    $t->head = [get_string('budgeting_code','local_kbacompany'), get_string('budgeting_name','local_kbacompany'),
        get_string('budgeting_type','local_kbacompany'), get_string('budgeting_period','local_kbacompany'),
        get_string('budgeting_status','local_kbacompany'), get_string('budgeting_plan','local_kbacompany'),
        get_string('budgeting_actual','local_kbacompany'), get_string('actions')];
    foreach ($data['budgets'] as $r) {
        $actions = html_writer::link(new moodle_url('/local/kbacompany/budgeting.php',['uuid'=>$uuid,'viewuuid'=>$r['uuid'],'viewrevision'=>($r['effectiveapprovedrevision']?:$r['revision'])]),
            get_string('view'), ['class'=>'btn btn-sm btn-outline-primary mr-1']);
        if ($r['status'] !== 'submitted' && $r['status'] !== 'archived') {
            $actions .= html_writer::link(new moodle_url('/local/kbacompany/budgeting.php',['uuid'=>$uuid,'edituuid'=>$r['uuid']]),
                get_string('edit'), ['class'=>'btn btn-sm btn-outline-secondary mr-1']);
        }
        $make = static function(string $action, string $label, string $class, bool $note = false) use ($hidden, $r): string {
            $x = html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-1']) . $hidden($action,$r);
            if ($note) { $x .= html_writer::empty_tag('input',['type'=>'text','name'=>'decisionnote','placeholder'=>get_string('budgeting_decisionnote','local_kbacompany'),'class'=>'form-control form-control-sm d-inline-block mr-1','style'=>'width:160px']); }
            $x .= html_writer::tag('button',$label,['type'=>'submit','class'=>'btn btn-sm '.$class]) . html_writer::end_tag('form'); return $x;
        };
        if ($r['status'] === 'draft') { $actions .= $make('submitbudget',get_string('budgeting_submit','local_kbacompany'),'btn-outline-primary'); }
        if ($r['status'] === 'submitted') {
            $actions .= $make('approvebudget',get_string('budgeting_approve','local_kbacompany'),'btn-outline-success',true);
            $actions .= $make('rejectbudget',get_string('budgeting_reject','local_kbacompany'),'btn-outline-danger',true);
        }
        if ($r['status'] !== 'submitted') { $actions .= $make('archivebudget',get_string('budgeting_archive','local_kbacompany'),'btn-outline-danger'); }
        $t->data[] = [s($r['code']),s($r['name']),s(get_string('budgeting_type_'.$r['budgettype'],'local_kbacompany')),
            s($r['periodstart'].' — '.$r['periodend']),s(get_string('budgeting_status_'.$r['status'],'local_kbacompany').' · v'.$r['revision']),
            s(format_float((float)$r['summary']['plan'],2).' '.$r['currency']), s(format_float((float)$r['summary']['actual'],2).' '.$r['currency']), $actions];
    }
    echo html_writer::table($t);
} else { echo html_writer::tag('p',get_string('budgeting_empty','local_kbacompany'),['class'=>'text-muted']); }

if ($viewing) {
    $b=$viewing['budget'];
    echo $OUTPUT->heading(get_string('budgeting_planfact','local_kbacompany').': '.s($b['name']),3);
    $t=new html_table(); $t->head=[get_string('budgeting_linecode','local_kbacompany'),get_string('budgeting_line','local_kbacompany'),
        get_string('budgeting_plan','local_kbacompany'),get_string('budgeting_limit','local_kbacompany'),get_string('budgeting_actual','local_kbacompany'),
        get_string('budgeting_variance','local_kbacompany'),get_string('budgeting_asof','local_kbacompany')];
    foreach($viewing['lines'] as $l){
        $var=$l['variance']===null?'—':format_float((float)$l['variance'],2).($l['variancepct']===null?'':' ('.format_float((float)$l['variancepct'],2).'%)');
        $t->data[]=[s($l['linecode']),s($l['name']),s(format_float($l['planamount'],2)),s(format_float($l['limitamount'],2)),
            $l['actualamount']===null?'—':s(format_float($l['actualamount'],2)),s($var),s($l['asofdate']?:'—')];
    }
    echo html_writer::table($t);
    if ($b['status']==='approved') {
        echo $OUTPUT->heading(get_string('budgeting_recordfact','local_kbacompany'),4);
        foreach($viewing['lines'] as $l){
            echo html_writer::start_tag('form',['method'=>'post','class'=>'form-inline mb-2']);
            echo $hidden('recordfact',$b);
            echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'linecode','value'=>$l['linecode']]);
            echo html_writer::tag('span',s($l['linecode'].' — '.$l['name']),['class'=>'mr-2']);
            echo html_writer::empty_tag('input',['type'=>'date','name'=>'asofdate','value'=>date('Y-m-d'),'class'=>'form-control form-control-sm mr-1','required'=>'required']);
            echo html_writer::empty_tag('input',['type'=>'number','step'=>'0.01','min'=>'0','name'=>'actualamount','class'=>'form-control form-control-sm mr-1','placeholder'=>get_string('budgeting_actual','local_kbacompany'),'required'=>'required']);
            echo html_writer::select($factopts,'sourcekind','manual',false,['class'=>'form-control form-control-sm mr-1']);
            echo html_writer::empty_tag('input',['type'=>'text','name'=>'sourceinfo','class'=>'form-control form-control-sm mr-1','placeholder'=>get_string('budgeting_source','local_kbacompany'),'required'=>'required']);
            echo html_writer::tag('button',get_string('budgeting_record','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-primary']);
            echo html_writer::end_tag('form');
        }
    }
}

if ($data['scenarios']) {
    $b = $editing ? $editing['budget'] : ['uuid'=>'','revision'=>0,'code'=>'','name'=>'','budgettype'=>'project','scenariouuid'=>array_key_first($scenarioopts),
        'centeruuid'=>'','targetuuid'=>'','periodstart'=>date('Y-m-01'),'periodend'=>date('Y-m-t'),'currency'=>'RUB','approvalrule'=>'','notes'=>''];
    $lines = $editing ? $editing['lines'] : [];
    $count=max(12,count($lines));
    echo $OUTPUT->heading($editing?get_string('budgeting_edit','local_kbacompany'):get_string('budgeting_new','local_kbacompany'),3);
    echo html_writer::start_tag('form',['method'=>'post']); echo $hidden('savebudget',$b);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'linecount','value'=>$count]);
    $f=static function($label,$control){return html_writer::tag('div',html_writer::tag('label',$label,['class'=>'font-weight-bold']).$control,['class'=>'form-group']);};
    $inp=static function($n,$v='',$type='text',$req=true){$a=['name'=>$n,'value'=>$v,'type'=>$type,'class'=>'form-control'];if($type==='number'){$a['step']='0.01';$a['min']='0';}if($req)$a['required']='required';return html_writer::empty_tag('input',$a);};
    echo $f(get_string('budgeting_code','local_kbacompany'),$inp('code',$b['code']));
    echo $f(get_string('budgeting_name','local_kbacompany'),$inp('name',$b['name']));
    $typeopts=[];foreach($data['budget_types'] as $x){$typeopts[$x]=get_string('budgeting_type_'.$x,'local_kbacompany');}
    echo $f(get_string('financialmodel_scenarios','local_kbacompany'),html_writer::select($scenarioopts,'scenariouuid',$b['scenariouuid'],false,['class'=>'form-control']));
    echo $f(get_string('budgeting_center','local_kbacompany'),html_writer::select($centeropts,'centeruuid',$b['centeruuid'],false,['class'=>'form-control']));
    $alltargets=[]; foreach($targetopts as $type=>$opts){foreach($opts as $k=>$v){$alltargets[$type.':'.$k]=get_string('budgeting_type_'.$type,'local_kbacompany').' — '.$v;}}
    echo $f(get_string('budgeting_target','local_kbacompany'),html_writer::select($alltargets,'targetcompound',$b['budgettype'].':'.$b['targetuuid'],false,['class'=>'form-control','required'=>'required']));
    echo $f(get_string('financialmodel_periodstart','local_kbacompany'),$inp('periodstart',$b['periodstart'],'date'));
    echo $f(get_string('financialmodel_periodend','local_kbacompany'),$inp('periodend',$b['periodend'],'date'));
    echo $f(get_string('financialmodel_currency','local_kbacompany'),$inp('currency',$b['currency']));
    echo $f(get_string('budgeting_approvalrule','local_kbacompany'),html_writer::tag('textarea',s($b['approvalrule']),['name'=>'approvalrule','class'=>'form-control','rows'=>2]));
    echo $f(get_string('budgeting_notes','local_kbacompany'),html_writer::tag('textarea',s($b['notes']),['name'=>'notes','class'=>'form-control','rows'=>2]));
    $t=new html_table();$t->head=[get_string('budgeting_linecode','local_kbacompany'),get_string('budgeting_line','local_kbacompany'),get_string('budgeting_linetype','local_kbacompany'),get_string('budgeting_plan','local_kbacompany'),get_string('budgeting_limit','local_kbacompany'),get_string('budgeting_owner','local_kbacompany'),get_string('budgeting_indicator','local_kbacompany'),get_string('budgeting_source','local_kbacompany')];
    for($i=1;$i<=$count;$i++){$l=$lines[$i-1]??['linecode'=>'','name'=>'','linetype'=>'expense','planamount'=>0,'limitamount'=>0,'ownerpositionuuid'=>'','indicatoruuid'=>'','sourceinfo'=>''];$t->data[]=[
        $inp('linecode_'.$i,$l['linecode'],'text',false),$inp('linename_'.$i,$l['name'],'text',false),html_writer::select($lineopts,'linetype_'.$i,$l['linetype'],false,['class'=>'form-control form-control-sm']),
        $inp('plan_'.$i,(string)$l['planamount'],'number',false),$inp('limit_'.$i,(string)$l['limitamount'],'number',false),html_writer::select($positionopts,'owner_'.$i,$l['ownerpositionuuid'],false,['class'=>'form-control form-control-sm']),html_writer::select($indicatoropts,'indicator_'.$i,$l['indicatoruuid'],false,['class'=>'form-control form-control-sm']),$inp('source_'.$i,$l['sourceinfo'],'text',false)];}
    echo html_writer::table($t); echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary']); echo html_writer::end_tag('form');
} else { echo $OUTPUT->notification(get_string('budgeting_need_scenario','local_kbacompany'),'warning'); }
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
