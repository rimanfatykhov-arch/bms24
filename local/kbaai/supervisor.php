<?php
require_once('../../config.php');
$courseid=required_param('courseid',PARAM_INT); $fromcmid=optional_param('fromcmid',0,PARAM_INT);
$course=get_course($courseid); require_login($course); $coursecontext=\context_course::instance($courseid);
$allowed=is_siteadmin();
if (!$allowed) {
    $modinfo=get_fast_modinfo($course,$USER->id);
    foreach ($modinfo->get_instances_of('kbaelement') as $cm) {
        if (has_capability('mod/kbaelement:grade',\context_module::instance($cm->id))) { $allowed=true; break; }
    }
}
if (!$allowed) { throw new required_capability_exception($coursecontext,'moodle/course:update','nopermissions',''); }
$PAGE->set_url('/local/kbaai/supervisor.php',array('courseid'=>$courseid,'fromcmid'=>$fromcmid));
$PAGE->set_context($coursecontext); $PAGE->set_title(get_string('supervisorassistant','local_kbaai')); $PAGE->set_heading(format_string($course->fullname));
$repo=new \local_kbaai\repository\supervisor_repository(); $cfg=$repo->get($courseid);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey(); $away=optional_param('away',0,PARAM_BOOL); $instructions=optional_param('instructions','',PARAM_RAW);
    $cfg=$repo->save($courseid,$away,$USER->id,$instructions);
    redirect($PAGE->url,get_string($away?'supervisorawayenabled':'supervisorawaydisabled','local_kbaai'),null,\core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('supervisorassistant','local_kbaai'));
$back=$fromcmid?new moodle_url('/mod/kbaelement/inbox.php',array('courseid'=>$courseid,'fromcmid'=>$fromcmid)):new moodle_url('/mod/kbaelement/inbox.php',array('courseid'=>$courseid));
echo html_writer::link($back,get_string('navdiscussions','local_kbaai'),array('class'=>'btn btn-outline-secondary mr-2 mb-3'));
if (is_siteadmin() && $fromcmid) {
    echo html_writer::link(new moodle_url('/local/kbaai/context_debug.php', array('cmid'=>$fromcmid)),
        get_string('aicontextdebugbutton','local_kbaai'), array('class'=>'btn btn-outline-info mb-3'));
}
echo html_writer::start_div('card'); echo html_writer::start_div('card-body');
echo html_writer::tag('p',get_string('supervisorassistantdesc','local_kbaai'));
echo html_writer::start_tag('form',array('method'=>'post','action'=>$PAGE->url)); echo html_writer::empty_tag('input',array('type'=>'hidden','name'=>'sesskey','value'=>sesskey()));
$attrs=array('type'=>'checkbox','name'=>'away','value'=>1); if (!empty($cfg->away)) {$attrs['checked']='checked';}
echo html_writer::start_tag('label',array('class'=>'font-weight-bold d-block mb-3')); echo html_writer::empty_tag('input',$attrs).' '.get_string('supervisorawaytoggle','local_kbaai'); echo html_writer::end_tag('label');
echo html_writer::tag('label',get_string('supervisorinstructions','local_kbaai')); echo html_writer::tag('textarea',s($cfg->instructions),array('name'=>'instructions','rows'=>4,'class'=>'form-control mb-3'));
echo html_writer::tag('button',get_string('savechanges'),array('type'=>'submit','class'=>'btn btn-primary')); echo html_writer::end_tag('form');
echo html_writer::end_div(); echo html_writer::end_div();
$pending=$repo->pending_escalations($courseid); if ($pending) { echo $OUTPUT->heading(get_string('supervisorescalations','local_kbaai'),3); foreach ($pending as $r) { echo html_writer::div(s($r->reason?:get_string('supervisorescalationgeneric','local_kbaai')),'alert alert-warning'); } }
echo $OUTPUT->footer();
