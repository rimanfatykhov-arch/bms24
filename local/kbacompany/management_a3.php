<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\management_a3_service;
use local_kbacompany\local\management_cycle_template_service;
use local_kbacompany\local\a3_standardization_service;
use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$cycleuuid = optional_param('cycle', '', PARAM_RAW_TRIMMED);
$a3uuid = optional_param('a3', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'create') {
        $a3uuid = management_a3_service::create($uuid, (int)$USER->id,
            required_param('cycle', PARAM_RAW_TRIMMED), [
                'title'=>required_param('title', PARAM_TEXT),
                'problemcontext'=>optional_param('problemcontext', '', PARAM_TEXT),
                'indicatoruuid'=>optional_param('indicatoruuid', '', PARAM_RAW_TRIMMED),
                'currentstate'=>optional_param('currentstate', '', PARAM_TEXT),
                'targetstate'=>optional_param('targetstate', '', PARAM_TEXT),
                'targetfact'=>optional_param('targetfact', '', PARAM_TEXT),
            ]);
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'save') {
        management_a3_service::save($uuid, (int)$USER->id, required_param('a3', PARAM_RAW_TRIMMED), [
            'title'=>required_param('title', PARAM_TEXT),
            'problemcontext'=>optional_param('problemcontext', '', PARAM_TEXT),
            'indicatoruuid'=>optional_param('indicatoruuid', '', PARAM_RAW_TRIMMED),
            'currentstate'=>optional_param('currentstate', '', PARAM_TEXT),
            'targetstate'=>optional_param('targetstate', '', PARAM_TEXT),
            'gapanalysis'=>optional_param('gapanalysis', '', PARAM_TEXT),
            'causeanalysis'=>optional_param('causeanalysis', '', PARAM_TEXT),
            'countermeasures'=>optional_param('countermeasures', '', PARAM_TEXT),
            'targetfact'=>optional_param('targetfact', '', PARAM_TEXT),
            'repeatfact'=>optional_param('repeatfact', '', PARAM_TEXT),
            'conclusion'=>optional_param('conclusion', '', PARAM_TEXT),
            'standardization'=>optional_param('standardization', '', PARAM_TEXT),
        ]);
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'transition') {
        management_a3_service::transition($uuid, (int)$USER->id, required_param('a3', PARAM_RAW_TRIMMED),
            required_param('status', PARAM_ALPHA));
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'capturefact') {
        management_a3_service::capture_repeat_fact($uuid, (int)$USER->id, required_param('a3', PARAM_RAW_TRIMMED));
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'createtask') {
        management_a3_service::create_countermeasure_task($uuid, (int)$USER->id,
            required_param('a3', PARAM_RAW_TRIMMED), required_param('planuuid', PARAM_RAW_TRIMMED), [
                'title'=>required_param('tasktitle', PARAM_TEXT),
                'product'=>required_param('taskproduct', PARAM_TEXT),
                'description'=>optional_param('taskdescription', '', PARAM_TEXT),
                'planminutes'=>optional_param('planminutes', 0, PARAM_INT),
                'duedate'=>optional_param('duedate', '', PARAM_TEXT),
            ]);
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'linktask') {
        management_a3_service::link_task($uuid, (int)$USER->id, required_param('a3', PARAM_RAW_TRIMMED),
            required_param('taskuuid', PARAM_RAW_TRIMMED));
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'stdpropose') {
        $targetkey = required_param('targetkey', PARAM_RAW_TRIMMED);
        $parts = explode('|', $targetkey, 2);
        $kind = (string)($parts[0] ?? '');
        $ref = (string)($parts[1] ?? '');
        a3_standardization_service::propose($uuid, (int)$USER->id, required_param('a3', PARAM_RAW_TRIMMED),
            $kind, $ref, required_param('proposal', PARAM_TEXT));
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    } else if ($action === 'stddecide') {
        a3_standardization_service::decide($uuid, (int)$USER->id,
            required_param('std', PARAM_RAW_TRIMMED), required_param('decision', PARAM_ALPHA));
        redirect(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'a3'=>$a3uuid]));
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/management_a3.php', ['uuid'=>$uuid,'cycle'=>$cycleuuid,'a3'=>$a3uuid]));
$PAGE->set_title(get_string('a3_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'managementcycles', get_string('a3_title','local_kbacompany'));
echo bms_workspace_ux_service::management_nav($uuid, 'cycles');

if ($a3uuid === '') {
    $cycles = management_cycle_template_service::context($uuid, (int)$USER->id, $cycleuuid);
    $cycle = $cycles['selected'];
    if (!$cycle) { throw new moodle_exception('mcycle_not_found', 'local_kbacompany'); }
    echo bms_workspace_ux_service::record_header(get_string('a3_create_title','local_kbacompany'),
        get_string('a3_create_intro','local_kbacompany'), get_string('a3_eyebrow','local_kbacompany'),
        s($cycle['title']), '');
    $form = '';
    foreach ([['sesskey',sesskey()],['uuid',$uuid],['cycle',$cycle['uuid']],['action','create']] as $h) {
        $form .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
    }
    $form .= html_writer::tag('label',get_string('a3_name','local_kbacompany'),['class'=>'form-label']);
    $form .= html_writer::empty_tag('input',['name'=>'title','class'=>'form-control mb-3','required'=>'required']);
    $form .= html_writer::tag('label',get_string('a3_indicator','local_kbacompany'),['class'=>'form-label']);
    $opts = [''=>get_string('a3_indicator_none','local_kbacompany')];
    foreach ($cycles['indicators'] as $m) {
        if (in_array((string)$m['uuid'], (array)$cycle['indicatoruuids'], true)) {
            $opts[(string)$m['uuid']] = (string)($m['title'] ?? $m['name'] ?? $m['uuid']);
        }
    }
    $form .= html_writer::select($opts,'indicatoruuid','',false,['class'=>'form-control mb-3']);
    foreach ([['problemcontext','a3_problem_context'],['currentstate','a3_current_state'],['targetstate','a3_target_state']] as $f) {
        $form .= html_writer::tag('label',get_string($f[1],'local_kbacompany'),['class'=>'form-label']);
        $form .= html_writer::tag('textarea','',['name'=>$f[0],'rows'=>4,'class'=>'form-control mb-3']);
    }
    $form .= html_writer::tag('label',get_string('a3_target_fact','local_kbacompany'),['class'=>'form-label']);
    $form .= html_writer::empty_tag('input',['name'=>'targetfact','class'=>'form-control mb-3']);
    $form .= html_writer::tag('button',get_string('a3_create','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::tag('form',$form,['method'=>'post','class'=>'card card-body']);
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$uuid,'cycle'=>$cycle['uuid']]),get_string('back')),'mt-3');
} else {
    $data = management_a3_service::context($uuid, (int)$USER->id, $a3uuid);
    $r = $data['row']; $cycle = $data['cycle'];
    $actions = '';
    $transitions = ['draft'=>['active'=>'a3_start'],'active'=>['verify'=>'a3_to_verify'],'verify'=>['active'=>'a3_reopen','closed'=>'a3_close']];
    foreach (($transitions[(string)$r->status] ?? []) as $to=>$label) {
        $f = html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]).
            html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]).
            html_writer::empty_tag('input',['type'=>'hidden','name'=>'a3','value'=>$a3uuid]).
            html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'transition']).
            html_writer::empty_tag('input',['type'=>'hidden','name'=>'status','value'=>$to]).
            html_writer::tag('button',get_string($label,'local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-primary btn-sm']);
        $actions .= html_writer::tag('form',$f,['method'=>'post','class'=>'d-inline mr-1']);
    }
    echo bms_workspace_ux_service::record_header(s($r->title), get_string('a3_status_'.$r->status,'local_kbacompany'),
        get_string('a3_eyebrow','local_kbacompany'), s($cycle->title), $actions);

    $form = '';
    foreach ([['sesskey',sesskey()],['uuid',$uuid],['a3',$a3uuid],['action','save']] as $h) {
        $form .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
    }
    $form .= html_writer::tag('label',get_string('a3_name','local_kbacompany'),['class'=>'form-label']);
    $form .= html_writer::empty_tag('input',['name'=>'title','value'=>$r->title,'class'=>'form-control mb-3','required'=>'required']);
    $form .= html_writer::tag('label',get_string('a3_indicator','local_kbacompany'),['class'=>'form-label']);
    $opts=[''=>get_string('a3_indicator_none','local_kbacompany')]+$data['indicators'];
    $form .= html_writer::select($opts,'indicatoruuid',(string)$r->indicatoruuid,false,['class'=>'form-control mb-3']);
    $sections = [
        ['problemcontext','a3_section_1'],['currentstate','a3_section_2'],['targetstate','a3_section_3'],
        ['gapanalysis','a3_section_4'],['causeanalysis','a3_section_5'],['countermeasures','a3_section_6'],
    ];
    foreach ($sections as $sec) {
        $form .= html_writer::tag('h4',get_string($sec[1],'local_kbacompany'),['class'=>'h6 mt-4']);
        $form .= html_writer::tag('textarea',s((string)$r->{$sec[0]}),['name'=>$sec[0],'rows'=>5,'class'=>'form-control']);
    }
    $form .= html_writer::tag('h4',get_string('a3_section_8','local_kbacompany'),['class'=>'h6 mt-4']);
    $factgrid = html_writer::div(get_string('a3_baseline_fact','local_kbacompany').': '.s((string)$r->baselinefact).' '.s((string)$r->baselineperiod),'mb-2');
    $factgrid .= html_writer::tag('label',get_string('a3_target_fact','local_kbacompany'),['class'=>'form-label']).
        html_writer::empty_tag('input',['name'=>'targetfact','value'=>$r->targetfact,'class'=>'form-control mb-2']);
    $factgrid .= html_writer::tag('label',get_string('a3_repeat_fact','local_kbacompany'),['class'=>'form-label']).
        html_writer::empty_tag('input',['name'=>'repeatfact','value'=>$r->repeatfact,'class'=>'form-control mb-2']);
    if ((string)$r->repeatperiod !== '') { $factgrid .= html_writer::div(get_string('a3_repeat_period','local_kbacompany').': '.s((string)$r->repeatperiod),'small text-muted'); }
    $form .= html_writer::div($factgrid,'card card-body bg-light');
    $form .= html_writer::tag('h4',get_string('a3_result_conclusion','local_kbacompany'),['class'=>'h6 mt-4']);
    $form .= html_writer::tag('textarea',s((string)$r->conclusion),['name'=>'conclusion','rows'=>4,'class'=>'form-control']);
    $form .= html_writer::tag('h4',get_string('a3_section_9','local_kbacompany'),['class'=>'h6 mt-4']);
    $form .= html_writer::tag('textarea',s((string)$r->standardization),['name'=>'standardization','rows'=>4,'class'=>'form-control']);
    if ((string)$r->status !== 'closed') { $form .= html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary mt-3']); }
    echo html_writer::tag('form',$form,['method'=>'post','class'=>'card card-body mb-4']);

    if ((string)$r->indicatoruuid !== '' && (string)$r->status !== 'closed') {
        $f=html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]).html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]).html_writer::empty_tag('input',['type'=>'hidden','name'=>'a3','value'=>$a3uuid]).html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'capturefact']).html_writer::tag('button',get_string('a3_capture_fact','local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-secondary btn-sm']);
        echo html_writer::tag('form',$f,['method'=>'post','class'=>'mb-4']);
    }

    echo $OUTPUT->heading(get_string('a3_section_7','local_kbacompany'),2,'h4');
    echo html_writer::div(get_string('a3_tasks_title','local_kbacompany'),'text-muted mb-2');
    if ($data['linked_tasks']) {
        $table=new html_table(); $table->attributes['class']='table table-sm table-hover';
        $table->head=[get_string('taskcenter_title','local_kbacompany'),get_string('taskcenter_product','local_kbacompany'),get_string('taskcenter_status','local_kbacompany')];
        foreach($data['linked_tasks'] as $t){ $table->data[]=[html_writer::link($t['url'],s($t['title'])),s($t['product']),s($t['status'])]; }
        echo html_writer::table($table);
    } else { echo html_writer::div(get_string('a3_tasks_empty','local_kbacompany'),'alert alert-light border'); }

    if ((string)$r->status !== 'closed') {
        $draftplans=[]; foreach($data['plans'] as $p){ if($p['status']==='draft'){$draftplans[$p['uuid']]=$p['label'];} }
        if ($draftplans) {
            $f=''; foreach ([['sesskey',sesskey()],['uuid',$uuid],['a3',$a3uuid],['action','createtask']] as $h){$f.=html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);} 
            $f.=html_writer::tag('h5',get_string('a3_create_task','local_kbacompany'),['class'=>'h6']);
            $f.=html_writer::select($draftplans,'planuuid','',false,['class'=>'form-control mb-2']);
            $f.=html_writer::empty_tag('input',['name'=>'tasktitle','required'=>'required','placeholder'=>get_string('taskcenter_title','local_kbacompany'),'class'=>'form-control mb-2']);
            $f.=html_writer::empty_tag('input',['name'=>'taskproduct','required'=>'required','placeholder'=>get_string('taskcenter_product','local_kbacompany'),'class'=>'form-control mb-2']);
            $f.=html_writer::empty_tag('input',['name'=>'planminutes','type'=>'number','min'=>'0','placeholder'=>get_string('taskcenter_planminutes','local_kbacompany'),'class'=>'form-control mb-2']);
            $f.=html_writer::empty_tag('input',['name'=>'duedate','type'=>'date','class'=>'form-control mb-2']);
            $f.=html_writer::tag('button',get_string('a3_create_task_button','local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-primary btn-sm']);
            echo html_writer::tag('form',$f,['method'=>'post','class'=>'card card-body mb-3']);
        }
        $available = array_diff_key($data['task_options'], array_flip(array_map(function($t){return $t['uuid'];},$data['linked_tasks'])));
        if ($available) {
            $f=''; foreach ([['sesskey',sesskey()],['uuid',$uuid],['a3',$a3uuid],['action','linktask']] as $h){$f.=html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);} 
            $f.=html_writer::select($available,'taskuuid','',false,['class'=>'form-control d-inline-block mr-2','style'=>'max-width:500px']).html_writer::tag('button',get_string('a3_link_task','local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-secondary btn-sm']);
            echo html_writer::tag('form',$f,['method'=>'post','class'=>'mb-4']);
        }
    }
    echo $OUTPUT->heading(get_string('a3std_title','local_kbacompany'),2,'h4');
    echo html_writer::div(get_string('a3std_intro','local_kbacompany'),'alert alert-light border');
    $std = (array)($data['standardization_context'] ?? []);
    $stdrecords = (array)($std['records'] ?? []);
    if ($stdrecords) {
        $table = new html_table();
        $table->attributes['class'] = 'table table-sm table-hover';
        $table->head = [get_string('a3std_target','local_kbacompany'), get_string('a3std_proposal','local_kbacompany'), get_string('a3_status','local_kbacompany'), ''];
        foreach ($stdrecords as $sr) {
            $kindlabel = get_string('a3std_target_'.$sr['targetkind'],'local_kbacompany');
            $target = $kindlabel . ': ' . (($sr['targetlabel'] ?? '') !== '' ? s($sr['targetlabel']) : get_string('a3std_no_target','local_kbacompany'));
            if (($sr['url'] ?? '') !== '') { $target .= ' · ' . html_writer::link($sr['url'],get_string('a3std_open_target','local_kbacompany')); }
            $buttons='';
            if (($sr['status'] ?? '') === 'proposed') {
                foreach (['confirmed'=>'a3std_confirm','cancelled'=>'a3std_cancel'] as $decision=>$label) {
                    $bf='';
                    foreach ([['sesskey',sesskey()],['uuid',$uuid],['a3',$a3uuid],['action','stddecide'],['std',$sr['uuid']],['decision',$decision]] as $h) {
                        $bf .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
                    }
                    $bf .= html_writer::tag('button',get_string($label,'local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-secondary btn-sm']);
                    $buttons .= html_writer::tag('form',$bf,['method'=>'post','class'=>'d-inline mr-1']);
                }
            }
            $table->data[] = [$target, s($sr['proposal']), get_string('a3std_status_'.$sr['status'],'local_kbacompany'), $buttons];
        }
        echo html_writer::table($table);
    } else {
        echo html_writer::div(get_string('a3std_empty','local_kbacompany'),'text-muted mb-3');
    }
    if ((string)$r->status === 'closed') {
        $targetopts=[];
        foreach ((array)($std['target_options'] ?? []) as $kind=>$opts) {
            $kindlabel=get_string('a3std_target_'.$kind,'local_kbacompany');
            $targetopts[$kind.'|']=$kindlabel.' — '.get_string('a3std_new_target','local_kbacompany');
            foreach ((array)$opts as $ref=>$label) { $targetopts[$kind.'|'.$ref]=$kindlabel.' — '.$label; }
        }
        if ($targetopts) {
            $sf='';
            foreach ([['sesskey',sesskey()],['uuid',$uuid],['a3',$a3uuid],['action','stdpropose']] as $h) {
                $sf .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
            }
            $sf .= html_writer::tag('label',get_string('a3std_target','local_kbacompany'),['class'=>'form-label']);
            $sf .= html_writer::select($targetopts,'targetkey','',false,['class'=>'form-control mb-2']);
            $sf .= html_writer::tag('label',get_string('a3std_proposal','local_kbacompany'),['class'=>'form-label']);
            $sf .= html_writer::tag('textarea','',['name'=>'proposal','rows'=>4,'required'=>'required','class'=>'form-control mb-2']);
            $sf .= html_writer::tag('button',get_string('a3std_propose','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
            echo html_writer::tag('form',$sf,['method'=>'post','class'=>'card card-body mb-4']);
        }
    }

    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$uuid,'cycle'=>(string)$cycle->uuid]),get_string('back')),'mt-3');
}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
