<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\management_execution_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$week = optional_param('week', '', PARAM_TEXT);
$data = management_execution_service::context($uuid, (int)$USER->id, $week);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/management_review.php', ['uuid'=>$uuid, 'week'=>$data['week_start']]));
$PAGE->set_title(get_string('management_review_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'management',
    get_string('management_review_title', 'local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'review', $data['week_start']);
$actions = html_writer::link(new moodle_url('/local/kbacompany/management_loop.php', ['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('management_loop_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);
echo bms_workspace_ux_service::record_header(
    get_string('management_review_title', 'local_kbacompany'),
    get_string('management_review_intro', 'local_kbacompany'),
    get_string('management_workspace_eyebrow', 'local_kbacompany'),
    battle_plan_service::period_label($data['week_start'], $data['week_end']),
    $actions
);

$summary = $data['review_summary'];
$cards = [
    ['closure_ready','management_review_kpi_ready'],
    ['repeat_required','management_review_kpi_repeat'],
    ['awaiting_fact','management_review_kpi_awaiting'],
    ['active','management_review_kpi_active'],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$summary[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('management_review_table_title', 'local_kbacompany'), 2, 'h4 mt-4');
if (!$data['review_rows']) {
    echo html_writer::div(get_string('management_review_empty', 'local_kbacompany'), 'alert alert-success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('management_loop_indicator','local_kbacompany'),
        get_string('management_review_decision','local_kbacompany'),
        get_string('management_review_execution','local_kbacompany'),
        get_string('management_review_fact','local_kbacompany'),
        get_string('management_review_result','local_kbacompany'),
        get_string('management_review_status','local_kbacompany'),
    ];
    foreach ($data['review_rows'] as $row) {
        $decision = $row['decision_comment'] !== '' ? s($row['decision_comment']) : ($row['decision_period'] !== '' ? s($row['decision_period']) : '—');
        $execution = '—';
        if ($row['task_exists']) {
            $execution = $row['task_title'] !== '' ? s($row['task_title']) : get_string('management_loop_task_exists','local_kbacompany');
            if ($row['task_visible'] && $row['task_url'] !== '') { $execution = html_writer::link($row['task_url'], $execution); }
            $execution .= '<br><small>'.get_string('management_loop_task_status_'.$row['task_status'],'local_kbacompany').'</small>';
        }
        $fact = $row['followup_period'] !== '' ? s($row['followup_period']) : '—';
        if ($row['baseline_fact'] !== null || $row['followup_fact'] !== null) {
            $fact .= '<br><small>'.s((string)($row['baseline_fact'] ?? '—')).' → '.s((string)($row['followup_fact'] ?? '—')).'</small>';
        }
        $result = get_string('management_loop_quality_'.$row['followup_quality'], 'local_kbacompany');
        $status = get_string('management_review_state_'.$row['review_state'], 'local_kbacompany');
        $table->data[] = [
            s($row['metric_name']).($row['context_label'] !== '' ? '<br><small>'.s($row['context_label']).'</small>' : ''),
            $decision, $execution, $fact, $result, $status,
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::div(get_string('management_review_note','local_kbacompany'), 'alert alert-info mt-3');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
