<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_management_overview_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$reviewer = optional_param('reviewer', 0, PARAM_INT);
$attention = optional_param('attention', 'all', PARAM_ALPHAEXT);
$teamsignal = optional_param('teamsignal', 'all', PARAM_ALPHA);
$employeeinsight = optional_param('employeeinsight', 0, PARAM_INT);
$approvalfilter = optional_param('approvalfilter', 'all', PARAM_ALPHA);

$data = battle_plan_management_overview_service::context($uuid, (int)$USER->id, $week, $orgnode, $reviewer, $attention);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_overview_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan', get_string('battleplan_overview_title','local_kbacompany'));
echo \local_kbacompany\local\bms_workspace_ux_service::management_nav($uuid, 'week', $data['week_start']);
echo $OUTPUT->heading(get_string('battleplan_overview_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_overview_intro','local_kbacompany'), ['class'=>'lead']);
echo html_writer::div(get_string('battleplan_overview_rule','local_kbacompany'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid,'week'=>$data['week_start']]), get_string('battleplan_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
if (task_service::can_manage($uuid, (int)$USER->id)) {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_coverage.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
        get_string('battleplan_coverage_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
        get_string('battleplan_readiness_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
}
echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'exceptions']), get_string('indicator_tab_exceptions','local_kbacompany'), ['class'=>'btn btn-outline-secondary']);

// Filters.
$nodeopts = [''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach ($data['orgnodes'] as $node) { $nodeopts[$node['uuid']] = $node['name']; }
$reviewopts = [0=>get_string('battleplan_overview_all_reviewers','local_kbacompany')] + $data['reviewers'];
$attopts = ['all'=>get_string('battleplan_overview_attention_all','local_kbacompany')];
foreach (battle_plan_management_overview_service::ATTENTION_FILTERS as $code) {
    $attopts[$code] = get_string('battleplan_overview_attention_'.$code,'local_kbacompany');
}
echo bms24_ui_kit_service::filterbar(
    new moodle_url('/local/kbacompany/battle_plan_overview.php'),
    get_string('battleplan_overview_title','local_kbacompany'),
    [
        ['id'=>'bpov_week','name'=>'week','label'=>get_string('battleplan_week','local_kbacompany'),'type'=>'date','value'=>$data['week_start']],
        ['id'=>'bpov_unit','name'=>'orgnode','label'=>get_string('battleplan_overview_unit','local_kbacompany'),'type'=>'select','value'=>$data['orgnode_filter'],'options'=>$nodeopts],
        ['id'=>'bpov_reviewer','name'=>'reviewer','label'=>get_string('battleplan_overview_reviewer','local_kbacompany'),'type'=>'select','value'=>(string)$data['reviewer_filter'],'options'=>$reviewopts],
        ['id'=>'bpov_attention','name'=>'attention','label'=>get_string('battleplan_overview_attention','local_kbacompany'),'type'=>'select','value'=>$data['attention_filter'],'options'=>$attopts],
    ],
    ['uuid'=>$uuid],
    new moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$uuid]),
    get_string('battleplan_show','local_kbacompany'),
    get_string('reset','core')
);
echo html_writer::tag('p', s(battle_plan_service::period_label($data['week_start'],$data['week_end'])), ['class'=>'text-muted']);

// Unified reviewer queue for the selected week. The company/employee ACL and reviewer permissions
// have already been applied by the read-model. The attention filter MUST NOT hide submitted plans.
$approvalpage = max(0, optional_param('approvalpage', 0, PARAM_INT));
$approvalrows = (array)($data['review_queue'] ?? []);
$approvaldetails = [];
foreach ($approvalrows as $row) {
    // Only rows authorised by the management overview service reach this code.
    // Read latest review batch across the ENTIRE queue, before filtering or pagination.
    $reviews = $DB->get_records('local_kbacompany_bplanrev',
        ['companyuuid'=>$uuid,'planuuid'=>(string)$row['plan_uuid']], 'timecreated DESC, id DESC');
    $record = $DB->get_record('local_kbacompany_bplan',
        ['companyuuid'=>$uuid,'uuid'=>(string)$row['plan_uuid']], 'id,statepayload');
    $payload = $record ? json_decode((string)$record->statepayload, true) : null;
    $corrections = \local_kbacompany\local\battle_plan_approval_queue_service::correction_counts(
        array_values($reviews), is_array($payload) ? $payload : []);
    $latestreturnnote = '';
    foreach ($reviews as $reviewevent) {
        if ((string)($reviewevent->decision ?? '') === 'changes' &&
                trim((string)($reviewevent->comment ?? '')) !== '') {
            $latestreturnnote = core_text::substr(trim((string)$reviewevent->comment), 0, 400);
            break;
        }
    }
    $approvaldetails[] = [
        'latest_return_note'=>$latestreturnnote,
        'row'=>$row, 'corrections'=>$corrections,
        'assessment'=>\local_kbacompany\local\battle_plan_approval_queue_service::assessment($row, $corrections),
    ];
}
$queueprojection = \local_kbacompany\local\battle_plan_approval_queue_service::summarize(
    $approvaldetails, $approvalfilter);
$approvalfilter = $queueprojection['filter'];
$approvaltotal = count($queueprojection['rows']);
$approvalpagesize = 10;
$approvalpages = max(1, (int)ceil($approvaltotal / $approvalpagesize));
$approvalpage = min($approvalpage, $approvalpages - 1);
$visibleapprovals = array_slice($queueprojection['rows'], $approvalpage * $approvalpagesize, $approvalpagesize);
// Keep all other management filters when changing only the approval queue status or page.
$approvalparams = ['uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'],
    'reviewer'=>$data['reviewer_filter'], 'attention'=>$data['attention_filter'],
    'teamsignal'=>$teamsignal, 'employeeinsight'=>$employeeinsight];
echo html_writer::start_div('card mb-4 bms24-approval-queue', ['id'=>'bms24-approval-queue']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', s(get_string('bpu_approval_queue_title','local_kbacompany')) .
    ' · ' . $approvaltotal, ['class'=>'h4']);
echo html_writer::tag('p', get_string('bpu_approval_queue_help','local_kbacompany'), ['class'=>'text-muted']);
// Status chips reflect the entire accessible queue for this week (not the current page).
$quicklinks = '';
foreach (['all', 'ready', 'review'] as $quickstatus) {
    $params = $approvalparams + ['approvalfilter'=>$quickstatus, 'approvalpage'=>0];
    $url = new moodle_url('/local/kbacompany/battle_plan_overview.php', $params);
    $url->set_anchor('bms24-approval-queue');
    $caption = get_string('bpu_approval_filter_' . $quickstatus, 'local_kbacompany') .
        ' (' . (int)$queueprojection['counts'][$quickstatus] . ')';
    $active = $approvalfilter === $quickstatus;
    $quicklinks .= html_writer::link($url, s($caption), [
        'class'=>'btn btn-sm mr-2 mb-2 ' . ($active ? 'btn-primary' : 'btn-outline-secondary'),
        'aria-current'=>$active ? 'page' : 'false',
    ]);
}
echo html_writer::div($quicklinks, 'bms24-approval-quickfilters mb-2');

// Department rollup uses ALL authorised submitted plans under the selected week,
// unit and reviewer filters. It stays stable when users switch approval quick filters.
if (!empty($queueprojection['units'])) {
    $summary = new html_table();
    $summary->attributes['class'] = 'generaltable bms24-approval-unit-table';
    $summary->head = [get_string('battleplan_overview_unit','local_kbacompany'),
        get_string('bpu_approval_filter_all','local_kbacompany'),
        get_string('bpu_approval_filter_ready','local_kbacompany'),
        get_string('bpu_approval_filter_review','local_kbacompany')];
    foreach ($queueprojection['units'] as $unit) {
        $unitlabel = $unit['name'] !== '' ? s($unit['name']) :
            s(get_string('bpu_approval_no_unit','local_kbacompany'));
        if ($unit['uuid'] !== '') {
            $uniturl = new moodle_url('/local/kbacompany/battle_plan_overview.php',
                array_merge($approvalparams, ['orgnode'=>$unit['uuid'], 'approvalfilter'=>'all', 'approvalpage'=>0]));
            $uniturl->set_anchor('bms24-approval-queue');
            $unitlabel = html_writer::link($uniturl, $unitlabel);
        }
        $summary->data[] = [$unitlabel, (int)$unit['all'], (int)$unit['ready'], (int)$unit['review']];
    }
    echo html_writer::tag('h3', get_string('bpu_approval_units_title','local_kbacompany'), ['class'=>'h6 mt-3']);
    echo html_writer::tag('p', get_string('bpu_approval_units_help','local_kbacompany'), ['class'=>'small text-muted']);
    echo html_writer::table($summary);
}
if (!$approvaltotal) {
    echo html_writer::div(get_string($queueprojection['counts']['all'] > 0 ?
        'bpu_approval_filter_empty' : 'bpu_approval_queue_empty','local_kbacompany'), 'text-muted');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable bms24-approval-table';
    $table->head = [get_string('battleplan_employee','local_kbacompany'),
        get_string('bpu_approval_queue_tasks','local_kbacompany'),
        get_string('bpu_approval_queue_metrics','local_kbacompany'),
        get_string('bpu_approval_queue_corrections','local_kbacompany'),
        get_string('bpu_approval_queue_assessment','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany')];
    foreach ($visibleapprovals as $detail) {
        $row = $detail['row']; $check = $detail['assessment']; $fix = $detail['corrections'];
        $contextmeta = trim((string)$row['position']['name'] . ' · ' . (string)$row['orgnode']['name'], ' ·');
        $who = html_writer::tag('strong', s($row['employee_name']));
        if ($contextmeta !== '') {
            $who .= html_writer::tag('small', s($contextmeta), ['class'=>'d-block text-muted']);
        }
        $tasksummary = get_string('bpu_approval_queue_task_count','local_kbacompany', (int)$check['task_count']);
        $metricvalue = get_string('bpu_approval_queue_metric_count', 'local_kbacompany', (object)[
            'evaluated'=>(int)$check['metrics_total'] - (int)$check['metrics_missing'],
            'total'=>(int)$check['metrics_total']]);
        $fixsummary = $fix['total'] > 0 ?
            get_string('bpu_approval_queue_fix_count','local_kbacompany', (object)[
                'accepted'=>(int)$fix['accepted'],'total'=>(int)$fix['total']]) :
            get_string('bpu_approval_queue_no_corrections','local_kbacompany');
        // Only whitelisted filter values are carried to the review page. No raw return URL.
        $returnfilters = [
            'fromqueue'=>1, 'qweek'=>$data['week_start'], 'qorgnode'=>$data['orgnode_filter'],
            'qreviewer'=>$data['reviewer_filter'], 'qattention'=>$data['attention_filter'],
            'qfilter'=>$approvalfilter, 'qpage'=>$approvalpage,
            'qteamsignal'=>$teamsignal, 'qemployeeinsight'=>$employeeinsight,
        ];
        $reviewurl = new moodle_url('/local/kbacompany/battle_plan.php', array_merge([
            'uuid'=>$uuid, 'plan'=>$row['plan_uuid'], 'view'=>'detail', 'tab'=>'review'], $returnfilters));
        $taskurl = new moodle_url('/local/kbacompany/battle_plan.php', [
            'uuid'=>$uuid, 'plan'=>$row['plan_uuid'], 'view'=>'detail', 'tab'=>'tasks']);
        $metricsurl = new moodle_url('/local/kbacompany/battle_plan.php', [
            'uuid'=>$uuid, 'plan'=>$row['plan_uuid'], 'view'=>'detail', 'tab'=>'indicators']);
        $previewrows = html_writer::tag('p', s(get_string('bpu_approval_preview_tasks',
            'local_kbacompany', (int)$check['task_count'])), ['class'=>'small mb-1']);
        $previewrows .= html_writer::tag('p', s(get_string('bpu_approval_preview_metrics',
            'local_kbacompany', (int)$check['metrics_missing'])), ['class'=>'small mb-1']);
        $previewrows .= html_writer::tag('p', s(get_string('bpu_approval_preview_corrections',
            'local_kbacompany', (int)$check['outstanding'])), ['class'=>'small mb-1']);
        if ($detail['latest_return_note'] !== '') {
            $previewrows .= html_writer::tag('p',
                html_writer::tag('strong', get_string('bpu_approval_preview_last_note', 'local_kbacompany')) .
                ' ' . nl2br(s($detail['latest_return_note'])), ['class'=>'small mb-2']);
        }
        $previewrows .= html_writer::div(
            html_writer::link($taskurl, get_string('bpu_approval_preview_open_tasks', 'local_kbacompany'),
                ['class'=>'mr-3']) .
            html_writer::link($metricsurl, get_string('bpu_approval_preview_open_metrics', 'local_kbacompany')),
            'small');
        $preview = html_writer::tag('details',
            html_writer::tag('summary', get_string('bpu_approval_preview_title', 'local_kbacompany')) .
            html_writer::div($previewrows, 'p-2 border rounded mt-2'),
            ['class'=>'bms24-approval-inline-preview mt-2']);
        $table->data[] = [$who, s($tasksummary), s($metricvalue), s($fixsummary),
            html_writer::span(s(get_string($check['advisory'] ? 'bpu_approval_queue_check' :
                'bpu_approval_queue_ready', 'local_kbacompany')),
                $check['advisory'] ? 'badge badge-warning' : 'badge badge-success'),
            html_writer::div(html_writer::link($reviewurl, get_string('bpu_review','local_kbacompany'),
                ['class'=>'btn btn-sm btn-primary']) . $preview)];
    }
    echo html_writer::table($table);
    if ($approvalpages > 1) {
        $navitems = '';
        $linkparams = $approvalparams + ['approvalfilter'=>$approvalfilter];
        foreach ([max(0, $approvalpage-1), min($approvalpages-1, $approvalpage+1)] as $index=>$targetpage) {
            $url = new moodle_url('/local/kbacompany/battle_plan_overview.php',
                $linkparams+['approvalpage'=>$targetpage]);
            $url->set_anchor('bms24-approval-queue');
            $label = get_string($index === 0 ? 'bpu_approval_queue_prev' : 'bpu_approval_queue_next',
                'local_kbacompany');
            $navitems .= html_writer::link($url, s($label),
                ['class'=>'btn btn-sm btn-outline-secondary mr-2' . ($targetpage === $approvalpage ? ' disabled' : ''),
                 'aria-disabled'=>$targetpage === $approvalpage ? 'true' : 'false']);
        }
        echo html_writer::div(
            html_writer::tag('span', s(get_string('bpu_approval_queue_paging','local_kbacompany',
                (object)['page'=>$approvalpage+1,'pages'=>$approvalpages])), ['class'=>'mr-3']) . $navitems,
            'bms24-approval-pagination');
    }
}
echo html_writer::end_div();
echo html_writer::end_div();


