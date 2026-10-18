<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/api.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('apidiagnostics', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$status = \local_kbacorporate\local\bridge::status();
$contract = \local_kbacorporate\local\bridge::contract();
$diskinfo = (static function(): stdClass {
    $plugin = new stdClass();
    require(__DIR__ . '/version.php');
    return $plugin;
})();
$diskversion = isset($diskinfo->version) ? (int)$diskinfo->version : 0;
$diskrelease = isset($diskinfo->release) ? (string)$diskinfo->release : '';
$dbpluginversion = get_config('local_kbacorporate', 'version');

$yes = get_string('yes');
$no = get_string('no');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('apidiagnostics', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('apidiagnosticsdesc', 'local_kbacorporate'), ['class' => 'text-muted']);

$summary = new html_table();
$summary->head = [get_string('parameter', 'local_kbacorporate'), get_string('value', 'local_kbacorporate')];
$summary->data[] = [get_string('apicomponent', 'local_kbacorporate'), s($status['component'])];
$summary->data[] = [get_string('apiversion', 'local_kbacorporate'), s($status['api_release']) . ' (v' . (int)$status['api_version'] . ')'];
$summary->data[] = [get_string('erpapiversion', 'local_kbacorporate'), (int)$status['erpexchangeversion']];
$summary->data[] = [get_string('erpcontractrelease', 'local_kbacorporate'),
    s($status['erpexchangerelease']) . ' · ' . s($status['erpcontractstability'])];
$summary->data[] = [get_string('erpcontracthash', 'local_kbacorporate'),
    html_writer::tag('code', s($status['erpcontracthash']))];
$summary->data[] = [get_string('erpserviceinstalled', 'local_kbacorporate'),
    !empty($status['erpserviceinstalled']) ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('erpserviceenabled', 'local_kbacorporate'),
    !empty($status['erpserviceenabled']) ? '✅ ' . $yes : '— ' . $no];
$summary->data[] = [get_string('installedpluginversion', 'local_kbacorporate'), s($diskrelease)];
$summary->data[] = [get_string('diskbuild', 'local_kbacorporate'), $diskversion > 0 ? html_writer::tag('code', (string)$diskversion) : '—'];
$summary->data[] = [get_string('moodledbversion', 'local_kbacorporate'), $dbpluginversion !== false ? html_writer::tag('code', (string)(int)$dbpluginversion) : '—'];
$summary->data[] = [get_string('apischemaok', 'local_kbacorporate'), $status['schemaok'] ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('companies', 'local_kbacorporate'), (int)$status['companies']];
$summary->data[] = [get_string('employees', 'local_kbacorporate'), (int)$status['members']];
$summary->data[] = [get_string('corporatemanagers', 'local_kbacorporate'), (int)$status['managers']];
$summary->data[] = [get_string('departments', 'local_kbacorporate'), (int)$status['departments']];
$summary->data[] = [get_string('departmentmanagers', 'local_kbacorporate'), (int)$status['departmentmanagers']];
$summary->data[] = [get_string('apialertsenabledcompanies', 'local_kbacorporate'), (int)($status['alertsenabledcompanies'] ?? 0)];
$summary->data[] = [get_string('apiassignedcourses', 'local_kbacorporate'), (int)$status['courseassignments']];
$summary->data[] = [get_string('apiinactivecourses', 'local_kbacorporate'), (int)$status['inactivecourseassignments']];
$summary->data[] = [get_string('apilicenses', 'local_kbacorporate'), (int)($status['licenses'] ?? 0)];
$summary->data[] = [get_string('apiactivelicenses', 'local_kbacorporate'), (int)($status['activelicenses'] ?? 0)];
$summary->data[] = [get_string('apilicenseallocations', 'local_kbacorporate'), (int)($status['licenseallocations'] ?? 0)];
$summary->data[] = [get_string('apilicenseavailable', 'local_kbacorporate'), !empty($status['licenseavailable']) ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('apiwritesafe', 'local_kbacorporate'), $status['writesafe'] ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('apibigbluebuttonavailable', 'local_kbacorporate'), !empty($status['bigbluebuttonavailable']) ? '✅ ' . $yes : '— ' . $no];
$summary->data[] = [get_string('apicustomcertavailable', 'local_kbacorporate'), !empty($status['customcertavailable']) ? '✅ ' . $yes : '— ' . $no];
$summary->data[] = [get_string('kbaconnectconfigured', 'local_kbacorporate'), !empty($status['kbaconnectconfigured']) ? '✅ ' . $yes : '— ' . $no];
$summary->data[] = [get_string('bitrixrolefieldcode', 'local_kbacorporate'), !empty($status['bitrixrolefieldcode']) ? html_writer::tag('code', s($status['bitrixrolefieldcode'])) : '—'];
$summary->data[] = [get_string('apiattentionavailable', 'local_kbacorporate'), !empty($status['attentionavailable']) ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('apiduplicatecompanies', 'local_kbacorporate'), (int)$status['duplicatebitrixcompanies']];
$summary->data[] = [get_string('apiduplicatemembers', 'local_kbacorporate'), (int)($status['duplicatemembers'] ?? 0)];
$summary->data[] = [get_string('apiduplicatelicenserefs', 'local_kbacorporate'), (int)($status['duplicatelicenserefs'] ?? 0)];
echo html_writer::table($summary);

if (!$status['schemaok']) {
    echo $OUTPUT->notification(get_string('apischemawarning', 'local_kbacorporate'), 'notifyproblem');
}
if (!empty($status['duplicatebitrixcompanies'])) {
    echo $OUTPUT->notification(get_string('apiduplicatewarning', 'local_kbacorporate', $status['duplicatebitrixcompanies']), 'notifyproblem');
}
if (!empty($status['duplicatemembers'])) {
    echo $OUTPUT->notification(get_string('apiduplicatememberwarning', 'local_kbacorporate', $status['duplicatemembers']), 'notifyproblem');
}
if (!empty($status['duplicatelicenserefs'])) {
    echo $OUTPUT->notification(get_string('apiduplicatelicenserefwarning', 'local_kbacorporate', $status['duplicatelicenserefs']), 'notifyproblem');
}
if (empty($status['writesafe'])) {
    echo $OUTPUT->notification(get_string('apiwritesafewarning', 'local_kbacorporate'), 'notifyproblem');
}

echo $OUTPUT->heading(get_string('apimethods', 'local_kbacorporate'), 3);
$methods = new html_table();
$methods->head = [get_string('apimethod', 'local_kbacorporate'), get_string('apitype', 'local_kbacorporate'), get_string('apisince', 'local_kbacorporate')];
foreach ($contract['methods'] as $method => $meta) {
    $label = html_writer::tag('code', '\\local_kbacorporate\\local\\bridge::' . $method . '()');
    if (!empty($meta['alias_of'])) {
        $label .= ' ' . html_writer::tag('span', get_string('apialiasof', 'local_kbacorporate', $meta['alias_of']), ['class' => 'text-muted']);
    }
    $methods->data[] = [$label, s($meta['type']), s($meta['since'])];
}
echo html_writer::table($methods);

echo $OUTPUT->heading(get_string('apischema', 'local_kbacorporate'), 3);
$schematable = new html_table();
$schematable->head = [get_string('apitable', 'local_kbacorporate'), get_string('apistatus', 'local_kbacorporate'), get_string('apimissingfields', 'local_kbacorporate')];
foreach ($status['schema']['tables'] as $tablename => $info) {
    $missing = !empty($info['missingfields']) ? implode(', ', array_map('s', $info['missingfields'])) : '—';
    $schematable->data[] = [html_writer::tag('code', $tablename), $info['exists'] && empty($info['missingfields']) ? '✅ OK' : '⚠️', $missing];
}
echo html_writer::table($schematable);

echo html_writer::div(
    $OUTPUT->single_button(new moodle_url('/local/kbacorporate/versioninfo.php'), get_string('versioninfo', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bitrixroles.php'), get_string('bitrixrolesprovisioning', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/companies.php'), get_string('companies', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/index.php'), get_string('backtodashboard', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);

echo $OUTPUT->footer();
