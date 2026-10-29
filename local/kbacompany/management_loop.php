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
$PAGE->set_url(new moodle_url('/local/kbacompany/management_loop.php', ['uuid'=>$uuid, 'week'=>$data['week_start']]));
$PAGE->set_title(get_string('management_loop_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'management',
    get_string('management_loop_title', 'local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'loop', $data['week_start']);
$actions = html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'exceptions']),
    get_string('management_open_exceptions', 'local_kbacompany'), ['class'=>'btn btn-primary btn-sm']);
echo bms_workspace_ux_service::record_header(
    get_string('management_loop_title', 'local_kbacompany'),
    get_string('management_loop_intro', 'local_kbacompany'),
    get_string('management_workspace_eyebrow', 'local_kbacompany'),
    battle_plan_service::period_label($data['week_start'], $data['week_end']),
    $actions
);

$counts = ['decision'=>0,'action'=>0,'execution'=>0,'control'=>0,'followup'=>0,'repeat'=>0,'closed'=>0];
foreach ($data['decision_loops'] as $row) {
    if (isset($counts[$row['stage']])) { $counts[$row['stage']]++; }
}
$cards = [
    ['decision','management_loop_kpi_decision'],
    ['execution','management_loop_kpi_execution'],
    ['followup','management_loop_kpi_followup'],
    ['repeat','management_loop_kpi_repeat'],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$counts[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('management_loop_table_title', 'local_kbacompany'), 2, 'h4 mt-4');
if (!$data['decision_loops']) {
    echo html_writer::div(get_string('management_loop_empty', 'local_kbacompany'), 'alert alert-success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('management_loop_indicator','local_kbacompany'),
        get_string('management_loop_stage','local_kbacompany'),
        get_string('management_loop_decision','local_kbacompany'),
        get_string('management_loop_action','local_kbacompany'),
        get_string('management_loop_control','local_kbacompany'),
        get_string('management_loop_followup','local_kbacompany'),
        get_string('management_loop_outcome','local_kbacompany'),
    ];
    foreach ($data['decision_loops'] as $row) {
        $decision = $row['decision_uuid'] !== '' ? ($row['decision_comment'] !== '' ? s($row['decision_comment']) : s($row['decision_period'])) : '—';
        $action = '—';
        if ($row['task_exists']) {
            $label = $row['task_title'] !== '' ? s($row['task_title']) : get_string('management_loop_task_exists','local_kbacompany');
            $action = $row['task_visible'] && $row['task_url'] !== '' ? html_writer::link($row['task_url'], $label) : $label;
        }
        $control = $row['task_exists'] ? get_string('management_loop_task_status_'.$row['task_status'], 'local_kbacompany') : '—';
        if ($row['task_overdue']) { $control .= ' · '.get_string('management_loop_overdue','local_kbacompany'); }
        $followup = $row['followup_period'] !== '' ? s($row['followup_period']) : '—';
        if ($row['baseline_fact'] !== null || $row['followup_fact'] !== null) {
            $followup .= '<br><small>'.s((string)($row['baseline_fact'] ?? '—')).' → '.s((string)($row['followup_fact'] ?? '—')).'</small>';
        }
        $outcome = get_string('management_loop_quality_'.$row['followup_quality'], 'local_kbacompany');
        if ($row['requires_new_decision']) { $outcome .= ' · <strong>'.get_string('management_loop_requires_repeat','local_kbacompany').'</strong>'; }
        $table->data[] = [
            s($row['metric_name']).($row['context_label'] !== '' ? '<br><small>'.s($row['context_label']).'</small>' : ''),
            get_string('management_loop_stage_'.$row['stage'], 'local_kbacompany'),
            $decision, $action, $control, $followup, $outcome,
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::div(get_string('management_loop_causality_note','local_kbacompany'), 'alert alert-info mt-3');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
