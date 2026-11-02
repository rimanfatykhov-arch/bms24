<?php
// Read-only projections keep their provider's owner and canonical source URL.
defined('MOODLE_INTERNAL') || die();
$provider=optional_param('provider','all',PARAM_ALPHANUMEXT);
if(!in_array($provider,['all','legacy','kbaelement','kbacorporate_impl'],true)){$provider='all';}
$sourcescope=in_array($scope,['my','created','review','overdue','all'],true)?$scope:'my';
$data=\local_kbacompany\local\task_provider_service::context($uuid,$actor,$sourcescope,$provider==='all'?'external':$provider,
    $sourcescope==='all'&&$manager?0:$actor);
$settings=$view==='sourceconfig';
echo \local_kbacompany\local\bms_workspace_ux_service::record_header(
    \local_kbacompany\local\task_ui_service::tr($settings?'tui_source_settings':'tui_source_tasks'),
    \local_kbacompany\local\task_ui_service::tr('tui_sources_help'),'','',
    \local_kbacompany\local\task_ui_service::link(\local_kbacompany\local\task_ui_service::url($uuid),\local_kbacompany\local\task_ui_service::tr('tui_back_list')));
if(!$settings){
    $opts=['all'=>get_string('taskprovider_all','local_kbacompany'),'legacy'=>get_string('taskprovider_legacy','local_kbacompany'),'kbaelement'=>get_string('taskprovider_kbaelement','local_kbacompany'),'kbacorporate_impl'=>get_string('taskprovider_kbacorporate_impl','local_kbacompany')];
    $scopes=[];foreach(['my','created','review','overdue','all'] as $s){if($s!=='all'||$manager){$scopes[$s]=get_string('taskcenter_scope_'.$s,'local_kbacompany');}}
    echo html_writer::tag('form',\local_kbacompany\local\task_ui_service::hidden(['uuid'=>$uuid,'view'=>'sources']).
        \local_kbacompany\local\task_ui_service::control('provider',['label'=>'taskprovider_source','options'=>$opts],$provider).
        \local_kbacompany\local\task_ui_service::control('scope',['label'=>'tui_representation','options'=>$scopes],$sourcescope).
        html_writer::tag('button',get_string('tui_apply','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']),
        ['method'=>'get','class'=>'bms-tui-filters']);
    foreach($data['providers'] as $diag){if($diag['error']!==''){echo html_writer::div(s($diag['label'].': '.$diag['error']),'alert alert-warning',['role'=>'status']);}}
    $rows=[];foreach($data['tasks'] as $task){$rows[]=[($task['source_url']!==''?html_writer::link($task['source_url'],s($task['title'])):s($task['title'])),s($task['provider_label']),
        s($task['assignee_name']),\local_kbacompany\local\task_ui_service::badge($task['status']),s($task['due_date']?:get_string('tui_no_date','local_kbacompany'))];}
    echo $rows?\local_kbacompany\local\task_ui_service::table([get_string('taskcenter_task','local_kbacompany'),get_string('taskprovider_source','local_kbacompany'),
        get_string('taskcenter_assignee','local_kbacompany'),get_string('taskcenter_status','local_kbacompany'),get_string('taskcenter_duedate','local_kbacompany')],$rows,get_string('tui_source_tasks','local_kbacompany')):
        \local_kbacompany\local\task_ui_service::panel(get_string('tui_empty','local_kbacompany'),\local_kbacompany\local\task_ui_service::note(get_string('tui_sources_empty','local_kbacompany')));
}else{
    // The Corporate source uses only a verified BMS UUID -> Corporate company identity.
    // There is no free-text company ID field.
    $corporatebound=[];
    foreach ($data['bindings'] as $binding) {
        if ($binding['provider']==='kbacorporate_impl' && $binding['active']) {
            $corporatebound[$binding['source_key']]=$binding;
        }
    }
    foreach ($data['available_sources']['kbacorporate_impl']??[] as $source) {
        if (isset($corporatebound[$source['source_key']])) { continue; }
        $body=\local_kbacompany\local\task_ui_service::hidden(['uuid'=>$uuid,'view'=>'sourceconfig',
            'action'=>'bindsource','providerkey'=>'kbacorporate_impl','sourcekey'=>$source['source_key'],
            'sesskey'=>sesskey(),'requestkey'=>bin2hex(random_bytes(16))]).
            html_writer::tag('label',html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed',
                'value'=>1,'required'=>'required']).' '.get_string('tui_confirm','local_kbacompany'),['class'=>'bms-tui-confirm']).
            html_writer::tag('button',get_string('taskprovider_bind','local_kbacompany'),
                ['type'=>'submit','class'=>'bms24-ui-btn bms24-ui-btn--primary']);
        echo \local_kbacompany\local\task_ui_service::panel(s($source['label']),html_writer::tag('form',$body,['method'=>'post']));
    }
    foreach ($corporatebound as $binding) {
        $body=\local_kbacompany\local\task_ui_service::hidden(['uuid'=>$uuid,'view'=>'sourceconfig',
            'action'=>'unbindsource','providerkey'=>'kbacorporate_impl','sourcekey'=>$binding['source_key'],
            'sesskey'=>sesskey(),'requestkey'=>bin2hex(random_bytes(16))]).
            html_writer::tag('label',html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed',
                'value'=>1,'required'=>'required']).' '.get_string('tui_confirm','local_kbacompany'),['class'=>'bms-tui-confirm']).
            html_writer::tag('button',get_string('taskprovider_unbind','local_kbacompany'),
                ['type'=>'submit','class'=>'bms24-ui-btn bms24-ui-btn--danger']);
        echo \local_kbacompany\local\task_ui_service::panel(s($binding['label']),html_writer::tag('form',$body,['method'=>'post']));
    }
    $bound=[];foreach($data['bindings'] as $binding){if($binding['provider']==='kbaelement'&&$binding['active']){$bound[$binding['source_key']]=$binding;}}
    $available=[''=>get_string('tui_choose_source','local_kbacompany')];foreach($data['available_sources']['kbaelement']??[] as $src){if(!isset($bound[$src['source_key']])){$available[$src['source_key']]=$src['label'];}}
    if(count($available)>1){
        $body=\local_kbacompany\local\task_ui_service::hidden(['uuid'=>$uuid,'view'=>'sourceconfig','action'=>'bindsource','providerkey'=>'kbaelement','sesskey'=>sesskey(),'requestkey'=>bin2hex(random_bytes(16))]).
            \local_kbacompany\local\task_ui_service::control('sourcekey',['label'=>'taskprovider_source','options'=>$available,'required'=>true]).
            html_writer::tag('label',html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']).' '.get_string('tui_confirm','local_kbacompany'),['class'=>'bms-tui-confirm']).
            html_writer::tag('button',get_string('taskprovider_bind','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
        echo \local_kbacompany\local\task_ui_service::panel(get_string('taskprovider_sources','local_kbacompany'),html_writer::tag('form',$body,['method'=>'post']));
    }
    foreach($bound as $binding){
        $body=\local_kbacompany\local\task_ui_service::hidden(['uuid'=>$uuid,'view'=>'sourceconfig','action'=>'unbindsource','providerkey'=>'kbaelement',
            'sourcekey'=>$binding['source_key'],'sesskey'=>sesskey(),'requestkey'=>bin2hex(random_bytes(16))]).
            html_writer::tag('label',html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']).' '.get_string('tui_confirm','local_kbacompany'),['class'=>'bms-tui-confirm']).
            html_writer::tag('button',get_string('taskprovider_unbind','local_kbacompany'),['type'=>'submit','class'=>'btn btn-outline-danger']);
        echo \local_kbacompany\local\task_ui_service::panel($binding['label'],html_writer::tag('form',$body,['method'=>'post']));
    }
    if(!$bound&&count($available)===1){echo \local_kbacompany\local\task_ui_service::note(get_string('tui_sources_empty','local_kbacompany'));}
}
