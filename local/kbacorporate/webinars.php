<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewattendance', $context);
$companyid = required_param('companyid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$state = optional_param('state', '', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$validstates = ['', 'attended', 'partial', 'missed', 'upcoming', 'nodata'];
if (!in_array($state, $validstates, true)) {
    $state = '';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/webinars.php', [
    'companyid' => $companyid,
    'courseid' => $courseid,
    'userid' => $userid,
    'state' => $state,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('webinarsnav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('webinarsnav', 'local_kbacorporate'));

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    'kbacorp-backlink'
);
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('webinarspagedesc_v070', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/webinars.php', ['courseid' => $courseid, 'userid' => $userid, 'state' => $state]);

if (!\local_kbacorporate\local\bigbluebutton::is_available()) {
    echo $OUTPUT->notification(get_string('bbbnotavailable', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$activities = \local_kbacorporate\local\bigbluebutton::get_company_activities($companyid);
if (!$activities) {
    echo $OUTPUT->notification(get_string('nobbbwebinars', 'local_kbacorporate'), 'info');
    echo html_writer::div(get_string('nobbbwebinarshint', 'local_kbacorporate'), 'kbacorp-empty-hint');
    echo $OUTPUT->footer();
    exit;
}

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$allrows = \local_kbacorporate\local\bigbluebutton::get_company_rows($companyid, $visibleids);
$rows = [];
foreach ($allrows as $row) {
    if ($courseid && (int)$row->courseid !== $courseid) {
        continue;
    }
    if ($userid && (int)$row->userid !== $userid) {
        continue;
    }
    if ($state !== '' && $row->state !== $state) {
        continue;
    }
    $rows[] = $row;
}

$courses = [];
$employees = [];
foreach ($activities as $activity) {
    $courses[$activity->courseid] = format_string($activity->coursename);
}
foreach (\local_kbacorporate\local\report::get_employees($companyid, $visibleids) as $employee) {
    $employees[$employee->id] = fullname($employee);
}
asort($courses);
asort($employees);

// Read-only filters.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/webinars.php'))->out(false),
    'class' => 'kbacorp-filters',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
$courseoptions = [0 => get_string('allcourses', 'local_kbacorporate')] + $courses;
echo html_writer::label(get_string('course', 'local_kbacorporate'), 'kbacorp-webinar-course-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($courseoptions, 'courseid', $courseid, false,
    ['id' => 'kbacorp-webinar-course-filter', 'class' => 'custom-select']);
$useroptions = [0 => get_string('allemployees', 'local_kbacorporate')] + $employees;
echo html_writer::label(get_string('fullname', 'local_kbacorporate'), 'kbacorp-webinar-user-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($useroptions, 'userid', $userid, false,
    ['id' => 'kbacorp-webinar-user-filter', 'class' => 'custom-select']);
$stateoptions = [
    '' => get_string('allstatuses', 'local_kbacorporate'),
    'attended' => get_string('bbbstateattended', 'local_kbacorporate'),
    'partial' => get_string('bbbstatepartial', 'local_kbacorporate'),
    'missed' => get_string('bbbstatemissed', 'local_kbacorporate'),
    'upcoming' => get_string('bbbstateupcoming', 'local_kbacorporate'),
    'nodata' => get_string('bbbstatenodata', 'local_kbacorporate'),
];
echo html_writer::label(get_string('status', 'local_kbacorporate'), 'kbacorp-webinar-state-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($stateoptions, 'state', $state, false,
    ['id' => 'kbacorp-webinar-state-filter', 'class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

$summary = \local_kbacorporate\local\bigbluebutton::summary($rows);
$latevalue = $summary->lateeligible
    ? $summary->lateassignments . ' / ' . $summary->lateeligible
    : '—';
$coveragevalue = $summary->avgcoveragepercent === null ? '—' : format_float($summary->avgcoveragepercent, 1) . '%';
$cards = [
    [get_string('bbbwebinars', 'local_kbacorporate'), $summary->webinars],
    [get_string('bbbattendance', 'local_kbacorporate'),
        $summary->attendancepercent === null ? '—' : format_float($summary->attendancepercent, 1) . '%'],
    [get_string('bbbtotalminutes', 'local_kbacorporate'),
        $summary->totalminutes > 0 ? format_time((int)round($summary->totalminutes * 60)) : '—'],
    [get_string('bbbavgcoverage', 'local_kbacorporate'), $coveragevalue],
    [get_string('bbblateassignments', 'local_kbacorporate'), $latevalue],
    [get_string('bbbengagementevents', 'local_kbacorporate'), $summary->engagementtotal],
];

echo html_writer::start_div('kbacorp-summary kbacorp-summary-compact');
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::div(s((string)$card[1]), 'value') . html_writer::div(s($card[0]), 'label'),
        'kbacorp-card'
    );
}
echo html_writer::end_div();

echo html_writer::div(get_string('bbbanalyticsnote', 'local_kbacorporate'), 'kbacorp-info-note');

if (has_capability('local/kbacorporate:exportreport', $context)) {
    $exportparams = ['companyid' => $companyid, 'courseid' => $courseid, 'userid' => $userid, 'state' => $state, 'departmentid' => $departmentid];
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url('/local/kbacorporate/export_webinars.php', $exportparams),
            get_string('bbbexportcsv', 'local_kbacorporate'), 'get'),
        'kbacorp-toolbar'
    );
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('nofilterresults', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$formatwindow = function($row) {
    if ($row->openingtime && $row->closingtime) {
        return userdate($row->openingtime, get_string('strftimedatetimeshort', 'langconfig')) . ' — ' .
            userdate($row->closingtime, get_string('strftimedatetimeshort', 'langconfig'));
    }
    if ($row->openingtime) {
        return get_string('bbbfrom', 'local_kbacorporate',
            userdate($row->openingtime, get_string('strftimedatetimeshort', 'langconfig')));
    }
    if ($row->closingtime) {
        return get_string('bbbtill', 'local_kbacorporate',
            userdate($row->closingtime, get_string('strftimedatetimeshort', 'langconfig')));
    }
    return get_string('bbbnoschedule', 'local_kbacorporate');
};

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-webinar-analytics';
$table->head = [
    get_string('fullname', 'local_kbacorporate'),
    get_string('bbbwebinar', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('bbbtime', 'local_kbacorporate'),
    get_string('bbbcoverage', 'local_kbacorporate'),
    get_string('bbbfirstjoin', 'local_kbacorporate'),
    get_string('bbblateness', 'local_kbacorporate'),
    get_string('bbbengagement', 'local_kbacorporate'),
    get_string('bbbrequirement', 'local_kbacorporate'),
];

foreach ($rows as $row) {
    $userurl = new moodle_url('/local/kbacorporate/employee.php', [
        'companyid' => $companyid,
        'userid' => $row->userid,
    ]);

    $webinarcell = html_writer::div(format_string($row->webinarname), 'kbacorp-cell-main');
    $webinarcell .= html_writer::div(
        html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
        'kbacorp-cell-muted'
    );
    if ($row->groupid) {
        $webinarcell .= html_writer::div(format_string($row->groupname), 'kbacorp-cell-muted');
    }
    $webinarcell .= html_writer::div(s($formatwindow($row)), 'kbacorp-cell-muted');

    $requirement = '—';
    if ($row->requiredminutes > 0) {
        $requirement = get_string('bbbminutesrequired', 'local_kbacorporate', $row->requiredminutes);
        if ($row->thresholdmet === true) {
            $requirement .= ' · ' . get_string('bbbmet', 'local_kbacorporate');
        } else if ($row->thresholdmet === false) {
            $requirement .= ' · ' . get_string('bbbnotmet', 'local_kbacorporate');
        }
    }

    $coverage = '—';
    if ($row->coveragepercent !== null) {
        $coverage = format_float($row->coveragepercent, 1) . '%';
        if ($row->scheduledminutes !== null) {
            $coverage .= html_writer::div(
                get_string('bbbscheduledminutes', 'local_kbacorporate', format_float($row->scheduledminutes, 0)),
                'kbacorp-cell-muted'
            );
        }
    }

    $firstjoin = $row->firstjoin
        ? userdate($row->firstjoin, get_string('strftimetime', 'langconfig'))
        : '—';
    if ($row->joincount > 1) {
        $firstjoin .= html_writer::div(get_string('bbbrejoins', 'local_kbacorporate', $row->joincount), 'kbacorp-cell-muted');
    }

    $lateness = '—';
    if ($row->latenessminutes !== null) {
        if ($row->late) {
            $lateness = html_writer::span(
                get_string('bbblateby', 'local_kbacorporate', format_float($row->latenessminutes, 0)),
                'kbacorp-status kbacorp-bbb-status-partial'
            );
        } else {
            $lateness = get_string('bbbon_time', 'local_kbacorporate');
        }
    }

    $e = $row->engagement;
    $engagement = html_writer::div(
        get_string('bbbengagementsummary', 'local_kbacorporate', (object)[
            'chats' => (int)$e['chats'],
            'talks' => (int)$e['talks'],
            'raisehand' => (int)$e['raisehand'],
            'polls' => (int)$e['poll_votes'],
            'emojis' => (int)$e['emojis'],
        ]),
        'kbacorp-engagement-line'
    );
    $engagement .= html_writer::div(
        get_string('bbbengagementtotal', 'local_kbacorporate', $row->engagementtotal),
        'kbacorp-cell-muted'
    );

    $table->data[] = [
        html_writer::link($userurl, s($row->fullname)),
        $webinarcell,
        html_writer::span(get_string('bbbstate' . $row->state, 'local_kbacorporate'),
            'kbacorp-status kbacorp-bbb-status-' . $row->state),
        $row->attended ? s(format_time((int)round($row->minutes * 60))) : '—',
        $coverage,
        $firstjoin,
        $lateness,
        $engagement,
        s($requirement),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
