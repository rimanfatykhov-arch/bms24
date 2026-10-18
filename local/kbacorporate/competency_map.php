<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$roleid = optional_param('roleid', 0, PARAM_INT);
$frameworkid = optional_param('frameworkid', 0, PARAM_INT);
$status = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;
$allowedstatuses = ['all', 'attention', 'gap', 'notassessed', 'targetreached'];
if (!in_array($status, $allowedstatuses, true)) {
    $status = 'all';
}
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if (!$companyid || !isset($companies[$companyid])) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}
\local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$params = ['companyid' => $companyid];
foreach (['departmentid' => $departmentid, 'roleid' => $roleid, 'frameworkid' => $frameworkid] as $key => $value) {
    if ($value) { $params[$key] = $value; }
}
if ($status !== 'all') { $params['status'] = $status; }
if ($query !== '') { $params['q'] = $query; }
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/competency_map.php', $params));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('competencymap', 'local_kbacorporate'));
$PAGE->set_heading(get_string('competencymap', 'local_kbacorporate'));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$map = \local_kbacorporate\local\competency_map::build($companyid, $USER->id, $departmentid);
$rows = \local_kbacorporate\local\competency_map::filter($map['rows'], $roleid, $frameworkid, $status, $query);
$summary = \local_kbacorporate\local\competency_map::summary($rows);
$totalrows = count($rows);
$displayrows = array_slice($rows, $page * $perpage, $perpage);
$roleoptions = [0 => get_string('allbusinessroles', 'local_kbacorporate')];
$frameworkoptions = [0 => get_string('allframeworks', 'local_kbacorporate')];
foreach ($map['rows'] as $row) {
    foreach ($row->roleids as $index => $maproleid) {
        $roleoptions[$maproleid] = $row->roles[$index];
    }
    $frameworkoptions[$row->frameworkid] = $row->framework;
}
asort($roleoptions);
asort($frameworkoptions);

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::start_div('kbacorp-hero');
echo html_writer::div(get_string('competencydevelopment', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('competencymap', 'local_kbacorporate'), 2);
echo html_writer::div(get_string('competencymapdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::end_div();

$companyoptions = [];
foreach ($companies as $company) { $companyoptions[(int)$company->id] = format_string($company->name); }
echo $OUTPUT->single_select(new moodle_url('/local/kbacorporate/competency_map.php'), 'companyid', $companyoptions, $companyid);
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid,
    '/local/kbacorporate/competency_map.php', ['roleid' => $roleid, 'frameworkid' => $frameworkid, 'status' => $status, 'q' => $query]);

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
if ($departmentid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]); }
echo html_writer::select($roleoptions, 'roleid', $roleid, false, [
    'aria-label' => get_string('businessrole', 'local_kbacorporate'),
    'class' => 'kbacorp-map-autosubmit',
]);
echo html_writer::select($frameworkoptions, 'frameworkid', $frameworkid, false, [
    'aria-label' => get_string('competencyframework', 'local_kbacorporate'),
    'class' => 'kbacorp-map-autosubmit',
]);
$statusoptions = ['all' => get_string('allstatuses', 'local_kbacorporate'), 'attention' => get_string('requiresattention', 'local_kbacorporate'),
    'gap' => get_string('confirmedcompetencygap', 'local_kbacorporate'), 'notassessed' => get_string('competencynotassessed', 'local_kbacorporate'),
    'targetreached' => get_string('competencystatus_confirmed', 'local_kbacorporate')];
