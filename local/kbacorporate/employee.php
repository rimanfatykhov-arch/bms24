<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewemployees', $context);
$companyid = required_param('companyid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
if (!\local_kbacorporate\local\access::can_view_employee($companyid, $USER->id, $userid)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

$membership = $DB->get_record('local_kbacorp_member', [
    'companyid' => $companyid,
    'userid' => $userid,
    'isemployee' => 1,
], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$rows = array_values(array_filter(\local_kbacorporate\local\report::get_rows($companyid), function($row) use ($userid) {
    return (int)$row->userid === $userid;
}));

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employee.php', ['companyid' => $companyid, 'userid' => $userid]));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/kbacorporate/engagement.css', ['v' => 2026081800]));

$engagementstring = static function(string $id, string $ru, string $en): string {
    $manager = get_string_manager();
    if ($manager->string_exists($id, 'local_kbacorporate')) {
        return get_string($id, 'local_kbacorporate');
    }
    return current_language() === 'ru' ? $ru : $en;
};
$PAGE->set_title(fullname($user));
$PAGE->set_heading(fullname($user));

echo $OUTPUT->header();
echo html_writer::div(format_string($company->name), 'kbacorp-muted');
echo $OUTPUT->heading(fullname($user), 2);

$profile = new html_table();
$profile->data = [
    [get_string('email', 'local_kbacorporate'), s($user->email)],
    [get_string('phone', 'local_kbacorporate'), s($user->phone1 ?: $user->phone2 ?: '—')],
];
if ($rows) {
    $profile->data[] = [get_string('position', 'local_kbacorporate'), s($rows[0]->position ?: '—')];
}
echo html_writer::table($profile);

if (has_capability('local/kbacorporate:viewgamification', $context) &&
        \local_kbacorporate\local\gamification::is_enabled() &&
        \local_kbacorporate\local\gamification::is_available()) {
    $gamificationsummary = \local_kbacorporate\local\gamification::get_user_summary($userid);
    if ($gamificationsummary) {
        echo $OUTPUT->heading(get_string('gamificationnav', 'local_kbacorporate'), 3);
        echo html_writer::start_div('kbacorp-summary kbacorp-gamification-summary');
        $gamificationcards = [
            [get_string('gamificationlevel', 'local_kbacorporate'), $gamificationsummary['levelname'] ?: '—'],
            [get_string('gamificationxp', 'local_kbacorporate'), (int)$gamificationsummary['xp']],
            [get_string('gamificationachievements', 'local_kbacorporate'),
                (int)$gamificationsummary['achievementcount']],
        ];
        if (\local_kbacorporate\local\gamification::show_coins()) {
            $gamificationcards[] = [get_string('gamificationcoins', 'local_kbacorporate'),
                (int)$gamificationsummary['coins']];
        }
        foreach ($gamificationcards as $gamificationcard) {
            echo html_writer::start_div('kbacorp-card');
            echo html_writer::div(s((string)$gamificationcard[1]), 'value');
            echo html_writer::div(s($gamificationcard[0]), 'label');
            echo html_writer::end_div();
        }
        echo html_writer::end_div();

        if (!empty($gamificationsummary['nextlevelname'])) {
            echo html_writer::div(
                get_string('gamificationnextlevel', 'local_kbacorporate', (object)[
                    'level' => $gamificationsummary['nextlevelname'],
                    'progress' => (int)$gamificationsummary['levelprogress'],
                ]),
                'kbacorp-gamification-nextlevel'
            );
        }

        $latestachievement = \local_kbacorporate\local\gamification::get_user_latest_achievement($userid);
        if ($latestachievement) {
            echo html_writer::start_div('kbacorp-gamification-employee-latest');
            echo html_writer::span(s((string)$latestachievement['icon']), 'kbacorp-gamification-latest-icon');
            echo html_writer::start_div('kbacorp-gamification-latest-body');
            echo html_writer::span($engagementstring('gamificationlatestachievement', 'Последнее достижение', 'Latest achievement'),
                'kbacorp-gamification-latest-user');
            echo html_writer::tag('strong', s((string)$latestachievement['name']));
            echo html_writer::span(userdate((int)$latestachievement['timeawarded'], get_string('strftimedateshort', 'langconfig')),
                'kbacorp-gamification-latest-date');
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        $achievements = \local_kbacorporate\local\gamification::get_user_achievements($userid);
        if ($achievements) {
            $achievementtable = new html_table();
            $achievementtable->attributes['class'] = 'generaltable kbacorp-report kbacorp-achievement-table';
            $achievementtable->head = [
                get_string('gamificationachievement', 'local_kbacorporate'),
                get_string('gamificationrarity', 'local_kbacorporate'),
                get_string('gamificationawarded', 'local_kbacorporate'),
            ];
            foreach ($achievements as $achievement) {
                $name = trim((string)$achievement['icon'] . ' ' . (string)$achievement['name']);
                $raritykey = 'gamificationrarity_' . preg_replace('/[^a-z]/', '', strtolower((string)$achievement['rarity']));
                $rarity = get_string_manager()->string_exists($raritykey, 'local_kbacorporate')
                    ? get_string($raritykey, 'local_kbacorporate')
                    : (string)$achievement['rarity'];
                $achievementtable->data[] = [
                    html_writer::tag('strong', s($name)) .
                        (!empty($achievement['description'])
                            ? html_writer::div(s(html_to_text((string)$achievement['description'], 0, false)),
                                'kbacorp-muted') : ''),
                    s($rarity),
                    !empty($achievement['timeawarded']) ? userdate((int)$achievement['timeawarded']) : '—',
                ];
            }
            echo html_writer::table($achievementtable);
        } else {
            echo html_writer::div(get_string('gamificationnoachievements', 'local_kbacorporate'), 'kbacorp-muted');
        }
        echo html_writer::div(
            html_writer::link(new moodle_url('/local/kbacorporate/gamification.php', [
                'companyid' => $companyid,
            ]), get_string('opengamification', 'local_kbacorporate') . ' →'),
            'kbacorp-viewall'
        );
    }
}

if (has_capability('local/kbacorporate:viewcompetencies', $context)) {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
            'userid' => $userid,
            'companyid' => $companyid,
        ]),
            get_string('viewemployeecompetencies', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
            'userid' => $userid,
            'companyid' => $companyid,
        ]), get_string('employeeassessmenthistory', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
}
if (has_capability('local/kbacorporate:managerolecompetencies', $context) &&
        \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id)) {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]), get_string('businessroleassignments', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]), get_string('employeegapopen', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
}
if (has_capability('local/kbacorporate:managecompetencyaudits', $context) &&
        \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id)) {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]), get_string('createemployeeaudit', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
}

