<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewtrainingtime', $context);

$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'week', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) {
    $userid = 0;
}
$period = \local_kbacorporate\local\trainingtime::period_for_preset($preset, $from, $to);
$report = \local_kbacorporate\local\trainingtime::build_report(
    $companyid, $period->start, $period->endexclusive, $userid, $visibleids
);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/trainingtime.php', [
    'companyid' => $companyid,
    'preset' => $period->preset,
    'from' => $period->from,
    'to' => $period->to,
    'userid' => $userid,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('trainingtimenav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('trainingtimenav', 'local_kbacorporate'));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$hours = function($minutes) {
    if ($minutes === null) {
        return '—';
    }
    return format_float(((float)$minutes) / 60, 2) . ' ' . get_string('hoursabbr', 'local_kbacorporate');
};
$signedhours = function($minutes) {
    if ($minutes === null) {
        return '—';
    }
    $value = ((float)$minutes) / 60;
    $prefix = $value > 0 ? '+' : '';
    return $prefix . format_float($value, 2) . ' ' . get_string('hoursabbr', 'local_kbacorporate');
};
$varianceclass = function($minutes) {
    if ((float)$minutes > 0) {
        return 'kbacorp-variance-positive';
    }
    if ((float)$minutes < 0) {
        return 'kbacorp-variance-negative';
    }
    return 'kbacorp-variance-neutral';
};

$employeeoptions = [0 => get_string('allemployees', 'local_kbacorporate')];
foreach (\local_kbacorporate\local\report::get_employees($companyid, $visibleids) as $employee) {
    $employeeoptions[(int)$employee->id] = fullname($employee);
}

$weekdaylabels = [
    1 => get_string('weekday1', 'local_kbacorporate'),
    2 => get_string('weekday2', 'local_kbacorporate'),
    3 => get_string('weekday3', 'local_kbacorporate'),
    4 => get_string('weekday4', 'local_kbacorporate'),
    5 => get_string('weekday5', 'local_kbacorporate'),
    6 => get_string('weekday6', 'local_kbacorporate'),
    7 => get_string('weekday7', 'local_kbacorporate'),
];
$plandays = [];
foreach ($report->weekdays as $day) {
    if (isset($weekdaylabels[$day])) {
        $plandays[] = $weekdaylabels[$day];
    }
}

