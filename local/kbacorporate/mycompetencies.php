<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$userid = optional_param('userid', $USER->id, PARAM_INT);
$requestedcompanyid = optional_param('companyid', 0, PARAM_INT);
$statusfilter = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$coursefilter = optional_param('courseid', 0, PARAM_INT);
$searchquery = trim((string)optional_param('q', '', PARAM_TEXT));
$allowedfilters = [
    'all', 'confirmed', 'developing', 'notassessed', 'notstarted',
    'needsconfirmation', 'outsideprofile', 'attention',
];
if (!in_array($statusfilter, $allowedfilters, true)) {
    $statusfilter = 'all';
}
$isself = ((int)$userid === (int)$USER->id);

if (!$isself) {
    require_capability('local/kbacorporate:viewcompetencies', $context);
}

$targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$companyoptions = \local_kbacorporate\local\access::get_competency_company_options($userid, $USER->id);
if (!$isself && !$companyoptions) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
$selectedcompany = \local_kbacorporate\local\access::resolve_competency_company_context(
    $userid,
    $USER->id,
    $requestedcompanyid
);
$companyid = $selectedcompany ? (int)$selectedcompany->id : 0;
$snapshot = \local_kbacorporate\local\competencies::get_user_snapshot($userid, $companyid, $isself);

$params = $isself ? [] : ['userid' => $userid];
if ($companyid > 0) {
    $params['companyid'] = $companyid;
}
if ($statusfilter !== 'all') {
    $params['status'] = $statusfilter;
}
if ($coursefilter > 0) {
    $params['courseid'] = $coursefilter;
}
if ($searchquery !== '') {
    $params['q'] = $searchquery;
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/mycompetencies.php', $params));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycompetencies', 'local_kbacorporate'));
$PAGE->set_heading($isself ? get_string('mycompetencies', 'local_kbacorporate') : fullname($targetuser));

$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));
$PAGE->requires->js_init_code(<<<'JS'
(function() {
    function setCardExpanded(button, expanded) {
        var target = document.getElementById(button.getAttribute('data-target'));
        if (!target) {
            return;
        }
        target.hidden = !expanded;
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.textContent = expanded ? button.getAttribute('data-collapselabel') : button.getAttribute('data-expandlabel');
    }

    document.querySelectorAll('.kbacorp-competency-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            var expanded = button.getAttribute('aria-expanded') === 'true';
            setCardExpanded(button, !expanded);
        });
    });

    var collapseAll = document.querySelector('[data-kbacorp-collapse-all]');
    if (collapseAll) {
        collapseAll.addEventListener('click', function() {
            document.querySelectorAll('.kbacorp-competency-toggle').forEach(function(button) {
                setCardExpanded(button, false);
            });
        });
    }

    var expandAll = document.querySelector('[data-kbacorp-expand-all]');
    if (expandAll) {
        expandAll.addEventListener('click', function() {
            document.querySelectorAll('.kbacorp-competency-toggle').forEach(function(button) {
                setCardExpanded(button, true);
            });
        });
    }
})();
JS
);

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'competencies');

