<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedevelopmentplans', $context);
$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$statusfilter = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));
$period = optional_param('period', '12', PARAM_ALPHANUMEXT);
$allowedperiods = ['3', '6', '12', 'all'];
if (!in_array($period, $allowedperiods, true)) {
    $period = '12';
}
$allowedstatuses = ['all', 'draft', 'pending', 'active', 'completed', 'cancelled'];
if (!in_array($statusfilter, $allowedstatuses, true)) {
    $statusfilter = 'all';
}
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$report = \local_kbacorporate\local\development_plans::get_company_effectiveness(
    $companyid, $visibleids, $statusfilter, $query);
$breakdowns = \local_kbacorporate\local\development_plans::get_effectiveness_breakdowns($companyid, $report->rows);
$periodfrom = $period === 'all' ? 0 : strtotime('-' . (int)$period . ' months', usergetmidnight(time()));
$timeline = \local_kbacorporate\local\development_plans::get_effectiveness_timeline($report->rows, $periodfrom, time());

$PAGE->set_context($context);
$pageparams = ['companyid' => $companyid, 'departmentid' => $departmentid,
    'status' => $statusfilter, 'q' => $query, 'period' => $period];
$PAGE->set_url(new moodle_url('/local/kbacorporate/development_effectiveness.php', $pageparams));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('developmenteffectiveness', 'local_kbacorporate'));
$PAGE->set_heading(get_string('developmenteffectiveness', 'local_kbacorporate'));
echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::start_div('kbacorp-effectiveness-hero');
echo html_writer::div(get_string('employeedevelopment', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('developmenteffectiveness', 'local_kbacorporate'), 2);
echo html_writer::div(get_string('developmenteffectivenessdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::end_div();
$companyoptions = [];
foreach ($companies as $item) {
    $companyoptions[(int)$item->id] = format_string($item->name);
}
echo html_writer::start_div('kbacorp-effectiveness-context');
echo html_writer::div(get_string('company', 'local_kbacorporate'), 'kbacorp-effectiveness-context-label');
echo $OUTPUT->single_select(new moodle_url('/local/kbacorporate/development_effectiveness.php'),
    'companyid', $companyoptions, $companyid);
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-effectiveness-filterpanel');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid,
    '/local/kbacorporate/development_effectiveness.php', ['status' => $statusfilter, 'q' => $query, 'period' => $period]);
$statusoptions = ['all' => get_string('allstatuses', 'local_kbacorporate')];
foreach (['draft', 'pending', 'active', 'completed', 'cancelled'] as $planstatus) {
    $statusoptions[$planstatus] = get_string('developmentplanstatus_' . $planstatus, 'local_kbacorporate');
}
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
if ($departmentid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
}
echo html_writer::select($statusoptions, 'status', $statusfilter, false,
    ['aria-label' => get_string('status')]);
$periodoptions = [
    '3' => get_string('effectivenessperiod3', 'local_kbacorporate'),
    '6' => get_string('effectivenessperiod6', 'local_kbacorporate'),
    '12' => get_string('effectivenessperiod12', 'local_kbacorporate'),
    'all' => get_string('effectivenessperiodall', 'local_kbacorporate'),
];
echo html_writer::select($periodoptions, 'period', $period, false,
    ['aria-label' => get_string('effectivenessperiod', 'local_kbacorporate')]);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('developmenteffectivenesssearch', 'local_kbacorporate'),
    'aria-label' => get_string('search')]);
echo html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/development_effectiveness.php', [
    'companyid' => $companyid,
]), get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();

