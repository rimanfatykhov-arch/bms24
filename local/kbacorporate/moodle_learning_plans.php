<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid <= 0) {
    throw new invalid_parameter_exception('Company is required.');
}
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);

$url = new moodle_url('/local/kbacorporate/moodle_learning_plans.php', ['companyid' => $companyid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('moodlelearningplansintegration', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$available = \local_kbacorporate\local\moodle_learning_plans::available();
if (data_submitted() && confirm_sesskey()) {
    if (!$available) {
        throw new moodle_exception('moodlelearningplansdisabled', 'local_kbacorporate');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'syncall') {
        $result = \local_kbacorporate\local\moodle_learning_plans::migrate_company($companyid);
        $message = get_string('moodlelearningplanssyncresult', 'local_kbacorporate', (object)[
            'roles' => $result->roles,
            'templatescreated' => $result->templatescreated,
            'plans' => $result->plans,
            'planscreated' => $result->planscreated,
            'errors' => count($result->errors),
        ]);
        if ($result->errors) {
            $message .= ' ' . implode(' | ', array_slice($result->errors, 0, 5));
        }
        redirect($url, $message, null, $result->errors ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
    }
    throw new moodle_exception('invalidaction');
}

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
        get_string('businessroles', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', ['companyid' => $companyid]),
        get_string('rolelearningmatrix', 'local_kbacorporate')),
];
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('moodlelearningplansintegration', 'local_kbacorporate'));
echo html_writer::div(get_string('moodlelearningplansintegrationdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

if (!$available) {
    echo $OUTPUT->notification(get_string('moodlelearningplansdisabled', 'local_kbacorporate'), 'warning');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$status = \local_kbacorporate\local\moodle_learning_plans::company_status($companyid);
$cards = [
    [$status->rolelinked . '/' . $status->roles, get_string('moodletemplateslinked', 'local_kbacorporate')],
    [$status->planlinked . '/' . $status->plans, get_string('moodleplanslinked', 'local_kbacorporate')],
    [get_config('core_competency', 'enabled') ? get_string('yes') : get_string('no'), get_string('moodlecompetenciesenabled', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'), 'kbacorp-metric-card');
}
echo html_writer::end_div();

$alllinked = $status->roles === $status->rolelinked && $status->plans === $status->planlinked;
if ($alllinked) {
    echo $OUTPUT->notification(get_string('moodlelearningplansalllinked', 'local_kbacorporate'), 'success');
} else {
    echo $OUTPUT->notification(get_string('moodlelearningplansmigrationnotice', 'local_kbacorporate'), 'info');
}

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-toolbar']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'syncall']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', get_string('moodlelearningplanssyncall', 'local_kbacorporate'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

$roles = \local_kbacorporate\local\business_roles::get_catalog($companyid, false);
$roletable = new html_table();
$roletable->attributes['class'] = 'generaltable';
$roletable->head = [get_string('businessrole', 'local_kbacorporate'), get_string('moodlelearningplantemplate', 'local_kbacorporate'), get_string('status')];
foreach ($roles as $role) {
    $templateid = property_exists($role, 'moodletemplateid') ? (int)$role->moodletemplateid : 0;
    $valid = $templateid > 0 && $DB->record_exists('competency_template', ['id' => $templateid]);
    $template = $valid ? html_writer::link(
        \local_kbacorporate\local\moodle_learning_plans::template_url($templateid),
        get_string('openmoodletemplate', 'local_kbacorporate') . ' #' . $templateid,
        ['target' => '_blank', 'rel' => 'noopener']
    ) : '—';
    $roletable->data[] = [format_string($role->name), $template,
        $valid ? get_string('linked', 'local_kbacorporate') : get_string('notlinked', 'local_kbacorporate')];
}
echo $OUTPUT->heading(get_string('moodlelearningplantemplates', 'local_kbacorporate'), 3);
echo html_writer::div(html_writer::table($roletable), 'kbacorp-table-scroll');

$plans = $DB->get_records_select('local_kbacorp_devplan',
    'companyid = :companyid AND status IN (:draft, :pending, :active)', [
        'companyid' => $companyid,
        'draft' => \local_kbacorporate\local\development_plans::STATUS_DRAFT,
        'pending' => \local_kbacorporate\local\development_plans::STATUS_PENDING,
        'active' => \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
    ], 'timemodified DESC');
$plantable = new html_table();
$plantable->attributes['class'] = 'generaltable';
$plantable->head = [get_string('developmentplan', 'local_kbacorporate'), get_string('moodlelearningplan', 'local_kbacorporate'), get_string('status')];
foreach ($plans as $plan) {
    $moodleplanid = property_exists($plan, 'moodleplanid') ? (int)$plan->moodleplanid : 0;
    $valid = $moodleplanid > 0 && $DB->record_exists('competency_plan', ['id' => $moodleplanid]);
    $moodle = '—';
    $statuslabel = get_string('notlinked', 'local_kbacorporate');
    if ($valid) {
        $corerecord = $DB->get_record('competency_plan', ['id' => $moodleplanid], 'id,status');
        $moodle = html_writer::link(\local_kbacorporate\local\moodle_learning_plans::plan_url($moodleplanid),
            get_string('openmoodlelearningplan', 'local_kbacorporate') . ' #' . $moodleplanid,
            ['target' => '_blank', 'rel' => 'noopener']);
        $statuslabel = \local_kbacorporate\local\moodle_learning_plans::plan_status_name((int)$corerecord->status);
    }
    $plantable->data[] = [html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $plan->id]),
        format_string($plan->title)), $moodle, $statuslabel];
}
echo $OUTPUT->heading(get_string('moodlelearningplans', 'local_kbacorporate'), 3);
echo $plans ? html_writer::div(html_writer::table($plantable), 'kbacorp-table-scroll') :
    $OUTPUT->notification(get_string('nodevelopmentplans', 'local_kbacorporate'), 'info');

echo $navigation;
echo $OUTPUT->footer();