echo $OUTPUT->heading(get_string('courseprogress', 'local_kbacorporate'), 3);
if (!$rows) {
    echo $OUTPUT->notification(get_string('nocoursesassigned', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('course', 'local_kbacorporate'),
        get_string('status', 'local_kbacorporate'),
        get_string('progress', 'local_kbacorporate'),
        get_string('grade', 'local_kbacorporate'),
        get_string('enrolledat', 'local_kbacorporate'),
        get_string('lastaccess', 'local_kbacorporate'),
    ];
    foreach ($rows as $row) {
        $table->data[] = [
            html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
            get_string($row->status, 'local_kbacorporate'),
            $row->progress === null ? '—' : s($row->progress . '%'),
            $row->grade === null ? '—' : s($row->grade . '%'),
            $row->enrolledat ? userdate($row->enrolledat) : '—',
            $row->lastaccess ? userdate($row->lastaccess) : '—',
        ];
    }
    echo html_writer::table($table);
}

if (has_capability('local/kbacorporate:viewalerts', $context)) {
    $attentionreport = \local_kbacorporate\local\management_report::build($companyid, 'month', '', '', [$userid]);
    echo $OUTPUT->heading(get_string('attentioncenter', 'local_kbacorporate'), 3);
    if (!$attentionreport->alerts) {
        echo $OUTPUT->notification(get_string('noattentionitems', 'local_kbacorporate'), 'notifysuccess');
    } else {
        $alerttable = new html_table();
        $alerttable->attributes['class'] = 'generaltable kbacorp-report kbacorp-alert-table';
        $alerttable->head = [
            get_string('riskseverity', 'local_kbacorporate'),
            get_string('risktype', 'local_kbacorporate'),
            get_string('course', 'local_kbacorporate'),
            get_string('attentionreason', 'local_kbacorporate'),
        ];
        foreach ($attentionreport->alerts as $alert) {
            $coursehtml = $alert->courseid > 0
                ? html_writer::link(new moodle_url('/course/view.php', ['id' => $alert->courseid]),
                    format_string($alert->coursename), ['class' => 'kbacorp-course-link'])
                : '—';
            $alerttable->data[] = [
                html_writer::span(
                    s(\local_kbacorporate\local\attention::severity_label($alert->severity)),
                    'kbacorp-risk-badge kbacorp-risk-' . $alert->severity
                ),
                s(\local_kbacorporate\local\attention::type_label($alert->type)),
                $coursehtml,
                s($alert->reason),
            ];
        }
        echo html_writer::table($alerttable);
        echo html_writer::div(
            html_writer::link(new moodle_url('/local/kbacorporate/alerts.php', [
                'companyid' => $companyid,
                'userid' => $userid,
            ]), get_string('openattentioncenter', 'local_kbacorporate') . ' →'),
            'kbacorp-viewall'
        );
    }
}


if (has_capability('local/kbacorporate:viewtrainingtime', $context)) {
    $timeperiod = \local_kbacorporate\local\trainingtime::default_period();
    $timeperiod = \local_kbacorporate\local\trainingtime::normalize_period($timeperiod->from, $timeperiod->to);
    $timereport = \local_kbacorporate\local\trainingtime::build_report(
        $companyid, $timeperiod->start, $timeperiod->endexclusive, $userid
    );
    $timesummary = $timereport->summary;
    $hoursformat = function($minutes) {
        return format_float(((float)$minutes) / 60, 2) . ' ' . get_string('hoursabbr', 'local_kbacorporate');
    };

    echo $OUTPUT->heading(get_string('trainingtimenav', 'local_kbacorporate'), 3);
    echo html_writer::div(
        get_string('currentweeklabel', 'local_kbacorporate', $timeperiod->from . ' — ' . $timeperiod->to),
        'kbacorp-muted'
    );
    echo html_writer::start_div('kbacorp-employee-time-summary');
    echo html_writer::div(
        html_writer::tag('strong', s($hoursformat($timesummary->planminutes))) .
        html_writer::span(get_string('trainingplanhours', 'local_kbacorporate'))
    );
    echo html_writer::div(
        html_writer::tag('strong', s($hoursformat($timesummary->totalminutes))) .
        html_writer::span(get_string('trainingfacthours', 'local_kbacorporate'))
    );
    $timepercent = $timesummary->percent === null ? '—' : format_float($timesummary->percent, 1) . '%';
    echo html_writer::div(
        html_writer::tag('strong', s($timepercent)) .
        html_writer::span(get_string('trainingplanpercent', 'local_kbacorporate'))
    );
    echo html_writer::end_div();
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/kbacorporate/trainingtime.php', [
            'companyid' => $companyid,
            'userid' => $userid,
            'from' => $timeperiod->from,
            'to' => $timeperiod->to,
        ]), get_string('openlearningtime', 'local_kbacorporate') . ' →'),
        'kbacorp-viewall'
    );
}