echo html_writer::start_div('kbacorp-competency-hero');
echo html_writer::div(get_string('competencydevelopment', 'local_kbacorporate'), 'kbacorp-competency-eyebrow');
echo html_writer::tag('h2', $isself ? get_string('mycompetencies', 'local_kbacorporate') : s(fullname($targetuser)), ['class' => 'kbacorp-competency-title']);
echo html_writer::div(get_string('mycompetenciesdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::end_div();

if (count($companyoptions) > 1) {
    $selectorparams = $isself ? [] : ['userid' => $userid];
    if ($statusfilter !== 'all') {
        $selectorparams['status'] = $statusfilter;
    }
    if ($searchquery !== '') {
        $selectorparams['q'] = $searchquery;
    }
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/local/kbacorporate/mycompetencies.php'))->out(false),
        'class' => 'kbacorp-company-context-form',
    ]);
    foreach ($selectorparams as $name => $value) {
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $name,
            'value' => $value,
        ]);
    }
    echo html_writer::tag('label', get_string('competencycompanycontext', 'local_kbacorporate'), [
        'for' => 'kbacorp-company-context',
    ]);
    $companyoptionshtml = '';
    $chooseattributes = ['value' => 0];
    if ($companyid === 0) {
        $chooseattributes['selected'] = 'selected';
    }
    $companyoptionshtml .= html_writer::tag(
        'option',
        get_string('selectcompanyforcompetencies', 'local_kbacorporate'),
        $chooseattributes
    );
    foreach ($companyoptions as $company) {
        $attributes = ['value' => (int)$company->id];
        if ((int)$company->id === $companyid) {
            $attributes['selected'] = 'selected';
        }
        $companyoptionshtml .= html_writer::tag('option', s($company->name), $attributes);
    }
    echo html_writer::tag('select', $companyoptionshtml, [
        'name' => 'companyid',
        'id' => 'kbacorp-company-context',
        'class' => 'custom-select',
    ]);
    echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}
if (count($companyoptions) > 1 && $companyid === 0) {
    echo $OUTPUT->notification(get_string('competencycompanyrequired', 'local_kbacorporate'), 'info');
}

if ($selectedcompany && $snapshot['businessroles']) {
    $rolelabels = array_map(static function($role) {
        return format_string($role->name) . ' (' . s($role->code) . ')';
    }, $snapshot['businessroles']);
    echo html_writer::div(
        html_writer::tag('strong', get_string('businessrolesforcompany', 'local_kbacorporate', s($selectedcompany->name))) .
        html_writer::span(s(implode(', ', $rolelabels))),
        'kbacorp-business-role-context'
    );
}

// Contextual management navigation is visible only to authorised managers.
if ($companyid > 0 &&
        \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id)) {
    $managementlinks = [];
    $managementlinks[] = $OUTPUT->single_button(new moodle_url(
        '/local/kbacorporate/employee_competency_profile.php',
        ['companyid' => $companyid, 'userid' => $userid]
    ), get_string('employeecompetencyprofile', 'local_kbacorporate'), 'get');
    if (has_capability('local/kbacorporate:managerolecompetencies', $context)) {
        $managementlinks[] = $OUTPUT->single_button(new moodle_url(
            '/local/kbacorporate/business_roles.php',
            ['companyid' => $companyid]
        ), get_string('businessroles', 'local_kbacorporate'), 'get');
        if (is_siteadmin()) {
            $managementlinks[] = $OUTPUT->single_button(new moodle_url(
                '/local/kbacorporate/role_templates.php',
                ['companyid' => $companyid]
            ), get_string('roletemplatelibrary', 'local_kbacorporate'), 'get');
        }
    }
    if (has_capability('local/kbacorporate:managecompetencyaudits', $context)) {
        $managementlinks[] = $OUTPUT->single_button(new moodle_url(
            '/local/kbacorporate/competency_audits.php',
            ['companyid' => $companyid, 'userid' => $userid]
        ), get_string('competencyaudits', 'local_kbacorporate'), 'get');
    }
    if (has_capability('local/kbacorporate:managedevelopmentplans', $context)) {
        $managementlinks[] = $OUTPUT->single_button(new moodle_url(
            '/local/kbacorporate/development_plans.php',
            ['companyid' => $companyid, 'userid' => $userid]
        ), get_string('developmentplans', 'local_kbacorporate'), 'get');
    }
    if ($managementlinks) {
        echo html_writer::div(implode('', $managementlinks), 'kbacorp-toolbar kbacorp-competency-management');
    }
}
if ($companyid > 0 && \local_kbacorporate\local\development_plans::available()) {
    $visibleplanstatuses = $isself ? [
        \local_kbacorporate\local\development_plans::STATUS_PENDING,
        \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
    ] : [
        \local_kbacorporate\local\development_plans::STATUS_DRAFT,
        \local_kbacorporate\local\development_plans::STATUS_PENDING,
        \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
    ];
    [$statussql, $statusparams] = $DB->get_in_or_equal($visibleplanstatuses, SQL_PARAMS_NAMED, 'myplanstatus');
    $statusparams['companyid'] = $companyid;
    $statusparams['userid'] = $userid;
    $activeplan = $DB->get_record_select('local_kbacorp_devplan',
        "companyid = :companyid AND userid = :userid AND status {$statussql}",
        $statusparams, 'id,title,status', IGNORE_MULTIPLE);
    if ($activeplan) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', [
            'id' => $activeplan->id,
        ]), get_string('opendevelopmentplanstatus', 'local_kbacorporate',
            get_string('developmentplanstatus_' . $activeplan->status, 'local_kbacorporate')) . ' →'), 'kbacorp-viewall');
    }
}
if (!$isself && $companyid > 0 &&
        has_capability('local/kbacorporate:managecompetencyaudits', $context) &&
        \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id)) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('createemployeeaudit', 'local_kbacorporate') . ' →'), 'kbacorp-viewall');
}

