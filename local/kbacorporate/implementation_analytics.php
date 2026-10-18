<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$hasassignments = $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]);
$canviewall = has_capability('local/kbacorporate:manageimplementationaudits', $context);
if (!$hasassignments && !$canviewall) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}

$companyid = optional_param('companyid', 0, PARAM_INT);
$curatorid = optional_param('curatorid', 0, PARAM_INT);
$period = optional_param('period', 'all', PARAM_ALPHANUM);
$query = trim(optional_param('q', '', PARAM_TEXT));
if (!in_array($period, \local_kbacorporate\local\implementation_analytics::PERIODS, true)) {
    $period = 'all';
}

$alltasks = \local_kbacorporate\local\implementation_analytics::get_task_rows(
    (int)$USER->id, $canviewall);
$companies = [];
$curators = [];
foreach ($alltasks as $task) {
    $companies[(int)$task->companyid] = format_string($task->companyname);
    $curator = clone $task;
    $curator->id = (int)$task->programcuratorid;
    $curator->firstname = $task->curatorfirstname;
    $curator->lastname = $task->curatorlastname;
    $curator->firstnamephonetic = $task->curatorfirstnamephonetic;
    $curator->lastnamephonetic = $task->curatorlastnamephonetic;
    $curator->middlename = $task->curatormiddlename;
    $curator->alternatename = $task->curatoralternatename;
    $curators[(int)$task->programcuratorid] = fullname($curator);
}
asort($companies, SORT_NATURAL | SORT_FLAG_CASE);
asort($curators, SORT_NATURAL | SORT_FLAG_CASE);

$tasks = \local_kbacorporate\local\implementation_analytics::get_task_rows(
    (int)$USER->id, $canviewall, $companyid, $curatorid, $period);
if ($query !== '') {
    $needle = core_text::strtolower($query);
    $tasks = array_values(array_filter($tasks, static function($task) use ($needle) {
        $curatorname = trim($task->curatorfirstname . ' ' . $task->curatorlastname);
        $haystack = core_text::strtolower($task->companyname . ' ' . $curatorname . ' ' . $task->title);
        return core_text::strpos($haystack, $needle) !== false;
    }));
}
$summary = \local_kbacorporate\local\implementation_analytics::summarize($tasks);
$groups = \local_kbacorporate\local\implementation_analytics::group_by_company_curator($tasks);
$trend = \local_kbacorporate\local\implementation_analytics::monthly_trend($tasks);
$params = ['q' => $query, 'companyid' => $companyid, 'curatorid' => $curatorid, 'period' => $period];

