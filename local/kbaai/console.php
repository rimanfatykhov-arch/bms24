<?php
require_once('../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('kbaelement', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!is_siteadmin() && !has_capability('mod/kbaelement:grade', $context)
        && !has_capability('local/kbaai:manage', $context)) {
    throw new required_capability_exception($context, 'local/kbaai:manage', 'nopermissions', '');
}

$PAGE->set_url(new moodle_url('/local/kbaai/console.php', array('cmid' => $cmid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('teacherconsole', 'local_kbaai'));
$PAGE->set_heading(format_string($course->fullname));

$adapter = new \local_kbaai\source\kbaelement_adapter();
$irepo = new \local_kbaai\repository\instance_repository();
$arepo = new \local_kbaai\repository\assessment_repository();
$cfg = $irepo->get($cm->instance);

function local_kbaai_render_file_statuses(array $files) {
    if (!$files) { return ''; }
    $items='';
    foreach ($files as $file) {
        $status=!empty($file['analysis_status'])?(string)$file['analysis_status']:(!empty($file['kind'])?(string)$file['kind']:'');
        if ($status==='analysed') { $label=get_string('fileanalysed','local_kbaai'); $class='text-success'; }
        else if ($status==='mock') { $label=get_string('filemock','local_kbaai'); $class='text-info'; }
        else if ($status==='failed') { $label=get_string('filefailed','local_kbaai'); $class='text-danger'; }
        else if ($status==='partial') { $label=get_string('filepartial','local_kbaai'); $class='text-warning'; }
        else if ($status==='too_large') { $label=get_string('filetoolarge','local_kbaai'); $class='text-danger'; }
        else { $label=get_string('fileunsupported','local_kbaai'); $class='text-warning'; }
        $line=s($file['filename']).' — '.html_writer::span($label,$class);
        if (!empty($file['analysis_pages'])) {
            $line .= ' · ' . get_string('pdfpages', 'local_kbaai') . ': ' . (int)$file['analysis_pages'];
        }
        if (isset($file['analysis_paragraphs']) || isset($file['analysis_tables']) || isset($file['analysis_images'])) {
            $meta = array();
            if (isset($file['analysis_paragraphs'])) { $meta[] = get_string('docxparagraphs','local_kbaai') . ': ' . (int)$file['analysis_paragraphs']; }
            if (isset($file['analysis_tables'])) { $meta[] = get_string('docxtables','local_kbaai') . ': ' . (int)$file['analysis_tables']; }
            if (isset($file['analysis_images'])) { $meta[] = get_string('docximages','local_kbaai') . ': ' . (int)$file['analysis_images']; }
            if (isset($file['analysis_images_analysed'])) { $meta[] = get_string('docximagesanalysed','local_kbaai') . ': ' . (int)$file['analysis_images_analysed']; }
            if ($meta) { $line .= ' · ' . implode(' · ', $meta); }
        }
        if (isset($file['analysis_sheets']) || isset($file['analysis_cells']) || isset($file['analysis_formulas'])) {
            $meta = array();
            if (isset($file['analysis_sheets'])) { $meta[] = get_string('xlsxsheets','local_kbaai') . ': ' . (int)$file['analysis_sheets']; }
            if (isset($file['analysis_cells'])) { $meta[] = get_string('xlsxcellscount','local_kbaai') . ': ' . (int)$file['analysis_cells']; }
            if (isset($file['analysis_formulas'])) { $meta[] = get_string('xlsxformulas','local_kbaai') . ': ' . (int)$file['analysis_formulas']; }
            if (isset($file['analysis_merged'])) { $meta[] = get_string('xlsxmerged','local_kbaai') . ': ' . (int)$file['analysis_merged']; }
            if ($meta) { $line .= ' · ' . implode(' · ', $meta); }
        }
        if ($status==='failed' && !empty($file['analysis_error'])) {
            $line .= html_writer::tag('div', get_string('fileanalysiserror','local_kbaai').': '.s($file['analysis_error']), array('class'=>'small text-muted'));
        }
        $items.=html_writer::tag('li',$line);
    }
    return html_writer::tag('div',html_writer::tag('strong',get_string('officialfiles','local_kbaai')).html_writer::tag('ul',$items),array('class'=>'small mt-2'));
}

function local_kbaai_render_criteria_result(array $result, array $snapshotcriteria) {
    if (empty($result['criteria']) || !is_array($result['criteria'])) { return ''; }
    $lookup = array();
    foreach ($snapshotcriteria as $criterion) {
        if (is_array($criterion) && isset($criterion['id'])) { $lookup[(string)$criterion['id']] = $criterion; }
    }
    $rows = '';
    foreach ($result['criteria'] as $item) {
        if (!is_array($item) || !isset($item['id'])) { continue; }
        $id = (string)$item['id'];
        $cfg = isset($lookup[$id]) ? $lookup[$id] : array();
        $title = !empty($cfg['title']) ? (string)$cfg['title'] : $id;
        $score = isset($item['score']) && is_numeric($item['score']) ? format_float((float)$item['score'], 2) : '—';
        $max = isset($item['max_score']) && is_numeric($item['max_score']) ? format_float((float)$item['max_score'], 2) : (isset($cfg['maxscore']) ? format_float((float)$cfg['maxscore'], 2) : '—');
        $body = html_writer::tag('div', html_writer::tag('strong', s($title)) . html_writer::span($score . ' / ' . $max, 'float-right font-weight-bold'), array('class'=>'mb-1'));
        if (!empty($item['rationale'])) { $body .= html_writer::div(s($item['rationale']), 'small mb-1'); }
        if (!empty($item['evidence']) && is_array($item['evidence'])) {
            $evidence=''; foreach ($item['evidence'] as $ev) { $evidence .= html_writer::tag('li', s($ev)); }
            if ($evidence !== '') { $body .= html_writer::tag('details', html_writer::tag('summary', get_string('criterionevidence','local_kbaai')) . html_writer::tag('ul',$evidence,array('class'=>'mb-0 mt-1')), array('class'=>'small text-muted')); }
        }
        $rows .= html_writer::div($body, 'border rounded p-2 mb-2');
    }
    return html_writer::div(html_writer::tag('strong', get_string('criteriabreakdown','local_kbaai')) . $rows, 'mb-3');
}

function local_kbaai_confidence_label($confidence) {
    $confidence = (int)$confidence;
    if ($confidence >= 85) { return get_string('confidencehigh','local_kbaai'); }
    if ($confidence >= 65) { return get_string('confidencemedium','local_kbaai'); }
    return get_string('confidencelow','local_kbaai');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    try {
        if ($action === 'savepedagogy') {
            $cfg = $irepo->save_teacher_config($cm->instance, array(
                'enabled' => optional_param('enabled', 0, PARAM_BOOL),
                'teacherinstructions' => optional_param('teacherinstructions', '', PARAM_RAW),
                'referenceanswer' => optional_param('referenceanswer', '', PARAM_RAW),
            ), $USER->id);
            redirect($PAGE->url, get_string('settingssaved', 'local_kbaai'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action === 'queue') {
            $sid = required_param('submissionid', PARAM_INT);
            $id = (new \local_kbaai\service\queue_service())->queue($sid, 'manual', false);
            redirect($PAGE->url, get_string('queuedok', 'local_kbaai', $id), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action === 'rerun') {
            $sid = required_param('submissionid', PARAM_INT);
            $id = (new \local_kbaai\service\queue_service())->queue($sid, 'manual', true);
            redirect($PAGE->url, get_string('queuedok', 'local_kbaai', $id), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action === 'approve') {
            $aid = required_param('assessmentid', PARAM_INT);
            $score = required_param('finalscore', PARAM_FLOAT);
            $feedback = optional_param('feedback', '', PARAM_RAW);
            (new \local_kbaai\service\publication_service())->publish($aid, $USER->id, false, $score, $feedback);
            redirect($PAGE->url, get_string('publishedok', 'local_kbaai'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (Throwable $e) {
        redirect($PAGE->url, get_string('actionfailed', 'local_kbaai', $e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$elementurl = new moodle_url('/mod/kbaelement/view.php', array('id' => $cm->id, 'forceview' => 1));
$reporturl = new moodle_url('/mod/kbaelement/report.php', array('id' => $cm->id));
$inboxurl = new moodle_url('/mod/kbaelement/inbox.php', array('courseid' => $course->id, 'fromcmid' => $cm->id));
$supervisorurl = new moodle_url('/local/kbaai/supervisor.php', array('courseid' => $course->id, 'fromcmid' => $cm->id));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('teacherconsole', 'local_kbaai') . ' — ' . format_string($cm->name));

echo html_writer::start_div('kbaai-toolbar mb-4');
echo html_writer::link($elementurl, get_string('navlearningelement', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mr-2 mb-2'));
echo html_writer::link($reporturl, get_string('navsubmissions', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mr-2 mb-2'));
echo html_writer::link($inboxurl, get_string('navdiscussions', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mr-2 mb-2'));
echo html_writer::link($supervisorurl, get_string('opensupervisorassistant', 'local_kbaai'), array('class' => 'btn btn-outline-primary mr-2 mb-2'));
echo html_writer::link(new moodle_url('/local/kbaai/rubric.php', array('cmid' => $cmid)), get_string('visualrubric', 'local_kbaai'), array('class' => 'btn btn-outline-primary mr-2 mb-2'));
if (is_siteadmin()) {
    echo html_writer::link(new moodle_url('/local/kbaai/context_debug.php', array('cmid' => $cm->id)),
        get_string('aicontextdebugbutton', 'local_kbaai'), array('class' => 'btn btn-outline-info mr-2 mb-2'));
    echo html_writer::link(new moodle_url('/admin/category.php', array('category' => 'local_kbaai_admin')),
        get_string('adminaisettings', 'local_kbaai'), array('class' => 'btn btn-outline-dark mb-2'));
}
echo html_writer::end_div();

$diag = $adapter->diagnose();
if (!$diag['ready']) {
    echo $OUTPUT->notification(get_string('mappingnotready', 'local_kbaai') . ' ' . implode(' ', $diag['errors']), 'error');
    echo html_writer::link(new moodle_url('/local/kbaai/diagnostics.php'), get_string('opendiagnostics', 'local_kbaai'), array('class' => 'btn btn-primary'));
    echo $OUTPUT->footer();
    exit;
}

$maxgrade = $adapter->get_max_grade($cm->instance);
$rubricservice = new \local_kbaai\service\rubric_service();
$rubric = $rubricservice->get($cm->instance, $maxgrade);
$rubricurl = new moodle_url('/local/kbaai/rubric.php', array('cmid' => $cmid));

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('assessmentmethod', 'local_kbaai'), array('class' => 'h5 mb-3'));
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'savepedagogy'));
$checked = !empty($cfg->enabled) ? array('checked' => 'checked') : array();
echo html_writer::start_tag('label', array('class' => 'd-block mb-3 font-weight-bold'));
echo html_writer::empty_tag('input', array_merge(array('type' => 'checkbox', 'name' => 'enabled', 'value' => 1), $checked));
echo ' ' . get_string('enabledteacher', 'local_kbaai');
echo html_writer::end_tag('label');

echo html_writer::start_tag('details', array('class' => 'mb-3'));
echo html_writer::tag('summary', get_string('assessmentmethoddetails', 'local_kbaai'), array('class' => 'mb-3'));
echo html_writer::tag('label', get_string('teacherinstructions', 'local_kbaai'));
echo html_writer::tag('textarea', s($cfg->teacherinstructions), array('name' => 'teacherinstructions', 'rows' => 4, 'class' => 'form-control mb-3'));
echo html_writer::tag('label', get_string('referenceanswer', 'local_kbaai'));
echo html_writer::tag('textarea', s($cfg->referenceanswer), array('name' => 'referenceanswer', 'rows' => 4, 'class' => 'form-control mb-3'));
$rubricitems = '';
foreach ($rubric['criteria'] as $criterion) {
    $rubricitems .= html_writer::tag('li', s($criterion['title']) . ' — ' . format_float((float)$criterion['maxscore'], 2));
}
echo html_writer::div(
    html_writer::tag('strong', get_string('visualrubric', 'local_kbaai')) .
    html_writer::tag('ul', $rubricitems, array('class' => 'mb-2')) .
    html_writer::div(get_string('rubrictotal', 'local_kbaai') . ': ' . format_float((float)$rubric['total'], 2) . ' / ' . format_float((float)$maxgrade, 2), empty($rubric['valid']) ? 'text-danger font-weight-bold' : 'text-success font-weight-bold') .
    html_writer::link($rubricurl, get_string('editvisualrubric', 'local_kbaai'), array('class' => 'btn btn-outline-primary btn-sm mt-2')),
    'border rounded p-3 mb-3'
);
echo html_writer::end_tag('details');
echo html_writer::tag('button', get_string('savechanges'), array('type' => 'submit', 'class' => 'btn btn-primary'));
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

try { $subs = $adapter->list_submissions($cm->instance, 100); }
catch (Throwable $e) { $subs = array(); echo $OUTPUT->notification($e->getMessage(), 'error'); }

echo $OUTPUT->heading(get_string('submissions', 'local_kbaai'), 3);
if (!$subs) {
    echo html_writer::tag('p', get_string('nosubmissions', 'local_kbaai'));
} else {
    $table = new html_table();
    $table->head = array(get_string('student', 'local_kbaai'), get_string('answerpreview', 'local_kbaai'),
        get_string('assessmentstatus', 'local_kbaai'), get_string('actionscolumn', 'local_kbaai'));
    foreach ($subs as $s) {
        $uid = $adapter->get_userid($s);
        $user = \core_user::get_user($uid, 'id,firstname,lastname', IGNORE_MISSING);
        $target = $adapter->get_target($s);
        $latest = $arepo->latest_for_submission($s->id);
        $workurl = new moodle_url('/mod/kbaelement/grade.php', array('id' => $cm->id, 'userid' => $uid));
        $studentname = $user ? fullname($user) : ('#' . $uid);
        $studentlink = html_writer::link($workurl, s($studentname), array('title' => get_string('openwork', 'local_kbaai')));
        $status = html_writer::tag('span', \local_kbaai\output\teacher_presenter::status_label($latest),
            array('class' => \local_kbaai\output\teacher_presenter::status_class($latest)));
        if ($latest && $latest->status === 'failed' && !empty($latest->lasterror)) {
            $status .= html_writer::tag('div', get_string('failurecause', 'local_kbaai') . ': ' . s($latest->lasterror),
                array('class' => 'text-danger small mt-1'));
        }

        $actions = html_writer::link($workurl, get_string('openwork', 'local_kbaai'),
            array('class' => 'btn btn-sm btn-outline-secondary mr-1 mb-1'));
        $actions .= html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url, 'class' => 'd-inline mr-1'));
        $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
        $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => $latest ? 'rerun' : 'queue'));
        $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'submissionid', 'value' => $s->id));
        $actions .= html_writer::tag('button', $latest ? get_string('rerun', 'local_kbaai') : get_string('queueassessment', 'local_kbaai'),
            array('type' => 'submit', 'class' => 'btn btn-sm btn-secondary mb-1'));
        $actions .= html_writer::end_tag('form');

        if ($latest && $latest->status === 'completed' && $latest->mode !== 'tutor' && $latest->publishstatus !== 'published') {
            $result = \local_kbaai\output\teacher_presenter::result($latest);
            $actions .= html_writer::start_tag('details', array('class' => 'mt-2 kbaai-result'));
            $actions .= html_writer::tag('summary', get_string('reviewandpublish', 'local_kbaai'), array('class' => 'font-weight-bold'));
            $actions .= html_writer::start_div('mt-2 p-3 border rounded');
            $actions .= html_writer::tag('div', get_string('proposedgrade', 'local_kbaai') . ': <strong>' .
                format_float($latest->aiscore, 2) . ' / ' . format_float($latest->maxscore, 2) . '</strong>', array('class' => 'mb-1'));
            $actions .= html_writer::tag('div', get_string('confidencequalitative', 'local_kbaai') . ': <strong>' . s(local_kbaai_confidence_label($latest->confidence)) . '</strong>', array('class' => 'mb-3 text-muted'));
            $snap=json_decode((string)$latest->inputsnapshot,true);
            $snapshotcriteria = is_array($snap) && !empty($snap['criteria']) && is_array($snap['criteria']) ? $snap['criteria'] : array();
            $actions .= local_kbaai_render_criteria_result($result, $snapshotcriteria);
            if (is_array($snap) && !empty($snap['assessment_target']['files'])) { $actions .= local_kbaai_render_file_statuses($snap['assessment_target']['files']); }
            if (!empty($result['strengths']) && is_array($result['strengths'])) {
                $items = '';
                foreach ($result['strengths'] as $item) { $items .= html_writer::tag('li', s($item)); }
                $actions .= html_writer::tag('div', html_writer::tag('strong', get_string('strengths', 'local_kbaai')) . html_writer::tag('ul', $items), array('class' => 'mb-2'));
            }
            if (!empty($result['improvements']) && is_array($result['improvements'])) {
                $items = '';
                foreach ($result['improvements'] as $item) { $items .= html_writer::tag('li', s($item)); }
                $actions .= html_writer::tag('div', html_writer::tag('strong', get_string('improvements', 'local_kbaai')) . html_writer::tag('ul', $items), array('class' => 'mb-2'));
            }
            if (!empty($result['teacher_note'])) {
                $actions .= html_writer::div(html_writer::tag('strong', get_string('aiteachernote', 'local_kbaai')) . html_writer::div(s($result['teacher_note']), 'mt-1'), 'alert alert-light border mb-2');
            }
            if (!empty($result['student_feedback'])) {
                $actions .= html_writer::div(html_writer::tag('strong', get_string('aistudentfeedbackpreview', 'local_kbaai')) . html_writer::div(s($result['student_feedback']), 'mt-1'), 'border rounded p-2 mb-3');
            }
            $actions .= html_writer::tag('h6', get_string('teacherdecision', 'local_kbaai'), array('class'=>'mt-3'));
            $actions .= html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url, 'class' => 'mt-2'));
            $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
            $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'approve'));
            $actions .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'assessmentid', 'value' => $latest->id));
            $actions .= html_writer::tag('label', get_string('finalgrade', 'local_kbaai'));
            $actions .= html_writer::empty_tag('input', array('type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => $latest->maxscore,
                'name' => 'finalscore', 'value' => $latest->aiscore, 'class' => 'form-control mb-2'));
            $actions .= html_writer::tag('label', get_string('feedbackforstudent', 'local_kbaai'));
            $feedbackvalue = ($latest->provider === 'mock') ? get_string('mockstudentfeedback', 'local_kbaai') : $latest->studentfeedback;
            $actions .= html_writer::tag('textarea', s($feedbackvalue), array('name' => 'feedback', 'rows' => 4, 'class' => 'form-control mb-2'));
            $actions .= html_writer::tag('button', get_string('approvepublish', 'local_kbaai'), array('type' => 'submit', 'class' => 'btn btn-sm btn-success'));
            $actions .= html_writer::end_tag('form');
            if (is_siteadmin() || has_capability('local/kbaai:viewtechnical', $context)) {
                $actions .= html_writer::start_tag('details', array('class' => 'mt-3'));
                $actions .= html_writer::tag('summary', get_string('technicaldetails', 'local_kbaai'));
                $actions .= html_writer::tag('div', get_string('confidence', 'local_kbaai') . ': ' . (int)$latest->confidence . '%',
                    array('class' => 'small text-muted mt-2'));
                $actions .= html_writer::end_tag('details');
            }
            $actions .= html_writer::end_div();
            $actions .= html_writer::end_tag('details');
        }
        $preview = shorten_text(trim(strip_tags($target['text'])), 160);
        $previewhtml=s($preview);
        if (!empty($target['files'])) {
            $names=array(); foreach ($target['files'] as $f) { $names[]=s($f['filename']); }
            $previewhtml .= html_writer::div(get_string('officialfiles','local_kbaai').': '.implode(', ',$names),'small text-muted mt-1');
        }
        $table->data[] = array($studentlink, $previewhtml, $status, $actions);
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