if (!$snapshot['available']) {
    echo $OUTPUT->notification(get_string('competencynotavailable', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$summary = $snapshot['summary'];
$cards = [
    ['label' => get_string('competenciestotal', 'local_kbacorporate'), 'value' => $summary->total, 'class' => 'kbacorp-card-accent-total'],
    ['label' => get_string('competenciestargeted', 'local_kbacorporate'), 'value' => $summary->targeted, 'class' => 'kbacorp-card-accent-total'],
    ['label' => get_string('competenciesconfirmed', 'local_kbacorporate'), 'value' => $summary->confirmed, 'class' => 'kbacorp-card-accent-confirmed'],
    ['label' => get_string('competenciesdeveloping', 'local_kbacorporate'), 'value' => $summary->developing, 'class' => 'kbacorp-card-accent-developing'],
    ['label' => get_string('competenciesattention', 'local_kbacorporate'), 'value' => $summary->attention, 'class' => 'kbacorp-card-accent-notstarted'],
];
echo html_writer::start_div('kbacorp-summary kbacorp-competency-summary');
foreach ($cards as $card) {
    $content = html_writer::div(s((string)$card['value']), 'value') . html_writer::div(s($card['label']), 'label');
    echo html_writer::div($content, 'kbacorp-card ' . $card['class']);
}
echo html_writer::end_div();

if (!$snapshot['competencies']) {
    echo $OUTPUT->notification(get_string('nocompetenciesassigned', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$plannames = [];
foreach ($snapshot['plans'] as $plan) {
    $plannames[(int)$plan->id] = format_string($plan->name);
}
$coursenames = [];
foreach ($snapshot['courses'] as $course) {
    $coursenames[(int)$course->id] = format_string($course->fullname);
}

$basecompetencies = [];
$searchneedle = core_text::strtolower($searchquery);
foreach ($snapshot['competencies'] as $item) {
    if ($coursefilter > 0) {
        $sourcecourses = array_map('intval', $item->sourcecourses ?? []);
        if (!in_array($coursefilter, $sourcecourses, true)) {
            continue;
        }
    }
    if ($searchneedle !== '') {
        $searchstack = trim((string)$item->idnumber . ' ' . (string)$item->shortname . ' ' . html_to_text((string)$item->description, 0, false));
        if (core_text::strpos(core_text::strtolower($searchstack), $searchneedle) === false) {
            continue;
        }
    }
    $basecompetencies[] = $item;
}

$statuscounts = [
    'all' => count($basecompetencies),
    'confirmed' => 0,
    'developing' => 0,
    'notassessed' => 0,
    'notstarted' => 0,
    'needsconfirmation' => 0,
    'outsideprofile' => 0,
    'attention' => 0,
];
foreach ($basecompetencies as $item) {
    if (isset($statuscounts[$item->displaystatus])) {
        $statuscounts[$item->displaystatus]++;
    }
    if (!empty($item->attentionrequired)) {
        $statuscounts['attention']++;
    }
}
$filteredcompetencies = [];
foreach ($basecompetencies as $item) {
    if ($statusfilter === 'all' || $item->displaystatus === $statusfilter ||
            ($statusfilter === 'attention' && !empty($item->attentionrequired))) {
        $filteredcompetencies[] = $item;
    }
}

$courseoptions = [0 => get_string('allcourses', 'local_kbacorporate')] + $coursenames;

echo $OUTPUT->heading(get_string('competenciesfromlearning', 'local_kbacorporate'), 3);

$filteritems = [
    'all' => get_string('filterstatusall', 'local_kbacorporate'),
    'confirmed' => get_string('competencystatus_confirmed', 'local_kbacorporate'),
    'developing' => get_string('competencystatus_developing', 'local_kbacorporate'),
    'notassessed' => get_string('competencystatus_notassessed', 'local_kbacorporate'),
    'notstarted' => get_string('competencystatus_notstarted', 'local_kbacorporate'),
    'needsconfirmation' => get_string('competencystatus_needsconfirmation', 'local_kbacorporate'),
    'outsideprofile' => get_string('competencystatus_outsideprofile', 'local_kbacorporate'),
    'attention' => get_string('competencystatus_attention', 'local_kbacorporate'),
];
echo html_writer::start_div('kbacorp-competency-filters');
echo html_writer::div(get_string('filterbystatus', 'local_kbacorporate'), 'kbacorp-competency-filters-label');
echo html_writer::start_div('kbacorp-competency-filter-list');
foreach ($filteritems as $filterkey => $filterlabel) {
    $filterparams = $isself ? [] : ['userid' => $userid];
    if ($companyid > 0) {
        $filterparams['companyid'] = $companyid;
    }
    if ($filterkey !== 'all') {
        $filterparams['status'] = $filterkey;
    }
    if ($coursefilter > 0) {
        $filterparams['courseid'] = $coursefilter;
    }
    if ($searchquery !== '') {
        $filterparams['q'] = $searchquery;
    }
    $count = $statuscounts[$filterkey] ?? 0;
    $classes = 'kbacorp-filter-chip';
    if ($filterkey === $statusfilter) {
        $classes .= ' is-active';
    }
    $content = html_writer::span($filterlabel, 'kbacorp-filter-chip-label') .
        html_writer::span((string)$count, 'kbacorp-filter-chip-count');
    echo html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', $filterparams), $content, ['class' => $classes]);
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-competency-toolbar');
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/mycompetencies.php'))->out(false),
    'class' => 'kbacorp-competency-searchform',
]);
if (!$isself) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
}
if ($companyid > 0) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'status', 'value' => $statusfilter]);
echo html_writer::start_div('kbacorp-competency-toolbar-fields');
echo html_writer::start_div('kbacorp-toolbar-field');
echo html_writer::tag('label', get_string('filterbycourse', 'local_kbacorporate'), ['for' => 'kbacorp-coursefilter']);
$optionshtml = '';
foreach ($courseoptions as $optionvalue => $optionlabel) {
    $attributes = ['value' => (int)$optionvalue];
    if ((int)$optionvalue === (int)$coursefilter) {
        $attributes['selected'] = 'selected';
    }
    $optionshtml .= html_writer::tag('option', s($optionlabel), $attributes);
}
echo html_writer::tag('select', $optionshtml, ['name' => 'courseid', 'id' => 'kbacorp-coursefilter', 'class' => 'custom-select']);
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-toolbar-field kbacorp-toolbar-field-search');
echo html_writer::tag('label', get_string('searchcompetencies', 'local_kbacorporate'), ['for' => 'kbacorp-search']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'q',
    'id' => 'kbacorp-search',
    'value' => $searchquery,
    'class' => 'form-control',
    'placeholder' => get_string('searchcompetenciesplaceholder', 'local_kbacorporate'),
]);
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-toolbar-actions');
echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-primary']);
$resetparams = $isself ? [] : ['userid' => $userid];
if ($companyid > 0) {
    $resetparams['companyid'] = $companyid;
}
echo html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', $resetparams), get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('kbacorp-competency-view-actions');
echo html_writer::tag('button', get_string('collapseall', 'local_kbacorporate'), ['type' => 'button', 'class' => 'btn btn-light', 'data-kbacorp-collapse-all' => '1']);
echo html_writer::tag('button', get_string('expandall', 'local_kbacorporate'), ['type' => 'button', 'class' => 'btn btn-light', 'data-kbacorp-expand-all' => '1']);
echo html_writer::end_div();
echo html_writer::end_div();

if (!$filteredcompetencies) {
    echo $OUTPUT->notification(get_string('nocompetenciesforselectedfilters', 'local_kbacorporate'), 'info');
    echo html_writer::div(get_string('competencypilotnote', 'local_kbacorporate'), 'kbacorp-info-note');
    echo $OUTPUT->footer();
    exit;
}

$competencyindex = 0;
foreach ($filteredcompetencies as $competency) {
    $competencyindex++;
    $bodyid = 'kbacorp-competency-body-' . $competencyindex;

    $statuskey = 'competencystatus_' . $competency->displaystatus;
    $statuslabel = get_string($statuskey, 'local_kbacorporate');
    $code = trim((string)$competency->idnumber);
    $title = ($code !== '' ? s($code) . ' · ' : '') . format_string($competency->shortname);
    $description = trim(html_to_text((string)$competency->description, 0, false));

    $cardclass = 'kbacorp-competency-card kbacorp-competency-status-' . $competency->displaystatus;
    if (!empty($competency->attentionrequired)) {
        $cardclass .= ' kbacorp-competency-attention-required';
    }
    echo html_writer::start_div($cardclass);
    echo html_writer::start_div('kbacorp-competency-card-head');
    echo html_writer::start_div('kbacorp-competency-card-titlebox');
    echo html_writer::tag('h4', $title, ['class' => 'kbacorp-competency-card-title']);
    if (!empty($competency->frameworkname)) {
        echo html_writer::div(format_string($competency->frameworkname), 'kbacorp-muted');
    }
    $sourceshtml = [];
    foreach (($competency->sourcecourses ?? []) as $courseid) {
        $courseid = (int)$courseid;
        if (isset($coursenames[$courseid])) {
            if ($isself) {
                $url = new moodle_url('/course/view.php', ['id' => $courseid]);
                $link = html_writer::link($url, $coursenames[$courseid], [
                    'class' => 'kbacorp-source-chip kbacorp-source-chip-course',
                    'title' => get_string('competencysourcecourse', 'local_kbacorporate'),
                ]);
            } else {
                $link = html_writer::span(
                    s($coursenames[$courseid]),
                    'kbacorp-source-chip kbacorp-source-chip-course'
                );
            }
            $sourceshtml[] = html_writer::span(get_string('competencysourcecourse', 'local_kbacorporate') . ': ', 'kbacorp-source-prefix') . $link;
        }
    }
    foreach (($competency->sourceplans ?? []) as $planid) {
        $planid = (int)$planid;
        if (isset($plannames[$planid])) {
            $sourceshtml[] = html_writer::span(get_string('competencysourceplan', 'local_kbacorporate') . ': ', 'kbacorp-source-prefix') .
                html_writer::span($plannames[$planid], 'kbacorp-source-chip kbacorp-source-chip-plan');
        }
    }
    foreach (($competency->targetroles ?? []) as $targetrole) {
        $rolelabel = format_string($targetrole->name);
        $rolecode = trim((string)$targetrole->code);
        if ($rolecode !== '') {
            $rolelabel .= ' · ' . $rolecode;
        }
        $sourceshtml[] = html_writer::span(get_string('competencysourcerole', 'local_kbacorporate') . ': ', 'kbacorp-source-prefix') .
            html_writer::span($rolelabel, 'kbacorp-source-chip kbacorp-source-chip-role');
    }
    if ($sourceshtml) {
        echo html_writer::start_div('kbacorp-competency-source');
        foreach ($sourceshtml as $sourcehtml) {
            echo html_writer::div($sourcehtml, 'kbacorp-competency-source-item');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::start_div('kbacorp-competency-card-actions');
    echo html_writer::span(s($statuslabel), 'kbacorp-competency-badge kbacorp-competency-badge-' . $competency->displaystatus);
    if (!empty($competency->attentionrequired)) {
        echo html_writer::span(
            get_string('competencystatus_attention', 'local_kbacorporate'),
            'kbacorp-competency-badge kbacorp-competency-badge-attention'
        );
    }
    echo html_writer::tag('button', get_string('collapse', 'local_kbacorporate'), [
        'type' => 'button',
        'class' => 'kbacorp-competency-toggle',
        'data-target' => $bodyid,
        'data-expandlabel' => get_string('expand', 'local_kbacorporate'),
        'data-collapselabel' => get_string('collapse', 'local_kbacorporate'),
        'aria-expanded' => 'true',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-competency-body', ['id' => $bodyid]);
    if ($description !== '') {
        echo html_writer::div(s(shorten_text($description, 260)), 'kbacorp-competency-description');
    }

    echo html_writer::start_div('kbacorp-competency-levels');
    $scaleid = (int)($competency->competencyscaleid ?: $competency->frameworkscaleid);
    $levelitems = [
        [get_string('currentlevel', 'local_kbacorporate'), \local_kbacorporate\local\competencies::level_label($competency->currentlevel, $scaleid)],
        [get_string('targetlevel', 'local_kbacorporate'), $competency->targetlevel === null
            ? get_string('competencytargetnotset', 'local_kbacorporate')
            : \local_kbacorporate\local\competencies::level_label($competency->targetlevel, $scaleid)],
        [get_string('competencygap', 'local_kbacorporate'), $competency->gap === null
            ? get_string('competencygap_' . $competency->gapreason, 'local_kbacorporate')
            : (string)$competency->gap],
    ];
    foreach ($levelitems as $item) {
        echo html_writer::div(html_writer::div(s($item[0]), 'kbacorp-competency-level-label') .
            html_writer::div(s($item[1]), 'kbacorp-competency-level-value'), 'kbacorp-competency-level');
    }
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-competency-assessment');
    echo html_writer::tag('strong', get_string('latestassessment', 'local_kbacorporate'));
    if (empty($competency->latestassessment)) {
        echo html_writer::div(get_string('noassessmentyet', 'local_kbacorporate'), 'kbacorp-muted');
    } else {
        $assessment = $competency->latestassessment;
        if ($assessment->type === 'audit') {
            $assessmentlabel = get_string('auditassessment', 'local_kbacorporate');
        } else if ($assessment->type === 'ai') {
            $assessmentlabel = get_string('aiassessment', 'local_kbacorporate');
        } else {
            $assessmentlabel = get_string('assessmentconfirmation', 'local_kbacorporate');
        }
        $assessmentparts = [$assessmentlabel];
        if (!empty($assessment->assessorname)) {
            $assessmentparts[] = $assessment->assessorname;
        }
        if (!empty($assessment->timecreated)) {
            $assessmentparts[] = userdate($assessment->timecreated, get_string('strftimedateshort', 'langconfig'));
        }
        echo html_writer::div(s(implode(' · ', $assessmentparts)), 'kbacorp-competency-assessment-meta');
    }
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-competency-recommendations');
    echo html_writer::tag('strong', get_string('recommendedcourses', 'local_kbacorporate'));
    if (empty($competency->recommendedcourses)) {
        echo html_writer::div(get_string('norecommendedcourses', 'local_kbacorporate'), 'kbacorp-muted');
    } else {
        echo html_writer::start_tag('ul', ['class' => 'kbacorp-competency-course-list']);
        foreach ($competency->recommendedcourses as $recommendation) {
            $coursecontent = $recommendation->url !== ''
                ? html_writer::link($recommendation->url, s($recommendation->fullname))
                : html_writer::span(s($recommendation->fullname));
            $coursestatus = get_string(
                'recommendation_' . $recommendation->enrolmentstatus,
                'local_kbacorporate'
            );
            if ($recommendation->completionstatus === 'completed') {
                $coursestatus .= ' · ' . get_string('recommendation_completed', 'local_kbacorporate');
            } else if ($recommendation->completionstatus === 'inprogress') {
                $coursestatus .= ' · ' . get_string('recommendation_inprogress', 'local_kbacorporate');
                if ($recommendation->progress !== null) {
                    $coursestatus .= ' ' . $recommendation->progress . '%';
                }
            }
            echo html_writer::tag(
                'li',
                $coursecontent . html_writer::span(s($coursestatus), 'kbacorp-course-status')
            );
        }
        echo html_writer::end_tag('ul');
        echo html_writer::div(
            get_string('coursecompletionnotcompetence', 'local_kbacorporate'),
            'kbacorp-muted kbacorp-course-note'
        );
    }
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-competency-evidence');
    echo html_writer::tag('strong', get_string('competencyevidence', 'local_kbacorporate'));
    if (!empty($competency->evidenceprogress) && $competency->evidenceprogress->total > 0) {
        $progressparts = [];
        if ($competency->evidenceprogress->learning > 0) {
            $progressparts[] = get_string('evidencelearning', 'local_kbacorporate') . ': ' . $competency->evidenceprogress->learning;
        }
        if ($competency->evidenceprogress->checks > 0) {
            $progressparts[] = get_string('evidencechecks', 'local_kbacorporate') . ': ' . $competency->evidenceprogress->checks;
        }
        if ($competency->evidenceprogress->ai > 0) {
            $progressparts[] = get_string('aiassessment', 'local_kbacorporate') . ': ' . $competency->evidenceprogress->ai;
        }
        echo html_writer::div(s(implode(' · ', $progressparts)), 'kbacorp-competency-evidence-progress');
    }
    if (!$competency->evidence) {
        echo html_writer::div(get_string('noevidenceyet', 'local_kbacorporate'), 'kbacorp-muted');
    } else {
        echo html_writer::start_tag('ul', ['class' => 'kbacorp-competency-evidence-list']);
        foreach (array_slice($competency->evidence, 0, 5) as $evidence) {
            if ($evidence->type === 'ai') {
                $prefix = get_string('aiassessment', 'local_kbacorporate');
            } else if ($evidence->type === 'audit') {
                $prefix = get_string('auditassessment', 'local_kbacorporate');
            } else if ($evidence->type === 'check') {
                $prefix = get_string('evidencecheck', 'local_kbacorporate');
            } else {
                $prefix = get_string('evidencelearningitem', 'local_kbacorporate');
            }
            $text = $evidence->text !== '' ? $evidence->text : get_string('competencyevidenceitem', 'local_kbacorporate');
            $meta = $evidence->timecreated ? ' · ' . userdate($evidence->timecreated, get_string('strftimedateshort', 'langconfig')) : '';
            echo html_writer::tag('li', html_writer::tag('strong', s($prefix)) . ': ' . s($text . $meta));
        }
        echo html_writer::end_tag('ul');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::div(get_string('competencypilotnote', 'local_kbacorporate'), 'kbacorp-info-note');
echo $OUTPUT->footer();
