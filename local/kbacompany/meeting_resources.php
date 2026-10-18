<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\meeting_resource_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$canmanage = meeting_resource_service::can_manage($uuid, (int)$USER->id);
$edituuid = optional_param('edit', '', PARAM_RAW_TRIMMED);
$action = optional_param('action','',PARAM_ALPHA);
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new moodle_exception('communication_post','local_kbacompany'); }
    require_sesskey();
    if ($action === 'save') {
        meeting_resource_service::save($uuid,(int)$USER->id,optional_param('resource','',PARAM_RAW_TRIMMED),[
            'code'=>optional_param('code','',PARAM_ALPHANUMEXT),'name'=>required_param('name',PARAM_TEXT),
            'type'=>required_param('type',PARAM_ALPHA),'location'=>optional_param('location','',PARAM_TEXT),
            'capacity'=>optional_param('capacity',0,PARAM_INT),
        ]);
    } else if ($action === 'archive') {
        meeting_resource_service::archive($uuid,(int)$USER->id,required_param('resource',PARAM_RAW_TRIMMED));
    }
    redirect(new moodle_url('/local/kbacompany/meeting_resources.php',['uuid'=>$uuid]));
}
$resources = meeting_resource_service::list($uuid,(int)$USER->id,true);
$editresource = null;
if ($edituuid !== '' && $canmanage) { $editresource = meeting_resource_service::get($uuid, (int)$USER->id, $edituuid); }
$availability = meeting_resource_service::availability($uuid,(int)$USER->id,time(),time()+7*DAYSECS);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/meeting_resources.php',['uuid'=>$uuid]));
$PAGE->set_title(get_string('meeting_resources','local_kbacompany'));
$PAGE->set_heading(get_string('meeting_resources','local_kbacompany'));
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/kbacompany/meetings.php',['uuid'=>$uuid]),get_string('meeting_resources_back','local_kbacompany'),['class'=>'btn btn-outline-secondary mb-3']);
if (!$resources) { echo $OUTPUT->notification(get_string('meeting_resource_empty','local_kbacompany'),'info'); }
else {
    $t=new html_table(); $t->head=[get_string('meeting_resource_code','local_kbacompany'),get_string('meeting_resource_name','local_kbacompany'),get_string('meeting_resource_type','local_kbacompany'),get_string('meeting_resource_location','local_kbacompany'),get_string('meeting_resource_capacity','local_kbacompany'),get_string('status'),get_string('actions')];
    foreach($resources as $r){
        $actions='';
        if($canmanage){
            $actions .= html_writer::link(new moodle_url('/local/kbacompany/meeting_resources.php', ['uuid'=>$uuid,'edit'=>$r['uuid']]),
                get_string('edit'), ['class'=>'btn btn-sm btn-outline-secondary mr-1']);
        }
        if($canmanage && $r['active']){
            $actions.=html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']);
            $actions.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
            $actions.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'archive']);
            $actions.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'resource','value'=>$r['uuid']]);
            $actions.=html_writer::tag('button',get_string('meeting_resource_archive','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-outline-danger']);
            $actions.=html_writer::end_tag('form');
        }
        $t->data[]=[s($r['code']),s($r['name']),get_string('meeting_resource_type_'.$r['type'],'local_kbacompany'),s($r['location']),(string)$r['capacity'],$r['active']?get_string('active'):get_string('inactive'),$actions];
    }
    echo html_writer::table($t);
}

echo $OUTPUT->heading(get_string('meeting_resource_availability','local_kbacompany'),3);
if (!$availability) { echo $OUTPUT->notification(get_string('meeting_resource_empty','local_kbacompany'),'info'); }
else {
    $a = new html_table();
    $a->head = [get_string('meeting_resource_name','local_kbacompany'), get_string('meeting_resource_next7','local_kbacompany')];
    foreach ($availability as $resource) {
        $busy = '';
        foreach ($resource['busy'] as $slot) {
            $period = s(userdate($slot['starts_at'],get_string('strftimedatetimeshort'))) . '–' .
                s(userdate($slot['ends_at'],get_string('strftimetime')));
            if (!empty($slot['can_view'])) {
                $url = new moodle_url('/local/kbacompany/meetings.php',['uuid'=>$uuid,'meeting'=>$slot['meeting_uuid']]);
                $label = html_writer::link($url, s($slot['title']));
            } else {
                $label = html_writer::span(get_string('meeting_resource_busy_slot','local_kbacompany'), 'text-muted');
            }
            $busy .= html_writer::div($label . ' · ' . $period);
        }
        if ($busy === '') { $busy = html_writer::span(get_string('meeting_resource_free','local_kbacompany'),'text-success'); }
        $a->data[] = [s($resource['name']), $busy];
    }
    echo html_writer::table($a);
}

if($canmanage){
    $formtitle = $editresource ? get_string('meeting_resource_edit','local_kbacompany') : get_string('meeting_resource_create','local_kbacompany');
    echo $OUTPUT->heading($formtitle,3);
    echo html_writer::start_tag('form',['method'=>'post','class'=>'border rounded p-3']);
    foreach([['sesskey',sesskey()],['action','save'],['resource',$editresource['uuid'] ?? '']] as $h){
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
    }
    echo html_writer::label(get_string('meeting_resource_code','local_kbacompany'),'res_code');
    echo html_writer::empty_tag('input',['id'=>'res_code','name'=>'code','class'=>'form-control','maxlength'=>40,'value'=>$editresource['code'] ?? '']);
    echo html_writer::label(get_string('meeting_resource_name','local_kbacompany'),'res_name');
    echo html_writer::empty_tag('input',['id'=>'res_name','name'=>'name','class'=>'form-control','required'=>'required','maxlength'=>255,'value'=>$editresource['name'] ?? '']);
    echo html_writer::label(get_string('meeting_resource_type','local_kbacompany'),'res_type');
    echo html_writer::select(['room'=>get_string('meeting_resource_type_room','local_kbacompany'),'place'=>get_string('meeting_resource_type_place','local_kbacompany')],
        'type',$editresource['type'] ?? 'room',false,['id'=>'res_type','class'=>'form-control']);
    echo html_writer::label(get_string('meeting_resource_location','local_kbacompany'),'res_location');
    echo html_writer::empty_tag('input',['id'=>'res_location','name'=>'location','class'=>'form-control','maxlength'=>255,'value'=>$editresource['location'] ?? '']);
    echo html_writer::label(get_string('meeting_resource_capacity','local_kbacompany'),'res_capacity');
    echo html_writer::empty_tag('input',['id'=>'res_capacity','name'=>'capacity','type'=>'number','min'=>0,'class'=>'form-control','value'=>$editresource['capacity'] ?? 0]);
    echo html_writer::tag('button',$editresource ? get_string('savechanges') : get_string('meeting_resource_create','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary mt-2']);
    if ($editresource) {
        echo ' ' . html_writer::link(new moodle_url('/local/kbacompany/meeting_resources.php',['uuid'=>$uuid]), get_string('cancel'), ['class'=>'btn btn-outline-secondary mt-2']);
    }
    echo html_writer::end_tag('form');
}
echo $OUTPUT->footer();