if (has_capability('local/kbacorporate:viewlicenses', $context) &&
        \local_kbacorporate\local\licenses::is_available()) {
    echo $OUTPUT->heading(get_string('licensesnav', 'local_kbacorporate'), 3);
    $userlicenses = \local_kbacorporate\local\licenses::get_user_licenses($companyid, $userid);
    if (!$userlicenses) {
        echo $OUTPUT->notification(get_string('nolicensesforuser', 'local_kbacorporate'), 'info');
    } else {
        $licensetable = new html_table();
        $licensetable->attributes['class'] = 'generaltable kbacorp-report';
        $licensetable->head = [
            get_string('course', 'local_kbacorporate'),
            get_string('licenseperiod', 'local_kbacorporate'),
            get_string('licenseallocationstatus', 'local_kbacorporate'),
            get_string('licenseassignedat', 'local_kbacorporate'),
        ];
        foreach ($userlicenses as $userlicense) {
            $from = $userlicense->datefrom
                ? userdate($userlicense->datefrom, get_string('strftimedateshort', 'langconfig')) : '—';
            $to = $userlicense->dateto
                ? userdate($userlicense->dateto, get_string('strftimedateshort', 'langconfig')) : '—';
            $licensetable->data[] = [
                html_writer::link(new moodle_url('/course/view.php', ['id' => $userlicense->courseid]),
                    format_string($userlicense->fullname), ['class' => 'kbacorp-course-link']),
                s($from . ' — ' . $to),
                s(\local_kbacorporate\local\licenses::allocation_status_label(
                    (string)$userlicense->effectiveallocationstatus
                )),
                $userlicense->timeassigned ? userdate($userlicense->timeassigned) : '—',
            ];
        }
        echo html_writer::table($licensetable);
        echo html_writer::div(
            html_writer::link(new moodle_url('/local/kbacorporate/licenses.php', [
                'companyid' => $companyid,
            ]), get_string('openlicenseregister', 'local_kbacorporate') . ' →'),
            'kbacorp-viewall'
        );
    }
}


