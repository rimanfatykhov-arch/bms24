<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/corporate_schema_diagnostics.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('corporate_role_schema_diagnostics', 'local_bitrixsync'));

$service = new \local_bitrixsync\services\corporate_role_schema_service();
$status = $service->status();

$tokenconfigured = trim((string)get_config('local_bitrixsync', 'corporate_integration_token')) !== '';
$pairingservice = new \local_bitrixsync\services\corporate_pairing_service();
$connectionservice = new \local_bitrixsync\services\corporate_connection_service();
$activeconnection = $pairingservice->latest_connection();
$dashboard = is_array($status['dashboard'] ?? null) ? $status['dashboard'] : [];
$field = is_array($status['field'] ?? null) ? $status['field'] : [];
$roles = is_array($status['roles'] ?? null) ? $status['roles'] : [];
$rolecount = 0;
foreach ($roles as $role) {
    if (!empty($role['exists'])) {
        $rolecount++;
    }
}

$rows = [
    'Dashboard detected' => !empty($dashboard['detected']) ? 'Yes' : 'No',
    'Dashboard API' => (string)($dashboard['api_release'] ?? '0'),
    'Role schema endpoint' => !empty($dashboard['role_schema_compatible']) ? 'Ready' : 'Not ready',
    'Pairing support' => 'Ready',
    'Active connections' => (string)$connectionservice->active_count(),
    'Production Dashboard' => $activeconnection ? 'Connected' : 'Not connected',
    'Client version' => $activeconnection ? (string)$activeconnection->clientversion : '—',
    'Pairing API' => $activeconnection ? (string)$activeconnection->apiversion : '—',
    'Last seen' => $activeconnection && !empty($activeconnection->lastseen) ? userdate((int)$activeconnection->lastseen) : '—',
    'Legacy integration token' => $tokenconfigured ? 'Configured' : 'Not configured',
    'Bitrix contact field' => !empty($field['exists']) ? 'Found' : 'Missing',
    'Field code' => (string)($field['field_name'] ?? ''),
    'Type' => (string)($field['type'] ?? ''),
    'Multiple' => !empty($field['multiple']) ? 'Yes' : 'No',
    'Roles configured' => $roles ? ($rolecount . ' / ' . count($roles)) : '0 / 0',
    'Ready' => !empty($status['ready']) ? 'Yes' : 'No',
];

if (!empty($status['reason'])) {
    $rows['Reason'] = (string)$status['reason'];
}
if (empty($status['success'])) {
    $rows['Error'] = (string)($status['message'] ?? $status['code'] ?? 'Unknown');
}

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('diagnostics');
echo html_writer::tag('h2', get_string('corporate_role_schema_diagnostics', 'local_bitrixsync'));
echo html_writer::tag('p', get_string('corporate_role_schema_diagnostics_desc', 'local_bitrixsync'));

echo html_writer::start_tag('table', ['class' => 'generaltable']);
foreach ($rows as $label => $value) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', s($label));
    echo html_writer::tag('td', s($value));
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
