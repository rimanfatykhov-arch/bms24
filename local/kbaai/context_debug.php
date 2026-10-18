<?php
require_once('../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$cm = get_coursemodule_from_id('kbaelement', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
if (!is_siteadmin()) {
    throw new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', '');
}
$context = context_module::instance($cm->id);
$PAGE->set_url(new moodle_url('/local/kbaai/context_debug.php', array('cmid'=>$cmid, 'submissionid'=>$submissionid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('aicontextdebug', 'local_kbaai'));
$PAGE->set_heading(format_string($course->fullname));

$adapter = new \local_kbaai\source\kbaelement_adapter();
$submissions = $adapter->list_submissions($cm->instance, 100);
if (!$submissionid && $submissions) {
    $first = reset($submissions);
    $submissionid = (int)$first->id;
}
$builder = new \local_kbaai\service\supervisor_context_builder();
$preview = $builder->preview($cmid, $submissionid);
$learning = $preview['learningcontext'];
$conversation = $preview['conversation'];

function local_kbaai_debug_metric($label, $value, $ok = true) {
    $class = $ok ? 'text-success' : 'text-warning';
    return html_writer::div(html_writer::tag('strong', s($label) . ': ') . html_writer::span(s((string)$value), $class), 'mb-1');
}

function local_kbaai_debug_text_block($title, $text) {
    $text = trim((string)$text);
    $body = $text === '' ? html_writer::span(get_string('contextempty', 'local_kbaai'), 'text-warning')
        : html_writer::tag('div', nl2br(s($text)), array('class'=>'kbaai-context-text'));
    return html_writer::div(html_writer::tag('h4', s($title), array('class'=>'h6')) . $body, 'border rounded p-3 mb-3');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aicontextdebug', 'local_kbaai'));
echo html_writer::tag('p', get_string('aicontextdebugdesc', 'local_kbaai'), array('class'=>'text-muted'));
echo html_writer::link(new moodle_url('/local/kbaai/console.php', array('cmid'=>$cmid)), get_string('backtoconsole', 'local_kbaai'), array('class'=>'btn btn-outline-secondary mb-3'));

if ($submissions) {
    echo html_writer::start_tag('form', array('method'=>'get', 'action'=>new moodle_url('/local/kbaai/context_debug.php'), 'class'=>'mb-4'));
    echo html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'cmid', 'value'=>$cmid));
    echo html_writer::tag('label', get_string('contextstudent', 'local_kbaai'), array('for'=>'submissionid', 'class'=>'font-weight-bold mr-2'));
    $options = array();
    foreach ($submissions as $sub) {
        $user = $DB->get_record('user', array('id'=>(int)$sub->userid), 'id,firstname,lastname', IGNORE_MISSING);
        $options[(int)$sub->id] = $user ? fullname($user) . ' (#' . (int)$sub->id . ')' : '#' . (int)$sub->id;
    }
    echo html_writer::select($options, 'submissionid', $submissionid, false, array('id'=>'submissionid', 'class'=>'custom-select w-auto mr-2'));
    echo html_writer::tag('button', get_string('contextshow', 'local_kbaai'), array('type'=>'submit', 'class'=>'btn btn-primary'));
    echo html_writer::end_tag('form');
}

$provider = (string)(get_config('local_kbaai', 'defaultprovider') ?: 'mock');
$model = (string)(get_config('local_kbaai', 'defaultmodel') ?: '');
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('contextsummary', 'local_kbaai'), array('class'=>'h5'));
echo local_kbaai_debug_metric(get_string('contextprovider', 'local_kbaai'), $provider, $provider !== 'mock');
echo local_kbaai_debug_metric(get_string('contextmodel', 'local_kbaai'), $model ?: '—', $model !== '');
echo local_kbaai_debug_metric(get_string('contextcurrentmaterial', 'local_kbaai'), $learning['meta']['current_material_chars'] . ' ' . get_string('charunit', 'local_kbaai'), $learning['meta']['current_material_chars'] > 0);
echo local_kbaai_debug_metric(get_string('contextcurrentassignment', 'local_kbaai'), $learning['meta']['current_assignment_chars'] . ' ' . get_string('charunit', 'local_kbaai'), $learning['meta']['current_assignment_chars'] > 0);
echo local_kbaai_debug_metric(get_string('contextrelatedcount', 'local_kbaai'), $learning['meta']['related_count'], $learning['meta']['related_count'] > 0);
echo local_kbaai_debug_metric(get_string('contextconversationcount', 'local_kbaai'), $conversation['meta']['message_count'], $conversation['meta']['message_count'] > 0);
echo local_kbaai_debug_metric(get_string('contextfirstreply', 'local_kbaai'), $conversation['meta']['first_assistant_reply'] ? get_string('yes') : get_string('no'));
echo html_writer::end_div(); echo html_writer::end_div();

$current = $learning['current'];
echo $OUTPUT->heading(get_string('contextcurrent', 'local_kbaai') . ': ' . format_string($current['title']), 3);
echo html_writer::tag('p', s($current['section']), array('class'=>'text-muted'));
echo local_kbaai_debug_text_block(get_string('contextmaterial', 'local_kbaai'), $current['learning_material']);
echo local_kbaai_debug_text_block(get_string('contextassignment', 'local_kbaai'), $current['assignment']);
echo local_kbaai_debug_text_block(get_string('contextobjectives', 'local_kbaai'), $current['learning_objectives']);

if (!empty($learning['related'])) {
    echo $OUTPUT->heading(get_string('contextrelated', 'local_kbaai'), 3);
    foreach ($learning['related'] as $item) {
        echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body');
        echo html_writer::tag('h4', format_string($item['title']), array('class'=>'h6 mb-1'));
        echo html_writer::tag('div', s($item['section']) . ' · ' . s($item['relationship']), array('class'=>'small text-muted mb-2'));
        if ($item['learning_material'] !== '') { echo html_writer::tag('div', nl2br(s($item['learning_material'])), array('class'=>'small mb-2')); }
        if ($item['assignment'] !== '') { echo html_writer::tag('div', html_writer::tag('strong', get_string('contextassignment', 'local_kbaai') . ': ') . nl2br(s($item['assignment'])), array('class'=>'small')); }
        echo html_writer::end_div(); echo html_writer::end_div();
    }
}

if ($preview['submission']->id) {
    echo $OUTPUT->heading(get_string('contextconversation', 'local_kbaai'), 3);
    if (!$conversation['messages']) {
        echo $OUTPUT->notification(get_string('contextnoconversation', 'local_kbaai'), 'info');
    } else {
        foreach ($conversation['messages'] as $message) {
            echo html_writer::div(html_writer::tag('strong', s($message['role'])) . ': ' . s($message['text']), 'border-bottom py-2');
        }
    }
    echo $OUTPUT->heading(get_string('contextofficialanswer', 'local_kbaai'), 3);
    echo local_kbaai_debug_text_block(get_string('contextofficialanswer', 'local_kbaai'), $preview['officialanswer']);
}

echo $OUTPUT->footer();