// Approval outcomes for the selected PLAN week (distinct from review-event calendar dates).
// Unlike the submitted-only queue, this read-model includes visible approved and closed plans.
$outcomes = \local_kbacompany\local\battle_plan_weekly_review_outcome_service::context(
    $uuid, (int)$USER->id, $data['week_start'], $data['orgnode_filter'], (int)$data['reviewer_filter']);
$outcomecounts = $outcomes['counts'];
echo html_writer::start_div('card mb-4 bms24-weekly-approval-outcomes', ['id'=>'bms24-weekly-approval-outcomes']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', get_string('bpu_weekly_outcomes_title', 'local_kbacompany'), ['class'=>'h4']);
echo html_writer::tag('p', get_string('bpu_weekly_outcomes_help', 'local_kbacompany'), ['class'=>'small text-muted']);
$outcomecards = '';
foreach (['all', 'approved', 'returned', 'waiting', 'other'] as $outcometype) {
    $outcomecards .= html_writer::div(
        html_writer::tag('strong', (string)(int)$outcomecounts[$outcometype], ['class'=>'h4 d-block mb-1']) .
        html_writer::tag('span', get_string('bpu_weekly_outcome_' . $outcometype, 'local_kbacompany'),
            ['class'=>'small']), 'bms24-weekly-outcome-card');
}
echo html_writer::div($outcomecards, 'bms24-weekly-outcome-grid');
if (!$outcomecounts['all']) {
    echo html_writer::div(get_string('bpu_weekly_outcomes_empty', 'local_kbacompany'), 'text-muted mt-3');
} else {
    $groups = [
        ['rows'=>$outcomes['units'], 'title'=>'bpu_weekly_outcomes_units', 'groupkey'=>'unit'],
        ['rows'=>$outcomes['reviewers'], 'title'=>'bpu_weekly_outcomes_reviewers', 'groupkey'=>'reviewer'],
    ];
    foreach ($groups as $group) {
        if (empty($group['rows'])) { continue; }
        echo html_writer::tag('h3', get_string($group['title'], 'local_kbacompany'), ['class'=>'h6 mt-3']);
        $table = new html_table();
        $table->attributes['class'] = 'generaltable bms24-weekly-outcome-table';
        $table->head = [get_string($group['groupkey'] === 'unit' ?
                'battleplan_overview_unit' : 'battleplan_overview_reviewer', 'local_kbacompany')];
        foreach (['all', 'approved', 'returned', 'waiting', 'other'] as $status) {
            $table->head[] = get_string('bpu_weekly_outcome_' . $status, 'local_kbacompany');
        }
        foreach ($group['rows'] as $groupitem) {
            $name = trim((string)$groupitem['name']);
            if ($name === '') {
                $name = get_string($group['groupkey'] === 'unit' ?
                    'bpu_approval_no_unit' : 'bpu_weekly_outcome_no_reviewer', 'local_kbacompany');
            }
            $label = s($name);
            // Use company-scoped, typed filters, never user-controlled links.
            if ($group['groupkey'] === 'unit' && $groupitem['uuid'] !== '') {
                $link = new moodle_url('/local/kbacompany/battle_plan_overview.php',
                    array_merge($approvalparams, ['orgnode'=>(string)$groupitem['uuid']]));
                $link->set_anchor('bms24-weekly-approval-outcomes');
                $label = html_writer::link($link, $label);
            }
            if ($group['groupkey'] === 'reviewer' && $groupitem['id'] > 0) {
                $link = new moodle_url('/local/kbacompany/battle_plan_overview.php',
                    array_merge($approvalparams, ['reviewer'=>(int)$groupitem['id']]));
                $link->set_anchor('bms24-weekly-approval-outcomes');
                $label = html_writer::link($link, $label);
            }
            $cells = [$label];
            foreach (['all', 'approved', 'returned', 'waiting', 'other'] as $status) {
                $cells[] = (int)$groupitem[$status];
            }
            $table->data[] = $cells;
        }
        echo html_writer::table($table);
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

// Review-time report for the selected PLAN week. Threshold 48h is a monitoring hint, NOT an SLA.
$approvaltiming = \local_kbacompany\local\battle_plan_review_timeliness_service::context(
    $uuid, (int)$USER->id, $data['week_start'], $data['orgnode_filter'], (int)$data['reviewer_filter']);
$timingcounts = $approvaltiming['counts'];
$timingseconds = static function($seconds): string {
    if ($seconds === null) { return '—'; }
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) { return get_string('bpu_timing_less_minute', 'local_kbacompany'); }
    if ($seconds < 3600) { return (int)ceil($seconds / 60) . ' ' . get_string('bpu_timing_minutes', 'local_kbacompany'); }
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    return $hours . ' ' . get_string('bpu_timing_hours', 'local_kbacompany') .
        ($minutes ? ' ' . $minutes . ' ' . get_string('bpu_timing_minutes', 'local_kbacompany') : '');
};
echo html_writer::start_div('card mb-4 bms24-approval-timing', ['id'=>'bms24-approval-timing']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', get_string('bpu_timing_title', 'local_kbacompany'), ['class'=>'h4']);
echo html_writer::tag('p', get_string('bpu_timing_help', 'local_kbacompany'), ['class'=>'small text-muted']);
$timingcards = '';
foreach (['waiting', 'waiting_long', 'approved_known', 'waiting_unknown'] as $timingkey) {
    $timingcards .= html_writer::div(
        html_writer::tag('strong', (string)(int)$timingcounts[$timingkey], ['class'=>'h4 d-block mb-1']) .
        html_writer::tag('span', get_string('bpu_timing_' . $timingkey, 'local_kbacompany'), ['class'=>'small']),
        'bms24-weekly-outcome-card');
}
$timingcards .= html_writer::div(
    html_writer::tag('strong', s($timingseconds($approvaltiming['avg_seconds'])), ['class'=>'h4 d-block mb-1']) .
    html_writer::tag('span', get_string('bpu_timing_average', 'local_kbacompany'), ['class'=>'small']),
    'bms24-weekly-outcome-card');
echo html_writer::div($timingcards, 'bms24-weekly-outcome-grid');
if (!$timingcounts['waiting'] && !$timingcounts['approved_known']) {
    echo html_writer::tag('p', get_string('bpu_timing_no_data', 'local_kbacompany'),
        ['class'=>'text-muted mt-3']);
}
// Groups are aggregates computed after ACL filtering, BEFORE limiting detail rows.
foreach ([
    ['rows'=>$approvaltiming['units'], 'heading'=>'bpu_weekly_outcomes_units'],
    ['rows'=>$approvaltiming['reviewers'], 'heading'=>'bpu_weekly_outcomes_reviewers'],
] as $group) {
    if (empty($group['rows'])) { continue; }
    echo html_writer::tag('h3', get_string($group['heading'], 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $ttable = new html_table();
    $ttable->attributes['class'] = 'generaltable bms24-approval-timing-table';
    $ttable->head = [get_string('bpu_timing_group', 'local_kbacompany'),
        get_string('bpu_timing_waiting', 'local_kbacompany'),
        get_string('bpu_timing_waiting_long', 'local_kbacompany'),
        get_string('bpu_timing_approved_known', 'local_kbacompany'),
        get_string('bpu_timing_average', 'local_kbacompany')];
    foreach ($group['rows'] as $entry) {
        $avg = $entry['approved_known'] ? (int)round($entry['approved_seconds'] / $entry['approved_known']) : null;
        $ttable->data[] = [s($entry['name'] ?: '—'), (int)$entry['waiting'],
            (int)$entry['waiting_long'], (int)$entry['approved_known'],
            s($timingseconds($avg))];
    }
    echo html_writer::table($ttable);
}
if ($approvaltiming['waiting']) {
    echo html_writer::tag('h3', get_string('bpu_timing_waiting_plans', 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $wtable = new html_table();
    $wtable->attributes['class'] = 'generaltable bms24-approval-timing-table';
    $wtable->head = [get_string('battleplan_employee', 'local_kbacompany'),
        get_string('battleplan_overview_reviewer', 'local_kbacompany'),
        get_string('bpu_timing_sent', 'local_kbacompany'),
        get_string('bpu_timing_elapsed', 'local_kbacompany'),
        get_string('indicator_actions', 'local_kbacompany')];
    foreach ($approvaltiming['waiting'] as $wrow) {
        $wurl = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid, 'plan'=>$wrow['plan_uuid'], 'view'=>'detail', 'tab'=>'overview']);
        $sent = (int)$wrow['submitted_at'];
        $wtable->data[] = [s($wrow['employee_name'] ?: '—'), s($wrow['reviewer_name'] ?: '—'),
            $sent > 0 && $wrow['elapsed_seconds'] !== null ? userdate($sent, '%d.%m.%Y %H:%M') : '—',
            s($timingseconds($wrow['elapsed_seconds'])),
            html_writer::link($wurl, get_string('battleplan_open', 'local_kbacompany'))];
    }
    echo html_writer::table($wtable);
    if ($approvaltiming['waiting_total'] > count($approvaltiming['waiting'])) {
        echo html_writer::tag('p', get_string('bpu_timing_more', 'local_kbacompany',
            (int)($approvaltiming['waiting_total'] - count($approvaltiming['waiting']))), ['class'=>'small text-muted']);
    }
}
if ($approvaltiming['recent_approved']) {
    echo html_writer::tag('h3', get_string('bpu_timing_recent', 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $atable = new html_table();
    $atable->attributes['class'] = 'generaltable bms24-approval-timing-table';
    $atable->head = [get_string('battleplan_employee', 'local_kbacompany'),
        get_string('bpu_timing_actual_approver', 'local_kbacompany'),
        get_string('bpu_timing_approved_at', 'local_kbacompany'),
        get_string('bpu_timing_elapsed', 'local_kbacompany')];
    foreach ($approvaltiming['recent_approved'] as $arow) {
        $atable->data[] = [s($arow['employee_name'] ?: '—'), s($arow['approved_by_name'] ?: '—'),
            userdate((int)$arow['approved_at'], '%d.%m.%Y %H:%M'),
            s($timingseconds($arow['elapsed_seconds']))];
    }
    echo html_writer::table($atable);
}
if ($timingcounts['approved_unknown'] || $timingcounts['selfapproved']) {
    echo html_writer::tag('p', get_string('bpu_timing_excluded', 'local_kbacompany', (object)[
        'unknown'=>(int)$timingcounts['approved_unknown'],
        'self'=>(int)$timingcounts['selfapproved']]), ['class'=>'small text-muted']);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Review-cycle analytics: strictly the recorded events in accessible plans for this PLAN week.
$cycleanalytics = $approvaltiming['cycle_analytics'];
$cyclecounts = $cycleanalytics['counts'];
echo html_writer::start_div('card mb-4 bms24-review-cycle-analytics', ['id'=>'bms24-review-cycle-analytics']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', get_string('bpu_cycleanalytics_title', 'local_kbacompany'), ['class'=>'h4']);
echo html_writer::tag('p', get_string('bpu_cycleanalytics_help', 'local_kbacompany'), ['class'=>'small text-muted']);
$cyclecards = '';
foreach (['tracked', 'recorded_submissions', 'recorded_returns', 'repeated_submissions',
        'repeated_returns', 'measured_decisions'] as $key) {
    $cyclecards .= html_writer::div(
        html_writer::tag('strong', (string)(int)$cyclecounts[$key], ['class'=>'h4 d-block mb-1']) .
        html_writer::tag('span', get_string('bpu_cycleanalytics_' . $key, 'local_kbacompany'), ['class'=>'small']),
        'bms24-weekly-outcome-card');
}
$cyclecards .= html_writer::div(
    html_writer::tag('strong', s($timingseconds($cycleanalytics['average_seconds'])), ['class'=>'h4 d-block mb-1']) .
    html_writer::tag('span', get_string('bpu_cycleanalytics_average', 'local_kbacompany'), ['class'=>'small']),
    'bms24-weekly-outcome-card');
echo html_writer::div($cyclecards, 'bms24-weekly-outcome-grid');
echo html_writer::tag('p', get_string('bpu_cycleanalytics_coverage', 'local_kbacompany', (object)[
    'tracked'=>(int)$cyclecounts['tracked'],
    'all'=>(int)$cyclecounts['plans'],
    'untracked'=>(int)$cyclecounts['untracked'],
]), ['class'=>'small text-muted mt-3']);
foreach ([
    ['key'=>'units', 'heading'=>'bpu_weekly_outcomes_units'],
    ['key'=>'reviewers', 'heading'=>'bpu_cycleanalytics_assigned_reviewers'],
    ['key'=>'employees', 'heading'=>'bpu_cycleanalytics_employees'],
] as $section) {
    $groups = $cycleanalytics[$section['key']];
    if (!$groups) { continue; }
    echo html_writer::tag('h3', get_string($section['heading'], 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable bms24-review-cycle-table';
    $table->head = [get_string('bpu_timing_group', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_tracked', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_recorded_returns', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_repeated_submissions', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_average', 'local_kbacompany')];
    foreach (array_slice($groups, 0, 30) as $group) {
        $mean = $group['measured'] ? (int)round($group['duration_seconds'] / $group['measured']) : null;
        $table->data[] = [s($group['name'] ?: '—'), (int)$group['tracked'],
            (int)$group['returns'], (int)$group['repeated'], s($timingseconds($mean))];
    }
    echo html_writer::table($table);
    if (count($groups) > 30) {
        echo html_writer::tag('p', get_string('bpu_cycleanalytics_group_limit', 'local_kbacompany',
            count($groups) - 30), ['class'=>'small text-muted']);
    }
}
if (!empty($cycleanalytics['repeated'])) {
    echo html_writer::tag('h3', get_string('bpu_cycleanalytics_repeated_title', 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable bms24-review-cycle-table';
    $table->head = [get_string('battleplan_employee', 'local_kbacompany'),
        get_string('battleplan_overview_reviewer', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_recorded_submissions', 'local_kbacompany'),
        get_string('bpu_cycleanalytics_recorded_returns', 'local_kbacompany'),
        get_string('indicator_actions', 'local_kbacompany')];
    foreach ($cycleanalytics['repeated'] as $row) {
        $url = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid, 'plan'=>$row['plan_uuid'], 'view'=>'detail', 'tab'=>'history']);
        $table->data[] = [s($row['employee_name'] ?: '—'), s($row['reviewer_name'] ?: '—'),
            (int)$row['submissions'], (int)$row['returns'],
            html_writer::link($url, get_string('battleplan_open', 'local_kbacompany'))];
    }
    echo html_writer::table($table);
    if ($cycleanalytics['repeated_total'] > count($cycleanalytics['repeated'])) {
        echo html_writer::tag('p', get_string('bpu_timing_more', 'local_kbacompany',
            $cycleanalytics['repeated_total'] - count($cycleanalytics['repeated'])), ['class'=>'small text-muted']);
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

// Evidence-based attribution of elapsed time to the stage, not to an at-fault person.
// The dataset is access-filtered, company-scoped, and aggregated BEFORE page slicing.
$stagedelays = $approvaltiming['stage_delays'];
$stagecounts = $stagedelays['counts'];
$delaypage = max(0, optional_param('delaypage', 0, PARAM_INT));
$delayfilter = optional_param('delayfilter', 'all', PARAM_ALPHA);
if (!in_array($delayfilter, ['all', 'current'], true)) { $delayfilter = 'all'; }
$stageplanrows = $delayfilter === 'current' ? array_values(array_filter($stagedelays['rows'],
    static function(array $r): bool { return $r['open'] !== null; })) : $stagedelays['rows'];
$delaypagesize = 20;
$delaytotal = count($stageplanrows);
$delaypages = max(1, (int)ceil($delaytotal / $delaypagesize));
$delaypage = min($delaypage, $delaypages - 1);
$delayrows = array_slice($stageplanrows, $delaypage * $delaypagesize, $delaypagesize);
$delayparams = $approvalparams + ['approvalfilter'=>$approvalfilter, 'delayfilter'=>$delayfilter];
$stagelabel = static function(?array $open): string {
    if ($open === null) { return '—'; }
    return get_string('bpu_stagedelay_' . $open['stage'], 'local_kbacompany');
};
echo html_writer::start_div('card mb-4 bms24-stage-delay', ['id'=>'bms24-stage-delay']);
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', get_string('bpu_stagedelay_title', 'local_kbacompany'), ['class'=>'h4']);
echo html_writer::tag('p', get_string('bpu_stagedelay_help', 'local_kbacompany'), ['class'=>'small text-muted']);
$delaycards = '';
foreach (['manager_cycles', 'employee_cycles', 'manager_open', 'employee_open'] as $key) {
    $delaycards .= html_writer::div(
        html_writer::tag('strong', (string)(int)$stagecounts[$key], ['class'=>'h4 d-block mb-1']) .
        html_writer::tag('span', get_string('bpu_stagedelay_' . $key, 'local_kbacompany'), ['class'=>'small']),
        'bms24-weekly-outcome-card');
}
foreach (['manager_average', 'employee_average'] as $key) {
    $delaycards .= html_writer::div(
        html_writer::tag('strong', s($timingseconds($stagedelays[$key])), ['class'=>'h4 d-block mb-1']) .
        html_writer::tag('span', get_string('bpu_stagedelay_' . $key, 'local_kbacompany'), ['class'=>'small']),
        'bms24-weekly-outcome-card');
}
echo html_writer::div($delaycards, 'bms24-weekly-outcome-grid');
echo html_writer::tag('p', get_string('bpu_stagedelay_coverage', 'local_kbacompany', (object)[
    'recorded'=>$stagecounts['recorded'], 'plans'=>$stagecounts['plans'],
    'evidenced'=>$stagecounts['evidenced'], 'missing'=>$stagecounts['missing'],
    'partial'=>$stagecounts['partial'], 'duplicate'=>$stagecounts['duplicate'],
    'self'=>$stagecounts['selfapproved'],
]), ['class'=>'small text-muted mt-3']);
foreach ([
    ['key'=>'units', 'heading'=>'bpu_weekly_outcomes_units'],
    ['key'=>'reviewers', 'heading'=>'bpu_cycleanalytics_assigned_reviewers'],
    ['key'=>'employees', 'heading'=>'bpu_cycleanalytics_employees'],
] as $section) {
    $groups = $stagedelays[$section['key']];
    if (!$groups) { continue; }
    echo html_writer::tag('h3', get_string($section['heading'], 'local_kbacompany'), ['class'=>'h6 mt-3']);
    $gtable = new html_table();
    $gtable->attributes['class'] = 'generaltable bms24-stage-delay-table';
    $gtable->head = [get_string('bpu_timing_group', 'local_kbacompany'),
        get_string('bpu_stagedelay_manager_cycles', 'local_kbacompany'),
        get_string('bpu_stagedelay_manager_average', 'local_kbacompany'),
        get_string('bpu_stagedelay_employee_cycles', 'local_kbacompany'),
        get_string('bpu_stagedelay_employee_average', 'local_kbacompany'),
        get_string('bpu_stagedelay_manager_open', 'local_kbacompany'),
        get_string('bpu_stagedelay_employee_open', 'local_kbacompany')];
    foreach ($groups as $g) {
        $mavg = $g['manager_cycles'] ? (int)round($g['manager_seconds'] / $g['manager_cycles']) : null;
        $eavg = $g['employee_cycles'] ? (int)round($g['employee_seconds'] / $g['employee_cycles']) : null;
        $gtable->data[] = [s($g['name'] ?: '—'), (int)$g['manager_cycles'], s($timingseconds($mavg)),
            (int)$g['employee_cycles'], s($timingseconds($eavg)),
            (int)$g['manager_open'], (int)$g['employee_open']];
    }
    echo html_writer::table($gtable);
}
echo html_writer::tag('h3', get_string('bpu_stagedelay_plans', 'local_kbacompany'), ['class'=>'h6 mt-3']);
$stagefilters = '';
foreach (['all', 'current'] as $kind) {
    $url = new moodle_url('/local/kbacompany/battle_plan_overview.php',
        array_merge($delayparams, ['delayfilter'=>$kind, 'delaypage'=>0]));
    $url->set_anchor('bms24-stage-delay');
    $stagefilters .= html_writer::link($url, get_string('bpu_stagedelay_filter_' . $kind, 'local_kbacompany'),
        ['class'=>'btn btn-sm mr-2 mb-2 ' . ($kind === $delayfilter ? 'btn-primary' : 'btn-outline-secondary')]);
}
echo html_writer::div($stagefilters, 'mb-2');
if (!$delayrows) {
    echo html_writer::div(get_string('bpu_stagedelay_empty', 'local_kbacompany'), 'text-muted');
} else {
    $ptable = new html_table();
    $ptable->attributes['class'] = 'generaltable bms24-stage-delay-table';
    $ptable->head = [get_string('battleplan_employee', 'local_kbacompany'),
        get_string('battleplan_overview_reviewer', 'local_kbacompany'),
        get_string('bpu_stagedelay_status', 'local_kbacompany'),
        get_string('bpu_stagedelay_manager_average', 'local_kbacompany'),
        get_string('bpu_stagedelay_employee_average', 'local_kbacompany'),
        get_string('bpu_stagedelay_current', 'local_kbacompany'),
        get_string('bpu_timing_elapsed', 'local_kbacompany'),
        get_string('indicator_actions', 'local_kbacompany')];
    foreach ($delayrows as $pr) {
        $planparams = ['uuid'=>$uuid, 'plan'=>$pr['plan_uuid'], 'view'=>'detail'];
        $planurl = new moodle_url('/local/kbacompany/battle_plan.php', $planparams + ['tab'=>'overview']);
        $historyurl = new moodle_url('/local/kbacompany/battle_plan.php', $planparams + ['tab'=>'history']);
        $statuskey = 'battleplan_status_' . $pr['status'];
        $statuslabel = get_string_manager()->string_exists($statuskey, 'local_kbacompany') ?
            get_string($statuskey, 'local_kbacompany') : get_string('bpu_stagedelay_unknown', 'local_kbacompany');
        $actions = html_writer::link($planurl, get_string('battleplan_open', 'local_kbacompany')) .
            ' · ' . html_writer::link($historyurl, get_string('bpu_stagedelay_history', 'local_kbacompany'));
        $ptable->data[] = [s($pr['employee_name'] ?: '—'), s($pr['reviewer_name'] ?: '—'),
            s($statuslabel),
            s($timingseconds($pr['manager_avg'])) . ' (' . (int)$pr['manager_cycles'] . ')',
            s($timingseconds($pr['employee_avg'])) . ' (' . (int)$pr['employee_cycles'] . ')',
            s($stagelabel($pr['open'])),
            s($timingseconds($pr['open']['seconds'] ?? null)), $actions];
    }
    echo html_writer::table($ptable);
}
if ($delaypages > 1) {
    $links = [];
    foreach ([max(0, $delaypage - 1), min($delaypages - 1, $delaypage + 1)] as $i=>$target) {
        $url = new moodle_url('/local/kbacompany/battle_plan_overview.php',
            array_merge($delayparams, ['delaypage'=>$target]));
        $url->set_anchor('bms24-stage-delay');
        $links[] = html_writer::link($url,
            get_string($i ? 'bpu_approval_queue_next' : 'bpu_approval_queue_prev', 'local_kbacompany'),
            ['class'=>'btn btn-sm btn-outline-secondary mr-2' . ($target === $delaypage ? ' disabled' : ''),
                'aria-disabled'=>$target === $delaypage ? 'true' : 'false']);
    }
    echo html_writer::div(get_string('bpu_approval_queue_paging', 'local_kbacompany',
        (object)['page'=>$delaypage + 1, 'pages'=>$delaypages]) . ' ' . implode(' ', $links), 'mt-2');
}
echo html_writer::end_div();
echo html_writer::end_div();

// Prior-week read-only carryover projection. No changes to tasks or plan history.
$carry = battle_plan_management_overview_service::rollover_summary($uuid, (int)$USER->id, $data['week_start']);
echo html_writer::start_div('card mb-3 bms24-rollover-manager');
echo html_writer::start_div('card-body');
echo $OUTPUT->heading(get_string('bms24_rollover_report_title','local_kbacompany'), 3);
echo html_writer::tag('p', get_string('bms24_rollover_report_help','local_kbacompany') . ' ' .
    s((string)$carry['week']), ['class'=>'text-muted']);
if (!$carry['rows']) {
    echo html_writer::div(get_string('bms24_rollover_report_empty','local_kbacompany'), 'text-muted');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('battleplan_employee','local_kbacompany'),
        get_string('bms24_rollover_report_open','local_kbacompany'),
        get_string('bms24_rollover_report_carried','local_kbacompany'),
        get_string('bms24_rollover_report_pending','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany'),
        get_string('bms24_rollover_snapshot_source','local_kbacompany')];
    foreach ($carry['rows'] as $carryrow) {
        $planlink = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid,'plan'=>$carryrow['plan_uuid'],'view'=>'detail','tab'=>'overview']);
        $table->data[] = [s($carryrow['employee']), (int)$carryrow['counts']['open'],
            (int)$carryrow['counts']['carried'], (int)$carryrow['counts']['notcarried'],
            html_writer::link($planlink, get_string('battleplan_open','local_kbacompany')),
            get_string(!empty($carryrow['is_snapshot']) ? 'bms24_rollover_snapshot_frozen' :
                'bms24_rollover_snapshot_live', 'local_kbacompany')];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Read-only verified repeated carryovers. Uses canonical item references; no inferred transfers.
$history = battle_plan_management_overview_service::rollover_history($uuid, (int)$USER->id, $data['week_start']);
echo html_writer::start_div('card mb-3 bms24-rollover-history');
echo html_writer::start_div('card-body');
echo $OUTPUT->heading(get_string('bms24_repeatcarry_title', 'local_kbacompany'), 3);
echo html_writer::tag('p', get_string('bms24_repeatcarry_help', 'local_kbacompany'), ['class'=>'text-muted']);
if (!$history['rows']) {
    echo html_writer::div(get_string('bms24_repeatcarry_empty', 'local_kbacompany'), 'text-muted');
} else {
    $historytable = new html_table();
    $historytable->attributes['class'] = 'generaltable';
    $historytable->head = [get_string('battleplan_employee','local_kbacompany'),
        get_string('taskcenter_title','local_kbacompany'),
        get_string('bms24_repeatcarry_count','local_kbacompany'),
        get_string('bms24_repeatcarry_weeks','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany')];
    foreach ($history['rows'] as $historyrow) {
        $planurl = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid, 'plan'=>$historyrow['plan_uuid'], 'view'=>'detail', 'tab'=>'overview']);
        $historytable->data[] = [s($historyrow['employee']), s($historyrow['title']),
            (int)$historyrow['count'], s(implode(', ', $historyrow['weeks'])),
            html_writer::link($planurl, get_string('battleplan_open','local_kbacompany'))];
    }
    echo html_writer::table($historytable);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Management observation based strictly on immutable close-time snapshots.
// This must not be described as deadline compliance: plans may close after their week.
$discipline = battle_plan_management_overview_service::closure_discipline(
    $uuid, (int)$USER->id, $data['week_start']);
echo html_writer::start_div('card mb-3 bms24-closure-discipline');
echo html_writer::start_div('card-body');
echo $OUTPUT->heading(get_string('bms24_discipline_title', 'local_kbacompany'), 3);
echo html_writer::tag('p', get_string('bms24_discipline_help', 'local_kbacompany'), ['class'=>'text-muted']);
if (empty($discipline['rows'])) {
    echo html_writer::div(get_string('bms24_discipline_empty', 'local_kbacompany'), 'text-muted');
} else {
    $disciplinetable = new html_table();
    $disciplinetable->attributes['class'] = 'generaltable';
    $disciplinetable->head = [get_string('battleplan_employee','local_kbacompany'),
        get_string('bms24_discipline_weeks','local_kbacompany'),
        get_string('bms24_discipline_completed','local_kbacompany'),
        get_string('bms24_discipline_open','local_kbacompany'),
        get_string('bms24_discipline_carried','local_kbacompany'),
        get_string('bms24_discipline_rate','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany')];
    foreach ($discipline['rows'] as $drow) {
        $planurl = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid, 'plan'=>$drow['plan_uuid'], 'view'=>'detail', 'tab'=>'overview']);
        $disciplinetable->data[] = [s($drow['employee']), (int)$drow['plans'],
            (int)$drow['done'], (int)$drow['unfinished'], (int)$drow['carried'],
            $drow['rate'] === null ? '—' : (int)$drow['rate'] . '%',
            html_writer::link($planurl, get_string('battleplan_open','local_kbacompany'))];
    }
    echo html_writer::table($disciplinetable);
}
if ($discipline['legacy_plans'] > 0) {
    echo html_writer::tag('p', get_string('bms24_discipline_legacy','local_kbacompany',
        (int)$discipline['legacy_plans']), ['class'=>'text-muted']);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Weekly trend: close-time snapshots only; do not treat missing history as zero percent.
$trend = battle_plan_management_overview_service::closure_discipline_trend(
    $uuid, (int)$USER->id, $data['week_start']);
echo html_writer::start_div('card mb-3 bms24-discipline-trend');
echo html_writer::start_div('card-body');
echo $OUTPUT->heading(get_string('bms24_trend_title', 'local_kbacompany'), 3);
echo html_writer::tag('p', get_string('bms24_trend_help', 'local_kbacompany'), ['class'=>'text-muted']);
$trendtable = new html_table();
$trendtable->attributes['class'] = 'generaltable';
$trendtable->head = [get_string('bms24_trend_week', 'local_kbacompany'),
    get_string('bms24_discipline_weeks', 'local_kbacompany'),
    get_string('bms24_discipline_completed', 'local_kbacompany'),
    get_string('bms24_discipline_open', 'local_kbacompany'),
    get_string('bms24_discipline_carried', 'local_kbacompany'),
    get_string('bms24_discipline_rate', 'local_kbacompany')];
foreach ($trend['points'] as $point) {
    $label = userdate(strtotime($point['week'] . ' 12:00:00 UTC'), '%d.%m.%Y');
    if ($point['legacy'] > 0) {
        $label .= ' (' . get_string('bms24_trend_legacy_mark', 'local_kbacompany', (int)$point['legacy']) . ')';
    }
    $rate = $point['rate'] === null ? '—' : (int)$point['rate'] . '%';
    $trendtable->data[] = [s($label), (int)$point['plans'], (int)$point['done'],
        (int)$point['unfinished'], (int)$point['carried'], $rate];
}
echo html_writer::table($trendtable);
echo html_writer::end_div();
echo html_writer::end_div();

// Personal execution insight: employees are sourced exclusively from accessible snapshot rows.
// No unrestricted employee lookup or cross-company query is exposed.
$insightemployees = [];
foreach ($discipline['rows'] as $erow) {
    $insightemployees[(int)$erow['userid']] = (string)$erow['employee'];
}
asort($insightemployees, SORT_NATURAL | SORT_FLAG_CASE);
$insightselected = $employeeinsight > 0 && isset($insightemployees[$employeeinsight]) ? $employeeinsight : 0;
echo html_writer::start_div('card mb-3 bms24-personal-execution');
echo html_writer::start_div('card-body');
echo $OUTPUT->heading(get_string('bms24_personal_title', 'local_kbacompany'), 3);
echo html_writer::tag('p', get_string('bms24_personal_help', 'local_kbacompany'), ['class'=>'text-muted']);
if (!$insightemployees) {
    echo html_writer::div(get_string('bms24_personal_empty', 'local_kbacompany'), 'text-muted');
} else {
    $employeeoptions = [0 => get_string('bms24_personal_choose', 'local_kbacompany')];
    foreach ($insightemployees as $eid => $ename) { $employeeoptions[$eid] = $ename; }
    echo html_writer::start_tag('form', ['method'=>'get', 'action'=>(new moodle_url('/local/kbacompany/battle_plan_overview.php'))->out(false),
        'class'=>'form-inline mb-3']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'week','value'=>$data['week_start']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'orgnode','value'=>$data['orgnode_filter']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'reviewer','value'=>$data['reviewer_filter']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'attention','value'=>$data['attention_filter']]);
    echo html_writer::label(get_string('battleplan_employee','local_kbacompany'), 'bms24-employeeinsight', false, ['class'=>'mr-2']);
    echo html_writer::select($employeeoptions, 'employeeinsight', $insightselected, false,
        ['id'=>'bms24-employeeinsight','class'=>'custom-select mr-2']);
    echo html_writer::empty_tag('input', ['type'=>'submit','class'=>'btn btn-primary','value'=>get_string('battleplan_show','local_kbacompany')]);
    echo html_writer::end_tag('form');
    if ($insightselected > 0) {
        $personaltrend = battle_plan_management_overview_service::closure_discipline_trend(
            $uuid, (int)$USER->id, $data['week_start'], $insightselected);
        $personaltable = new html_table();
        $personaltable->attributes['class'] = 'generaltable bms24-personal-weekly-table';
        $personaltable->head = [get_string('bms24_trend_week','local_kbacompany'),
            get_string('bms24_discipline_completed','local_kbacompany'),
            get_string('bms24_discipline_open','local_kbacompany'),
            get_string('bms24_discipline_carried','local_kbacompany'),
            get_string('bms24_discipline_rate','local_kbacompany')];
        foreach ($personaltrend['points'] as $pt) {
            $date = userdate(strtotime($pt['week'] . ' 12:00:00 UTC'), '%d.%m.%Y');
            if ($pt['legacy'] > 0) {
                $date .= ' (' . get_string('bms24_trend_legacy_mark','local_kbacompany',(int)$pt['legacy']) . ')';
            }
            $personaltable->data[] = [s($date), (int)$pt['done'], (int)$pt['unfinished'],
                (int)$pt['carried'], $pt['rate'] === null ? '—' : (int)$pt['rate'] . '%'];
        }
        echo html_writer::tag('h4', s($insightemployees[$insightselected]));
        // Profile access is checked independently by the employee directory service; avoid offering a link
        // when the viewer cannot access that person's personnel record.
        try {
            $employeeprofile = \local_kbacompany\local\employee_service::directory_record(
                $uuid, $insightselected, (int)$USER->id, '');
            if (!empty($employeeprofile['employee'])) {
                echo html_writer::link(new moodle_url('/local/kbacompany/employee_view.php',
                    ['uuid'=>$uuid, 'userid'=>$insightselected, 'tab'=>'battleplan']),
                    get_string('bms24_personal_open_employee', 'local_kbacompany'),
                    ['class'=>'btn btn-outline-secondary mb-2']);
            }
        } catch (\moodle_exception $exception) {
            // A visible execution snapshot does not imply access to private employee profile data.
        }

        echo html_writer::table($personaltable);
        $repeats = array_values(array_filter($history['rows'], static function(array $row) use ($insightselected): bool {
            return (int)($row['userid'] ?? 0) === $insightselected;
        }));
        echo html_writer::tag('h4', get_string('bms24_personal_repeats','local_kbacompany'));
        if (!$repeats) {
            echo html_writer::div(get_string('bms24_repeatcarry_empty','local_kbacompany'), 'text-muted');
        } else {
            $repeatstable = new html_table();
            $repeatstable->attributes['class'] = 'generaltable';
            $repeatstable->head = [get_string('taskcenter_title','local_kbacompany'),
                get_string('bms24_repeatcarry_count','local_kbacompany'),
                get_string('bms24_repeatcarry_weeks','local_kbacompany')];
            foreach ($repeats as $rr) {
                $repeatstable->data[] = [s($rr['title']), (int)$rr['count'], s(implode(', ', $rr['weeks']))];
            }
            echo html_writer::table($repeatstable);
        }
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

// Combined weekly manager action queue: existing authorized current-week plan projection +
// canonical visible previous-week rollover projection. Read-only, no implicit approvals/transfers.
// The current-week plans follow the active organization/reviewer/attention filters.
$pendingreviews = [];
foreach ($data['rows'] as $reviewrow) {
    if ((string)($reviewrow['status'] ?? '') !== 'submitted') { continue; }
    $pendingreviews[] = $reviewrow;
}
$rolloverpending = array_filter($carry['rows'], static function(array $row): bool {
    return (int)($row['counts']['notcarried'] ?? 0) > 0;
});
$actioncards = html_writer::div(
    html_writer::tag('strong', (string)count($pendingreviews)) .
    html_writer::span(get_string('bms24_weekmanager_reviews', 'local_kbacompany')),
    'bms24-weekmanager-stat'
) . html_writer::div(
    html_writer::tag('strong', (string)(int)$carry['totals']['notcarried']) .
    html_writer::span(get_string('bms24_weekmanager_rollovers', 'local_kbacompany')),
    'bms24-weekmanager-stat'
);
echo html_writer::start_div('bms24-card bms24-weekmanager mb-3');
echo html_writer::tag('h2', get_string('bms24_weekmanager_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('bms24_weekmanager_help', 'local_kbacompany'), ['class'=>'text-muted']);
echo html_writer::div($actioncards, 'bms24-weekmanager-stats');
if (!$pendingreviews && !$rolloverpending) {
    echo html_writer::div(get_string('bms24_weekmanager_clear', 'local_kbacompany'), 'text-muted');
} else {
    $queue = new html_table();
    $queue->attributes['class'] = 'generaltable bms24-weekmanager-table';
    $queue->head = [get_string('battleplan_employee', 'local_kbacompany'),
        get_string('bms24_weekmanager_reason', 'local_kbacompany'),
        get_string('indicator_actions', 'local_kbacompany')];
    foreach ($pendingreviews as $reviewrow) {
        $reviewurl = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid,'plan'=>(string)$reviewrow['plan_uuid'],'view'=>'detail','tab'=>'review']);
        $queue->data[] = [s((string)$reviewrow['employee_name']),
            get_string('bms24_weekmanager_reviews', 'local_kbacompany'),
            html_writer::link($reviewurl, get_string('battleplan_open', 'local_kbacompany'))];
    }
    foreach ($rolloverpending as $carryrow) {
        $sourceurl = new moodle_url('/local/kbacompany/battle_plan.php',
            ['uuid'=>$uuid,'plan'=>(string)$carryrow['plan_uuid'],'view'=>'detail','tab'=>'overview']);
        $queue->data[] = [s((string)$carryrow['employee']),
            get_string('bms24_weekmanager_rollovers', 'local_kbacompany') . ': ' .
            (int)$carryrow['counts']['notcarried'],
            html_writer::link($sourceurl, get_string('battleplan_open', 'local_kbacompany'))];
    }
    echo html_writer::table($queue);
}
echo html_writer::end_div();

$overviewurl = static function(array $extra = []) use ($uuid, $data, $teamsignal): moodle_url {
    $params = [
        'uuid' => $uuid,
        'week' => $data['week_start'],
    ];
    if ((string)$data['orgnode_filter'] !== '') { $params['orgnode'] = (string)$data['orgnode_filter']; }
    if ((int)$data['reviewer_filter'] > 0) { $params['reviewer'] = (int)$data['reviewer_filter']; }
    if ((string)$data['attention_filter'] !== 'all') { $params['attention'] = (string)$data['attention_filter']; }
    if ($teamsignal !== 'all') { $params['teamsignal'] = $teamsignal; }
    foreach ($extra as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === 'all') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return new moodle_url('/local/kbacompany/battle_plan_overview.php', $params);
};

$kpis = '';
$kpidefs = [
    'active_plans' => ['lang'=>'battleplan_overview_active_plans', 'url'=>$overviewurl(['attention'=>'all'])],
    'critical_indicators' => ['lang'=>'battleplan_overview_critical_indicators',
        'url'=>new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'exceptions'])],
    'critical_exceptions' => ['lang'=>'battleplan_overview_critical_exceptions', 'url'=>$overviewurl(['attention'=>'critical'])],
    'needs_decision' => ['lang'=>'battleplan_overview_needs_decision', 'url'=>$overviewurl(['attention'=>'needs_decision'])],
    'open_tasks' => ['lang'=>'battleplan_overview_open_tasks', 'url'=>$overviewurl(['attention'=>'open'])],
    'overdue_tasks' => ['lang'=>'battleplan_overview_overdue_tasks', 'url'=>$overviewurl(['attention'=>'overdue'])],
];
foreach ($kpidefs as $key=>$def) {
    $body = html_writer::tag('strong',(string)$data['summary'][$key]) .
        html_writer::tag('small',get_string($def['lang'],'local_kbacompany'));
    $kpis .= html_writer::link($def['url'], $body, [
        'class'=>'kba-indicator-kpi bms24-overview-kpi',
        'aria-label'=>get_string($def['lang'],'local_kbacompany') . ': ' . (int)$data['summary'][$key],
    ]);
}
echo html_writer::div($kpis,'kba-indicator-kpis bms24-overview-kpis mb-4');

// Department action-first view. This is a compact presentation over the existing by-unit
// aggregation: it does not introduce a new score, threshold model or persistence layer.
if (!empty($data['by_unit'])) {
    $unitcards = '';
    foreach ($data['by_unit'] as $unit) {
        $up = (array)($unit['performance'] ?? []);
        $ud = (array)($up['delta'] ?? []);
        $signals = [];
        $links = [];
        $unitfilter = (string)($unit['uuid'] ?? '');

        if ((int)($unit['overdue_tasks'] ?? 0) > 0) {
            $signals[] = html_writer::span(
                get_string('battleplan_overview_overdue_tasks','local_kbacompany') . ': ' . (int)$unit['overdue_tasks'],
                'bms24-unitfocus-badge is-danger'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'attention'=>'overdue','teamsignal'=>'all']),
                get_string('battleplan_overview_overdue_tasks','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }
        if ((int)($unit['needs_decision'] ?? 0) > 0) {
            $signals[] = html_writer::span(
                get_string('battleplan_overview_needs_decision','local_kbacompany') . ': ' . (int)$unit['needs_decision'],
                'bms24-unitfocus-badge is-danger'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'attention'=>'needs_decision','teamsignal'=>'all']),
                get_string('battleplan_overview_needs_decision','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }
        if ((int)($unit['critical_exceptions'] ?? 0) > 0) {
            $signals[] = html_writer::span(
                get_string('battleplan_overview_critical_exceptions','local_kbacompany') . ': ' . (int)$unit['critical_exceptions'],
                'bms24-unitfocus-badge is-warning'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'attention'=>'critical','teamsignal'=>'all']),
                get_string('battleplan_overview_critical_exceptions','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }

        $productivitydelta = $ud['productivity'] ?? null;
        $effectivenessdelta = $ud['effectiveness'] ?? null;
        $coverage = $up['coverage'] ?? null;
        $coveragecount = (int)($up['metric_evaluated'] ?? 0);
        $metrictotal = (int)($up['metric_total'] ?? 0);
        if ($productivitydelta !== null && (int)$productivitydelta < 0) {
            $signals[] = html_writer::span(
                get_string('bpu_cycle_productivity','local_kbacompany') . ': −' . abs((int)$productivitydelta) . ' п.п.',
                'bms24-unitfocus-badge is-warning'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'teamsignal'=>'attention']),
                get_string('bpu_cycle_productivity','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }
        if ($effectivenessdelta !== null && (int)$effectivenessdelta < 0) {
            $signals[] = html_writer::span(
                get_string('bpu_cycle_effectiveness','local_kbacompany') . ': −' . abs((int)$effectivenessdelta) . ' п.п.',
                'bms24-unitfocus-badge is-warning'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'teamsignal'=>'attention']),
                get_string('bpu_cycle_effectiveness','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }
        if ($metrictotal > 0 && $coveragecount < $metrictotal) {
            $signals[] = html_writer::span(
                get_string('bpu_cycle_data_coverage','local_kbacompany') . ': ' . $coveragecount . '/' . $metrictotal,
                'bms24-unitfocus-badge is-info'
            );
            $links[] = html_writer::link(
                $overviewurl(['orgnode'=>$unitfilter,'teamsignal'=>'insufficient']),
                get_string('bpu_cycle_data_coverage','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']
            );
        }

        if (!$signals) {
            continue;
        }

        $name = s((string)($unit['name'] ?? get_string('battleplan_overview_no_unit','local_kbacompany')));
        $uniturl = $overviewurl(['orgnode'=>$unitfilter,'attention'=>'all','teamsignal'=>'all']);
        $perfparts = [];
        $perfparts[] = get_string('bpu_cycle_productivity','local_kbacompany') . ': ' .
            (($up['productivity'] ?? null) === null ? '—' : ((int)$up['productivity']) . '%');
        $perfparts[] = get_string('bpu_cycle_effectiveness','local_kbacompany') . ': ' .
            (($up['effectiveness'] ?? null) === null ? '—' : ((int)$up['effectiveness']) . '%');
        $perfparts[] = get_string('bpu_cycle_data_coverage','local_kbacompany') . ': ' .
            ($coverage === null ? '—' : ((int)$coverage) . '%');

        $unitcards .= html_writer::div(
            html_writer::div(
                html_writer::link($uniturl, $name, ['class'=>'bms24-unitfocus-name']) .
                html_writer::tag('small', get_string('battleplan_overview_active_plans','local_kbacompany') . ': ' .
                    (int)($unit['plans'] ?? 0), ['class'=>'text-muted']),
                'bms24-unitfocus-head'
            ) .
            html_writer::div(implode('', $signals), 'bms24-unitfocus-signals') .
            html_writer::tag('small', s(implode(' · ', $perfparts)), ['class'=>'bms24-unitfocus-performance']) .
            html_writer::div(implode(' · ', $links), 'bms24-unitfocus-actions'),
            'bms24-unitfocus-card'
        );
    }
    if ($unitcards !== '') {
        echo html_writer::start_div('bms24-card bms24-unitfocus mb-4');
        echo html_writer::start_div('bms24-cardheader');
        echo html_writer::tag('h2', get_string('battleplan_overview_by_unit','local_kbacompany'));
        echo html_writer::tag('p', get_string('management_attention_title','local_kbacompany'));
        echo html_writer::end_div();
        echo html_writer::div($unitcards, 'bms24-unitfocus-grid');
        echo html_writer::end_div();
    }
}

// Team management-cycle view: productivity, metric effectiveness and data coverage are
// intentionally kept separate. Values are derived from the same canonical task/indicator
// projections as individual Battle Plans; no composite employee score is introduced.
if (!empty($data['rows'])) {
    $perf = (array)($data['summary']['performance'] ?? []);
    $metriccard = static function(string $label, $value, string $detail): string {
        $display = $value === null ? '—' : ((int)$value) . '%';
        $width = $value === null ? 0 : max(0, min(100, (int)$value));
        $bar = html_writer::div(
            html_writer::div('', 'bms24-teamperf-fill', ['style'=>'width:'.$width.'%']),
            'bms24-teamperf-track',
            ['role'=>'progressbar','aria-valuemin'=>'0','aria-valuemax'=>'100','aria-valuenow'=>(string)$width]
        );
        return html_writer::div(
            html_writer::tag('span', s($label), ['class'=>'bms24-teamperf-label']) .
            html_writer::tag('strong', s($display), ['class'=>'bms24-teamperf-value']) .
            html_writer::tag('small', s($detail), ['class'=>'text-muted']) . $bar,
            'bms24-teamperf-card'
        );
    };

    echo html_writer::start_div('bms24-card bms24-teamperf mb-4');
    echo html_writer::start_div('bms24-cardheader');
    echo html_writer::tag('h2', get_string('bpu_cycle_productivity','local_kbacompany') . ' / ' . get_string('bpu_cycle_effectiveness','local_kbacompany'));
    echo html_writer::end_div();

    $summarycards = '';
    $summarycards .= $metriccard(
        get_string('bpu_cycle_productivity','local_kbacompany'),
        $perf['productivity'] ?? null,
        (int)($perf['task_done'] ?? 0) . ' / ' . (int)($perf['task_total'] ?? 0)
    );
    $summarycards .= $metriccard(
        get_string('bpu_cycle_effectiveness','local_kbacompany'),
        $perf['effectiveness'] ?? null,
        (int)($perf['metric_ok'] ?? 0) . ' / ' . (int)($perf['metric_evaluated'] ?? 0)
    );
    $summarycards .= $metriccard(
        get_string('bpu_cycle_data_coverage','local_kbacompany'),
        $perf['coverage'] ?? null,
        (int)($perf['metric_evaluated'] ?? 0) . ' / ' . (int)($perf['metric_total'] ?? 0)
    );
    echo html_writer::div($summarycards, 'bms24-teamperf-grid');

    // Management attention is not an employee rating. It is an explainable queue derived from
    // factual signals already present in the canonical task/indicator projections. Priority order:
    // operational attention -> insufficient metric data -> positive movement -> stable/no new signal.
    $attentioncounts = ['attention'=>0, 'insufficient'=>0, 'improving'=>0, 'stable'=>0];
    foreach ($data['rows'] as &$attentionrow) {
        $arp = (array)($attentionrow['performance'] ?? []);
        $ard = (array)($arp['delta'] ?? []);
        $reasons = [];
        $hasoperationalattention = (string)($attentionrow['attention'] ?? 'clear') !== 'clear';
        $declining = (($ard['productivity'] ?? null) !== null && (int)$ard['productivity'] < 0) ||
            (($ard['effectiveness'] ?? null) !== null && (int)$ard['effectiveness'] < 0);
        $metricmissing = (int)($arp['metric_total'] ?? 0) > (int)($arp['metric_evaluated'] ?? 0);
        $improving = (($ard['productivity'] ?? null) !== null && (int)$ard['productivity'] > 0) ||
            (($ard['effectiveness'] ?? null) !== null && (int)$ard['effectiveness'] > 0);

        if ($hasoperationalattention || $declining) {
            $attentionrow['management_signal'] = 'attention';
            if ((int)($attentionrow['tasks']['overdue'] ?? 0) > 0) {
                $reasons[] = get_string('battleplan_overview_overdue_tasks','local_kbacompany') . ': ' .
                    (int)$attentionrow['tasks']['overdue'];
            }
            if ((int)($attentionrow['needs_decision'] ?? 0) > 0) {
                $reasons[] = get_string('battleplan_overview_needs_decision','local_kbacompany') . ': ' .
                    (int)$attentionrow['needs_decision'];
            }
            if (($ard['productivity'] ?? null) !== null && (int)$ard['productivity'] < 0) {
                $reasons[] = get_string('bpu_cycle_productivity','local_kbacompany') . ': -' .
                    abs((int)$ard['productivity']) . ' п.п.';
            }
            if (($ard['effectiveness'] ?? null) !== null && (int)$ard['effectiveness'] < 0) {
                $reasons[] = get_string('bpu_cycle_effectiveness','local_kbacompany') . ': -' .
                    abs((int)$ard['effectiveness']) . ' п.п.';
            }
        } elseif ($metricmissing) {
            $attentionrow['management_signal'] = 'insufficient';
            $missing = max(0, (int)($arp['metric_total'] ?? 0) - (int)($arp['metric_evaluated'] ?? 0));
            if ($missing > 0) {
                $reasons[] = get_string('bpu_cycle_data_coverage','local_kbacompany') . ': ' .
                    (int)($arp['metric_evaluated'] ?? 0) . '/' . (int)($arp['metric_total'] ?? 0);
            }
        } elseif ($improving) {
            $attentionrow['management_signal'] = 'improving';
            if (($ard['productivity'] ?? null) !== null && (int)$ard['productivity'] > 0) {
                $reasons[] = get_string('bpu_cycle_productivity','local_kbacompany') . ': +' .
                    (int)$ard['productivity'] . ' п.п.';
            }
            if (($ard['effectiveness'] ?? null) !== null && (int)$ard['effectiveness'] > 0) {
                $reasons[] = get_string('bpu_cycle_effectiveness','local_kbacompany') . ': +' .
                    (int)$ard['effectiveness'] . ' п.п.';
            }
        } else {
            $attentionrow['management_signal'] = 'stable';
        }
        $attentionrow['management_reasons'] = $reasons;
        $attentioncounts[$attentionrow['management_signal']]++;
    }
    unset($attentionrow);

    $attentioncards = '';
    $attentiondefs = [
        'attention' => ['label'=>get_string('management_attention_title','local_kbacompany'), 'class'=>'is-attention'],
        'insufficient' => ['label'=>get_string('management_loop_quality_insufficient','local_kbacompany'), 'class'=>'is-insufficient'],
        'improving' => ['label'=>get_string('management_loop_quality_improving','local_kbacompany'), 'class'=>'is-improving'],
        'stable' => ['label'=>get_string('indicator_trend_stable','local_kbacompany'), 'class'=>'is-stable'],
    ];
    if (!isset($attentiondefs[$teamsignal])) {
        $teamsignal = 'all';
    }
    foreach ($attentiondefs as $signal => $def) {
        $cardbody = html_writer::tag('strong', (string)$attentioncounts[$signal]) .
            html_writer::tag('span', s($def['label']));
        $classes = 'bms24-teamattention-card ' . $def['class'];
        if ($teamsignal === $signal) {
            $classes .= ' is-selected';
        }
        $attentioncards .= html_writer::link(
            $overviewurl(['teamsignal'=>$signal]),
            $cardbody,
            [
                'class'=>$classes,
                'aria-current'=>$teamsignal === $signal ? 'true' : null,
                'aria-label'=>$def['label'] . ': ' . (int)$attentioncounts[$signal],
            ]
        );
    }
    echo html_writer::div($attentioncards, 'bms24-teamattention-grid');
    if ($teamsignal !== 'all') {
        echo html_writer::div(
            html_writer::span(s($attentiondefs[$teamsignal]['label']), 'bms24-teamattention-badge ' . $attentiondefs[$teamsignal]['class']) .
            html_writer::link($overviewurl(['teamsignal'=>'all']), get_string('reset'), ['class'=>'btn btn-sm btn-link']),
            'bms24-teamattention-filter mb-3'
        );
    }

    $delta = static function($value): string {
        if ($value === null) { return '—'; }
        $value = (int)$value;
        if ($value > 0) { return '+' . $value; }
        if ($value < 0) { return (string)$value; }
        return '0';
    };
    $pct = static function($value): string { return $value === null ? '—' : ((int)$value) . '%'; };

    $t = new html_table();
    $t->attributes['class'] = 'generaltable bms24-teamperf-table';
    $t->head = [
        get_string('battleplan_employee','local_kbacompany'),
        get_string('battleplan_overview_unit','local_kbacompany'),
        get_string('bpu_cycle_productivity','local_kbacompany'),
        get_string('bpu_cycle_effectiveness','local_kbacompany'),
        get_string('bpu_cycle_data_coverage','local_kbacompany'),
        get_string('indicator_trend','local_kbacompany'),
        get_string('management_attention_title','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany'),
    ];
    $teamrows = $teamsignal === 'all' ? $data['rows'] : array_values(array_filter($data['rows'], static function(array $row) use ($teamsignal): bool {
        return (string)($row['management_signal'] ?? 'stable') === $teamsignal;
    }));
    foreach ($teamrows as $row) {
        $rp = (array)($row['performance'] ?? []);
        $rd = (array)($rp['delta'] ?? []);
        $changes = [];
        if (($rd['productivity'] ?? null) !== null) {
            $changes[] = get_string('bpu_cycle_productivity','local_kbacompany') . ': ' . $delta($rd['productivity']);
        }
        if (($rd['effectiveness'] ?? null) !== null) {
            $changes[] = get_string('bpu_cycle_effectiveness','local_kbacompany') . ': ' . $delta($rd['effectiveness']);
        }
        if (($rd['coverage'] ?? null) !== null) {
            $changes[] = get_string('bpu_cycle_data_coverage','local_kbacompany') . ': ' . $delta($rd['coverage']);
        }
        $changehtml = $changes ? implode('<br>', array_map('s', $changes)) : '—';
        $signal = (string)($row['management_signal'] ?? 'stable');
        $signallabels = [
            'attention'=>get_string('management_attention_title','local_kbacompany'),
            'insufficient'=>get_string('management_loop_quality_insufficient','local_kbacompany'),
            'improving'=>get_string('management_loop_quality_improving','local_kbacompany'),
            'stable'=>get_string('indicator_trend_stable','local_kbacompany'),
        ];
        $reasonhtml = '';
        if (!empty($row['management_reasons'])) {
            $reasonhtml = html_writer::tag('small', implode(' · ', array_map('s', $row['management_reasons'])),
                ['class'=>'text-muted d-block mt-1']);
        }
        $planparams = ['uuid'=>$uuid, 'plan'=>$row['plan_uuid']];
        if ($signal === 'insufficient') {
            $planparams['tab'] = 'indicators';
        } elseif ($signal === 'attention') {
            $productivitydeclined = ($rd['productivity'] ?? null) !== null && (int)$rd['productivity'] < 0;
            $effectivenessdeclined = ($rd['effectiveness'] ?? null) !== null && (int)$rd['effectiveness'] < 0;
            if ((int)($row['tasks']['overdue'] ?? 0) > 0 || (int)($row['needs_decision'] ?? 0) > 0 || $productivitydeclined) {
                $planparams['tab'] = 'tasks';
            } elseif ($effectivenessdeclined) {
                $planparams['tab'] = 'indicators';
            }
        }
        $signalurl = new moodle_url('/local/kbacompany/battle_plan.php', $planparams);
        $signalhtml = html_writer::link(
            $signalurl,
            html_writer::span(s($signallabels[$signal] ?? $signallabels['stable']),
                'bms24-teamattention-badge is-' . $signal) . $reasonhtml,
            ['class'=>'bms24-teamattention-signal-link']
        );
        $employee = html_writer::tag('strong', s($row['employee_name'])) .
            html_writer::tag('small', s($row['position']['name']), ['class'=>'text-muted d-block']);
        $taskcontext = '';
        $manualopen = (int)($row['tasks']['manual_open'] ?? 0);
        $manualdone = (int)($row['tasks']['manual_done'] ?? 0);
        if ($manualopen > 0 || $manualdone > 0) {
            $tasksummary = html_writer::tag('strong', get_string('bpu_next_actions_title','local_kbacompany')) .
                html_writer::tag('small', get_string('tui_count_open','local_kbacompany') . ': ' . $manualopen .
                    ' · ' . get_string('tui_count_done','local_kbacompany') . ': ' . $manualdone,
                    ['class'=>'text-muted d-block']);
            $tasklinks = '';
            foreach ((array)($row['tasks']['manual_items'] ?? []) as $taskitem) {
                $taskurl = new moodle_url('/local/kbacompany/tasks.php', [
                    'uuid'=>$uuid, 'task'=>(string)$taskitem['uuid'], 'view'=>'detail',
                ]);
                $statuskey = 'taskcenter_status_' . (string)$taskitem['status'];
                $statuslabel = get_string_manager()->string_exists($statuskey, 'local_kbacompany')
                    ? get_string($statuskey, 'local_kbacompany') : (string)$taskitem['status'];
                $tasklinks .= html_writer::div(
                    html_writer::link($taskurl, s((string)$taskitem['title'])) .
                    html_writer::span(s($statuslabel), 'bms24-teamtask-status'),
                    'bms24-teamtask-item'
                );
            }
            $alltasksurl = new moodle_url('/local/kbacompany/tasks.php', [
                'uuid'=>$uuid, 'scope'=>'all', 'battleplan'=>(string)$row['plan_uuid'],
            ]);
            $tasklinks .= html_writer::link($alltasksurl, get_string('taskcenter_title','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link px-0']);
            // If at least one manual management task has been completed, show the factual
            // week-over-week movement next to the execution context. This is an observation only:
            // it must not be presented as proof that the task caused the change.
            $impacthtml = '';
            if ($manualdone > 0) {
                $impactdeltas = [
                    'productivity' => $rd['productivity'] ?? null,
                    'effectiveness' => $rd['effectiveness'] ?? null,
                ];
                $knownimpact = array_values(array_filter($impactdeltas, static function($value): bool {
                    return $value !== null;
                }));
                if ($knownimpact) {
                    $haspositive = false;
                    $hasnegative = false;
                    $hasnonzero = false;
                    foreach ($knownimpact as $value) {
                        $ivalue = (int)$value;
                        if ($ivalue > 0) { $haspositive = true; $hasnonzero = true; }
                        if ($ivalue < 0) { $hasnegative = true; $hasnonzero = true; }
                    }
                    if ($haspositive && $hasnegative) {
                        $quality = get_string('management_loop_quality_neutral','local_kbacompany');
                        $qualityclass = 'is-neutral';
                    } elseif ($haspositive) {
                        $quality = get_string('management_loop_quality_improving','local_kbacompany');
                        $qualityclass = 'is-improving';
                    } elseif ($hasnegative) {
                        $quality = get_string('management_loop_quality_worsening','local_kbacompany');
                        $qualityclass = 'is-worsening';
                    } elseif (!$hasnonzero) {
                        $quality = get_string('management_loop_quality_unchanged','local_kbacompany');
                        $qualityclass = 'is-unchanged';
                    }

                    $impactparts = [];
                    foreach ($impactdeltas as $key => $value) {
                        if ($value === null) { continue; }
                        $label = $key === 'productivity'
                            ? get_string('bpu_cycle_productivity','local_kbacompany')
                            : get_string('bpu_cycle_effectiveness','local_kbacompany');
                        $ivalue = (int)$value;
                        if ($ivalue > 0) {
                            $deltatext = get_string('battleplan_team_delta_up','local_kbacompany', abs($ivalue));
                        } elseif ($ivalue < 0) {
                            $deltatext = get_string('battleplan_team_delta_down','local_kbacompany', abs($ivalue));
                        } else {
                            $deltatext = get_string('battleplan_team_delta_same','local_kbacompany');
                        }
                        $impactparts[] = s($label) . ': ' . s($deltatext);
                    }
                    $impacthtml = html_writer::div(
                        html_writer::div(
                            html_writer::tag('strong', get_string('management_loop_stage_followup','local_kbacompany')) .
                            html_writer::span(s($quality), 'bms24-teamimpact-badge ' . $qualityclass),
                            'bms24-teamimpact-head'
                        ) .
                        html_writer::tag('small', implode(' · ', $impactparts), ['class'=>'d-block']) .
                        html_writer::tag('small', get_string('management_loop_causality_note','local_kbacompany'),
                            ['class'=>'text-muted d-block mt-1']),
                        'bms24-teamimpact'
                    );
                }
            }
            $taskcontext = html_writer::div($tasksummary . $tasklinks . $impacthtml, 'bms24-teamtask-context');
        }

        $actions = $taskcontext . html_writer::div(
            html_writer::link($row['url'], get_string('battleplan_open','local_kbacompany'), ['class'=>'btn btn-sm btn-outline-primary']),
            'bms24-teamtask-actions'
        );
        if ($signal === 'attention' || $signal === 'insufficient') {
            $actions .= html_writer::link($signalurl, get_string('management_attention_title','local_kbacompany'),
                ['class'=>'btn btn-sm btn-link bms24-teamattention-focus']);

            // Management attention can be turned into one canonical Task Center task. The task is
            // not auto-created: this link only opens the normal create form with bounded, validated
            // prefill and a link to this draft/active Battle Plan.
            $cancreatetask = in_array((string)($row['status'] ?? ''), ['draft','active'], true) &&
                (!empty($data['can_manage']) || (int)$row['employee_id'] === (int)$USER->id ||
                    \local_kbacompany\local\access_role_service::may_edit_department_plan(
                        $uuid, (int)$USER->id, (int)$row['employee_id']));
            if ($cancreatetask) {
                $taskparams = [
                    'uuid'=>$uuid, 'view'=>'create', 'assigneeid'=>(int)$row['employee_id'],
                    'reviewerid'=>(int)$row['reviewer_id'], 'startdate'=>(string)$data['week_start'],
                    'duedate'=>(string)$data['week_end'], 'battleplanuuid'=>(string)$row['plan_uuid'],
                ];
                $taskurl = new moodle_url('/local/kbacompany/tasks.php', $taskparams);
                $actions .= html_writer::link($taskurl, get_string('bpu_next_actions_create_task','local_kbacompany'),
                    ['class'=>'btn btn-sm btn-primary bms24-teamattention-create-task']);
            }
        }
        $t->data[] = [
            $employee,
            s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
            s($pct($rp['productivity'] ?? null)),
            s($pct($rp['effectiveness'] ?? null)),
            s($pct($rp['coverage'] ?? null)),
            $changehtml,
            $signalhtml,
            $actions,
        ];
    }
    echo html_writer::table($t);

    // Department-level management-action feedback. This aggregates only canonical manual
    // tasks and the same task/metric projections used above. Week-over-week movement is
    // observational context after execution, not a causal attribution to the management task.
    if (!empty($data['by_unit'])) {
        $fmtpct = static function($value): string {
            return $value === null ? '—' : ((int)$value) . '%';
        };
        $fmtdelta = static function($value): string {
            if ($value === null) { return '—'; }
            $value = (int)$value;
            if ($value > 0) { return '+' . $value . ' п.п.'; }
            if ($value < 0) { return '−' . abs($value) . ' п.п.'; }
            return '0 п.п.';
        };
        $unitactionopen = 0;
        $unitactiondone = 0;
        foreach ($data['by_unit'] as $unit) {
            $unitactionopen += (int)($unit['management_tasks']['open'] ?? 0);
            $unitactiondone += (int)($unit['management_tasks']['done'] ?? 0);
        }

        echo html_writer::start_div('bms24-teamimpact-summary mt-4');
        echo html_writer::div(
            html_writer::tag('strong', get_string('bpu_next_actions_title','local_kbacompany')) .
            html_writer::tag('small',
                get_string('tui_count_open','local_kbacompany') . ': ' . $unitactionopen . ' · ' .
                get_string('tui_count_done','local_kbacompany') . ': ' . $unitactiondone,
                ['class'=>'text-muted d-block']),
            'bms24-teamimpact-summary-head'
        );

        $ut = new html_table();
        $ut->attributes['class'] = 'generaltable bms24-teamimpact-unit-table';
        $ut->head = [
            get_string('battleplan_overview_unit','local_kbacompany'),
            get_string('bpu_next_actions_title','local_kbacompany'),
            get_string('bpu_cycle_productivity','local_kbacompany'),
            get_string('bpu_cycle_effectiveness','local_kbacompany'),
            get_string('bpu_cycle_data_coverage','local_kbacompany'),
            get_string('management_loop_stage_followup','local_kbacompany'),
        ];
        foreach ($data['by_unit'] as $unit) {
            $up = (array)($unit['performance'] ?? []);
            $ud = (array)($up['delta'] ?? []);
            $actionopen = (int)($unit['management_tasks']['open'] ?? 0);
            $actiondone = (int)($unit['management_tasks']['done'] ?? 0);
            $uniturl = $unit['uuid'] !== '' ? $overviewurl(['orgnode'=>(string)$unit['uuid']]) : null;
            $unitname = $uniturl ? html_writer::link($uniturl, s($unit['name'])) : s($unit['name']);
            $actions = html_writer::tag('strong', get_string('tui_count_open','local_kbacompany') . ': ' . $actionopen) .
                html_writer::tag('small', get_string('tui_count_done','local_kbacompany') . ': ' . $actiondone,
                    ['class'=>'text-muted d-block']);
            $movement = html_writer::tag('small',
                get_string('bpu_cycle_productivity','local_kbacompany') . ': ' . $fmtdelta($ud['productivity'] ?? null) . '<br>' .
                get_string('bpu_cycle_effectiveness','local_kbacompany') . ': ' . $fmtdelta($ud['effectiveness'] ?? null) . '<br>' .
                get_string('bpu_cycle_data_coverage','local_kbacompany') . ': ' . $fmtdelta($ud['coverage'] ?? null),
                ['class'=>'bms24-teamimpact-deltas']
            );
            $ut->data[] = [
                $unitname,
                $actions,
                s($fmtpct($up['productivity'] ?? null)),
                s($fmtpct($up['effectiveness'] ?? null)),
                s($fmtpct($up['coverage'] ?? null)),
                $movement,
            ];
        }
        echo html_writer::table($ut);
        echo html_writer::tag('small', get_string('management_loop_causality_note','local_kbacompany'),
            ['class'=>'text-muted d-block mt-2']);
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
}

// Action queue: the overview is most useful when it immediately names the plans
// that require a management decision or execution follow-up. This is presentation
// over the canonical read-model; it creates no second task/incident model.
$attentionrows = array_values(array_filter($data['rows'], static function(array $row): bool {
    return (string)$row['attention'] !== 'clear';
}));
if ($attentionrows) {
    echo html_writer::start_div('bms24-card bms24-card-accent bms24-overview-attention mb-4');
    echo html_writer::start_div('bms24-cardheader');
    echo html_writer::tag('h2', get_string('battleplan_overview_attention','local_kbacompany'));
    echo html_writer::tag('p', get_string('battleplan_overview_rule','local_kbacompany'));
    echo html_writer::end_div();
    echo html_writer::start_div('bms24-overview-attention-list');
    foreach (array_slice($attentionrows, 0, 8) as $row) {
        $att = get_string('battleplan_overview_attention_'.$row['attention'],'local_kbacompany');
        $badgeclass = in_array($row['attention'],['needs_decision','overdue'],true) ? 'bms24-badge bms24-badge-danger' :
            ($row['attention']==='critical' ? 'bms24-badge bms24-badge-warning' : 'bms24-badge bms24-badge-info');
        $meta = [];
        if ((string)$row['position']['name'] !== '') { $meta[] = s($row['position']['name']); }
        if ((string)$row['orgnode']['name'] !== '') { $meta[] = s($row['orgnode']['name']); }
        $signals = [];
        if ((int)$row['needs_decision'] > 0) {
            $signals[] = get_string('battleplan_overview_needs_decision','local_kbacompany') . ': ' . (int)$row['needs_decision'];
        }
        if ((int)$row['tasks']['overdue'] > 0) {
            $signals[] = get_string('battleplan_overview_overdue_tasks','local_kbacompany') . ': ' . (int)$row['tasks']['overdue'];
        }
        if ((int)$row['critical_exceptions'] > 0) {
            $signals[] = get_string('battleplan_overview_critical_exceptions','local_kbacompany') . ': ' . (int)$row['critical_exceptions'];
        }
        if (!$signals && (int)$row['tasks']['open'] > 0) {
            $signals[] = get_string('battleplan_overview_open_tasks','local_kbacompany') . ': ' . (int)$row['tasks']['open'];
        }
        $title = html_writer::link($row['url'], s($row['employee_name']), ['class'=>'bms24-overview-person']);
        $left = html_writer::div($title .
            html_writer::div(implode(' · ', $meta),'bms24-overview-meta') .
            html_writer::div(implode(' · ', array_map('s', $signals)),'bms24-overview-signals'), 'bms24-overview-attention-main');
        $right = html_writer::span(s($att), $badgeclass) .
            html_writer::link($row['url'], get_string('battleplan_open','local_kbacompany'), ['class'=>'btn btn-sm btn-outline-primary']);
        echo html_writer::div($left . html_writer::div($right,'bms24-overview-attention-actions'), 'bms24-overview-attention-row');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$renderagg = static function(array $items, string $title, string $kind) use ($OUTPUT, $overviewurl) {
    echo $OUTPUT->heading($title,3);
    if (!$items) { echo html_writer::div('—','text-muted mb-3'); return; }
    $t = new html_table();
    $t->attributes['class'] = 'generaltable bms24-overview-aggregate';
    $t->head = [get_string('battleplan_overview_group','local_kbacompany'),get_string('battleplan_overview_active_plans','local_kbacompany'),get_string('battleplan_overview_needs_decision','local_kbacompany'),get_string('battleplan_overview_overdue_tasks','local_kbacompany'),get_string('battleplan_overview_critical_exceptions','local_kbacompany')];
    foreach ($items as $item) {
        $filterkey = $kind === 'unit' ? 'orgnode' : 'reviewer';
        $filterval = $kind === 'unit' ? (string)($item['uuid'] ?? '') : (int)($item['user_id'] ?? 0);
        $name = $filterval ? html_writer::link($overviewurl([$filterkey=>$filterval]), s($item['name'])) : s($item['name']);
        $decision = (int)$item['needs_decision'] > 0 ? html_writer::link($overviewurl([$filterkey=>$filterval,'attention'=>'needs_decision']), (string)$item['needs_decision']) : '0';
        $overdue = (int)$item['overdue_tasks'] > 0 ? html_writer::link($overviewurl([$filterkey=>$filterval,'attention'=>'overdue']), (string)$item['overdue_tasks']) : '0';
        $critical = (int)$item['critical_exceptions'] > 0 ? html_writer::link($overviewurl([$filterkey=>$filterval,'attention'=>'critical']), (string)$item['critical_exceptions']) : '0';
        $t->data[] = [$name,(int)$item['plans'],$decision,$overdue,$critical];
    }
    echo html_writer::table($t);
};
$renderagg($data['by_unit'], get_string('battleplan_overview_by_unit','local_kbacompany'), 'unit');
$renderagg($data['by_reviewer'], get_string('battleplan_overview_by_reviewer','local_kbacompany'), 'reviewer');

echo $OUTPUT->heading(get_string('battleplan_overview_plans','local_kbacompany'),3);
if (!$data['rows']) {
    echo $OUTPUT->notification(get_string('battleplan_overview_empty','local_kbacompany'),'info');
} else {
    $t = new html_table();
    $t->head = [get_string('battleplan_overview_attention','local_kbacompany'),get_string('battleplan_employee','local_kbacompany'),get_string('battleplan_position','local_kbacompany'),get_string('battleplan_overview_unit','local_kbacompany'),get_string('battleplan_overview_reviewer','local_kbacompany'),get_string('battleplan_status','local_kbacompany'),get_string('battleplan_overview_critical_indicators','local_kbacompany'),get_string('battleplan_overview_exceptions','local_kbacompany'),get_string('battleplan_overview_tasks','local_kbacompany'),get_string('indicator_actions','local_kbacompany')];
    foreach ($data['rows'] as $row) {
        $att = get_string('battleplan_overview_attention_'.$row['attention'],'local_kbacompany');
        $cls = in_array($row['attention'],['needs_decision','overdue'],true) ? 'badge badge-danger' : ($row['attention']==='critical' ? 'badge badge-warning' : ($row['attention']==='clear' ? 'badge badge-success' : 'badge badge-secondary'));
        $exceptions = get_string('battleplan_overview_exception_counts','local_kbacompany',(object)['all'=>$row['exceptions'],'critical'=>$row['critical_exceptions'],'decision'=>$row['needs_decision']]);
        $tasks = get_string('battleplan_overview_task_counts','local_kbacompany',(object)['open'=>$row['tasks']['open'],'overdue'=>$row['tasks']['overdue'],'done'=>$row['tasks']['done']]);
        $t->data[] = [html_writer::span(s($att),$cls),s($row['employee_name']),s($row['position']['name']),s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),s($row['reviewer_name'] ?: get_string('battleplan_overview_no_reviewer','local_kbacompany')),get_string('battleplan_status_'.$row['status'],'local_kbacompany'),(int)$row['critical_indicators'],s($exceptions),s($tasks),html_writer::link($row['url'],get_string('battleplan_open','local_kbacompany'))];
    }
    echo html_writer::table($t);
}
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
