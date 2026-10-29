<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_coordination_service;
use local_kbacompany\local\battle_plan_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$error = '';

if ($action !== '') {
    require_sesskey();
    try {
        if ($action === 'create') {
            battle_plan_coordination_service::create(
                $uuid, (int)$USER->id,
                required_param('sourceplan', PARAM_ALPHANUMEXT),
                required_param('targetplan', PARAM_ALPHANUMEXT),
                required_param('summary', PARAM_TEXT),
                optional_param('requestedchange', '', PARAM_TEXT)
            );
        } else if ($action === 'respond') {
            battle_plan_coordination_service::respond(
                $uuid, (int)$USER->id,
                required_param('coord', PARAM_ALPHANUMEXT),
                required_param('decision', PARAM_ALPHA),
                optional_param('response', '', PARAM_TEXT)
            );
        }
        redirect(new moodle_url('/local/kbacompany/battle_plan_coordination.php', ['uuid'=>$uuid,'week'=>$week]));
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$data = battle_plan_coordination_service::context($uuid, (int)$USER->id, $week);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_coordination.php', ['uuid'=>$uuid,'week'=>$data['week_start']]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('bpcoord_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan',
    get_string('bpcoord_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('bpcoord_title','local_kbacompany'));
echo html_writer::tag('p', get_string('bpcoord_intro','local_kbacompany'), ['class'=>'lead']);
echo html_writer::div(get_string('bpcoord_rule','local_kbacompany'), 'alert alert-info');
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid]),
    get_string('battleplan_title','local_kbacompany'),['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_overview_title','local_kbacompany'),['class'=>'btn btn-outline-primary']);