if (has_capability('local/kbacorporate:viewcertificates', $context)) {
    echo $OUTPUT->heading(get_string('certificatesnav', 'local_kbacorporate'), 3);
    if (!\local_kbacorporate\local\customcert::is_available()) {
        echo $OUTPUT->notification(get_string('customcertnotavailabledesc', 'local_kbacorporate'), 'info');
    } else {
        $certificaterows = \local_kbacorporate\local\customcert::get_user_rows($companyid, $userid);
        $certificatesummary = \local_kbacorporate\local\customcert::summary($certificaterows);
        echo html_writer::start_div('kbacorp-employee-time-summary');
        echo html_writer::div(
            html_writer::tag('strong', s((string)$certificatesummary->issued)) .
            html_writer::span(get_string('certificatesissued', 'local_kbacorporate'))
        );
        echo html_writer::div(
            html_writer::tag('strong', s((string)$certificatesummary->notissued)) .
            html_writer::span(get_string('certificatesnotissued', 'local_kbacorporate'))
        );
        $certcoverage = $certificatesummary->issuedpercent === null
            ? '—' : format_float($certificatesummary->issuedpercent, 1) . '%';
        echo html_writer::div(
            html_writer::tag('strong', s($certcoverage)) .
            html_writer::span(get_string('certificatecoverage', 'local_kbacorporate'))
        );
        echo html_writer::end_div();

        if (!$certificaterows) {
            echo $OUTPUT->notification(get_string('nocertificatesforuser', 'local_kbacorporate'), 'info');
        } else {
            $certtable = new html_table();
            $certtable->attributes['class'] = 'generaltable kbacorp-report kbacorp-cert-registry';
            $certtable->head = [
                get_string('course', 'local_kbacorporate'),
                get_string('certificate', 'local_kbacorporate'),
                get_string('status', 'local_kbacorporate'),
                get_string('certificateissuedat', 'local_kbacorporate'),
                get_string('actions', 'local_kbacorporate'),
            ];
            foreach ($certificaterows as $certrow) {
                $statushtml = $certrow->issued
                    ? html_writer::span(get_string('certificateissued', 'local_kbacorporate'),
                        'kbacorp-status kbacorp-cert-status-issued')
                    : html_writer::span(get_string('certificatenotissued', 'local_kbacorporate'),
                        'kbacorp-status kbacorp-cert-status-notissued');
                $actions = '—';
                if ($certrow->issued) {
                    $actions = html_writer::link(new moodle_url('/local/kbacorporate/certificate_download.php', [
                        'companyid' => $companyid,
                        'issueid' => $certrow->issueid,
                    ]), get_string('downloadcertificate', 'local_kbacorporate'), [
                        'class' => 'btn btn-sm btn-outline-primary',
                    ]);
                }
                $certtable->data[] = [
                    html_writer::link(new moodle_url('/course/view.php', ['id' => $certrow->courseid]), format_string($certrow->coursename), ['class' => 'kbacorp-course-link']),
                    format_string($certrow->certificatename),
                    $statushtml,
                    $certrow->issuedat ? userdate($certrow->issuedat) : '—',
                    $actions,
                ];
            }
            echo html_writer::table($certtable);
            echo html_writer::div(
                html_writer::link(new moodle_url('/local/kbacorporate/certificates.php', [
                    'companyid' => $companyid,
                    'userid' => $userid,
                ]), get_string('opencertificateregistry', 'local_kbacorporate') . ' →'),
                'kbacorp-viewall'
            );
        }
    }
}