if (optional_param('export', '', PARAM_ALPHA) === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer('semicolon');
    $csv->set_filename('bms-implementation-effectiveness-' . userdate(time(), '%Y-%m-%d'));
    $csv->add_data([
        get_string('company', 'local_kbacorporate'),
        get_string('implementationcurator', 'local_kbacorporate'),
        get_string('implementationanalyticstasks', 'local_kbacorporate'),
        get_string('implementationanalyticscompleted', 'local_kbacorporate'),
        get_string('implementationanalyticscompletionrate', 'local_kbacorporate'),
        get_string('implementationanalyticsreview', 'local_kbacorporate'),
        get_string('implementationanalyticsoverdue', 'local_kbacorporate'),
        get_string('implementationanalyticschecklistrate', 'local_kbacorporate'),
        get_string('implementationanalyticscycle', 'local_kbacorporate'),
        get_string('currentbmsindex', 'local_kbacorporate'),
        get_string('implementationanalyticsindexdelta', 'local_kbacorporate'),
    ]);
    $safe = static function($value): string {
        $value = (string)$value;
        return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
    };
    foreach ($groups as $group) {
        $metrics = $group->summary;
        $csv->add_data(array_map($safe, [
            $group->companyname, $group->curatorname, $metrics->total, $metrics->completed,
            format_float($metrics->completionrate, 1) . '%', $metrics->review, $metrics->overdue,
            format_float($metrics->checklistrate, 1) . '%',
            $metrics->averagecycledays === null ? '' : format_float($metrics->averagecycledays, 1),
            $group->bmsindex === null ? '' : format_float($group->bmsindex, 1),
            $group->bmsindexdelta === null ? '' : format_float($group->bmsindexdelta, 1),
        ]));
    }
    $csv->add_data([]);
    $csv->add_data([get_string('implementationanalyticsdynamics', 'local_kbacorporate')]);
    $csv->add_data([
        get_string('implementationanalyticsmonth', 'local_kbacorporate'),
        get_string('implementationanalyticscreated', 'local_kbacorporate'),
        get_string('implementationanalyticscompleted', 'local_kbacorporate'),
        get_string('implementationanalyticscycle', 'local_kbacorporate'),
    ]);
    foreach ($trend as $month) {
        $csv->add_data([
            userdate($month->periodtime, '%B %Y'), $month->created, $month->completed,
            $month->averagecycledays === null ? '' : format_float($month->averagecycledays, 1),
        ]);
    }
    $csv->download_file();
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_analytics.php', $params));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('implementationanalytics', 'local_kbacorporate'));
$PAGE->set_heading(get_string('implementationanalytics', 'local_kbacorporate'));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation(0, 'analytics');
echo html_writer::start_div('kbacorp-curator-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('implementationanalytics', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('implementationanalyticsdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php'),
    get_string('openimplementationboard', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php',
    $companyid > 0 ? ['companyid' => $companyid] : []),
    get_string('implementationsectionprogress', 'local_kbacorporate'),
    ['class' => 'btn btn-outline-primary']);
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-analytics-filters']);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('implementationanalyticssearch', 'local_kbacorporate'), 'class' => 'form-control']);
echo html_writer::select([0 => get_string('allcompanies', 'local_kbacorporate')] + $companies,
    'companyid', $companyid, false, ['class' => 'custom-select']);
if ($canviewall) {
    echo html_writer::select([0 => get_string('allcurators', 'local_kbacorporate')] + $curators,
        'curatorid', $curatorid, false, ['class' => 'custom-select']);
}
$periods = [];
foreach (\local_kbacorporate\local\implementation_analytics::PERIODS as $periodkey) {
    $periods[$periodkey] = get_string('implementationanalyticsperiod' . $periodkey, 'local_kbacorporate');
}
echo html_writer::select($periods, 'period', $period, false, ['class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_analytics.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::start_div('kbacorp-analytics-summary');
$boardbase = ['q' => $query, 'companyid' => $companyid, 'curatorid' => $curatorid, 'period' => $period];
$cards = [
    [$summary->total, 'implementationanalyticstasks', 'total', []],
    [format_float($summary->completionrate, 1) . '%', 'implementationanalyticscompletionrate', 'completed',
        ['status' => 'completed']],
    [$summary->review, 'implementationanalyticsreview', 'review', ['status' => 'review']],
    [$summary->overdue, 'implementationanalyticsoverdue', 'overdue', ['scope' => 'overdue']],
    [$summary->averagecycledays === null ? '—' : format_float($summary->averagecycledays, 1),
        'implementationanalyticscycle', 'cycle', ['status' => 'completed']],
    [format_float($summary->checklistrate, 1) . '%', 'implementationanalyticschecklistrate', 'checklist', []],
];
foreach ($cards as [$value, $label, $accent, $drilldown]) {
    $cardurl = new moodle_url('/local/kbacorporate/implementation_board.php', $boardbase + $drilldown);
    echo html_writer::link($cardurl, html_writer::tag('strong', (string)$value) .
        html_writer::span(get_string($label, 'local_kbacorporate')),
        ['class' => 'kbacorp-analytics-card kbacorp-analytics-' . $accent]);
}
echo html_writer::end_div();

$exporturl = new moodle_url('/local/kbacorporate/implementation_analytics.php', $params + ['export' => 'csv']);
echo html_writer::div(html_writer::link($exporturl,
    get_string('implementationanalyticsexport', 'local_kbacorporate'), ['class' => 'btn btn-secondary']),
    'kbacorp-analytics-actions');

echo $OUTPUT->heading(get_string('implementationanalyticsdynamics', 'local_kbacorporate'), 3);
echo html_writer::tag('p', get_string('implementationanalyticsdynamicsdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
$trendtable = new html_table();
$trendtable->attributes['class'] = 'generaltable kbacorp-analytics-trend-table';
$trendtable->head = [
    get_string('implementationanalyticsmonth', 'local_kbacorporate'),
    get_string('implementationanalyticscreated', 'local_kbacorporate'),
    get_string('implementationanalyticscompleted', 'local_kbacorporate'),
    get_string('implementationanalyticscycle', 'local_kbacorporate'),
];
$trendmax = 1;
foreach ($trend as $month) {
    $trendmax = max($trendmax, (int)$month->created, (int)$month->completed);
}
foreach ($trend as $month) {
    $createdbar = html_writer::div(html_writer::span('', '', [
        'style' => 'width:' . round(((int)$month->created * 100) / $trendmax, 1) . '%',
    ]), 'kbacorp-trend-bar kbacorp-trend-created');
    $completedbar = html_writer::div(html_writer::span('', '', [
        'style' => 'width:' . round(((int)$month->completed * 100) / $trendmax, 1) . '%',
    ]), 'kbacorp-trend-bar kbacorp-trend-completed');
    $trendtable->data[] = [
        userdate($month->periodtime, '%B %Y'),
        (int)$month->created . $createdbar,
        (int)$month->completed . $completedbar,
        $month->averagecycledays === null ? '—' :
            get_string('implementationanalyticsdays', 'local_kbacorporate',
                format_float($month->averagecycledays, 1)),
    ];
}
echo html_writer::div(html_writer::table($trendtable), 'table-responsive');

echo $OUTPUT->heading(get_string('implementationanalyticscomparison', 'local_kbacorporate'), 3);

if (!$groups) {
    echo $OUTPUT->notification(get_string('implementationanalyticsempty', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-analytics-table';
    $table->head = [
        get_string('company', 'local_kbacorporate'),
        get_string('implementationcurator', 'local_kbacorporate'),
        get_string('implementationanalyticstasks', 'local_kbacorporate'),
        get_string('implementationanalyticscompleted', 'local_kbacorporate'),
        get_string('implementationanalyticsreview', 'local_kbacorporate'),
        get_string('implementationanalyticsoverdue', 'local_kbacorporate'),
        get_string('implementationanalyticschecklist', 'local_kbacorporate'),
        get_string('implementationanalyticscycle', 'local_kbacorporate'),
        get_string('currentbmsindex', 'local_kbacorporate'),
        get_string('implementationanalyticsindexdelta', 'local_kbacorporate'),
    ];
    foreach ($groups as $group) {
        $metrics = $group->summary;
        $taskbase = ['companyid' => $group->companyid, 'curatorid' => $group->curatorid,
            'period' => $period];
        $progress = html_writer::div(
            html_writer::div(format_float($metrics->completionrate, 1) . '%', 'kbacorp-analytics-progress-value') .
            html_writer::div(html_writer::span('', '', [
                'style' => 'width:' . min(100, $metrics->completionrate) . '%',
            ]), 'kbacorp-analytics-progressbar'));
        $checklist = $metrics->checklisttotal
            ? $metrics->checklistdone . '/' . $metrics->checklisttotal . ' · ' .
                format_float($metrics->checklistrate, 1) . '%'
            : '—';
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacorporate/implementation.php', [
                'companyid' => $group->companyid,
            ]), format_string($group->companyname)),
            s($group->curatorname),
            html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php', $taskbase),
                (string)$metrics->total),
            html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php',
                $taskbase + ['status' => 'completed']), (string)$metrics->completed) .
                html_writer::div($progress, 'kbacorp-analytics-progress'),
            html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php',
                $taskbase + ['status' => 'review']), (string)$metrics->review),
            html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php',
                $taskbase + ['scope' => 'overdue']), html_writer::span((string)$metrics->overdue,
                $metrics->overdue ? 'kbacorp-analytics-overdue' : '')),
            $checklist,
            $metrics->averagecycledays === null ? '—' :
                get_string('implementationanalyticsdays', 'local_kbacorporate',
                    format_float($metrics->averagecycledays, 1)),
            $group->bmsindex === null ? '—' : format_float($group->bmsindex, 1),
            $group->bmsindexdelta === null ? '—' : html_writer::span(
                ($group->bmsindexdelta > 0 ? '+' : '') . format_float($group->bmsindexdelta, 1),
                $group->bmsindexdelta > 0 ? 'kbacorp-index-delta-positive' :
                    ($group->bmsindexdelta < 0 ? 'kbacorp-index-delta-negative' : '')),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}
echo $OUTPUT->footer();