$presetoptions = [
    'week' => get_string('periodweek', 'local_kbacorporate'),
    'month' => get_string('periodmonth', 'local_kbacorporate'),
    'quarter' => get_string('periodquarter', 'local_kbacorporate'),
    'custom' => get_string('periodcustom', 'local_kbacorporate'),
];

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    'kbacorp-backlink'
);
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('trainingtimepagedesc2', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/trainingtime.php', ['preset' => $period->preset, 'from' => $period->from, 'to' => $period->to, 'userid' => $userid]);

// Period and employee filters.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/trainingtime.php'))->out(false),
    'class' => 'kbacorp-filters kbacorp-time-filters',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
echo html_writer::label(get_string('periodtype', 'local_kbacorporate'), 'kbacorp-time-preset', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($presetoptions, 'preset', $period->preset, false, [
    'id' => 'kbacorp-time-preset', 'class' => 'custom-select',
]);
echo html_writer::label(get_string('periodfrom', 'local_kbacorporate'), 'kbacorp-time-from', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'id' => 'kbacorp-time-from', 'name' => 'from', 'value' => $period->from,
    'class' => 'form-control',
]);
echo html_writer::label(get_string('periodto', 'local_kbacorporate'), 'kbacorp-time-to', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'id' => 'kbacorp-time-to', 'name' => 'to', 'value' => $period->to,
    'class' => 'form-control',
]);
echo html_writer::label(get_string('fullname', 'local_kbacorporate'), 'kbacorp-time-user', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($employeeoptions, 'userid', $userid, false, [
    'id' => 'kbacorp-time-user', 'class' => 'custom-select',
]);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

echo html_writer::div(get_string('periodresolved', 'local_kbacorporate', (object)[
    'from' => userdate($period->start, get_string('strftimedateshort', 'langconfig')),
    'to' => userdate($period->endexclusive - DAYSECS, get_string('strftimedateshort', 'langconfig')),
    'days' => $period->days,
]), 'kbacorp-period-resolved');

// Export and management toolbar.
echo html_writer::start_div('kbacorp-toolbar');
if (has_capability('local/kbacorporate:exportreport', $context)) {
    $baseexport = [
        'companyid' => $companyid,
        'preset' => $period->preset,
        'from' => $period->from,
        'to' => $period->to,
        'userid' => $userid,
        'departmentid' => $departmentid,
    ];
    foreach ([
        'employees' => 'exporttimeemployees',
        'weeks' => 'exporttimeweeks',
        'courses' => 'exporttimecourses',
        'days' => 'exporttimedays',
    ] as $scope => $stringkey) {
        $params = $baseexport;
        $params['scope'] = $scope;
        echo $OUTPUT->single_button(
            new moodle_url('/local/kbacorporate/export_time.php', $params),
            get_string($stringkey, 'local_kbacorporate'),
            'get'
        );
    }
}
if (has_capability('local/kbacorporate:managecompanies', $context)) {
    echo $OUTPUT->single_button(
        new moodle_url('/local/kbacorporate/company_edit.php', ['id' => $companyid]),
        get_string('edittrainingplan', 'local_kbacorporate'),
        'get'
    );
}
echo html_writer::end_div();

$plantext = get_string('trainingplanline', 'local_kbacorporate', (object)[
    'hours' => format_float($report->dailyplanminutes / 60, 2),
    'days' => $plandays ? implode(', ', $plandays) : get_string('nodaysselected', 'local_kbacorporate'),
]);
echo html_writer::div($plantext, 'kbacorp-info-note');

if (!$report->asyncavailable) {
    echo $OUTPUT->notification(get_string('standardlognotavailable', 'local_kbacorporate'), 'warning');
}
if (!$report->bbbavailable) {
    echo $OUTPUT->notification(get_string('bbbnotavailable', 'local_kbacorporate'), 'info');
}

// KPI cards.
$summary = $report->summary;
$variance = $signedhours($summary->varianceminutes);
$cards = [
    [get_string('employees', 'local_kbacorporate'), $summary->employees, ''],
    [get_string('trainingplanhours', 'local_kbacorporate'), $hours($summary->planminutes), ''],
    [get_string('trainingfacthours', 'local_kbacorporate'), $hours($summary->totalminutes), ''],
    [get_string('trainingplanpercent', 'local_kbacorporate'),
        $summary->percent === null ? '—' : format_float($summary->percent, 1) . '%', ''],
    [get_string('trainingvariance', 'local_kbacorporate'), $variance, $varianceclass($summary->varianceminutes)],
    [get_string('trainingavgemployee', 'local_kbacorporate'), $hours($summary->avgminutes), ''],
    [get_string('belowplanemployees', 'local_kbacorporate'), $summary->belowplanemployees, ''],
];
echo html_writer::start_div('kbacorp-summary kbacorp-time-summary');
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::div(s((string)$card[1]), 'value ' . $card[2]) . html_writer::div(s($card[0]), 'label'),
        'kbacorp-card'
    );
}
echo html_writer::end_div();

// Data-quality explanation.
echo html_writer::start_div('kbacorp-time-method');
echo html_writer::div(get_string('trainingtimemethodtitle', 'local_kbacorporate'), 'kbacorp-time-method-title');
echo html_writer::div(get_string('trainingtimemethod', 'local_kbacorporate'), 'kbacorp-time-method-text');
echo html_writer::end_div();