if (has_capability('local/kbacorporate:viewattendance', $context)) {
    echo $OUTPUT->heading(get_string('webinarsnav', 'local_kbacorporate'), 3);
    if (!\local_kbacorporate\local\bigbluebutton::is_available()) {
        echo $OUTPUT->notification(get_string('bbbnotavailable', 'local_kbacorporate'), 'info');
    } else {
        $webinarrows = \local_kbacorporate\local\bigbluebutton::get_user_rows($companyid, $userid);
        if (!$webinarrows) {
            echo $OUTPUT->notification(get_string('nobbbwebinarsforuser', 'local_kbacorporate'), 'info');
        } else {
            $webinarsummary = \local_kbacorporate\local\bigbluebutton::summary($webinarrows);
            $attendancevalue = $webinarsummary->attendancepercent === null
                ? '—' : format_float($webinarsummary->attendancepercent, 1) . '%';
            $coveragevalue = $webinarsummary->avgcoveragepercent === null
                ? '—' : format_float($webinarsummary->avgcoveragepercent, 1) . '%';
            $latevalue = $webinarsummary->lateeligible
                ? $webinarsummary->lateassignments . ' / ' . $webinarsummary->lateeligible
                : '—';

            echo html_writer::start_div('kbacorp-employee-time-summary');
            echo html_writer::div(
                html_writer::tag('strong', s($attendancevalue)) .
                html_writer::span(get_string('bbbemployeeattendance', 'local_kbacorporate'))
            );
            echo html_writer::div(
                html_writer::tag('strong', s($coveragevalue)) .
                html_writer::span(get_string('bbbavgcoverage', 'local_kbacorporate'))
            );
            echo html_writer::div(
                html_writer::tag('strong', s($latevalue)) .
                html_writer::span(get_string('bbblateassignments', 'local_kbacorporate'))
            );
            echo html_writer::div(
                html_writer::tag('strong', s((string)$webinarsummary->engagementtotal)) .
                html_writer::span(get_string('bbbengagementevents', 'local_kbacorporate'))
            );
            echo html_writer::end_div();

            $webinartable = new html_table();
            $webinartable->attributes['class'] = 'generaltable kbacorp-report';
            $webinartable->head = [
                get_string('course', 'local_kbacorporate'),
                get_string('bbbwebinar', 'local_kbacorporate'),
                get_string('status', 'local_kbacorporate'),
                get_string('bbbtime', 'local_kbacorporate'),
                get_string('bbbcoverage', 'local_kbacorporate'),
                get_string('bbblateness', 'local_kbacorporate'),
                get_string('bbbengagement', 'local_kbacorporate'),
                get_string('bbbrequirement', 'local_kbacorporate'),
            ];
            foreach ($webinarrows as $webinarrow) {
                $requirement = '—';
                if ($webinarrow->requiredminutes > 0) {
                    $requirement = get_string('bbbminutesrequired', 'local_kbacorporate', $webinarrow->requiredminutes);
                    if ($webinarrow->thresholdmet === true) {
                        $requirement .= ' · ' . get_string('bbbmet', 'local_kbacorporate');
                    } else if ($webinarrow->thresholdmet === false) {
                        $requirement .= ' · ' . get_string('bbbnotmet', 'local_kbacorporate');
                    }
                }
                $coverage = $webinarrow->coveragepercent === null
                    ? '—' : format_float($webinarrow->coveragepercent, 1) . '%';
                $lateness = '—';
                if ($webinarrow->latenessminutes !== null) {
                    $lateness = $webinarrow->late
                        ? get_string('bbblateby', 'local_kbacorporate', format_float($webinarrow->latenessminutes, 0))
                        : get_string('bbbon_time', 'local_kbacorporate');
                }
                $engagement = get_string('bbbengagementtotal', 'local_kbacorporate', $webinarrow->engagementtotal);
                $webinartable->data[] = [
                    html_writer::link(new moodle_url('/course/view.php', ['id' => $webinarrow->courseid]), format_string($webinarrow->coursename), ['class' => 'kbacorp-course-link']),
                    format_string($webinarrow->webinarname),
                    html_writer::span(get_string('bbbstate' . $webinarrow->state, 'local_kbacorporate'),
                        'kbacorp-status kbacorp-bbb-status-' . $webinarrow->state),
                    $webinarrow->attended ? s(format_time((int)round($webinarrow->minutes * 60))) : '—',
                    s($coverage),
                    s($lateness),
                    s($engagement),
                    s($requirement),
                ];
            }
            echo html_writer::table($webinartable);
            echo html_writer::div(
                html_writer::link(new moodle_url('/local/kbacorporate/webinars.php', [
                    'companyid' => $companyid,
                    'userid' => $userid,
                ]), get_string('openwebinaranalytics', 'local_kbacorporate') . ' →'),
                'kbacorp-viewall'
            );
        }
    }
}

echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]), get_string('backtodashboard', 'local_kbacorporate'), 'get');
echo $OUTPUT->footer();
