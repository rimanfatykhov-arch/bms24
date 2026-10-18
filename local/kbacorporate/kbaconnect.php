<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/kbaconnect.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('kbaconnectconnection', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$action = optional_param('action', '', PARAM_ALPHA);
$result = null;

if ($action === 'pair' && confirm_sesskey()) {
    $baseurl = trim(required_param('baseurl', PARAM_URL));
    $pairingcode = trim(required_param('pairingcode', PARAM_RAW_TRIMMED));
    set_config('kbaconnectbaseurl', rtrim($baseurl, '/'), 'local_kbacorporate');
    $client = new \local_kbacorporate\local\kba_connect_client($baseurl, '');
    $result = $client->pair($pairingcode);
    if (!empty($result['success'])) {
        $token = (string)($result['integration_token'] ?? $result['token'] ?? $result['access_token'] ?? '');
        if ($token === '') {
            $result = [
                'success' => false,
                'message' => get_string('kbaconnectpairnotoken', 'local_kbacorporate'),
                'httpcode' => !empty($result['httpcode']) ? (int)$result['httpcode'] : 0,
            ];
        } else {
            set_config('kbaconnecttoken', $token, 'local_kbacorporate');
            set_config('kbaconnectpairedat', time(), 'local_kbacorporate');
            set_config('kbaconnectconnectionid', (string)($result['connection_id'] ?? $result['connectionid'] ?? ''), 'local_kbacorporate');
        }
    }
} else if ($action === 'disconnect' && confirm_sesskey()) {
    unset_config('kbaconnecttoken', 'local_kbacorporate');
    unset_config('kbaconnectpairedat', 'local_kbacorporate');
    unset_config('kbaconnectconnectionid', 'local_kbacorporate');
    $result = ['success' => true, 'local_disconnect' => true];
} else if ($action === 'check' && confirm_sesskey()) {
    $client = new \local_kbacorporate\local\kba_connect_client();
    $result = $client->connection_status();
}

$client = new \local_kbacorporate\local\kba_connect_client();
$config = $client->configuration_status();
$status = $config['paired'] ? $client->connection_status() : ['success' => false];

if (!empty($status['success'])) {
    set_config('kbaconnectlastcheck', time(), 'local_kbacorporate');
    set_config('kbaconnectlaststatus', 'healthy', 'local_kbacorporate');
} else if ($config['paired']) {
    set_config('kbaconnectlastcheck', time(), 'local_kbacorporate');
    set_config('kbaconnectlaststatus', 'attention', 'local_kbacorporate');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('kbaconnectconnection', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('kbaconnectconnectiondesc', 'local_kbacorporate'), ['class' => 'text-muted']);

if ($result !== null) {
    if (!empty($result['success'])) {
        $message = !empty($result['local_disconnect']) ? get_string('kbaconnectdisconnected', 'local_kbacorporate') : get_string('kbaconnectactionsuccess', 'local_kbacorporate');
        echo $OUTPUT->notification($message, 'notifysuccess');
    } else {
        echo $OUTPUT->notification(!empty($result['message']) ? s((string)$result['message']) : get_string('kbaconnectactionfailed', 'local_kbacorporate'), 'notifyproblem');
    }
}

$summary = new html_table();
$summary->head = [get_string('parameter', 'local_kbacorporate'), get_string('value', 'local_kbacorporate')];
$summary->data[] = [get_string('kbaconnectconnectionstatus', 'local_kbacorporate'), $config['paired'] ? (!empty($status['success']) ? '✅ ' . get_string('healthy', 'local_kbacorporate') : '⚠️ ' . get_string('requiresattention', 'local_kbacorporate')) : '— ' . get_string('kbaconnectnotpaired', 'local_kbacorporate')];
$summary->data[] = [get_string('kbaconnectbaseurl', 'local_kbacorporate'), $config['baseurl'] !== '' ? html_writer::tag('code', s($config['baseurl'])) : '—'];
$summary->data[] = [get_string('kbaconnecttokenstatus', 'local_kbacorporate'), $config['tokenconfigured'] ? '✅ ' . get_string('yes') : '— ' . get_string('no')];
if (!empty($status['version']) || !empty($status['release'])) {
    $summary->data[] = [get_string('kbaconnectremoteversion', 'local_kbacorporate'), s((string)($status['version'] ?? $status['release']))];
}
if (isset($status['bitrix_ready']) || isset($status['bitrixready'])) {
    $ready = !empty($status['bitrix_ready']) || !empty($status['bitrixready']);
    $summary->data[] = [get_string('kbaconnectbitrixstatus', 'local_kbacorporate'), $ready ? '✅ ' . get_string('ready', 'local_kbacorporate') : '⚠️ ' . get_string('requiresattention', 'local_kbacorporate')];
}
if (!empty($config['pairedat'])) {
    $summary->data[] = [get_string('kbaconnectpairedat', 'local_kbacorporate'), userdate($config['pairedat'])];
}
echo html_writer::table($summary);

if (!$config['paired']) {
    echo $OUTPUT->heading(get_string('kbaconnectpairheading', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('kbaconnectpairdesc', 'local_kbacorporate'));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/kbacorporate/kbaconnect.php'))->out(false), 'class' => 'mform']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'pair']);
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('kbaconnectbaseurl', 'local_kbacorporate'), ['class' => 'col-md-3 col-form-label', 'for' => 'id_baseurl']);
    echo html_writer::start_div('col-md-6');
    echo html_writer::empty_tag('input', ['type' => 'url', 'class' => 'form-control', 'id' => 'id_baseurl', 'name' => 'baseurl', 'required' => 'required', 'value' => s($config['baseurl'])]);
    echo html_writer::end_div(); echo html_writer::end_div();
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('kbaconnectpairingcode', 'local_kbacorporate'), ['class' => 'col-md-3 col-form-label', 'for' => 'id_pairingcode']);
    echo html_writer::start_div('col-md-6');
    echo html_writer::empty_tag('input', ['type' => 'password', 'class' => 'form-control', 'id' => 'id_pairingcode', 'name' => 'pairingcode', 'required' => 'required', 'autocomplete' => 'one-time-code']);
    echo html_writer::tag('small', get_string('kbaconnectpairingcode_desc', 'local_kbacorporate'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div(); echo html_writer::end_div();
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('kbaconnectpairbutton', 'local_kbacorporate')]), 'offset-md-3 col-md-6');
    echo html_writer::end_tag('form');
} else {
    $checkurl = new moodle_url('/local/kbacorporate/kbaconnect.php', ['action' => 'check', 'sesskey' => sesskey()]);
    $disconnecturl = new moodle_url('/local/kbacorporate/kbaconnect.php', ['action' => 'disconnect', 'sesskey' => sesskey()]);
    echo html_writer::div(
        $OUTPUT->single_button($checkurl, get_string('kbaconnectcheckbutton', 'local_kbacorporate'), 'post') . ' ' .
        $OUTPUT->single_button(new moodle_url('/local/kbacorporate/bitrixroles.php'), get_string('bitrixrolesprovisioning', 'local_kbacorporate'), 'get') . ' ' .
        $OUTPUT->single_button($disconnecturl, get_string('kbaconnectdisconnectbutton', 'local_kbacorporate'), 'post'),
        'kbacorp-toolbar'
    );
    echo $OUTPUT->notification(get_string('kbaconnectdisconnectnote', 'local_kbacorporate'), 'notifyinfo');
}

echo $OUTPUT->footer();
