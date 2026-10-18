<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedevelopmentplans', $context);
$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$defaultuserid = optional_param('userid', 0, PARAM_INT);
if (!$companyid && $companies) { $first = reset($companies); $companyid = (int)$first->id; }
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id);

if (data_submitted() && confirm_sesskey()) {
    $createuserid = required_param('createuserid', PARAM_INT);
    if (!in_array($createuserid, $visibleids, true)) {
        throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
    }
    $planid = \local_kbacorporate\local\development_plans::create_from_gaps($companyid, $createuserid, $USER->id);
    redirect(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
        get_string('developmentplancreated', 'local_kbacorporate'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/development_plans.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('developmentplans', 'local_kbacorporate'));
$PAGE->set_heading(get_string('developmentplans', 'local_kbacorporate'));
echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', [
    'companyid' => $companyid,
]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-page-navigation');
echo html_writer::start_div('kbacorp-development-list-hero');
echo html_writer::div(get_string('employeedevelopment', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('developmentplans', 'local_kbacorporate'), 2);
echo html_writer::div(get_string('developmentplansdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-development-list-actions');
echo html_writer::link(new moodle_url('/local/kbacorporate/development_effectiveness.php', [
    'companyid' => $companyid,
]), get_string('opendevelopmenteffectiveness', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
echo ' ';
echo html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
    'companyid' => $companyid,
]), get_string('gaplearningplanbuilder', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary']);
echo html_writer::end_div();

$companyoptions = [];
foreach ($companies as $item) { $companyoptions[(int)$item->id] = format_string($item->name); }
echo html_writer::start_div('kbacorp-effectiveness-context');
echo html_writer::div(get_string('company', 'local_kbacorporate'), 'kbacorp-effectiveness-context-label');
echo $OUTPUT->single_select(new moodle_url('/local/kbacorporate/development_plans.php'), 'companyid',
    $companyoptions, $companyid);
echo html_writer::end_div();

$useroptions = [];
if ($visibleids) {
    list($insql, $params) = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'dpu');
    foreach ($DB->get_records_select('user', "id {$insql} AND deleted = 0", $params, 'lastname, firstname') as $user) {
        $useroptions[(int)$user->id] = fullname($user);
    }
}
echo html_writer::start_div('kbacorp-development-create-panel');
echo html_writer::div(get_string('createdevelopmentplan', 'local_kbacorporate'), 'kbacorp-development-create-title');
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'createuserid');
echo html_writer::select($useroptions, 'createuserid', isset($useroptions[$defaultuserid]) ? $defaultuserid : 0,
    ['' => get_string('choose')], ['id' => 'createuserid', 'required' => 'required']);
echo html_writer::tag('button', get_string('generatedevelopmentplan', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();

$plans = \local_kbacorporate\local\development_plans::get_company_plans($companyid);
$visiblelookup = array_flip(array_map('intval', $visibleids));
$plans = array_values(array_filter($plans, static function($plan) use ($visiblelookup) {
    return isset($visiblelookup[(int)$plan->userid]);
}));
$planmetrics = ['total' => count($plans), 'draft' => 0, 'pending' => 0, 'active' => 0, 'completed' => 0];
foreach ($plans as $plan) {
    if (array_key_exists((string)$plan->status, $planmetrics)) {
        $planmetrics[(string)$plan->status]++;
    }
}
echo html_writer::start_div('kbacorp-development-list-metrics');
foreach ([
    ['total', get_string('developmentmetricplans', 'local_kbacorporate')],
    ['draft', get_string('developmentplanstatus_draft', 'local_kbacorporate')],
    ['pending', get_string('developmentplanstatus_pending', 'local_kbacorporate')],
    ['active', get_string('developmentplanstatus_active', 'local_kbacorporate')],
    ['completed', get_string('developmentplanstatus_completed', 'local_kbacorporate')],
] as $metric) {
    echo html_writer::div(html_writer::tag('strong', $planmetrics[$metric[0]]) .
        html_writer::span($metric[1]), 'kbacorp-development-list-metric kbacorp-development-list-metric-' . $metric[0]);
}
echo html_writer::end_div();
if (!$plans) {
    echo $OUTPUT->notification(get_string('nodevelopmentplans', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('developmentplan', 'local_kbacorporate'), get_string('employee', 'local_kbacorporate'),
        get_string('status'), get_string('developmentprogress', 'local_kbacorporate'),
        get_string('developmentactionsoverdue', 'local_kbacorporate'),
        get_string('targetdate', 'local_kbacorporate')];
    foreach ($plans as $plan) {
        $progress = (int)$plan->itemcount ? round(((int)$plan->completedcount / (int)$plan->itemcount) * 100) : 0;
        $items = \local_kbacorporate\local\development_plans::get_items((int)$plan->id);
        $summary = \local_kbacorporate\local\development_plans::get_progress_summary($items);
        $progresshtml = html_writer::div(html_writer::span('', '', ['style' => 'width:' . $progress . '%']),
            'kbacorp-progressbar') . html_writer::span($progress . '%');
        $statushtml = html_writer::span(get_string('developmentplanstatus_' . $plan->status, 'local_kbacorporate'),
            'kbacorp-development-status kbacorp-development-status-' . $plan->status);
        $plantitle = html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $plan->id]),
            format_string($plan->title), ['class' => 'kbacorp-development-plan-link']);
        $plantitle .= html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', [
            'id' => (int)$plan->id,
        ]), get_string('studyplanprogresstitle', 'local_kbacorporate')), 'small mt-1');
        $table->data[] = [$plantitle, fullname($plan), $statushtml,
            html_writer::div($progresshtml, 'kbacorp-progress'),
            $summary->overdue ? html_writer::span($summary->overdue, 'badge badge-danger') : '0',
            userdate((int)$plan->targetdate, get_string('strftimedate', 'langconfig'))];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll kbacorp-development-list-table');
}
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', [
    'companyid' => $companyid,
]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-page-navigation');
echo $OUTPUT->footer();