// Weekly dynamics.
echo $OUTPUT->heading(get_string('trainingbyweek', 'local_kbacorporate'), 3);
if (!$report->weeks) {
    echo $OUTPUT->notification(get_string('nodata', 'local_kbacorporate'), 'info');
} else {
    $weektable = new html_table();
    $weektable->attributes['class'] = 'generaltable kbacorp-report kbacorp-time-table';
    $weektable->head = [
        get_string('weekperiod', 'local_kbacorporate'),
        get_string('trainingplanhours', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('trainingvariance', 'local_kbacorporate'),
        get_string('trainingplanpercent', 'local_kbacorporate'),
    ];
    foreach ($report->weeks as $week) {
        $label = userdate($week->weekstart, get_string('strftimedateshort', 'langconfig')) . ' — ' .
            userdate($week->weekend, get_string('strftimedateshort', 'langconfig'));
        $percent = $week->percent === null ? '—' : format_float($week->percent, 1) . '%';
        $weektable->data[] = [
            s($label),
            $hours($week->planminutes),
            $hours($week->asyncminutes),
            $hours($week->bbbminutes),
            html_writer::tag('strong', s($hours($week->totalminutes))),
            html_writer::span(s($signedhours($week->varianceminutes)), $varianceclass($week->varianceminutes)),
            html_writer::span(s($percent), 'kbacorp-time-percent'),
        ];
    }
    echo html_writer::table($weektable);
}

// Per employee summary.
echo $OUTPUT->heading(get_string('trainingbyemployee', 'local_kbacorporate'), 3);
if (!$report->employees) {
    echo $OUTPUT->notification(get_string('noemployees', 'local_kbacorporate'), 'info');
} else {
    $employeetable = new html_table();
    $employeetable->attributes['class'] = 'generaltable kbacorp-report kbacorp-time-table';
    $employeetable->head = [
        get_string('fullname', 'local_kbacorporate'),
        get_string('position', 'local_kbacorporate'),
        get_string('trainingplanhours', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('trainingvariance', 'local_kbacorporate'),
        get_string('trainingplanpercent', 'local_kbacorporate'),
        get_string('planstatus', 'local_kbacorporate'),
    ];
    foreach ($report->employees as $row) {
        $userurl = new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid, 'userid' => $row->userid,
        ]);
        $percent = $row->percent === null ? '—' : format_float($row->percent, 1) . '%';
        $employeetable->data[] = [
            html_writer::link($userurl, s($row->fullname)),
            s($row->position ?: '—'),
            $hours($row->planminutes),
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            html_writer::tag('strong', s($hours($row->totalminutes))),
            html_writer::span(s($signedhours($row->varianceminutes)), $varianceclass($row->varianceminutes)),
            html_writer::span(s($percent), 'kbacorp-time-percent'),
            html_writer::span(
                get_string($row->planstate, 'local_kbacorporate'),
                'kbacorp-planstate kbacorp-planstate-' . $row->planstate
            ),
        ];
    }
    echo html_writer::table($employeetable);
}

// Actual time by corporate course.
if ($report->courses) {
    echo $OUTPUT->heading(get_string('trainingbycourse', 'local_kbacorporate'), 3);
    $coursetable = new html_table();
    $coursetable->attributes['class'] = 'generaltable kbacorp-report kbacorp-time-table';
    $coursetable->head = [
        get_string('course', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('timeshare', 'local_kbacorporate'),
    ];
    foreach ($report->courses as $row) {
        $coursetable->data[] = [
            html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            html_writer::tag('strong', s($hours($row->totalminutes))),
            format_float($row->sharepercent, 1) . '%',
        ];
    }
    echo html_writer::table($coursetable);
}

// Daily detail, preserving the historic spreadsheet-style view.
echo $OUTPUT->heading(get_string('trainingbyday', 'local_kbacorporate'), 3);
$dailytable = new html_table();
$dailytable->attributes['class'] = 'generaltable kbacorp-report kbacorp-time-table';
$dailytable->head = [
    get_string('date'),
    get_string('trainingplanhours', 'local_kbacorporate'),
    get_string('moodleestimatedhours', 'local_kbacorporate'),
    get_string('webinarhours', 'local_kbacorporate'),
    get_string('trainingfacthours', 'local_kbacorporate'),
    get_string('cumulativeplan', 'local_kbacorporate'),
    get_string('cumulativefact', 'local_kbacorporate'),
];
foreach ($report->days as $day) {
    $dailytable->data[] = [
        userdate($day->timestamp, get_string('strftimedateshort', 'langconfig')),
        $hours($day->planminutes),
        $hours($day->asyncminutes),
        $hours($day->bbbminutes),
        html_writer::tag('strong', s($hours($day->totalminutes))),
        $hours($day->cumulativeplanminutes),
        html_writer::tag('strong', s($hours($day->cumulativetotalminutes))),
    ];
}
echo html_writer::table($dailytable);

echo $OUTPUT->footer();
