<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewprogress', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/company_courses.php', ['companyid' => $companyid, 'departmentid' => $departmentid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('companycourses', 'local_kbacorporate'));
$PAGE->set_heading(get_string('companycourses', 'local_kbacorporate'));

$mappings = $DB->get_records_sql(
    "SELECT cc.id, cc.courseid, cc.groupid, c.fullname, c.shortname, g.name AS groupname
       FROM {local_kbacorp_course} cc
       JOIN {course} c ON c.id = cc.courseid
  LEFT JOIN {groups} g ON g.id = cc.groupid
      WHERE cc.companyid = :companyid AND cc.active = 1
   ORDER BY c.fullname",
    ['companyid' => $companyid]
);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$rows = \local_kbacorporate\local\report::get_rows($companyid, $visibleids);

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('companycoursespagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/company_courses.php');

if (!$mappings) {
    echo $OUTPUT->notification(get_string('nocoursesassigned', 'local_kbacorporate'), 'info');
    if (has_capability('local/kbacorporate:managecourses', $context)) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]), get_string('assigncourses', 'local_kbacorporate'), 'get');
    }
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('kbacorp-coursegrid');
foreach ($mappings as $mapping) {
    $courserows = [];
    foreach ($rows as $row) {
        if ((int)$row->courseid === (int)$mapping->courseid && (int)$row->groupid === (int)$mapping->groupid) {
            $courserows[] = $row;
        }
    }
    $summary = \local_kbacorporate\local\report::summary($courserows);
    $reconciliation = \local_kbacorporate\local\licenses::is_available()
        ? \local_kbacorporate\local\licenses::course_reconciliation(
            $companyid, (int)$mapping->courseid, (int)$mapping->groupid, $visibleids
        )
        : (object)[
            'employees' => count(array_unique(array_map(static function($row) { return (int)$row->userid; }, $courserows))),
            'enrolled' => count(array_unique(array_values(array_filter(array_map(static function($row) {
                return !empty($row->enrolled) ? (int)$row->userid : 0;
            }, $courserows))))),
            'training' => (int)$summary->training, 'completed' => (int)$summary->completed,
            'allocated' => 0, 'allocatedwithoutenrolment' => 0, 'enrolledwithoutallocation' => 0,
            'purchasedseats' => 0, 'unlimitedpackages' => 0, 'freeseats' => 0, 'packages' => [],
        ];
    $filterurl = new moodle_url('/local/kbacorporate/progress.php', ['companyid' => $companyid, 'courseid' => $mapping->courseid, 'departmentid' => $departmentid]);
    echo html_writer::start_div('kbacorp-coursecard');
    echo html_writer::div(
        html_writer::link(new moodle_url('/course/view.php', ['id' => $mapping->courseid]), format_string($mapping->fullname), ['class' => 'kbacorp-course-link']),
        'kbacorp-coursecard-title'
    );
    echo html_writer::div($mapping->groupid ? get_string('group', 'local_kbacorporate') . ': ' . format_string($mapping->groupname) : get_string('allcompanyemployees', 'local_kbacorporate'), 'kbacorp-coursecard-subtitle');
    echo html_writer::start_div('kbacorp-coursemetrics');
    echo html_writer::div(html_writer::tag('strong', $reconciliation->employees) . html_writer::span(get_string('companyemployeesmetric', 'local_kbacorporate')), 'kbacorp-coursemetric');
    echo html_writer::div(html_writer::tag('strong', $reconciliation->enrolled) . html_writer::span(get_string('moodleenrolledmetric', 'local_kbacorporate')), 'kbacorp-coursemetric');
    echo html_writer::div(html_writer::tag('strong', $reconciliation->training) . html_writer::span(get_string('training', 'local_kbacorporate')), 'kbacorp-coursemetric');
    echo html_writer::div(html_writer::tag('strong', $reconciliation->completed) . html_writer::span(get_string('completed', 'local_kbacorporate')), 'kbacorp-coursemetric');
    echo html_writer::div(html_writer::tag('strong', $summary->avgprogress === null ? '—' : $summary->avgprogress . '%') . html_writer::span(get_string('avgprogress', 'local_kbacorporate')), 'kbacorp-coursemetric');
    if (has_capability('local/kbacorporate:viewlicenses', $context)) {
        $purchased = !$reconciliation->packages ? '—' : ($reconciliation->purchasedseats > 0
            ? $reconciliation->purchasedseats : ($reconciliation->unlimitedpackages > 0 ? '∞' : '—'));
        $free = !$reconciliation->packages || $reconciliation->purchasedseats === 0 ? '—' : $reconciliation->freeseats;
        echo html_writer::div(html_writer::tag('strong', $purchased) . html_writer::span(get_string('purchasedseats', 'local_kbacorporate')), 'kbacorp-coursemetric');
        echo html_writer::div(html_writer::tag('strong', $reconciliation->allocated) . html_writer::span(get_string('allocatedseatsmetric', 'local_kbacorporate')), 'kbacorp-coursemetric');
        echo html_writer::div(html_writer::tag('strong', $free) . html_writer::span(get_string('freeseats', 'local_kbacorporate')), 'kbacorp-coursemetric');
    }
    echo html_writer::end_div();
    if (has_capability('local/kbacorporate:viewlicenses', $context)) {
        $warnings = [];
        if ($reconciliation->allocatedwithoutenrolment > 0) {
            $warnings[] = get_string('allocatedwithoutenrolmentwarning', 'local_kbacorporate', $reconciliation->allocatedwithoutenrolment);
        }
        if ($reconciliation->enrolledwithoutallocation > 0) {
            $warnings[] = get_string('enrolledwithoutallocationwarning', 'local_kbacorporate', $reconciliation->enrolledwithoutallocation);
        }
        foreach ($warnings as $warning) {
            echo html_writer::div(s($warning), 'alert alert-warning mt-2 mb-2');
        }
        if ($reconciliation->packages) {
            $packagehtml = '';
            foreach ($reconciliation->packages as $package) {
                if ($package->source === \local_kbacorporate\local\licenses::SOURCE_BITRIX) {
                    $source = get_string('licenseprovenancebitrix', 'local_kbacorporate', (object)[
                        'deal' => $package->externaldealid !== '' ? $package->externaldealid : '—',
                        'row' => $package->externalrowid !== '' ? $package->externalrowid : '—',
                    ]);
                } else {
                    $source = \local_kbacorporate\local\licenses::source_label($package->source);
                }
                $capacity = $package->seats > 0 ? (string)$package->seats : '∞';
                $available = $package->available === null ? '—' : (string)$package->available;
                $packagehtml .= html_writer::div(
                    s($source) . ' · ' . s(get_string('licensepackagecapacity', 'local_kbacorporate', (object)[
                        'seats' => $capacity, 'allocated' => $package->allocated, 'free' => $available,
                    ])),
                    'small mb-1'
                );
            }
            echo html_writer::tag('details',
                html_writer::tag('summary', get_string('licenseprovenancetitle', 'local_kbacorporate')) .
                html_writer::div($packagehtml, 'mt-2'),
                ['class' => 'mt-2']
            );
        }
    }
    echo html_writer::link($filterurl, get_string('viewcourseprogress', 'local_kbacorporate') . ' →', ['class' => 'kbacorp-coursecard-link']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo $OUTPUT->footer();
