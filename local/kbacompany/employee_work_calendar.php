<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\employee_work_calendar_service as wc;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = \context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$owner = required_param('userid', PARAM_INT);
$actor = (int)$USER->id;
$base = new moodle_url('/local/kbacompany/employee_work_calendar.php', ['uuid'=>$uuid, 'userid'=>$owner]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHA);
    try {
        if ($operation === 'grant') {
            wc::grant($uuid, $actor, $owner, required_param('recipient', PARAM_INT),
                optional_param('canview', 0, PARAM_BOOL) === 1,
                optional_param('canedit', 0, PARAM_BOOL) === 1,
                optional_param('cancomment', 0, PARAM_BOOL) === 1);
        } else if ($operation === 'save') {
            $start = strtotime(required_param('start', PARAM_RAW_TRIMMED));
            $end = strtotime(required_param('end', PARAM_RAW_TRIMMED));
            wc::save_event($uuid, $actor, $owner, optional_param('eventid', 0, PARAM_INT),
                required_param('title', PARAM_TEXT), (int)$start, (int)$end);
        } else if ($operation === 'comment') {
            wc::add_comment($uuid, $actor, $owner, required_param('eventid', PARAM_INT),
                required_param('message', PARAM_TEXT));
        } else { throw new invalid_parameter_exception('Unknown calendar operation.'); }
        redirect($base);
    } catch (\Throwable $e) {
        // Permission errors must not expose event identity or another employee's calendar.
        redirect($base, get_string('wcal_error', 'local_kbacompany'), null, \core\output\notification::NOTIFY_ERROR);
    }
}
$canview = wc::allowed($uuid, $actor, $owner, 'view');
$canedit = wc::allowed($uuid, $actor, $owner, 'edit');
$cancomment = wc::allowed($uuid, $actor, $owner, 'comment');
$cangrant = wc::allowed($uuid, $actor, $owner, 'grant');
if (!$canview && !$canedit && !$cancomment && !$cangrant) {
    throw new required_capability_exception($context, 'local/kbacompany:viewowncompany', 'nopermissions', '');
}
$events = $canview ? wc::list_events($uuid, $actor, $owner, strtotime('first day of this month 00:00'), strtotime('first day of next month 00:00')) : [];
$PAGE->set_context($context);
$PAGE->set_url($base);
$PAGE->set_title(get_string('wcal_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'employees', get_string('wcal_title', 'local_kbacompany'));
$back = new moodle_url('/local/kbacompany/employee_view.php', ['uuid'=>$uuid, 'userid'=>$owner, 'tab'=>'calendar']);
echo html_writer::div(html_writer::link($back, get_string('wcal_back', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']), 'mb-3');
echo html_writer::tag('h3', get_string('wcal_title', 'local_kbacompany'));
echo html_writer::div(get_string('wcal_scope', 'local_kbacompany'), 'text-muted mb-3');
$hidden = static function(string $operation, array $other = []) use ($uuid, $owner): string {
    $html = html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    $html .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'userid','value'=>$owner]);
    $html .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $html .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'operation','value'=>$operation]);
    foreach ($other as $name=>$value) { $html .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]); }
    return $html;
};
if ($canedit) {
    echo html_writer::start_div('card p-3 mb-3');
    echo html_writer::tag('h4', get_string('wcal_new', 'local_kbacompany'));
    echo html_writer::start_tag('form', ['method'=>'post','action'=>$base]);
    echo $hidden('save');
    echo html_writer::tag('label', get_string('wcal_name', 'local_kbacompany'), ['for'=>'wcalname']);
    echo html_writer::empty_tag('input', ['name'=>'title','id'=>'wcalname','maxlength'=>255,'required'=>true,'class'=>'form-control mb-2']);
    foreach (['start'=>'wcal_start','end'=>'wcal_end'] as $name=>$key) {
        echo html_writer::tag('label', get_string($key, 'local_kbacompany'), ['for'=>'wcal'.$name]);
        echo html_writer::empty_tag('input', ['type'=>'datetime-local','id'=>'wcal'.$name,'name'=>$name,'required'=>true,'class'=>'form-control mb-2']);
    }
    echo html_writer::tag('button', get_string('wcal_save', 'local_kbacompany'), ['type'=>'submit','class'=>'btn btn-success']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}
if ($canview) {
    echo html_writer::start_div('card p-3 mb-3');
    echo html_writer::tag('h4', get_string('wcal_month', 'local_kbacompany'));
    if (!$events) { echo html_writer::div(get_string('wcal_empty', 'local_kbacompany'), 'text-muted'); }
    foreach ($events as $event) {
        echo html_writer::start_div('border-bottom py-3');
        echo html_writer::tag('strong', s($event->title));
        echo html_writer::div(userdate((int)$event->starttime) . ' — ' . userdate((int)$event->endtime), 'text-muted small');
        $comments = wc::comments($uuid, $actor, $owner, (int)$event->id);
        foreach ($comments as $comment) {
            echo html_writer::div(s($comment->message) . ' · ' . userdate((int)$comment->timecreated), 'small border-left pl-2 mt-2');
        }
        if ($cancomment) {
            echo html_writer::start_tag('form', ['method'=>'post','action'=>$base,'class'=>'mt-2']);
            echo $hidden('comment', ['eventid'=>(int)$event->id]);
            echo html_writer::empty_tag('input', ['name'=>'message','maxlength'=>4000,'required'=>true,'class'=>'form-control mb-2','placeholder'=>get_string('wcal_comment','local_kbacompany')]);
            echo html_writer::tag('button', get_string('wcal_comment','local_kbacompany'), ['type'=>'submit','class'=>'btn btn-outline-secondary btn-sm']);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
if ($cangrant) {
    echo html_writer::start_div('card p-3 mb-3');
    echo html_writer::tag('h4', get_string('wcal_permissions', 'local_kbacompany'));
    echo html_writer::start_tag('form', ['method'=>'post','action'=>$base]);
    echo $hidden('grant');
    echo html_writer::tag('label', get_string('wcal_recipient', 'local_kbacompany'), ['for'=>'wcalrecipient']);
    echo html_writer::empty_tag('input', ['name'=>'recipient','id'=>'wcalrecipient','type'=>'number','min'=>1,'required'=>true,'class'=>'form-control mb-2']);
    foreach (['canview'=>'wcal_view','canedit'=>'wcal_edit','cancomment'=>'wcal_comment'] as $name=>$key) {
        echo html_writer::div(html_writer::empty_tag('input', ['type'=>'checkbox','id'=>$name,'name'=>$name,'value'=>1]) . ' ' .
            html_writer::tag('label', get_string($key,'local_kbacompany'), ['for'=>$name]), 'mb-1');
    }
    echo html_writer::tag('button', get_string('wcal_save_rights','local_kbacompany'), ['type'=>'submit','class'=>'btn btn-success']);
    echo html_writer::div(get_string('wcal_revoke', 'local_kbacompany'), 'small text-muted mt-2');
    echo html_writer::end_tag('form');
    foreach (wc::grants($uuid,$actor,$owner) as $grant) {
        echo html_writer::div('#' . (int)$grant->granteeid . ' · ' .
            get_string('wcal_view','local_kbacompany') . ': ' . (int)$grant->canview . ', ' .
            get_string('wcal_edit','local_kbacompany') . ': ' . (int)$grant->canedit . ', ' .
            get_string('wcal_comment','local_kbacompany') . ': ' . (int)$grant->cancomment, 'small mt-2');
    }
    echo html_writer::end_div();
}
bms_page_shell_service::finish($shell);
