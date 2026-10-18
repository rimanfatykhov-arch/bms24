<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewdashboard', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
if (!$companies) {
    $companyid = 0;
} else if (!$companyid) {
    $first = reset($companies);
    $companyid = $first->id;
} else if (!isset($companies[$companyid])) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/index.php', $companyid ? ['companyid' => $companyid, 'departmentid' => $departmentid] : []));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('dashboard', 'local_kbacorporate'));
$PAGE->set_heading(get_string('dashboard', 'local_kbacorporate'));

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'dashboard');

if (!$companies) {
    echo $OUTPUT->notification(get_string('nocorporatecompanies', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$company = $companies[$companyid];

// Searchable company switcher and administrator-only management shortcut.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/index.php'))->out(false),
    'class' => 'kbacorp-filters kbacorp-company-picker-filters kbacorp-dashboard-company-switcher',
    'id' => 'kbacorp-dashboard-company-form',
    'role' => 'search',
]);
echo html_writer::start_div('kbacorp-map-search');
echo html_writer::tag('label', get_string('dashboardcompanysearch', 'local_kbacorporate'), [
    'for' => 'kbacorp-dashboard-company-search',
]);
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'id' => 'kbacorp-dashboard-company-search',
    'value' => '',
    'placeholder' => get_string('dashboardcompanysearchplaceholder', 'local_kbacorporate'),
    'autocomplete' => 'off',
    'list' => 'kbacorp-dashboard-company-options',
    'aria-describedby' => 'kbacorp-dashboard-company-help',
]);
echo html_writer::start_tag('datalist', ['id' => 'kbacorp-dashboard-company-options']);
foreach ($companies as $item) {
    echo html_writer::empty_tag('option', [
        'value' => format_string($item->name),
        'data-companyid' => (int)$item->id,
    ]);
}
echo html_writer::end_tag('datalist');
echo html_writer::div(get_string('dashboardcompanysearchhelp', 'local_kbacorporate'),
    'form-text text-muted', ['id' => 'kbacorp-dashboard-company-help']);
echo html_writer::end_div();
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'id' => 'kbacorp-dashboard-company-id',
    'name' => 'companyid',
    'value' => $companyid,
]);
echo html_writer::tag('button', get_string('dashboardcompanyopen', 'local_kbacorporate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
if (has_capability('local/kbacorporate:managecompanies', $context)) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/companies.php'),
        get_string('manage', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_tag('form');
$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var form = document.getElementById('kbacorp-dashboard-company-form');
    var input = document.getElementById('kbacorp-dashboard-company-search');
    var companyid = document.getElementById('kbacorp-dashboard-company-id');
    var options = Array.prototype.slice.call(
        document.querySelectorAll('#kbacorp-dashboard-company-options option'));
    if (!form || !input || !companyid) { return; }

    var findExact = function(value) {
        var needle = value.trim().toLocaleLowerCase();
        return options.find(function(option) {
            return option.value.trim().toLocaleLowerCase() === needle;
        });
    };
    var selectCompany = function(option) {
        companyid.value = option.getAttribute('data-companyid');
        input.setCustomValidity('');
    };

    input.addEventListener('input', function() {
        var option = findExact(input.value);
        input.setCustomValidity('');
        if (option) {
            selectCompany(option);
            form.submit();
        }
    });
    form.addEventListener('submit', function(event) {
        var option = findExact(input.value);
        if (!option) {
            event.preventDefault();
            input.setCustomValidity(M.util.get_string('dashboardcompanynotfound', 'local_kbacorporate'));
            input.reportValidity();
            return;
        }
        selectCompany(option);
    });
}());
JS
);
$PAGE->requires->strings_for_js(['dashboardcompanynotfound'], 'local_kbacorporate');

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/index.php');

