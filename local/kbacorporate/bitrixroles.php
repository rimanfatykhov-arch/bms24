<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/bitrixroles.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bitrixrolesprovisioning', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$client = new \local_kbacorporate\local\kba_connect_client();
$schema = \local_kbacorporate\local\bridge::get_bitrix_role_schema();
$configstatus = $client->configuration_status();
$provisionresult = null;

if (optional_param('provision', 0, PARAM_BOOL)) {
    require_sesskey();
    $provisionresult = $client->provision_schema($schema);
    if (!empty($provisionresult['success'])) {
        $fieldcode = (string)($provisionresult['field_code'] ?? $provisionresult['fieldcode'] ?? '');
        $fieldid = (string)($provisionresult['field_id'] ?? $provisionresult['fieldid'] ?? '');
        if ($fieldcode !== '') {
            set_config('bitrixrolefieldcode', $fieldcode, 'local_kbacorporate');
        }
        if ($fieldid !== '') {
            set_config('bitrixrolefieldid', $fieldid, 'local_kbacorporate');
        }
        set_config('bitrixroleschemalastcheck', time(), 'local_kbacorporate');
        set_config('bitrixroleschemastatus', 'ready', 'local_kbacorporate');
    } else {
        set_config('bitrixroleschemalastcheck', time(), 'local_kbacorporate');
        set_config('bitrixroleschemastatus', 'attention', 'local_kbacorporate');
    }
}

$status = $client->schema_status();
if (!empty($status['success'])) {
    $fieldcode = (string)($status['field_code'] ?? $status['fieldcode'] ?? '');
    $fieldid = (string)($status['field_id'] ?? $status['fieldid'] ?? '');
    if ($fieldcode !== '') {
        set_config('bitrixrolefieldcode', $fieldcode, 'local_kbacorporate');
    }
    if ($fieldid !== '') {
        set_config('bitrixrolefieldid', $fieldid, 'local_kbacorporate');
    }
    set_config('bitrixroleschemalastcheck', time(), 'local_kbacorporate');
    set_config('bitrixroleschemastatus', 'ready', 'local_kbacorporate');
}

$storedfieldcode = (string)get_config('local_kbacorporate', 'bitrixrolefieldcode');
$storedfieldid = (string)get_config('local_kbacorporate', 'bitrixrolefieldid');

$yes = get_string('yes');
$no = get_string('no');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bitrixrolesprovisioning', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('bitrixrolesprovisioningdesc', 'local_kbacorporate'), ['class' => 'text-muted']);

if ($provisionresult !== null) {
    if (!empty($provisionresult['success'])) {
        echo $OUTPUT->notification(get_string('bitrixrolesprovisionsuccess', 'local_kbacorporate'), 'notifysuccess');
    } else {
        $message = !empty($provisionresult['message']) ? s((string)$provisionresult['message']) : get_string('bitrixrolesprovisionfailed', 'local_kbacorporate');
        echo $OUTPUT->notification($message, 'notifyproblem');
    }
}

$summary = new html_table();
$summary->head = [get_string('parameter', 'local_kbacorporate'), get_string('value', 'local_kbacorporate')];
$summary->data[] = [get_string('kbaconnectconfigured', 'local_kbacorporate'), $configstatus['paired'] ? '✅ ' . $yes : '⚠️ ' . $no];
$summary->data[] = [get_string('kbaconnectbaseurl', 'local_kbacorporate'), $configstatus['baseurl'] !== '' ? html_writer::tag('code', s($configstatus['baseurl'])) : '—'];
$summary->data[] = [get_string('kbaconnecttokenstatus', 'local_kbacorporate'), $configstatus['tokenconfigured'] ? '✅ ' . $yes : '— ' . $no];
$summary->data[] = [get_string('kbaconnectendpointstatus', 'local_kbacorporate'), !empty($status['success']) ? '✅ ' . get_string('ready', 'local_kbacorporate') : '⚠️ ' . get_string('requiresattention', 'local_kbacorporate')];
$summary->data[] = [get_string('bitrixrolefieldcode', 'local_kbacorporate'), $storedfieldcode !== '' ? html_writer::tag('code', s($storedfieldcode)) : '—'];
$summary->data[] = [get_string('bitrixrolefieldid', 'local_kbacorporate'), $storedfieldid !== '' ? html_writer::tag('code', s($storedfieldid)) : '—'];
$summary->data[] = [get_string('bitrixrolefieldtype', 'local_kbacorporate'), s($schema['type'])];
$summary->data[] = [get_string('bitrixrolefieldmultiple', 'local_kbacorporate'), !empty($schema['multiple']) ? $yes : $no];
echo html_writer::table($summary);

if (empty($configstatus['paired'])) {
    echo $OUTPUT->notification(get_string('kbaconnectpairfirst', 'local_kbacorporate'), 'notifyproblem');
} else if (empty($status['success'])) {
    $message = !empty($status['message']) ? s((string)$status['message']) : get_string('kbaconnectendpointunavailable', 'local_kbacorporate');
    echo $OUTPUT->notification($message, 'notifyproblem');
}

echo $OUTPUT->heading(get_string('bitrixrolesdesiredschema', 'local_kbacorporate'), 3);
$roles = new html_table();
$roles->head = [get_string('bitrixrolecode', 'local_kbacorporate'), get_string('bitrixrolelabel', 'local_kbacorporate')];
foreach ($schema['values'] as $role) {
    $roles->data[] = [html_writer::tag('code', s($role['xml_id'])), s($role['label_ru']) . ' / ' . s($role['label_en'])];
}
echo html_writer::table($roles);

$buttonurl = new moodle_url('/local/kbacorporate/bitrixroles.php', ['provision' => 1, 'sesskey' => sesskey()]);
echo html_writer::div(
    $OUTPUT->single_button($buttonurl, get_string('bitrixrolesprovisionbutton', 'local_kbacorporate'), 'post') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/kbaconnect.php'), get_string('kbaconnectconnection', 'local_kbacorporate'), 'get') .
    ' ' . $OUTPUT->single_button(new moodle_url('/local/kbacorporate/api.php'), get_string('apidiagnostics', 'local_kbacorporate'), 'get'),
    'kbacorp-toolbar'
);

echo $OUTPUT->footer();