echo html_writer::start_tag('form',['method'=>'get','class'=>'form-inline my-3']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
echo html_writer::label(get_string('battleplan_week','local_kbacompany'),'bpcoord_week',false,['class'=>'mr-2']);
echo html_writer::empty_tag('input',['type'=>'date','id'=>'bpcoord_week','name'=>'week','value'=>$data['week_start'],'class'=>'form-control mr-2']);
echo html_writer::tag('button',get_string('battleplan_show','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::tag('p',s(battle_plan_service::period_label($data['week_start'],$data['week_end'])),['class'=>'text-muted']);

// Read-only summary and explicit workflow guidance. All counts are ACL-scoped context rows.
$opencount = 0;
$agreedcount = 0;
$declinedcount = 0;
foreach ($data['rows'] as $coordrow) {
    if ($coordrow['status'] === 'open') { $opencount++; }
    if ($coordrow['status'] === 'agreed') { $agreedcount++; }
    if ($coordrow['status'] === 'declined') { $declinedcount++; }
}
$summarycards = [
    [get_string('bpcoord_ux_plans', 'local_kbacompany'), (int)$data['plan_count']],
    [get_string('bpcoord_ux_waiting', 'local_kbacompany'), $opencount],
    [get_string('bpcoord_ux_agreed', 'local_kbacompany'), $agreedcount],
    [get_string('bpcoord_ux_declined', 'local_kbacompany'), $declinedcount],
];
echo html_writer::start_div('row mt-3 mb-3');
foreach ($summarycards as $item) {
    echo html_writer::start_div('col-6 col-lg-3 mb-2');
    echo html_writer::div(html_writer::div(s($item[0]), 'text-muted small') .
        html_writer::tag('strong', (string)$item[1], ['class'=>'h3 d-block mb-0']),
        'card card-body h-100');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::div(get_string('bpcoord_ux_steps', 'local_kbacompany'), 'alert alert-light border mb-3');
if (!$data['can_create']) {
    $reason = (int)$data['plan_count'] < 2 ? 'bpcoord_ux_need_two' : 'bpcoord_ux_need_source';
    echo html_writer::div(get_string($reason, 'local_kbacompany'), 'alert alert-info');
}

if ($data['can_create']) {
    echo $OUTPUT->heading(get_string('bpcoord_create','local_kbacompany'),3);
    echo html_writer::tag('p', get_string('bpcoord_ux_createhelp','local_kbacompany'), ['class'=>'text-muted']);
    echo html_writer::start_tag('form',['method'=>'post','class'=>'border rounded p-3 mb-4']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'week','value'=>$data['week_start']]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'create']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::label(get_string('bpcoord_source','local_kbacompany'),'bpcoord_source');
    echo html_writer::select($data['sources'],'sourceplan','', [''=>get_string('bpcoord_select','local_kbacompany')], ['id'=>'bpcoord_source','class'=>'form-control mb-2','required'=>'required']);
    echo html_writer::label(get_string('bpcoord_target','local_kbacompany'),'bpcoord_target');
    echo html_writer::select($data['targets'],'targetplan','', [''=>get_string('bpcoord_select','local_kbacompany')], ['id'=>'bpcoord_target','class'=>'form-control mb-2','required'=>'required']);
    echo html_writer::label(get_string('bpcoord_summary','local_kbacompany'),'bpcoord_summary');
    echo html_writer::tag('textarea','',['id'=>'bpcoord_summary','name'=>'summary','rows'=>2,'class'=>'form-control mb-2','required'=>'required']);
    echo html_writer::label(get_string('bpcoord_change','local_kbacompany'),'bpcoord_change');
    echo html_writer::tag('textarea','',['id'=>'bpcoord_change','name'=>'requestedchange','rows'=>3,'class'=>'form-control mb-3']);
    echo html_writer::tag('button',get_string('bpcoord_send','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('bpcoord_list','local_kbacompany'),3);
if (!$data['rows']) {
    echo html_writer::div(html_writer::tag('strong', get_string('bpcoord_empty','local_kbacompany')) .
        html_writer::tag('p', get_string('bpcoord_ux_emptyhelp','local_kbacompany'), ['class'=>'mb-0 mt-2']),
        'card card-body mb-3');
} else {
    foreach ($data['rows'] as $row) {
        $status = get_string('bpcoord_status_'.$row['status'],'local_kbacompany');
        $body = html_writer::tag('div', s($row['source_label']) . ' → ' . s($row['target_label']), ['class'=>'font-weight-bold']);
        $body .= html_writer::tag('div', s($row['summary']), ['class'=>'mt-2']);
        if ($row['requested_change'] !== '') {
            $body .= html_writer::tag('div', html_writer::tag('strong',get_string('bpcoord_change','local_kbacompany').': ') . s($row['requested_change']), ['class'=>'mt-2']);
        }
        $body .= html_writer::tag('div', s($status) . ' · ' . s($row['created_by_name']) . ' · ' . userdate($row['time_created']), ['class'=>'text-muted small mt-2']);
        if ($row['response'] !== '') {
            $body .= html_writer::tag('div', html_writer::tag('strong',get_string('bpcoord_response','local_kbacompany').': ') . s($row['response']), ['class'=>'mt-2']);
        }
        if ($row['can_respond']) {
            $body .= html_writer::start_tag('form',['method'=>'post','class'=>'mt-3']);
            foreach (['uuid'=>$uuid,'week'=>$data['week_start'],'action'=>'respond','coord'=>$row['uuid'],'sesskey'=>sesskey()] as $k=>$v) {
                $body .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$k,'value'=>$v]);
            }
            $body .= html_writer::tag('textarea','',['name'=>'response','rows'=>2,'class'=>'form-control mb-2','placeholder'=>get_string('bpcoord_response_hint','local_kbacompany')]);
            $body .= html_writer::tag('button',get_string('bpcoord_agree','local_kbacompany'),['name'=>'decision','value'=>'agree','type'=>'submit','class'=>'btn btn-success mr-2']);
            $body .= html_writer::tag('button',get_string('bpcoord_decline','local_kbacompany'),['name'=>'decision','value'=>'decline','type'=>'submit','class'=>'btn btn-outline-danger']);
            $body .= html_writer::end_tag('form');
        }
        echo html_writer::div($body,'card card-body mb-3');
    }
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