echo html_writer::start_div('kbacorp-hero');
echo html_writer::div(get_string('corporatelearning', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(format_string($company->name), 2, 'kbacorp-company-heading');
if (!empty($company->primaryactivity) || !empty($company->foundedyear)) {
    $companydetails = [];
    if (!empty($company->primaryactivity)) {
        $companydetails[] = format_string($company->primaryactivity);
    }
    if (!empty($company->foundedyear)) {
        $companydetails[] = get_string('companyfoundedyearvalue', 'local_kbacorporate', (int)$company->foundedyear);
    }
    echo html_writer::div(implode(' · ', $companydetails), 'kbacorp-company-details');
}
echo html_writer::div(get_string('dashboardintro', 'local_kbacorporate'), 'kbacorp-hero-text');
echo html_writer::end_div();

$rows = \local_kbacorporate\local\report::get_rows($companyid, $visibleids);
$summary = \local_kbacorporate\local\report::summary($rows);
$summary->employees = count($visibleids);
$coursecount = $DB->count_records('local_kbacorp_course', ['companyid' => $companyid, 'active' => 1]);
$bbbavailable = \local_kbacorporate\local\bigbluebutton::is_available();
$webinarcount = $bbbavailable ? \local_kbacorporate\local\bigbluebutton::get_company_activity_count($companyid) : 0;
$webinarrows = ($bbbavailable && has_capability('local/kbacorporate:viewattendance', $context))
    ? \local_kbacorporate\local\bigbluebutton::get_company_rows($companyid, $visibleids) : [];
$webinarsummary = \local_kbacorporate\local\bigbluebutton::summary($webinarrows);
$customcertavailable = \local_kbacorporate\local\customcert::is_available();
$certificaterows = ($customcertavailable && has_capability('local/kbacorporate:viewcertificates', $context))
    ? \local_kbacorporate\local\customcert::get_company_rows($companyid, $visibleids) : [];
$certificatesummary = \local_kbacorporate\local\customcert::summary($certificaterows);
$attentionmanagement = has_capability('local/kbacorporate:viewalerts', $context)
    ? \local_kbacorporate\local\management_report::build($companyid, 'month', '', '', $visibleids) : null;
$attentionsummary = $attentionmanagement ? $attentionmanagement->attentionsummary : null;
$licenseavailable = \local_kbacorporate\local\licenses::is_available();
$licenserows = ($licenseavailable && has_capability('local/kbacorporate:viewlicenses', $context))
    ? \local_kbacorporate\local\licenses::get_company_licenses($companyid, true) : [];
$licensesummary = \local_kbacorporate\local\licenses::summary($licenserows);

$gamificationavailable = false;
$gamificationsnapshot = null;
if (has_capability('local/kbacorporate:viewgamification', $context) &&
        \local_kbacorporate\local\gamification::is_enabled()) {
    $gamificationavailable = \local_kbacorporate\local\gamification::is_available();
    if ($gamificationavailable) {
        $gamificationsnapshot = \local_kbacorporate\local\gamification::get_company_snapshot(
            $visibleids,
            \local_kbacorporate\local\gamification::leaderboard_limit(),
            'xp'
        );
    }
}

$developmenteffectiveness = null;
if (has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
        \local_kbacorporate\local\development_plans::available()) {
    $developmenteffectiveness = \local_kbacorporate\local\development_plans::get_company_effectiveness(
        $companyid, $visibleids
    );
}

// Main corporate navigation. These are read-only views for corporate managers.
$navcards = [
    [
        'title' => get_string('competencymap', 'local_kbacorporate'),
        'text' => get_string('competencymapdesc', 'local_kbacorporate'),
        'value' => count($visibleids),
        'url' => new moodle_url('/local/kbacorporate/competency_map.php', [
            'companyid' => $companyid, 'departmentid' => $departmentid,
        ]),
        'cap' => 'local/kbacorporate:viewcompetencies',
        'tone' => 'blue',
    ],
    [
        'title' => get_string('businessroles', 'local_kbacorporate'),
        'text' => get_string('businessrolesdesc', 'local_kbacorporate'),
        'value' => \local_kbacorporate\local\business_roles::available()
            ? count(\local_kbacorporate\local\business_roles::get_catalog($companyid, false)) : '—',
        'url' => new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
        'cap' => 'local/kbacorporate:managerolecompetencies',
        'enabled' => \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id),
        'tone' => 'violet',
    ],
    [
        'title' => get_string('competencyaudits', 'local_kbacorporate'),
        'text' => get_string('competencyauditsdesc', 'local_kbacorporate'),
        'value' => \local_kbacorporate\local\competency_audits::available()
            ? count(\local_kbacorporate\local\competency_audits::get_company_audits($companyid)) : '—',
        'url' => new moodle_url('/local/kbacorporate/competency_audits.php', ['companyid' => $companyid]),
        'cap' => 'local/kbacorporate:managecompetencyaudits',
        'enabled' => \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id),
        'tone' => 'green-soft',
        'order' => 2,
    ],
    [
        'title' => get_string('developmentplans', 'local_kbacorporate'),
        'text' => get_string('developmentplansdesc', 'local_kbacorporate'),
        'value' => \local_kbacorporate\local\development_plans::available()
            ? count(\local_kbacorporate\local\development_plans::get_company_plans($companyid)) : '—',
        'url' => new moodle_url('/local/kbacorporate/development_plans.php', ['companyid' => $companyid]),
        'cap' => 'local/kbacorporate:managedevelopmentplans',
        'tone' => 'teal',
    ],
    [
        'title' => get_string('developmenteffectiveness', 'local_kbacorporate'),
        'text' => get_string('developmenteffectivenessdesc', 'local_kbacorporate'),
        'value' => $developmenteffectiveness ? $developmenteffectiveness->improved : '—',
        'url' => new moodle_url('/local/kbacorporate/development_effectiveness.php', [
            'companyid' => $companyid, 'departmentid' => $departmentid,
        ]),
        'cap' => 'local/kbacorporate:managedevelopmentplans',
        'enabled' => $developmenteffectiveness !== null,
        'tone' => 'rose',
    ],
    [
        'title' => get_string('bmsimplementation', 'local_kbacorporate'),
        'text' => get_string('bmsimplementationnavdesc', 'local_kbacorporate'),
        'value' => \local_kbacorporate\local\implementation_audits::available()
            ? count(\local_kbacorporate\local\implementation_audits::get_audits($companyid)) : '—',
        'url' => new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]),
        'cap' => 'local/kbacorporate:viewdashboard',
        'enabled' => \local_kbacorporate\local\implementation_audits::available() &&
            \local_kbacorporate\local\access::can_manage_company_implementation(
                $companyid, (int)$USER->id),
        'tone' => 'green',
        'order' => 1,
    ],
    [
        'title' => get_string('employeesnav', 'local_kbacorporate'),
        'text' => get_string('employeesnavdesc', 'local_kbacorporate'),
        'value' => $summary->employees,
        'url' => new moodle_url('/local/kbacorporate/employees.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewemployees',
    ],
    [
        'title' => get_string('progressnav', 'local_kbacorporate'),
        'text' => get_string('progressnavdesc', 'local_kbacorporate'),
        'value' => $summary->avgprogress === null ? '—' : $summary->avgprogress . '%',
        'url' => new moodle_url('/local/kbacorporate/progress.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewprogress',
    ],
    [
        'title' => get_string('companycourses', 'local_kbacorporate'),
        'text' => get_string('companycoursesdesc', 'local_kbacorporate'),
        'value' => $coursecount,
        'url' => new moodle_url('/local/kbacorporate/company_courses.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewprogress',
    ],
    [
        'title' => get_string('webinarsnav', 'local_kbacorporate'),
        'text' => get_string('webinarsnavdesc', 'local_kbacorporate'),
        'value' => $bbbavailable ? $webinarcount : '—',
        'url' => new moodle_url('/local/kbacorporate/webinars.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewattendance',
    ],
    [
        'title' => get_string('trainingtimenav', 'local_kbacorporate'),
        'text' => get_string('trainingtimenavdesc', 'local_kbacorporate'),
        'value' => format_float(((int)$company->trainingminutesday) / 60, 1) . ' ' . get_string('hoursperdayabbr', 'local_kbacorporate'),
        'url' => new moodle_url('/local/kbacorporate/trainingtime.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewtrainingtime',
    ],
    [
        'title' => get_string('certificatesnav', 'local_kbacorporate'),
        'text' => get_string('certificatesnavdesc', 'local_kbacorporate'),
        'value' => $customcertavailable ? $certificatesummary->issued : '—',
        'url' => new moodle_url('/local/kbacorporate/certificates.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewcertificates',
    ],
    [
        'title' => get_string('departments', 'local_kbacorporate'),
        'text' => get_string('departmentsnavdesc', 'local_kbacorporate'),
        'value' => count(\local_kbacorporate\local\access::get_allowed_departments($companyid, $USER->id)),
        'url' => new moodle_url('/local/kbacorporate/departments_overview.php', ['companyid' => $companyid]),
        'cap' => 'local/kbacorporate:viewdepartments',
    ],
    [
        'title' => get_string('attentioncenter', 'local_kbacorporate'),
        'text' => get_string('attentionnavdesc', 'local_kbacorporate'),
        'value' => $attentionsummary ? $attentionsummary->employees : '—',
        'url' => new moodle_url('/local/kbacorporate/alerts.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewalerts',
    ],
    [
        'title' => get_string('licensesnav', 'local_kbacorporate'),
        'text' => get_string('licensesnavdesc', 'local_kbacorporate'),
        'value' => !$licenseavailable ? '—' : ($licensesummary->totalseats > 0
            ? $licensesummary->free . ' / ' . $licensesummary->totalseats
            : $licensesummary->licenses),
        'url' => new moodle_url('/local/kbacorporate/licenses.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:viewlicenses',
    ],
    [
        'title' => get_string('gamificationnav', 'local_kbacorporate'),
        'text' => get_string('gamificationnavdesc', 'local_kbacorporate'),
        'value' => ($gamificationsnapshot && !empty($gamificationsnapshot['summary']))
            ? (int)$gamificationsnapshot['summary']['totalxp'] . ' XP'
            : '—',
        'url' => new moodle_url('/local/kbacorporate/gamification.php', [
            'companyid' => $companyid,
            'departmentid' => $departmentid,
        ]),
        'cap' => 'local/kbacorporate:viewgamification',
        'enabled' => $gamificationavailable && $gamificationsnapshot !== null,
    ],
    [
        'title' => get_string('reportnav', 'local_kbacorporate'),
        'text' => get_string('reportnavdesc', 'local_kbacorporate'),
        'value' => 'PDF / XLSX',
        'url' => new moodle_url('/local/kbacorporate/reports.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        'cap' => 'local/kbacorporate:exportreport',
    ],
];

// Keep the two audit entry points first: implementation-system audit, then employee competency audit.
foreach ($navcards as $sequence => &$card) {
    $card['_sequence'] = $sequence;
    $card['_order'] = $card['order'] ?? (100 + $sequence);
}
unset($card);
usort($navcards, static function(array $left, array $right): int {
    return [$left['_order'], $left['_sequence']] <=> [$right['_order'], $right['_sequence']];
});

echo html_writer::start_div('kbacorp-navgrid');
foreach ($navcards as $card) {
    if ((!empty($card['cap']) && !has_capability($card['cap'], $context)) ||
            (array_key_exists('enabled', $card) && !$card['enabled'])) {
        continue;
    }
    $content = html_writer::start_div('kbacorp-navcard-inner');
    $content .= html_writer::div(s((string)$card['value']), 'kbacorp-navcard-value');
    $content .= html_writer::div(s($card['title']), 'kbacorp-navcard-title');
    $content .= html_writer::div(s($card['text']), 'kbacorp-navcard-text');
    $content .= html_writer::div(get_string('open', 'local_kbacorporate') . ' →', 'kbacorp-navcard-open');
    $content .= html_writer::end_div();
    $tone = !empty($card['tone']) ? ' kbacorp-navcard-' . $card['tone'] : '';
    $primary = ($card['_order'] ?? 100) <= 2 ? ' kbacorp-navcard-primary' : '';
    echo html_writer::link($card['url'], $content, ['class' => 'kbacorp-navcard' . $tone . $primary]);
}
echo html_writer::end_div();

// KPI cards.
$cards = [
    [get_string('employees', 'local_kbacorporate'), $summary->employees],
    [get_string('training', 'local_kbacorporate'), $summary->training],
    [get_string('completed', 'local_kbacorporate'), $summary->completed],
    [get_string('avgprogress', 'local_kbacorporate'), $summary->avgprogress === null ? '—' : $summary->avgprogress . '%'],
    [get_string('avggrade', 'local_kbacorporate'), $summary->avggrade === null ? '—' : $summary->avggrade . '%'],
];
if (has_capability('local/kbacorporate:viewgamification', $context) && $gamificationsnapshot) {
    $gamificationsummary = $gamificationsnapshot['summary'];
    $cards[] = [get_string('gamificationtotalxp', 'local_kbacorporate'), (int)$gamificationsummary['totalxp']];
    $cards[] = [get_string('gamificationachievements', 'local_kbacorporate'),
        (int)$gamificationsummary['achievementcount']];
    if (\local_kbacorporate\local\gamification::show_coins()) {
        $cards[] = [get_string('gamificationtotalcoins', 'local_kbacorporate'),
            (int)$gamificationsummary['totalcoins']];
    }
}

if (has_capability('local/kbacorporate:viewattendance', $context)) {
    $cards[] = [
        get_string('bbbattendance', 'local_kbacorporate'),
        !$bbbavailable || $webinarsummary->attendancepercent === null ? '—' : $webinarsummary->attendancepercent . '%'
    ];
    $cards[] = [
        get_string('bbbavgcoverage', 'local_kbacorporate'),
        !$bbbavailable || $webinarsummary->avgcoveragepercent === null ? '—' : $webinarsummary->avgcoveragepercent . '%'
    ];
}
if (has_capability('local/kbacorporate:viewcertificates', $context)) {
    $cards[] = [
        get_string('certificatesissued', 'local_kbacorporate'),
        !$customcertavailable ? '—' : $certificatesummary->issued
    ];
    $cards[] = [
        get_string('certificatecoverage', 'local_kbacorporate'),
        !$customcertavailable || $certificatesummary->issuedpercent === null
            ? '—' : format_float($certificatesummary->issuedpercent, 1) . '%'
    ];
}
if (has_capability('local/kbacorporate:viewlicenses', $context)) {
    $cards[] = [
        get_string('purchasedseats', 'local_kbacorporate'),
        !$licenseavailable ? '—' : $licensesummary->totalseats
    ];
    $cards[] = [
        get_string('freeseats', 'local_kbacorporate'),
        !$licenseavailable ? '—' : $licensesummary->free
    ];
    $cards[] = [
        get_string('licenseutilization', 'local_kbacorporate'),
        !$licenseavailable || $licensesummary->utilizationpercent === null
            ? '—' : format_float($licensesummary->utilizationpercent, 1) . '%'
    ];
}
if (has_capability('local/kbacorporate:viewalerts', $context)) {
    $cards[] = [
        get_string('employeesrequiringattention', 'local_kbacorporate'),
        $attentionsummary ? $attentionsummary->employees : '—'
    ];
    $cards[] = [
        get_string('highriskalerts', 'local_kbacorporate'),
        $attentionsummary ? $attentionsummary->high : '—'
    ];
}
echo $OUTPUT->heading(get_string('companysummary', 'local_kbacorporate'), 3);
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as $card) {
    $label = $card[0];
    $value = $card[1];
    echo html_writer::div(html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

$hasemployees = $summary->employees > 0;
$hascourses = $coursecount > 0;
if (!$hasemployees || !$hascourses) {
    echo html_writer::start_div('kbacorp-empty-state');
    echo html_writer::div(get_string('startsetup', 'local_kbacorporate'), 'kbacorp-empty-title');
    if (!$hasemployees) {
        echo html_writer::div(get_string('noemployees', 'local_kbacorporate'), 'kbacorp-empty-text');
    }
    if (!$hascourses) {
        echo html_writer::div(get_string('nocoursesassigned', 'local_kbacorporate'), 'kbacorp-empty-text');
    }
    if (has_capability('local/kbacorporate:managemembers', $context) || has_capability('local/kbacorporate:managecourses', $context)) {
        echo html_writer::start_div('kbacorp-empty-actions');
        if (!$hasemployees && has_capability('local/kbacorporate:managemembers', $context)) {
            echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid]), get_string('addemployees', 'local_kbacorporate'), 'get');
        }
        if (!$hascourses && has_capability('local/kbacorporate:managecourses', $context)) {
            echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]), get_string('assigncourses', 'local_kbacorporate'), 'get');
        }
        echo html_writer::end_div();
    } else {
        echo html_writer::div(get_string('contactacademyadmin', 'local_kbacorporate'), 'kbacorp-empty-hint');
    }
    echo html_writer::end_div();
} else {
    echo $OUTPUT->heading(get_string('recentoverview', 'local_kbacorporate'), 3);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('fullname', 'local_kbacorporate'),
        get_string('course', 'local_kbacorporate'),
        get_string('status', 'local_kbacorporate'),
        get_string('progress', 'local_kbacorporate'),
        get_string('grade', 'local_kbacorporate'),
        get_string('lastaccess', 'local_kbacorporate'),
    ];
    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= 8) {
            break;
        }
        $progress = '—';
        if ($row->progress !== null) {
            $width = max(0, min(100, (float)$row->progress));
            $progress = html_writer::div(
                s($row->progress . '%') . html_writer::div(html_writer::span('', '', ['style' => 'width:' . $width . '%']), 'kbacorp-progressbar'),
                'kbacorp-progress'
            );
        }
        $userurl = new moodle_url('/local/kbacorporate/employee.php', ['companyid' => $companyid, 'userid' => $row->userid]);
        $table->data[] = [
            html_writer::link($userurl, s($row->fullname)),
            html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
            html_writer::span(get_string($row->status, 'local_kbacorporate'), 'kbacorp-status kbacorp-status-' . $row->status),
            $progress,
            $row->grade === null ? '—' : s($row->grade . '%'),
            $row->lastaccess ? userdate($row->lastaccess) : '—',
        ];
        $shown++;
    }
    echo html_writer::table($table);
    if (count($rows) > $shown) {
        echo html_writer::div(
            html_writer::link(new moodle_url('/local/kbacorporate/progress.php', ['companyid' => $companyid, 'departmentid' => $departmentid]), get_string('viewallprogress', 'local_kbacorporate') . ' →'),
            'kbacorp-viewall'
        );
    }
}

echo $OUTPUT->footer();
