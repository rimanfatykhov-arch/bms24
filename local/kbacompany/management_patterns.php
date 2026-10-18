<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\management_execution_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$q = trim(optional_param('q', '', PARAM_TEXT));
$patterns = management_execution_service::patterns_context($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/management_patterns.php', ['uuid'=>$uuid, 'q'=>$q]));
$PAGE->set_title(get_string('management_patterns_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'management',
    get_string('management_patterns_title', 'local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'patterns');
$actions = html_writer::link(new moodle_url('/local/kbacompany/management_history.php', ['uuid'=>$uuid]),
    get_string('management_history_title', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);
echo bms_workspace_ux_service::record_header(
    get_string('management_patterns_title', 'local_kbacompany'),
    get_string('management_patterns_intro', 'local_kbacompany'),
    get_string('management_workspace_eyebrow', 'local_kbacompany'),
    '', $actions
);

$summary = $patterns['summary'];
$cards = [
    ['patterns', 'management_patterns_kpi_patterns'],
    ['occurrences', 'management_patterns_kpi_occurrences'],
    ['without_actions', 'management_patterns_kpi_without_actions'],
    ['after_completed_action', 'management_patterns_kpi_after_completed'],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$summary[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method'=>'get', 'class'=>'bms-workspace-actionbar mt-3 mb-3']);
echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'uuid', 'value'=>$uuid]);
echo html_writer::start_div('bms-workspace-actionbar-main');
echo html_writer::empty_tag('input', [
    'type'=>'search', 'name'=>'q', 'value'=>$q, 'class'=>'form-control form-control-sm',
    'placeholder'=>get_string('management_patterns_search_placeholder', 'local_kbacompany'),
]);
echo html_writer::tag('button', get_string('search'), ['type'=>'submit', 'class'=>'btn btn-sm btn-outline-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$rows = [];
foreach ((array)$patterns['rows'] as $row) {
    if ($q !== '') {
        $haystack = core_text::strtolower(implode(' ', [
            (string)$row['metric_code'], (string)$row['metric_name'], (string)$row['context_kind'],
            (string)$row['last_comment'], (string)$row['last_actor_name'],
        ]));
        if (strpos($haystack, core_text::strtolower($q)) === false) { continue; }
    }
    $rows[] = $row;
}

echo $OUTPUT->heading(get_string('management_patterns_table_title', 'local_kbacompany'), 2, 'h4 mt-4');
if (!$rows) {
    echo html_writer::div(get_string('management_patterns_empty', 'local_kbacompany'), 'alert alert-secondary');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('management_loop_indicator','local_kbacompany'),
        get_string('management_patterns_occurrences','local_kbacompany'),
        get_string('management_patterns_period','local_kbacompany'),
        get_string('management_patterns_actions','local_kbacompany'),
        get_string('management_patterns_signal','local_kbacompany'),
        get_string('management_patterns_last_decision','local_kbacompany'),
    ];
    foreach ($rows as $row) {
        $indicator = trim((string)$row['metric_code'] . ' ' . (string)$row['metric_name']);
        if ($indicator === '') { $indicator = '—'; }
        $period = get_string('management_patterns_period_range', 'local_kbacompany', (object)[
            'first'=>(string)$row['first_period'], 'last'=>(string)$row['last_period'],
        ]);
        $actions = get_string('management_patterns_actions_count', 'local_kbacompany', (object)[
            'with'=>(int)$row['with_actions'], 'without'=>(int)$row['without_actions'],
        ]);
        $signal = !empty($row['recurs_after_completed_action'])
            ? get_string('management_patterns_signal_after_completed', 'local_kbacompany')
            : ((int)$row['without_actions'] > 0
                ? get_string('management_patterns_signal_without_action', 'local_kbacompany')
                : get_string('management_patterns_signal_repeat', 'local_kbacompany'));
        $lastdecision = trim((string)$row['last_comment']);
        if ($lastdecision === '') { $lastdecision = get_string('management_history_no_comment', 'local_kbacompany'); }
        $table->data[] = [
            s($indicator), (int)$row['occurrences'], s($period), s($actions), s($signal), s($lastdecision),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::div(get_string('management_patterns_note','local_kbacompany'), 'alert alert-info mt-3');
\local_kbacompany\local\bms_page_shell_service::finish($shell);
