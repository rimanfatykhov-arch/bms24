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
$PAGE->set_url(new moodle_url('/local/kbacompany/management.php', ['uuid' => $uuid, 'week' => $data['week_start']]));
$PAGE->set_title(get_string('management_workspace_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'management',
    get_string('management_workspace_title', 'local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'overview', $data['week_start']);

$actions = html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'exceptions']),
    get_string('management_open_exceptions', 'local_kbacompany'), ['class'=>'btn btn-primary btn-sm']);
$actions .= html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('management_open_week', 'local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);

echo bms_workspace_ux_service::record_header(
    get_string('management_workspace_title', 'local_kbacompany'),
    get_string('management_workspace_intro', 'local_kbacompany'),
    get_string('management_workspace_eyebrow', 'local_kbacompany'),
    battle_plan_service::period_label($data['week_start'], $data['week_end']),
    $actions
);

echo html_writer::start_tag('form', ['method'=>'get','class'=>'form-inline mb-3']);
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
echo html_writer::label(get_string('battleplan_week','local_kbacompany'), 'management_week', false, ['class'=>'mr-2']);
echo html_writer::empty_tag('input', ['type'=>'date','id'=>'management_week','name'=>'week','value'=>$data['week_start'],'class'=>'form-control mr-2']);
echo html_writer::tag('button', get_string('battleplan_show','local_kbacompany'), ['type'=>'submit','class'=>'btn btn-sm btn-outline-primary']);
echo html_writer::end_tag('form');

$summary = $data['summary'];
$cards = [
    ['needs_decision','management_kpi_needs_decision'],
    ['critical_exceptions','management_kpi_critical_exceptions'],
    ['overdue_tasks','management_kpi_overdue_tasks'],
    ['active_plans','management_kpi_active_plans'],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$summary[$card[0]], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1], 'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

$acceptance = $data['acceptance'];
$stateclass = $acceptance['state'] === 'accepted' ? 'alert alert-success' :
    ($acceptance['state'] === 'blocked' ? 'alert alert-danger' : 'alert alert-warning');
echo html_writer::div(
    html_writer::tag('strong', get_string('battleplan_acceptance_state_'.$acceptance['state'], 'local_kbacompany')) . ' — ' .
    get_string('management_acceptance_summary', 'local_kbacompany', (object)[
        'hard'=>$acceptance['hard_count'], 'attention'=>$acceptance['attention_count']
    ]), $stateclass . ' mt-3');

// Execution chain.
echo $OUTPUT->heading(get_string('management_execution_chain_title', 'local_kbacompany'), 2, 'h4 mt-4');
$chain = [
    ['management_chain_indicators','indicators.php'],
    ['management_chain_exceptions','indicators.php?tab=exceptions'],
    ['management_chain_tasks','communication_center.php?tab=tasks&taskscope=my&provider=all'],
    ['management_chain_battleplan','battle_plan.php'],
    ['management_chain_cycle','management_cycles.php'],
];
echo html_writer::start_div('row');
foreach ($chain as $index=>$item) {
    $url = '/local/kbacompany/'.$item[1];
    $sep = strpos($url, '?') === false ? '?' : '&';
    $url .= $sep.'uuid='.rawurlencode($uuid);
    echo html_writer::start_div('col-xl col-md-4 col-sm-6 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::div((string)($index + 1), 'badge badge-secondary mb-2');
    echo html_writer::tag('h3', get_string($item[0], 'local_kbacompany'), ['class'=>'h6']);
    echo html_writer::link($url, get_string('bmsnav_open', 'local_kbacompany'), ['class'=>'stretched-link',
        'aria-label'=>get_string('bmsnav_open_section', 'local_kbacompany',
            get_string($item[0], 'local_kbacompany'))]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Attention queue.
echo $OUTPUT->heading(get_string('management_attention_title', 'local_kbacompany'), 2, 'h4 mt-3');
if (!$data['attention']) {
    echo html_writer::div(get_string('management_attention_clear', 'local_kbacompany'), 'alert alert-success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('management_attention_severity','local_kbacompany'),
        get_string('management_attention_object','local_kbacompany'),
        get_string('management_attention_context','local_kbacompany'),
        get_string('management_attention_reason','local_kbacompany'),
    ];
    foreach ($data['attention'] as $row) {
        $reason = [];
        if ($row['needs_decision']) { $reason[] = get_string('management_reason_decision','local_kbacompany'); }
        if ($row['overdue']) { $reason[] = get_string('management_reason_overdue','local_kbacompany'); }
        if (!$reason) { $reason[] = get_string('management_reason_exception','local_kbacompany'); }
        $table->data[] = [
            get_string('indicator_exception_severity_'.$row['severity'],'local_kbacompany'),
            s($row['title']), s($row['context']), s(implode(' · ', $reason)),
        ];
    }
    echo html_writer::table($table);
}

// Gate details.
echo $OUTPUT->heading(get_string('management_gate_title', 'local_kbacompany'), 2, 'h4 mt-4');
$gate = new html_table();
$gate->attributes['class'] = 'table table-sm';
$gate->head = [get_string('battleplan_acceptance_gate','local_kbacompany'),
    get_string('battleplan_acceptance_level','local_kbacompany'),
    get_string('battleplan_acceptance_count','local_kbacompany')];
foreach ($acceptance['gates'] as $row) {
    $gate->data[] = [get_string('battleplan_acceptance_gate_'.$row['code'],'local_kbacompany'),
        get_string('battleplan_acceptance_level_'.$row['level'],'local_kbacompany'), (int)$row['count']];
}
echo html_writer::table($gate);

\local_kbacompany\local\bms_page_shell_service::finish($shell);