echo html_writer::select($statusoptions, 'status', $status, false, [
    'aria-label' => get_string('status'),
    'class' => 'kbacorp-map-autosubmit',
]);
echo html_writer::start_div('kbacorp-map-search');
echo html_writer::tag('label', get_string('search'), ['for' => 'kbacorp-map-query']);
echo html_writer::empty_tag('input', ['type' => 'search', 'id' => 'kbacorp-map-query', 'name' => 'q',
    'value' => $query, 'placeholder' => get_string('competencymapsearch', 'local_kbacorporate')]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('competencymapfind', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/competency_map.php', ['companyid' => $companyid]),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$activefilters = [];
if ($departmentid) {
    $activefilters[] = get_string('activefilterdepartment', 'local_kbacorporate');
}
if ($roleid && isset($roleoptions[$roleid])) {
    $activefilters[] = get_string('activefilterrole', 'local_kbacorporate', $roleoptions[$roleid]);
}
if ($frameworkid && isset($frameworkoptions[$frameworkid])) {
    $activefilters[] = get_string('activefilterframework', 'local_kbacorporate', $frameworkoptions[$frameworkid]);
}
if ($status !== 'all') {
    $activefilters[] = get_string('activefilterstatus', 'local_kbacorporate', $statusoptions[$status]);
}
if ($query !== '') {
    $activefilters[] = get_string('activefiltersearch', 'local_kbacorporate', $query);
}
if ($activefilters) {
    echo html_writer::div(get_string('activefilters', 'local_kbacorporate') . ': ' .
        s(implode(' · ', $activefilters)), 'alert alert-info kbacorp-active-filters');
}
$PAGE->requires->js_init_code("document.querySelectorAll('.kbacorp-map-autosubmit').forEach(function (field) {" .
    "field.addEventListener('change', function () { field.form.submit(); }); });");

$cards = [[get_string('employees', 'local_kbacorporate'), $summary->employees],
    [get_string('targetrequirements', 'local_kbacorporate'), $summary->requirements],
    [get_string('assessmentcoverage', 'local_kbacorporate'), $summary->assessmentcoverage . '%'],
    [get_string('targetreadiness', 'local_kbacorporate'), $summary->targetreadiness . '%'],
    [get_string('competencygaps', 'local_kbacorporate'), $summary->gaps],
    [get_string('requiresattention', 'local_kbacorporate'), $summary->attention]];
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as [$label, $value]) {
    echo html_writer::div(html_writer::div((string)$value, 'value') . html_writer::div($label, 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

if (has_capability('local/kbacorporate:exportreport', $context)) {
    $exportparams = $params;
    unset($exportparams['page']);
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/competency_map_export.php', $exportparams),
        get_string('exportcompetencymapcsv', 'local_kbacorporate'), 'get', ['class' => 'mb-3']);
}

if (!$displayrows) {
    echo $OUTPUT->notification(get_string('nocompetencymaprows', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [get_string('employee', 'local_kbacorporate'), get_string('department', 'local_kbacorporate'),
        get_string('businessrole', 'local_kbacorporate'), get_string('competencylabel', 'local_kbacorporate'),
        get_string('competencyframework', 'local_kbacorporate'), get_string('currentlevel', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'), get_string('competencygap', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'), get_string('status'), get_string('actions', 'local_kbacorporate')];
    foreach ($displayrows as $row) {
        $current = $row->currentlevel === null ? get_string('competencynotassessed', 'local_kbacorporate')
            : \local_kbacorporate\local\competencies::level_label($row->currentlevel);
        $gap = $row->gap === null ? '—' : (string)$row->gap;
        $profileurl = new moodle_url('/local/kbacorporate/employee_competency_profile.php', ['companyid' => $companyid, 'userid' => $row->userid]);
        $actions = html_writer::link($profileurl, get_string('opencompetencyprofile', 'local_kbacorporate'), [
            'class' => 'btn btn-sm btn-outline-primary',
        ]);
        if (has_capability('local/kbacorporate:managecompetencyaudits', $context)) {
            $actions .= html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php',
                ['companyid' => $companyid, 'userid' => $row->userid]), get_string('createaudit', 'local_kbacorporate'), [
                    'class' => 'btn btn-sm btn-outline-secondary',
                ]);
        }
        if (has_capability('local/kbacorporate:managedevelopmentplans', $context)) {
            $actions .= html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php',
                ['companyid' => $companyid, 'userid' => $row->userid]),
                get_string('createdevelopmentplan', 'local_kbacorporate'), [
                    'class' => 'btn btn-sm btn-outline-secondary',
                ]);
        }
        $table->data[] = [html_writer::link($profileurl, s($row->employee)), s(implode(', ', $row->departments)),
            s(implode(', ', $row->roles)), s($row->competency), s($row->framework), $current,
            \local_kbacorporate\local\competencies::level_label($row->targetlevel), $gap,
            s(get_string('rolepriority_' . $row->priority, 'local_kbacorporate')),
            s(get_string('competencystatus_' . $row->status, 'local_kbacorporate')), $actions];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-competency-map-table-wrap');
    echo $OUTPUT->paging_bar($totalrows, $page, $perpage,
        new moodle_url('/local/kbacorporate/competency_map.php', $params));
}
echo $OUTPUT->footer();
