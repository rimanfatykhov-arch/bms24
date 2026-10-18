<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\employee_service;
use local_kbacompany\local\integration_service;
use local_kbacompany\local\permission_service;
use local_kbacompany\local\platform_boundary_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$employees = employee_service::employees_context($uuid, (int)$USER->id, true);
$company = $employees['company'];
$canmanage = permission_service::can_manage_organization($uuid, (int)$USER->id) || is_siteadmin();
if (!$canmanage) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHAEXT);
    if ($action === 'reconcile') {
        integration_service::enqueue('company_reconcile', $uuid, 0, [
            'company_uuid' => $uuid,
            'external_company_id' => (string)$employees['external_company_id'],
            'legacy_corporate_company_id' => (int)($company['compatibility']['source_id'] ?? 0),
            'scope' => 'company_and_employees',
        ]);
        redirect(new moodle_url('/local/kbacompany/employee_diagnostics.php', ['uuid' => $uuid]),
            get_string('bms_reconcile_queued', 'local_kbacompany'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/employee_diagnostics.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('bms_employee_diagnostics_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'employees',
    get_string('bms_employee_diagnostics_title', 'local_kbacompany'));

$listurl = new moodle_url('/local/kbacompany/employees.php', ['uuid' => $uuid]);
echo html_writer::div(html_writer::link($listurl, '← ' . get_string('bms_employee_back_to_list', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(get_string('bms_employee_diagnostics_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('bms_employee_diagnostics_intro', 'local_kbacompany'), ['class' => 'text-muted']);

echo html_writer::link(new moodle_url('/local/kbacompany/integration_center.php', ['uuid' => $uuid]),
    get_string('bms_int_open_center', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);

// Mapping and reconciliation.
echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('bms_employee_section_integration', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
$companyexternal = (string)$employees['external_company_id'];
if ($companyexternal === '') {
    echo $OUTPUT->notification(get_string('bms_company_mapping_missing', 'local_kbacompany'), 'warning');
} else {
    echo html_writer::tag('p', get_string('bms_company_mapping', 'local_kbacompany', s($companyexternal)));
}
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-0']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reconcile']);
echo html_writer::tag('button', get_string('bms_reconcile_queue', 'local_kbacompany'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

$diag = integration_service::diagnostics($uuid, (int)$USER->id);
echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('bms_integration_diagnostics', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
echo html_writer::tag('p', get_string('bms_integration_consumer_' . ($diag['consumer_available'] ? 'available' : 'pending'),
    'local_kbacompany'));
$summary = (object)['pending' => $diag['summary']['pending'], 'error' => $diag['summary']['error'],
    'done' => $diag['summary']['done']];
echo html_writer::tag('p', get_string('bms_integration_summary', 'local_kbacompany', $summary), ['class' => 'mb-0']);
echo html_writer::end_div();
echo html_writer::end_div();

$platform = platform_boundary_service::diagnostics($uuid, (int)$USER->id);
echo html_writer::start_tag('details', ['class' => 'card mb-3']);
echo html_writer::tag('summary', get_string('bms_platform_diagnostics', 'local_kbacompany'), ['class' => 'card-header font-weight-bold']);
echo html_writer::start_div('card-body');
if (!empty($platform['workspace']['mapped'])) {
    echo html_writer::tag('p', get_string('bms_platform_workspace', 'local_kbacompany',
        (string)$platform['workspace']['workspace_uuid']));
} else {
    echo html_writer::tag('p', get_string('bms_platform_workspace_unmapped', 'local_kbacompany'));
}
echo html_writer::tag('p', get_string('bms_platform_entitlement_mode', 'local_kbacompany',
    (string)$platform['entitlement']['mode']));
echo html_writer::tag('p', get_string('bms_platform_entitlement_reason', 'local_kbacompany',
    (string)$platform['entitlement']['reason_code']));
echo html_writer::tag('p', get_string('bms_platform_cloud_status', 'local_kbacompany',
    (string)$platform['workspace']['cloud_status']));
echo html_writer::tag('p', get_string('bms_platform_cloud_sync', 'local_kbacompany',
    (string)$platform['workspace']['sync_freshness']));
echo html_writer::tag('p', get_string('bms_platform_snapshot_revision', 'local_kbacompany',
    (string)($platform['workspace']['snapshot_revision'] !== '' ? $platform['workspace']['snapshot_revision'] : '—')));
echo html_writer::tag('p', get_string('bms_platform_erp_state', 'local_kbacompany',
    (string)$platform['workspace']['erp_application_state']));
echo html_writer::tag('p', get_string('bms_platform_integration_health', 'local_kbacompany',
    (string)$platform['workspace']['integration_health']));
$gate = $platform['readiness'];
echo html_writer::tag('p', get_string('bms_platform_pre07_gate', 'local_kbacompany', (string)$gate['status']));
echo html_writer::tag('p', get_string('bms_platform_pre07_summary', 'local_kbacompany', (object)[
    'pass' => (int)$gate['summary']['pass'],
    'warning' => (int)$gate['summary']['warning'],
    'block' => (int)$gate['summary']['block'],
    'next' => (string)$gate['next_action'],
]), ['class' => 'mb-0']);
echo html_writer::end_div();
echo html_writer::end_tag('details');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
