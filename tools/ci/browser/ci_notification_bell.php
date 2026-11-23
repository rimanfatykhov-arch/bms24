<?php
// CI-only helper for notification_bell.py on the disposable Moodle (synthetic data only). Phases:
//   notify <company> <userid> <tag>            -> creates one system notification through the canonical generator
//                                                 communication_service::ensure_system_notification (kind calendar_day,
//                                                 title "BMS bell synthetic <tag>"); JSON {uuid}
//   thread <company> <owner> <userid> <tag>    -> owner creates a group thread "BMS bell private <tag>" with userid and
//                                                 sends one message; JSON {thread, uuid} (uuid = the user's notification)
//   kick <company> <thread> <owner> <userid>   -> owner removes userid from that thread (participation revoked)
//   state <company> <userid>                   -> JSON {unread, read: {uuid: 0|1}, pref} of that user (read-only)
//   pref <userid> <0|1>                        -> resets the sound preference of that user
// Never used outside BMS_CI_BROWSER.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');

use local_kbacompany\local\communication_center_ui_service;
use local_kbacompany\local\communication_service;

$phase = $argv[2] ?? '';
$out = ['phase' => $phase];
\core\session\manager::set_user(get_admin());
if ($phase === 'notify') {
    $company = $argv[3]; $userid = (int)$argv[4]; $title = 'BMS bell synthetic ' . $argv[5];
    $out['created'] = communication_service::ensure_system_notification($company, $userid, 'calendar_day',
        hash('sha256', 'bms-bell-ci|' . $argv[5]), $title);
    $out['uuid'] = (string)$DB->get_field('local_kbacompany_commnotif', 'uuid',
        ['companyuuid' => $company, 'userid' => $userid, 'title' => $title], MUST_EXIST);
} else if ($phase === 'thread') {
    $company = $argv[3]; $owner = (int)$argv[4]; $userid = (int)$argv[5];
    \core\session\manager::set_user(core_user::get_user($owner, '*', MUST_EXIST));
    $thread = communication_service::create_thread($company, $owner, ['threadtype' => 'group',
        'title' => 'BMS bell private ' . $argv[6], 'participants' => [$userid]]);
    $message = communication_service::send_message($company, $owner, $thread, 'BMS bell message ' . $argv[6]);
    $out['thread'] = $thread;
    $out['uuid'] = (string)$DB->get_field('local_kbacompany_commnotif', 'uuid',
        ['companyuuid' => $company, 'userid' => $userid, 'messageuuid' => $message], MUST_EXIST);
} else if ($phase === 'kick') {
    $owner = (int)$argv[5];
    \core\session\manager::set_user(core_user::get_user($owner, '*', MUST_EXIST));
    communication_service::remove_participant($argv[3], $owner, $argv[4], (int)$argv[6]);
    $out['active'] = (int)$DB->get_field('local_kbacompany_commpart', 'active',
        ['companyuuid' => $argv[3], 'threaduuid' => $argv[4], 'userid' => (int)$argv[6]]);
} else if ($phase === 'state') {
    $company = $argv[3]; $userid = (int)$argv[4];
    $out['read'] = [];
    foreach ($DB->get_records('local_kbacompany_commnotif', ['companyuuid' => $company, 'userid' => $userid], 'id ASC',
            'id, uuid, isread') as $row) {
        $out['read'][(string)$row->uuid] = (int)$row->isread;
    }
    try {
        $out['unread'] = communication_service::visible_unread_notification_count($company, $userid);
    } catch (moodle_exception $e) {
        $out['unread'] = null; // No company access (revoked): the bell must show nothing.
    }
    $out['pref'] = (string)get_user_preferences(communication_center_ui_service::SOUND_PREFERENCE, '', $userid);
} else if ($phase === 'pref' && in_array($argv[4] ?? '', ['0', '1'], true)) {
    set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, $argv[4], (int)$argv[3]);
    $out['pref'] = $argv[4];
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
