<?php
/** Read-only project implementation stages: Corporate verified baseline snapshot. */
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$programid = required_param('programid', PARAM_INT);
$week = optional_param('week', '', PARAM_RAW_TRIMMED);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/implementation_project_stages.php', [
    'uuid' => $uuid, 'programid' => $programid, 'week' => $week,
]));
$PAGE->set_title(get_string('implstage_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$model = \local_kbacompany\local\implementation_project_result_service::model(
    $uuid, (int)$USER->id, $programid, $week);
$stages = $model['stages'];
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'adminscale',
    get_string('implstage_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('implstage_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('implstage_disclaimer', 'local_kbacompany'), ['class' => 'text-muted']);
echo html_writer::tag('p', get_string('implproj_linked', 'local_kbacompany') . ': ' .
    html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', [
        'uuid' => $uuid, 'viewkind' => 'project', 'detailuuid' => $model['projectuuid'],
    ]), s($model['projecttitle'])));
echo html_writer::link(new moodle_url('/local/kbacompany/implementation_project_dashboard.php', [
    'uuid'=>$uuid, 'programid'=>$programid, 'week'=>$model['week'],
]), get_string('impldash_open', 'local_kbacompany'), ['class'=>'bms24-ui-btn mb-3']);
echo html_writer::link(new moodle_url('/local/kbacompany/implementation_projects.php', [
    'uuid' => $uuid,
]), get_string('implstage_back', 'local_kbacompany'), ['class' => 'bms24-ui-btn mb-3']);
// Week selector is a read-only navigation: no changes to Battle Plans or Corporate tasks.
$weekstart = new DateTimeImmutable($model['week'], new DateTimeZone('UTC'));
$weekend = $weekstart->modify('+6 days');
$weekbase = '/local/kbacompany/implementation_project_stages.php';
$weeklink = static function(string $day) use ($weekbase, $uuid, $programid): moodle_url {
    return new moodle_url($weekbase, ['uuid'=>$uuid, 'programid'=>$programid, 'week'=>$day]);
};
echo html_writer::start_tag('nav', ['class'=>'d-flex align-items-center flex-wrap gap-2 mb-3',
    'aria-label'=>get_string('implweek_nav', 'local_kbacompany')]);