$metrics = [
    [$report->plans, get_string('developmentmetricplans', 'local_kbacorporate')],
    [$report->active, get_string('developmentmetricactive', 'local_kbacorporate')],
    [$report->completed, get_string('developmentmetriccompleted', 'local_kbacorporate')],
    [$report->progress . '%', get_string('developmentmetricprogress', 'local_kbacorporate')],
    [$report->overdue, get_string('developmentmetricoverdue', 'local_kbacorporate')],
    [$report->auditcoverage . '%', get_string('developmentmetricauditcoverage', 'local_kbacorporate')],
    [$report->improved, get_string('developmentmetricimproved', 'local_kbacorporate')],
    [$report->targetsreached, get_string('developmentmetrictargets', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-development-effectiveness-metrics');
foreach ($metrics as $index => $metric) {
    echo html_writer::div(html_writer::tag('strong', $metric[0]) . html_writer::span($metric[1]),
        'kbacorp-development-metric kbacorp-development-metric-' . ($index + 1));
}
echo html_writer::end_div();
if (has_capability('local/kbacorporate:exportreport', $context)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/development_effectiveness_export.php',
        $pageparams), get_string('exportdevelopmenteffectivenesscsv', 'local_kbacorporate'), 'get', ['class' => 'mb-3']);
}

if (!$report->rows) {
    echo $OUTPUT->notification(get_string('nodevelopmentplans', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-development-effectiveness-table';
    $table->head = [get_string('employee', 'local_kbacorporate'), get_string('developmentplan', 'local_kbacorporate'),
        get_string('status'), get_string('progress'), get_string('developmentactionsoverdue', 'local_kbacorporate'),
        get_string('developmentoutcomeassessed', 'local_kbacorporate'),
        get_string('developmentoutcomeimproved', 'local_kbacorporate'),
        get_string('developmentoutcomereached', 'local_kbacorporate')];
    foreach ($report->rows as $row) {
        $plan = $row->plan;
        $outcome = $row->outcome;
        $table->data[] = [fullname($plan), html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', [
            'id' => (int)$plan->id,
        ]), format_string($plan->title)), get_string('developmentplanstatus_' . $plan->status, 'local_kbacorporate'),
            $row->progress->percent . '%', $row->progress->overdue,
            $outcome ? $outcome->assessed . '/' . $outcome->total : '—',
            $outcome ? $outcome->improved : '—', $outcome ? $outcome->reached : '—'];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}
echo html_writer::start_div('kbacorp-effectiveness-section kbacorp-effectiveness-section-role');
echo $OUTPUT->heading(get_string('developmenteffectivenessbyrole', 'local_kbacorporate'), 3);
echo html_writer::div(get_string('developmenteffectivenessmultiplerolesnote', 'local_kbacorporate'), 'alert alert-info');
$renderbreakdown = static function(array $rows) {
    global $OUTPUT;
    if (!$rows) {
        return $OUTPUT->notification(get_string('nodevelopmentbreakdown', 'local_kbacorporate'), 'info');
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-development-breakdown';
    $table->head = [get_string('name'), get_string('developmentmetricplans', 'local_kbacorporate'),
        get_string('developmentmetricactive', 'local_kbacorporate'),
        get_string('developmentmetriccompleted', 'local_kbacorporate'),
        get_string('developmentmetricprogress', 'local_kbacorporate'),
        get_string('developmentmetricoverdue', 'local_kbacorporate'),
        get_string('developmentmetricimproved', 'local_kbacorporate'),
        get_string('developmentmetrictargets', 'local_kbacorporate')];
    foreach ($rows as $row) {
        $table->data[] = [$row->name, $row->plans, $row->active, $row->completed,
            $row->progress . '%', $row->overdue, $row->improved, $row->targetsreached];
    }
    return html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
};
echo $renderbreakdown($breakdowns->roles);
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-effectiveness-section kbacorp-effectiveness-section-department');
echo $OUTPUT->heading(get_string('developmenteffectivenessbydepartment', 'local_kbacorporate'), 3);
echo $renderbreakdown($breakdowns->departments);
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-effectiveness-section kbacorp-effectiveness-section-timeline');
echo $OUTPUT->heading(get_string('developmenteffectivenesstimeline', 'local_kbacorporate'), 3);
echo html_writer::div(get_string('developmenteffectivenesstimelinedesc', 'local_kbacorporate'), 'alert alert-info');
if (!$timeline->periods) {
    echo $OUTPUT->notification(get_string('nodevelopmenttimeline', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-development-timeline';
    $table->head = [get_string('effectivenessperiod', 'local_kbacorporate'),
        get_string('timelineassessed', 'local_kbacorporate'), get_string('timelinecomparable', 'local_kbacorporate'),
        get_string('timelineaveragebefore', 'local_kbacorporate'), get_string('timelineaverageafter', 'local_kbacorporate'),
        get_string('timelineaveragegrowth', 'local_kbacorporate'),
        get_string('developmentmetricimproved', 'local_kbacorporate'),
        get_string('developmentmetrictargets', 'local_kbacorporate')];
    foreach ($timeline->periods as $item) {
        $table->data[] = [userdate($item->periodtime, '%B %Y'), $item->assessed, $item->comparable,
            $item->averagebefore === null ? '—' : $item->averagebefore,
            $item->averageafter === null ? '—' : $item->averageafter,
            $item->averagegrowth === null ? '—' : (($item->averagegrowth > 0 ? '+' : '') . $item->averagegrowth),
            $item->improved, $item->reached];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}
echo html_writer::end_div();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php', [
    'companyid' => $companyid,
]), get_string('backtodevelopmentplans', 'local_kbacorporate')), 'mt-3');
echo $OUTPUT->footer();
