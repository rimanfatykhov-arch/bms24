<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);

$companyid = optional_param('companyid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$scope = optional_param('scope', \local_kbacorporate\local\employee_assessment_history::SCOPE_PROFILE,
    PARAM_ALPHANUMEXT);
$source = optional_param('source', \local_kbacorporate\local\employee_assessment_history::SOURCE_ALL,
    PARAM_ALPHANUMEXT);
$status = optional_param('status', \local_kbacorporate\local\employee_assessment_history::STATUS_ALL,
    PARAM_ALPHANUMEXT);
$trend = optional_param('trend', \local_kbacorporate\local\employee_assessment_history::TREND_ALL,
    PARAM_ALPHANUMEXT);
$datefrom = trim((string)optional_param('datefrom', '', PARAM_ALPHANUMEXT));
$dateuntil = trim((string)optional_param('dateuntil', '', PARAM_ALPHANUMEXT));
$query = trim((string)optional_param('q', '', PARAM_TEXT));
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid <= 0) {
    throw new invalid_parameter_exception('Company is required.');
}
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$fullaccess = \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id);

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id);
$employees = [];
if ($visibleids) {
    list($insql, $params) = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'eahuser');
    $employees = array_values($DB->get_records_select('user',
        "id {$insql} AND deleted = 0", $params, 'lastname, firstname',
        'id,firstname,lastname,email'));
}
$employeeoptions = [0 => get_string('employeeassessmenthistorychooseemployee', 'local_kbacorporate')];
foreach ($employees as $employeeoption) {
    $employeeoptions[(int)$employeeoption->id] = fullname($employeeoption) .
        ($employeeoption->email ? ' — ' . $employeeoption->email : '');
}
if ($userid > 0 && !isset($employeeoptions[$userid])) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

$urlparams = ['companyid' => $companyid, 'userid' => $userid];
foreach (['scope' => $scope, 'source' => $source, 'status' => $status, 'trend' => $trend] as $name => $value) {
    if ($value !== 'all' && !($name === 'scope' && $value === 'profile')) {
        $urlparams[$name] = $value;
    }
}
if ($datefrom !== '') {
    $urlparams['datefrom'] = $datefrom;
}
if ($dateuntil !== '') {
    $urlparams['dateuntil'] = $dateuntil;
}
if ($query !== '') {
    $urlparams['q'] = $query;
}
if ($page > 0) {
    $urlparams['page'] = $page;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employee_assessment_history.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('employeeassessmenthistory', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
];
if ($userid > 0) {
    if (has_capability('local/kbacorporate:viewemployees', $context)) {
        $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('employeecard', 'local_kbacorporate'));
    }
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeecompetencyprofile', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegap', 'local_kbacorporate'));
    if ($fullaccess && has_capability('local/kbacorporate:managecompetencyaudits', $context)) {
        $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('competencyaudits', 'local_kbacorporate'));
    }
}
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

$source_label = static function(string $value): string {
    $key = 'employeeprofilesource_' . $value;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : $value;
};
$status_label = static function(string $value): string {
    $key = 'employeeprofilehistorystatus_' . $value;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : $value;
};
$trend_label = static function(string $value, ?int $delta = null): string {
    $key = 'assessmenttrend_' . $value;
    if ($value === \local_kbacorporate\local\employee_assessment_history::TREND_IMPROVED && $delta !== null) {
        return get_string($key, 'local_kbacorporate', '+' . $delta);
    }
    if ($value === \local_kbacorporate\local\employee_assessment_history::TREND_DECLINED && $delta !== null) {
        return get_string($key, 'local_kbacorporate', (string)$delta);
    }
    return get_string($key, 'local_kbacorporate');
};


echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('employeeassessmenthistory', 'local_kbacorporate'));
echo html_writer::div(get_string('employeeassessmenthistorydesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_tag('form', [
    'method' => 'get', 'class' => 'kbacorp-toolbar kbacorp-employee-profile-selector',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'employee-assessment-history-userid');
echo html_writer::select($employeeoptions, 'userid', $userid, false, [
    'id' => 'employee-assessment-history-userid',
]);
echo html_writer::tag('button', get_string('employeeassessmenthistoryopen', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($userid <= 0) {
    echo $OUTPUT->notification(get_string('employeeassessmenthistorychooseinfo', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,email,department', MUST_EXIST);
$projection = \local_kbacorporate\local\employee_assessment_history::build($companyid, $userid);

$fromtime = \local_kbacorporate\local\employee_assessment_history::parse_date($datefrom, false);
$totime = \local_kbacorporate\local\employee_assessment_history::parse_date($dateuntil, true);
$baseevents = \local_kbacorporate\local\employee_assessment_history::filter(
    $projection->events, $scope, $source, $status,
    \local_kbacorporate\local\employee_assessment_history::TREND_ALL,
    $fromtime, $totime, $query
);
$dynamictrends = \local_kbacorporate\local\employee_assessment_history::build_trends($baseevents);
$trendmap = [];
foreach ($dynamictrends as $dynamictrend) {
    $trendmap[(int)$dynamictrend->competencyid] = (string)$dynamictrend->trend;
}
foreach ($baseevents as $event) {
    $event->trend = $trendmap[(int)$event->competencyid] ??
        \local_kbacorporate\local\employee_assessment_history::TREND_NO_COMPARISON;
}
$events = \local_kbacorporate\local\employee_assessment_history::filter(
    $baseevents, \local_kbacorporate\local\employee_assessment_history::SCOPE_ALL,
    \local_kbacorporate\local\employee_assessment_history::SOURCE_ALL,
    \local_kbacorporate\local\employee_assessment_history::STATUS_ALL,
    $trend, 0, 0, ''
);
$trends = \local_kbacorporate\local\employee_assessment_history::filter_trends($dynamictrends, $trend);
$summary = \local_kbacorporate\local\employee_assessment_history::summarise($events, $trends);


echo html_writer::start_div('kbacorp-employee-profile-hero');
echo html_writer::start_div('kbacorp-employee-profile-identity');
echo html_writer::tag('h3', s(fullname($employee)), ['class' => 'kbacorp-employee-profile-name']);
$meta = [];
if ($employee->email !== '') {
    $meta[] = s($employee->email);
}
if ($employee->department !== '') {
    $meta[] = s($employee->department);
}
if ($meta) {
    echo html_writer::div(implode(' · ', $meta), 'kbacorp-muted');
}
if ($projection->profile->roles) {
    $roles = [];
    foreach ($projection->profile->roles as $role) {
        $roles[] = format_string((string)$role->name);
    }
    echo html_writer::div(html_writer::tag('strong',
        get_string('employeegapassignedroles', 'local_kbacorporate') . ': ') . implode(', ', $roles),
        'kbacorp-employee-profile-roles');
}
echo html_writer::end_div();
$stateclass = $summary->pending > 0 ? 'is-attention' : ($summary->events > 0 ? 'is-ready' : 'is-empty');
$statelabel = $summary->events > 0
    ? get_string('employeeassessmenthistorystate', 'local_kbacorporate', $summary->events)
    : get_string('employeeassessmenthistoryempty', 'local_kbacorporate');
echo html_writer::div(s($statelabel), 'kbacorp-employee-profile-state ' . $stateclass);
echo html_writer::end_div();

$formatdate = static function(int $time): string {
    return $time > 0 ? userdate($time, get_string('strftimedateshort', 'langconfig')) : '—';
};
$cards = [
    [$summary->events, get_string('assessmenthistoryevents', 'local_kbacorporate')],
    [$summary->competencies, get_string('assessmenthistorycompetencies', 'local_kbacorporate')],
    [$summary->confirmed + $summary->recorded,
        get_string('assessmenthistoryaccepted', 'local_kbacorporate')],
    [$summary->pending, get_string('assessmenthistorypending', 'local_kbacorporate')],
    [$summary->rejected + $summary->missing,
        get_string('assessmenthistorynotaccepted', 'local_kbacorporate')],
    [$summary->assessors, get_string('assessmenthistoryassessors', 'local_kbacorporate')],
    [$formatdate($summary->firsttime), get_string('assessmenthistoryfirstdate', 'local_kbacorporate')],
    [$formatdate($summary->latesttime), get_string('assessmenthistorylatestdate', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::div(s((string)$card[0]), 'kbacorp-metric-value') .
        html_writer::div(s((string)$card[1]), 'kbacorp-metric-label'),
        'kbacorp-metric-card'
    );
}
echo html_writer::end_div();

$trendcards = [
    [$summary->improved, get_string('assessmenttrend_improved_short', 'local_kbacorporate')],
    [$summary->unchanged, get_string('assessmenttrend_unchanged', 'local_kbacorporate')],
    [$summary->declined, get_string('assessmenttrend_declined_short', 'local_kbacorporate')],
    [$summary->single + $summary->nocomparison,
        get_string('assessmenttrend_nocomparison_short', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4 kbacorp-assessment-trend-metrics');
foreach ($trendcards as $card) {
    echo html_writer::div(
        html_writer::div(s((string)$card[0]), 'kbacorp-metric-value') .
        html_writer::div(s((string)$card[1]), 'kbacorp-metric-label'),
        'kbacorp-metric-card'
    );
}
echo html_writer::end_div();

$scopeoptions = [
    \local_kbacorporate\local\employee_assessment_history::SCOPE_PROFILE =>
        get_string('assessmenthistoryscope_profile', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_assessment_history::SCOPE_ALL =>
        get_string('assessmenthistoryscope_all', 'local_kbacorporate'),
];
$sourceoptions = [
    'all' => get_string('assessmenthistoryallsources', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AUDIT =>
        get_string('employeeprofilesource_audit', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AI =>
        get_string('employeeprofilesource_ai', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_MOODLE =>
        get_string('employeeprofilesource_moodle', 'local_kbacorporate'),
];
$statusoptions = [
    'all' => get_string('allstatuses', 'local_kbacorporate'),
    'confirmed' => $status_label('confirmed'),
    'recorded' => $status_label('recorded'),
    'pending' => $status_label('pending'),
    'rejected' => $status_label('rejected'),
    'missing' => $status_label('missing'),
];
$trendoptions = [
    'all' => get_string('assessmenttrend_all', 'local_kbacorporate'),
    'improved' => get_string('assessmenttrend_improved_short', 'local_kbacorporate'),
    'unchanged' => get_string('assessmenttrend_unchanged', 'local_kbacorporate'),
    'declined' => get_string('assessmenttrend_declined_short', 'local_kbacorporate'),
    'single' => get_string('assessmenttrend_single', 'local_kbacorporate'),
    'nocomparison' => get_string('assessmenttrend_nocomparison', 'local_kbacorporate'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters kbacorp-assessment-history-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
echo html_writer::select($scopeoptions, 'scope', $scope, false, [
    'aria-label' => get_string('assessmenthistoryscope', 'local_kbacorporate'),
]);
echo html_writer::select($sourceoptions, 'source', $source, false, [
    'aria-label' => get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
]);
echo html_writer::select($statusoptions, 'status', $status, false, [
    'aria-label' => get_string('status'),
]);
echo html_writer::select($trendoptions, 'trend', $trend, false, [
    'aria-label' => get_string('assessmenthistorytrend', 'local_kbacorporate'),
]);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'datefrom', 'value' => $datefrom,
    'aria-label' => get_string('assessmenthistorydatefrom', 'local_kbacorporate'),
    'title' => get_string('assessmenthistorydatefrom', 'local_kbacorporate'),
]);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'dateuntil', 'value' => $dateuntil,
    'aria-label' => get_string('assessmenthistorydateuntil', 'local_kbacorporate'),
    'title' => get_string('assessmenthistorydateuntil', 'local_kbacorporate'),
]);
echo html_writer::empty_tag('input', [
    'type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('assessmenthistorysearch', 'local_kbacorporate'),
]);
echo html_writer::tag('button', get_string('apply', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
    'companyid' => $companyid, 'userid' => $userid,
]), get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$actionlinks = [];
if (has_capability('local/kbacorporate:exportreport', $context)) {
    $exportparams = $urlparams;
    unset($exportparams['page']);
    $actionlinks[] = html_writer::link(new moodle_url(
        '/local/kbacorporate/employee_assessment_history_export.php', $exportparams
    ), get_string('assessmenthistoryexportcsv', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
}
if ($actionlinks) {
    echo html_writer::div(implode(' ', $actionlinks), 'kbacorp-actions mb-3');
}

echo html_writer::div(get_string('assessmenthistoryfiltered', 'local_kbacorporate', (object)[
    'events' => count($events), 'competencies' => count($trends),
]), 'kbacorp-muted mb-3');

// Longitudinal comparison by competency.
echo $OUTPUT->heading(get_string('assessmenthistorydynamics', 'local_kbacorporate'), 3);
if (!$trends) {
    echo $OUTPUT->notification(get_string('assessmenthistorynodynamics', 'local_kbacorporate'), 'info');
} else {
    $trendtable = new html_table();
    $trendtable->attributes['class'] = 'generaltable kbacorp-report kbacorp-assessment-trend-table';
    $trendtable->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('assessmenthistoryfirstassessment', 'local_kbacorporate'),
        get_string('assessmenthistorylatestassessment', 'local_kbacorporate'),
        get_string('assessmenthistorychange', 'local_kbacorporate'),
        get_string('assessmenthistoryassessmentcount', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('competencygap', 'local_kbacorporate'),
        get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($trends as $row) {
        $competencyhtml = html_writer::tag('strong', s((string)$row->competencyshortname));
        if ($row->competencyidnumber !== '') {
            $competencyhtml .= html_writer::div(s((string)$row->competencyidnumber), 'text-muted small');
        }
        $first = $row->firstlevel === null ? '—' :
            \local_kbacorporate\local\competencies::level_label((int)$row->firstlevel, (int)$row->scaleid);
        if ($row->firsttime > 0) {
            $first .= html_writer::div(userdate((int)$row->firsttime,
                get_string('strftimedateshort', 'langconfig')), 'text-muted small');
        }
        $latest = $row->latestlevel === null ? '—' :
            \local_kbacorporate\local\competencies::level_label((int)$row->latestlevel, (int)$row->scaleid);
        if ($row->latesttime > 0) {
            $latest .= html_writer::div(userdate((int)$row->latesttime,
                get_string('strftimedateshort', 'langconfig')), 'text-muted small');
        }
        $trendclass = 'badge badge-light';
        if ($row->trend === 'improved') {
            $trendclass = 'badge badge-success';
        } else if ($row->trend === 'declined') {
            $trendclass = 'badge badge-danger';
        } else if ($row->trend === 'unchanged') {
            $trendclass = 'badge badge-info';
        }
        $change = html_writer::span(s($trend_label((string)$row->trend, $row->delta)), $trendclass);
        $target = $row->targetlevel === null ? '—' :
            \local_kbacorporate\local\competencies::level_label((int)$row->targetlevel, (int)$row->scaleid);
        $gap = $row->gap === null ? '—' : (string)$row->gap;
        $latestsource = $source_label((string)$row->latestsource);
        if ($row->latestactorname !== '') {
            $latestsource .= html_writer::div(s((string)$row->latestactorname), 'text-muted small');
        }
        $historyurl = new moodle_url('/local/kbacorporate/employee_competency_history.php', [
            'companyid' => $companyid, 'userid' => $userid,
            'competencyid' => (int)$row->competencyid,
        ]);
        $trendtable->data[] = [
            $competencyhtml,
            $first,
            $latest,
            $change,
            (string)$row->comparableevents,
            s($target),
            s($gap),
            $latestsource,
            html_writer::link($historyurl, get_string('employeeprofileopenhistory', 'local_kbacorporate')),
        ];
    }
    echo html_writer::div(html_writer::table($trendtable), 'kbacorp-table-scroll');
}

// Detailed assessment event journal.
echo $OUTPUT->heading(get_string('assessmenthistoryjournal', 'local_kbacorporate'), 3);
if (!$events) {
    echo $OUTPUT->notification(get_string('employeeassessmenthistoryemptyfiltered', 'local_kbacorporate'), 'info');
} else {
    $total = count($events);
    $paged = array_slice($events, $page * $perpage, $perpage);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-assessment-history-table';
    $table->head = [
        get_string('date'),
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
        get_string('competencylevel', 'local_kbacorporate'),
        get_string('status'),
        get_string('employeeprofilehistoryactor', 'local_kbacorporate'),
        get_string('assessmenthistoryevent', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($paged as $event) {
        $date = (int)$event->timecreated > 0
            ? userdate((int)$event->timecreated, get_string('strftimedatetimeshort', 'langconfig')) : '—';
        $competencyhtml = html_writer::tag('strong', s((string)$event->competencyshortname));
        if ($event->competencyidnumber !== '') {
            $competencyhtml .= html_writer::div(s((string)$event->competencyidnumber), 'text-muted small');
        }
        $level = $event->level === null ? '—' :
            \local_kbacorporate\local\competencies::level_label((int)$event->level, (int)$event->scaleid);
        $eventhtml = html_writer::tag('strong', s((string)$event->title));
        if (trim((string)$event->details) !== '') {
            $eventhtml .= html_writer::div(s(shorten_text((string)$event->details, 240)), 'text-muted small');
        }
        $actions = [];
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_history.php', [
            'companyid' => $companyid, 'userid' => $userid,
            'competencyid' => (int)$event->competencyid,
        ]), get_string('employeeprofileopenhistory', 'local_kbacorporate'));
        if ((string)$event->url !== '') {
            $actions[] = html_writer::link((string)$event->url,
                get_string('open', 'local_kbacorporate'));
        }
        if ((string)$event->externalurl !== '') {
            $actions[] = html_writer::link((string)$event->externalurl,
                get_string('employeeprofileopenevidence', 'local_kbacorporate'),
                ['target' => '_blank', 'rel' => 'noopener']);
        }
        $table->data[] = [
            s($date),
            $competencyhtml,
            s($source_label((string)$event->source)),
            s($level),
            s($status_label((string)$event->status)),
            s((string)$event->actorname ?: '—'),
            $eventhtml,
            implode(' · ', $actions),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
    if ($total > $perpage) {
        $pagingparams = $urlparams;
        unset($pagingparams['page']);
        echo $OUTPUT->paging_bar($total, $page, $perpage,
            new moodle_url('/local/kbacorporate/employee_assessment_history.php', $pagingparams), 'page');
    }
}

echo html_writer::div(get_string('assessmenthistorysourcetruthnote', 'local_kbacorporate'),
    'kbacorp-info-note');
echo $navigation;
echo $OUTPUT->footer();