echo html_writer::link($weeklink($weekstart->modify('-7 days')->format('Y-m-d')),
    get_string('implweek_previous', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
echo html_writer::tag('strong', get_string('implweek_period', 'local_kbacompany', (object)[
    'start'=>$weekstart->format('d.m.Y'), 'end'=>$weekend->format('d.m.Y')
]));
echo html_writer::link($weeklink($weekstart->modify('+7 days')->format('Y-m-d')),
    get_string('implweek_next', 'local_kbacompany'), ['class'=>'bms24-ui-btn']);
echo html_writer::link($weeklink(\local_kbacompany\local\battle_plan_service::week_start(
    \local_kbacompany\local\battle_plan_service::user_today())), get_string('implweek_current', 'local_kbacompany'),
    ['class'=>'bms24-ui-btn']);
echo html_writer::end_tag('nav');
if (!$model['weekly_complete']) {
    $reason = (string)$model['weekly_reason'];
    $key = in_array($reason, ['restricted','upgrade_corporate','shared_baseline',
            'limit_exceeded','unconfirmed_baseline'], true) ? $reason : 'incomplete';
    echo html_writer::tag('p', get_string('implweek_' . $key, 'local_kbacompany'),
        ['class'=>'alert alert-warning']);
} else {
    echo html_writer::tag('p', get_string('implweek_scope', 'local_kbacompany'),
        ['class'=>'text-muted']);
}
// Corporate owns result evidence and curator decisions. This page only reads metadata.
if (!$model['result_complete']) {
    $reason = (string)$model['result_reason'];
    $known = ['upgrade_corporate', 'unconfirmed_baseline', 'shared_baseline',
        'limit_exceeded', 'evidence_unavailable', 'changed'];
    $key = in_array($reason, $known, true) ? $reason : 'incomplete';
    echo html_writer::tag('p', get_string('implresult_' . $key, 'local_kbacompany'),
        ['class' => 'alert alert-warning']);
} else {
    echo html_writer::tag('p', get_string('implresult_scope', 'local_kbacompany'),
        ['class' => 'text-muted']);
}
if (empty($stages['available'])) {
    echo html_writer::tag('p', get_string('implstage_no_baseline', 'local_kbacompany'),
        ['class' => 'alert alert-warning']);
} else if (empty($stages['sections'])) {
    echo html_writer::tag('p', get_string('implstage_empty', 'local_kbacompany'),
        ['class' => 'alert alert-info']);
} else {
    $sum = $stages['summary'];
    echo html_writer::tag('p', get_string('implstage_total', 'local_kbacompany', (object)[
        'stages' => (int)$sum['section_count'], 'tasks' => (int)$sum['tasks'],
        'completed' => (int)$sum['completed'], 'overdue' => (int)$sum['overdue'],
        'evidence' => (int)$sum['accepted_evidence'],
    ]), ['class' => 'alert alert-light']);
    foreach ($stages['sections'] as $stage) {
        $name = trim((string)$stage['name']);
        if ($name === '') { $name = (string)$stage['code']; }
        $status = (string)$stage['status'];
        $allowed = ['unassessed', 'achieved', 'risk', 'noactions', 'partial', 'complete', 'review', 'inprogress'];
        if (!in_array($status, $allowed, true)) { $status = 'unassessed'; }
        echo html_writer::start_tag('section', ['class' => 'card mb-3']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('h3', format_string($name), ['class' => 'h5']);
        echo html_writer::tag('p', get_string('implstage_status_' . $status, 'local_kbacompany'),
            ['class' => 'text-muted']);
        echo html_writer::tag('p', get_string('implstage_metrics', 'local_kbacompany', (object)[
            'tasks' => (int)$stage['task_count'], 'done' => (int)$stage['completed_count'],
            'review' => (int)$stage['review_count'], 'overdue' => (int)$stage['overdue_count'],
            'accepted' => (int)$stage['accepted_evidence_count'],
        ]));
        echo html_writer::tag('p', get_string('implstage_criteria', 'local_kbacompany', (object)[
            'total' => (int)$stage['criteria_count'], 'gaps' => (int)$stage['gap_count'],
            'covered' => (int)$stage['covered_count'],
        ]), ['class' => 'text-muted']);
        if ($model['result_complete']) {
            $results = $model['result_sections'][(string)$stage['code']] ?? null;
            if ($results !== null) {
                $counts = $results['counts'];
                echo html_writer::tag('h4', get_string('implresult_heading', 'local_kbacompany'),
                    ['class' => 'h6 mt-3']);
                echo html_writer::tag('p', get_string('implresult_summary', 'local_kbacompany', (object)[
                    'review'=>(int)$counts['awaiting_review'] + (int)$counts['review_without_evidence'],
                    'returned'=>(int)$counts['returned'], 'accepted'=>(int)$counts['accepted'],
                    'legacy'=>(int)$counts['completed_unverified'],
                    'inconsistent'=>(int)$counts['inconsistent'],
                ]), ['class'=>'mb-2']);
                $list = [];
                // Keep the overview short. The detailed evidence and comments remain in Corporate.
                $order = ['returned'=>0, 'awaiting_review'=>1, 'review_without_evidence'=>2,
                    'inconsistent'=>3, 'completed_unverified'=>4, 'accepted'=>5,
                    'in_work'=>6, 'not_started'=>7, 'cancelled'=>8];
                $taskrows = $results['tasks'];
                usort($taskrows, static function(array $a, array $b) use ($order): int {
                    return ($order[$a['result_state']] <=> $order[$b['result_state']]);
                });
                foreach (array_slice($taskrows, 0, 12) as $task) {
                    $taskid = (int)substr((string)$task['external_id'], strlen('impltask:'));
                    $url = new moodle_url('/local/kbacorporate/implementation_task.php', ['id'=>$taskid]);
                    $state = (string)$task['result_state'];
                    $label = get_string('implresult_state_' . $state, 'local_kbacompany');
                    $line = html_writer::link($url, '#' . $taskid) . ' · ' . s($label);
                    if (!empty($task['evidence_version'])) {
                        $line .= ' · ' . s(get_string('implresult_version', 'local_kbacompany',
                            (int)$task['evidence_version']));
                    }
                    if (!empty($task['reviewed_at'])) {
                        $line .= ' · ' . s(userdate((int)$task['reviewed_at'], get_string('strftimedatetime', 'langconfig')));
                    }
                    if (!empty($task['plan_links'])) {
                        foreach ($task['plan_links'] as $plan) {
                            $line .= ' · ' . html_writer::link(new moodle_url(
                                '/local/kbacompany/battle_plan.php', [
                                    'uuid'=>$uuid, 'plan'=>$plan['plan_uuid'], 'view'=>'detail',
                                ]), get_string('implweek_openplan', 'local_kbacompany'));
                        }
                    }
                    $list[] = html_writer::tag('li', $line);
                }
                if ($list) {
                    $html = html_writer::tag('ul', implode('', $list), ['class'=>'mb-1']);
                    if (count($taskrows) > 12) {
                        $html .= html_writer::tag('p', get_string('implresult_more', 'local_kbacompany'),
                            ['class'=>'text-muted small']);
                    }
                    echo html_writer::tag('details', html_writer::tag('summary',
                        get_string('implresult_details', 'local_kbacompany')) . $html,
                        ['class'=>'mb-3']);
                }
            }
        }
        if ($model['weekly_complete']) {
            $weekly = $model['weekly_sections'][(string)$stage['code']] ??
                ['planned'=>0,'unplanned'=>0,'review'=>0,'completed'=>0,'tasks'=>[]];
            echo html_writer::tag('p', get_string('implweek_summary', 'local_kbacompany', (object)[
                'planned'=>(int)$weekly['planned'], 'unplanned'=>(int)$weekly['unplanned'],
                'review'=>(int)$weekly['review'], 'completed'=>(int)$weekly['completed'],
            ]), ['class'=>'font-weight-bold']);
            $shown = 0;
            foreach ($weekly['tasks'] as $task) {
                if (!in_array((string)$task['status'], ['planned','inprogress','review'], true)) { continue; }
                if ($shown++ >= 12) { break; }
                $taskid = (int)substr((string)$task['external_id'], strlen('impltask:'));
                $taskurl = new moodle_url('/local/kbacorporate/implementation_task.php', ['id'=>$taskid]);
                $planlinks = $task['plan_links'];
                $label = trim((string)$task['title']);
                if ($label === '') { $label = '#' . $taskid; }
                $row = html_writer::link($taskurl, s($label));
                if ($task['status'] === 'review') {
                    $row .= ' · ' . s(get_string('implweek_inreview', 'local_kbacompany'));
                } else if ($planlinks) {
                    $row .= ' · ' . s(get_string('implweek_inplan', 'local_kbacompany'));
                    foreach ($planlinks as $plan) {
                        $row .= ' · ' . html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', [
                            'uuid'=>$uuid, 'plan'=>$plan['plan_uuid'], 'view'=>'detail',
                        ]), s(get_string('implweek_openplan', 'local_kbacompany')));
                    }
                } else {
                    $row .= ' · ' . s(get_string('implweek_notplanned', 'local_kbacompany'));
                }
                echo html_writer::tag('p', $row, ['class'=>'mb-1']);
            }
            if ($shown > 12) {
                echo html_writer::tag('p', get_string('implweek_more', 'local_kbacompany'),
                    ['class'=>'text-muted small']);
            }
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('section');
    }
    // Corporate owns the evidence details. The source page independently enforces access.
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php', [
        'companyid' => $model['companyid'], 'auditid' => (int)$stages['audit_id'],
    ]), get_string('implstage_source', 'local_kbacompany'), ['class' => 'bms24-ui-btn']);
}
echo \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
