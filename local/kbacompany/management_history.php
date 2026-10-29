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
$q = trim(optional_param('q', '', PARAM_TEXT));
$data = management_execution_service::context($uuid, (int)$USER->id, $week);
$history = management_execution_service::history_context($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/management_history.php', [
    'uuid' => $uuid, 'week' => $data['week_start'], 'q' => $q,
]));
$PAGE->set_title(get_string('management_history_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'management',
    get_string('management_history_title', 'local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'history', $data['week_start']);
$actions = html_writer::link(new moodle_url('/local/kbacompany/management_review.php', [
    'uuid'=>$uuid, 'week'=>$data['week_start'],
]), get_string('management_review_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);
echo bms_workspace_ux_service::record_header(
    get_string('management_history_title', 'local_kbacompany'),
    get_string('management_history_intro', 'local_kbacompany'),
    get_string('management_workspace_eyebrow', 'local_kbacompany'),
    battle_plan_service::period_label($data['week_start'], $data['week_end']),
    $actions
);

$summary = $history['summary'];
$cards = [
    ['decisions', 'management_history_kpi_decisions'],
    ['repeat_bindings', 'management_history_kpi_repeat'],
    ['with_actions', 'management_history_kpi_with_actions'],
    ['without_actions', 'management_history_kpi_without_actions'],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$summary[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Compact standard search toolbar.
echo html_writer::start_tag('form', ['method'=>'get', 'class'=>'bms-workspace-actionbar mt-3 mb-3']);
echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'uuid', 'value'=>$uuid]);
echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'week', 'value'=>$data['week_start']]);
echo html_writer::start_div('bms-workspace-actionbar-main');
echo html_writer::empty_tag('input', [
    'type'=>'search', 'name'=>'q', 'value'=>$q, 'class'=>'form-control form-control-sm',
    'placeholder'=>get_string('management_history_search_placeholder', 'local_kbacompany'),
]);
echo html_writer::tag('button', get_string('search'), ['type'=>'submit', 'class'=>'btn btn-sm btn-outline-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$rows = [];
foreach ((array)$history['rows'] as $row) {
    if ($q !== '') {
        $haystack = core_text::strtolower(implode(' ', [
            (string)$row['metric_code'], (string)$row['metric_name'], (string)$row['comment'],
            (string)$row['actor_name'], (string)$row['period'],
        ]));
        if (strpos($haystack, core_text::strtolower($q)) === false) { continue; }
    }
    $rows[] = $row;
}

echo $OUTPUT->heading(get_string('management_history_table_title', 'local_kbacompany'), 2, 'h4 mt-4');
if (!$rows) {
    echo html_writer::div(get_string('management_history_empty', 'local_kbacompany'), 'alert alert-secondary');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('management_history_period','local_kbacompany'),
        get_string('management_loop_indicator','local_kbacompany'),
        get_string('management_history_decision','local_kbacompany'),
        get_string('management_history_execution','local_kbacompany'),
        get_string('management_history_repeat','local_kbacompany'),
        get_string('management_history_actor','local_kbacompany'),
    ];
    foreach ($rows as $row) {
        $indicator = trim((string)$row['metric_code'] . ' ' . (string)$row['metric_name']);
        if ($indicator === '') { $indicator = '—'; }
        $decision = (string)$row['comment'] !== '' ? s($row['comment']) : get_string('management_history_no_comment','local_kbacompany');
        $execution = $row['task_count'] > 0
            ? get_string('management_history_execution_count', 'local_kbacompany', (object)[
                'total'=>(int)$row['task_count'], 'completed'=>(int)$row['completed_task_count']
            ])
            : get_string('management_history_no_action','local_kbacompany');
        $repeat = !empty($row['is_repeated'])
            ? get_string('management_history_repeat_count','local_kbacompany', (int)$row['repeat_count'])
            : get_string('management_history_first_case','local_kbacompany');
        $table->data[] = [
            s((string)$row['period']), s($indicator), $decision, s($execution), s($repeat), s((string)$row['actor_name']),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::div(get_string('management_history_note','local_kbacompany'), 'alert alert-info mt-3');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
