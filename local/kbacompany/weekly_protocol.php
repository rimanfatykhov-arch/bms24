<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\weekly_management_protocol_service;
use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$meetinguuid = required_param('meeting', PARAM_RAW_TRIMMED);
$data = weekly_management_protocol_service::context($uuid, (int)$USER->id, $meetinguuid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/weekly_protocol.php', ['uuid'=>$uuid,'meeting'=>$meetinguuid]));
$PAGE->set_title(get_string('weeklyproto_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'managementcycles', get_string('weeklyproto_title','local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'cycles');
echo bms_workspace_ux_service::record_header(
    get_string('weeklyproto_title','local_kbacompany'),
    get_string('weeklyproto_intro','local_kbacompany'),
    get_string('management_workspace_eyebrow','local_kbacompany'),
    s($data['week_start'].' — '.$data['week_end'].' · '.$data['cycle_title'].' · '.$data['checkpoint_title']),
    html_writer::link($data['meeting_url'], get_string('weeklyproto_open_meeting','local_kbacompany'), ['class'=>'btn btn-outline-primary btn-sm'])
);

$cards = [
    [get_string('weeklyproto_kpi_previous_plans','local_kbacompany'), (int)$data['previous_plans']['summary']['plans']],
    [get_string('weeklyproto_kpi_open_tasks','local_kbacompany'), (int)$data['previous_plans']['summary']['open_tasks']],
    [get_string('weeklyproto_kpi_agreed_coordination','local_kbacompany'), count($data['coordination']['agreed'])],
    [get_string('weeklyproto_kpi_decisions','local_kbacompany'), count($data['decisions'])],
];
echo html_writer::start_div('kbacompany-summary-grid');
foreach ($cards as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$card[1], 'kbacompany-summary-value');
    echo html_writer::div($card[0], 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($data['priority'] !== '' || $data['outcome'] !== '') {
    echo html_writer::start_div('card card-body mb-3');
    if ($data['priority'] !== '') {
        echo html_writer::tag('div', html_writer::tag('strong', get_string('weeklyproto_priority','local_kbacompany').': ') . s($data['priority']));
    }
    if ($data['outcome'] !== '') {
        echo html_writer::tag('div', html_writer::tag('strong', get_string('weeklyproto_outcome','local_kbacompany').': ') . s($data['outcome']));
    }
    echo html_writer::end_div();
}

$renderplans = static function(array $block, string $heading) use ($OUTPUT): void {
    echo $OUTPUT->heading($heading, 3, 'h4 mt-4');
    if (!$block['rows']) {
        echo html_writer::div(get_string('weeklyproto_no_plans','local_kbacompany'), 'alert alert-light border');
        return;
    }
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [
        get_string('weeklyproto_unit','local_kbacompany'),
        get_string('weeklyproto_owner','local_kbacompany'),
        get_string('weeklyproto_plan_status','local_kbacompany'),
        get_string('weeklyproto_tasks','local_kbacompany'),
        get_string('weeklyproto_time','local_kbacompany'),
        get_string('actions'),
    ];
    foreach ($block['rows'] as $row) {
        $unit = trim($row['orgnode_name']) !== '' ? s($row['orgnode_name']) : '—';
        $owner = s($row['employee_name']) . html_writer::div(s($row['position_name']), 'small text-muted');
        $summary = (array)$row['summary'];
        $tasks = (int)$summary['done'].' / '.(int)$summary['tasks'];
        $time = format_float(((int)$summary['fact_minutes']) / 60, 1).' / '.format_float(((int)$summary['plan_minutes']) / 60, 1).' '.get_string('weeklyproto_hours','local_kbacompany');
        $table->data[] = [
            $unit, $owner, s($row['status']), $tasks, $time,
            html_writer::link($row['url'], get_string('weeklyproto_open_plan','local_kbacompany'), ['class'=>'btn btn-sm btn-outline-secondary']),
        ];
    }
    echo html_writer::table($table);
};

$renderplans($data['previous_plans'], get_string('weeklyproto_previous_results','local_kbacompany'));

echo $OUTPUT->heading(get_string('weeklyproto_indicators','local_kbacompany'), 3, 'h4 mt-4');
if (!$data['indicators']) {
    echo html_writer::div(get_string('weeklyproto_no_indicators','local_kbacompany'), 'alert alert-light border');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [get_string('battleplan_metric','local_kbacompany'), get_string('battleplan_previous_fact','local_kbacompany'),
        get_string('battleplan_fact','local_kbacompany'), get_string('battleplan_quota','local_kbacompany'), get_string('battleplan_trend','local_kbacompany')];
    foreach ($data['indicators'] as $m) {
        $metric = html_writer::link($m['indicator_url'], s($m['code'].' — '.$m['name']));
        $prev = $m['previous_fact'] === null ? '—' : format_float((float)$m['previous_fact'], 2).' '.s($m['unit']);
        $fact = $m['current_fact'] === null ? '—' : format_float((float)$m['current_fact'], 2).' '.s($m['unit']);
        $quota = $m['norm_label'] === '' ? '—' : s($m['norm_label']).' '.s($m['unit']);
        $trend = get_string('indicator_trend_'.($m['trend']['movement'] ?? 'insufficient'),'local_kbacompany');
        $table->data[] = [$metric,$prev,$fact,$quota,$trend];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('weeklyproto_coordination','local_kbacompany'), 3, 'h4 mt-4');
if (!$data['coordination']['agreed'] && !$data['coordination']['open']) {
    echo html_writer::div(get_string('weeklyproto_no_coordination','local_kbacompany'), 'alert alert-light border');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [get_string('weeklyproto_from','local_kbacompany'), get_string('weeklyproto_to','local_kbacompany'),
        get_string('weeklyproto_coordination_issue','local_kbacompany'), get_string('weeklyproto_coordination_change','local_kbacompany'), get_string('weeklyproto_plan_status','local_kbacompany')];
    foreach (array_merge($data['coordination']['agreed'], $data['coordination']['open']) as $row) {
        $table->data[] = [s($row['source_label']), s($row['target_label']), s($row['summary']), s($row['requested_change']), s($row['status'])];
    }
    echo html_writer::table($table);
}

$renderplans($data['current_plans'], get_string('weeklyproto_current_plans','local_kbacompany'));

echo $OUTPUT->heading(get_string('weeklyproto_finance','local_kbacompany'), 3, 'h4 mt-4');
if (!$data['finance']['available']) {
    echo html_writer::div(get_string('weeklyproto_finance_unavailable','local_kbacompany'), 'alert alert-light border');
} else {
    if ($data['finance']['statements']) {
        $table = new html_table();
        $table->attributes['class'] = 'table table-sm table-hover';
        $table->head = [get_string('weeklyproto_fin_statement','local_kbacompany'), get_string('weeklyproto_fin_period','local_kbacompany'),
            get_string('weeklyproto_revenue','local_kbacompany'), get_string('weeklyproto_operating_profit','local_kbacompany'), get_string('weeklyproto_net_profit','local_kbacompany')];
        foreach ($data['finance']['statements'] as $srow) {
            $revenue = $srow['revenue'] === null ? '—' : format_float((float)$srow['revenue'], 2).' '.s($srow['currency']);
            $op = isset($srow['summary']['operatingprofit']) ? format_float((float)$srow['summary']['operatingprofit'],2).' '.s($srow['currency']) : '—';
            $np = isset($srow['summary']['netprofit']) ? format_float((float)$srow['summary']['netprofit'],2).' '.s($srow['currency']) : '—';
            $table->data[] = [s($srow['code'].' — '.$srow['name']), s($srow['period_start'].' — '.$srow['period_end']), $revenue, $op, $np];
        }
        echo html_writer::table($table);
    } else {
        echo html_writer::div(get_string('weeklyproto_no_fin_statements','local_kbacompany'), 'text-muted mb-2');
    }
    echo html_writer::div(
        html_writer::link($data['finance']['statements_url'], get_string('weeklyproto_open_finance','local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm mr-2']).
        html_writer::link($data['finance']['budgets_url'], get_string('weeklyproto_open_budgets','local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm mr-2']).
        html_writer::link($data['finance']['resource_url'], get_string('weeklyproto_open_resources','local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']),
        'mb-3'
    );
}

echo $OUTPUT->heading(get_string('weeklyproto_policy','local_kbacompany'), 3, 'h4 mt-4');
if (!$data['policy_proposals']) {
    echo html_writer::div(get_string('weeklyproto_no_policy','local_kbacompany'), 'alert alert-light border');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [get_string('weeklyproto_policy_code','local_kbacompany'), get_string('weeklyproto_policy_title','local_kbacompany'),
        get_string('weeklyproto_policy_basis','local_kbacompany'), get_string('actions')];
    foreach ($data['policy_proposals'] as $p) {
        $table->data[] = [s($p['code']), s($p['title']), s($p['basis']), html_writer::link($p['url'], get_string('weeklyproto_open_policy','local_kbacompany'))];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('weeklyproto_decisions','local_kbacompany'), 3, 'h4 mt-4');
if (!$data['decisions']) {
    echo html_writer::div(get_string('weeklyproto_no_decisions','local_kbacompany'), 'alert alert-light border');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm table-hover';
    $table->head = [get_string('weeklyproto_decision','local_kbacompany'), get_string('weeklyproto_owner','local_kbacompany'),
        get_string('weeklyproto_expected_result','local_kbacompany'), get_string('weeklyproto_plan_status','local_kbacompany'), get_string('weeklyproto_tasks','local_kbacompany')];
    foreach ($data['decisions'] as $d) {
        $table->data[] = [s($d['title']), s($d['owner_name']), s($d['expected_result']), s($d['status']), (string)count($d['tasks'])];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('weeklyproto_minutes_draft','local_kbacompany'), 3, 'h4 mt-4');
echo html_writer::tag('p', get_string('weeklyproto_minutes_rule','local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::tag('textarea', s($data['minutes_draft']), ['class'=>'form-control', 'rows'=>16, 'readonly'=>'readonly']);
echo html_writer::div(html_writer::link($data['meeting_url'], get_string('weeklyproto_open_meeting_minutes','local_kbacompany'), ['class'=>'btn btn-primary mt-3']), 'mb-4');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
