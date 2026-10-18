<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\google_calendar_provider_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$company = company_context_service::context($uuid, (int)$USER->id);
$uuid = (string)$company['uuid'];
$action = optional_param('action', '', PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    throw new invalid_parameter_exception('Unsupported request method.');
}

$url = new moodle_url('/local/kbacompany/calendar_google.php', ['uuid' => $uuid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('bms_google_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($action === 'connect') {
    if ($method !== 'POST') {
        throw new invalid_parameter_exception('Connection requires POST.');
    }
    require_sesskey();
    $issuerid = required_param('issuerid', PARAM_INT);
    google_calendar_provider_service::save_config((int)$USER->id, $issuerid,
        (string)google_calendar_provider_service::config((int)$USER->id)['calendar_id'], true, true);
    $client = google_calendar_provider_service::connect_client((int)$USER->id, $issuerid, $url);
    if (!$client->is_logged_in()) {
        redirect($client->get_login_url());
    }
    redirect($url, get_string('bms_google_connected', 'local_kbacompany'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($method === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHA);
    try {
        if ($operation === 'save') {
            google_calendar_provider_service::save_config(
                (int)$USER->id,
                optional_param('issuerid', 0, PARAM_INT),
                optional_param('calendarid', 'primary', PARAM_RAW_TRIMMED),
                optional_param('importenabled', 0, PARAM_BOOL),
                optional_param('enabled', 0, PARAM_BOOL)
            );
            redirect($url, get_string('bms_google_saved', 'local_kbacompany'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'sync') {
            $result = google_calendar_provider_service::sync_upcoming_meetings($uuid, (int)$USER->id, 90);
            redirect($url, get_string('bms_google_sync_result', 'local_kbacompany', (object)$result), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else if ($operation === 'disable') {
            $cfg = google_calendar_provider_service::config((int)$USER->id);
            google_calendar_provider_service::save_config((int)$USER->id, (int)$cfg['issuer_id'],
                (string)$cfg['calendar_id'], (bool)$cfg['import_enabled'], false);
            redirect($url, get_string('bms_google_disabled', 'local_kbacompany'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        throw new invalid_parameter_exception('Unknown Google Calendar operation.');
    } catch (\Throwable $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$model = google_calendar_provider_service::diagnostics($uuid, (int)$USER->id);
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'calendar', get_string('bms_google_title', 'local_kbacompany'));

echo $OUTPUT->heading(get_string('bms_google_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('bms_google_intro', 'local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::div(get_string('bms_google_boundary', 'local_kbacompany'), 'alert alert-info');

if (!$model['issuer_options']) {
    echo $OUTPUT->notification(get_string('bms_google_no_issuer', 'local_kbacompany'), 'warning');
} else {
    $cfg = $model['config'];
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('bms_google_connection', 'local_kbacompany'), ['class'=>'h5']);
    echo html_writer::start_tag('form', ['method'=>'post','action'=>$url->out(false)]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'operation','value'=>'save']);
    $options = '';
    foreach ($model['issuer_options'] as $id=>$name) {
        $attrs=['value'=>(int)$id]; if ((int)$cfg['issuer_id']===(int)$id) {$attrs['selected']='selected';}
        $options .= html_writer::tag('option', s($name), $attrs);
    }
    echo html_writer::div(html_writer::tag('label',get_string('bms_google_issuer','local_kbacompany'),['for'=>'issuerid']).
        html_writer::tag('select',$options,['id'=>'issuerid','name'=>'issuerid','class'=>'form-control']), 'form-group');
    echo html_writer::div(html_writer::tag('label',get_string('bms_google_calendar_id','local_kbacompany'),['for'=>'calendarid']).
        html_writer::empty_tag('input',['id'=>'calendarid','name'=>'calendarid','class'=>'form-control','value'=>s((string)$cfg['calendar_id'])]), 'form-group');
    $checked = !empty($cfg['import_enabled']) ? ['checked'=>'checked'] : [];
    echo html_writer::div(html_writer::empty_tag('input',array_merge(['type'=>'checkbox','name'=>'importenabled','value'=>'1','id'=>'importenabled'],$checked)).
        ' '.html_writer::tag('label',get_string('bms_google_import','local_kbacompany'),['for'=>'importenabled']), 'form-group');
    $checked2 = !empty($cfg['enabled']) ? ['checked'=>'checked'] : [];
    echo html_writer::div(html_writer::empty_tag('input',array_merge(['type'=>'checkbox','name'=>'enabled','value'=>'1','id'=>'enabled'],$checked2)).
        ' '.html_writer::tag('label',get_string('bms_google_enabled','local_kbacompany'),['for'=>'enabled']), 'form-group');
    echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-success mr-2']);
    if ((int)$cfg['issuer_id'] > 0) {

    }
    echo html_writer::end_tag('form');
    if ((int)$cfg['issuer_id'] > 0) {
        // The connect flow modifies the user's provider configuration; never initiate it with GET.
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'mt-2']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'connect']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'issuerid', 'value' => (int)$cfg['issuer_id']]);
        echo html_writer::tag('button',
            $model['connected'] ? get_string('bms_google_reconnect','local_kbacompany') :
                get_string('bms_google_connect','local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::tag('p', $model['connected'] ? get_string('bms_google_status_connected','local_kbacompany') : get_string('bms_google_status_disconnected','local_kbacompany'), ['class'=>'mt-3 mb-0 font-weight-bold']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($model['connected'] && !empty($cfg['enabled'])) {
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('bms_google_sync_title','local_kbacompany'), ['class'=>'h5']);
        echo html_writer::tag('p', get_string('bms_google_sync_intro','local_kbacompany'), ['class'=>'text-muted']);
        echo html_writer::start_tag('form',['method'=>'post','action'=>$url->out(false),'class'=>'d-inline-block mr-2']);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'operation','value'=>'sync']);
        echo html_writer::tag('button',get_string('bms_google_sync_meetings','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
        echo html_writer::end_tag('form');
        echo html_writer::link(new moodle_url('/local/kbacompany/calendar.php',['uuid'=>$uuid,'source'=>'google']),get_string('bms_google_open_calendar','local_kbacompany'),['class'=>'btn btn-outline-secondary']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

echo html_writer::link(new moodle_url('/local/kbacompany/calendar.php',['uuid'=>$uuid]),get_string('bms_google_back','local_kbacompany'),['class'=>'btn btn-outline-secondary']);
bms_page_shell_service::finish($shell);
