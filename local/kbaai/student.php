<?php
require_once('../../config.php');
$cmid=required_param('cmid',PARAM_INT); $cm=get_coursemodule_from_id('kbaelement',$cmid,0,false,MUST_EXIST); $course=get_course($cm->course); require_login($course,false,$cm); $context=context_module::instance($cm->id);
$PAGE->set_url(new moodle_url('/local/kbaai/student.php',array('cmid'=>$cmid))); $PAGE->set_context($context); $PAGE->set_title(get_string('aifeedback','local_kbaai')); $PAGE->set_heading(format_string($course->fullname));
$repo=new \local_kbaai\repository\assessment_repository(); $records=$repo->published_for_user($cm->instance,$USER->id);
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('aifeedback','local_kbaai'));
if (!$records) { echo html_writer::tag('p',get_string('nopublishedfeedback','local_kbaai')); }
foreach ($records as $a) { echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body'); echo html_writer::tag('h3',get_string('assessmentresult','local_kbaai').' #'.$a->id,array('class'=>'h5')); if ($a->finalscore!==null) { echo html_writer::tag('p',get_string('grade','local_kbaai').': '.format_float($a->finalscore,2).' / '.format_float($a->maxscore,2)); } $studentfeedback = ($a->provider === 'mock') ? get_string('mockstudentfeedback', 'local_kbaai') : $a->studentfeedback; echo format_text($studentfeedback,FORMAT_PLAIN); echo html_writer::tag('p',get_string('aidisclosure','local_kbaai'),array('class'=>'text-muted small')); echo html_writer::end_div(); echo html_writer::end_div(); }
echo $OUTPUT->footer();
