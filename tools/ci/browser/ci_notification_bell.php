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
//   dual <aid> <auuid> <bid> <buuid> <tag>     -> synthetic account in both companies, sound on; JSON {userid, companies}
//   claim <company> <userid> <startat> <ids>   -> notification_bell_service::claim_signal as that account, started at the
//                                                 wall-clock instant <startat>; JSON {play, t0, t1, pid, company}
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
    $out['id'] = (int)$DB->get_field('local_kbacompany_commnotif', 'id', ['uuid' => $out['uuid']], MUST_EXIST);
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
} else if ($phase === 'dual') {
    // Synthetic account that belongs to both companies (ids/uuids passed in), sound on; used only by the claim phases.
    require_once($CFG->dirroot . '/user/lib.php');
    [$aid, $auuid, $bid, $buuid, $tag] = array_slice($argv, 3, 5);
    $username = 'bmsbelldual' . preg_replace('/[^a-z0-9]/', '', strtolower($tag));
    $uid = user_create_user((object)['username' => $username, 'password' => 'Bell!' . bin2hex(random_bytes(16)) . 'a9',
        'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic',
        'lastname' => 'Dual', 'email' => $username . '@example.invalid', 'lang' => 'en', 'maildisplay' => 0], true, false);
    foreach ([[(int)$aid, $auuid], [(int)$bid, $buuid]] as [$cid, $cuuid]) {
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $uid, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4'; $hex[16] = '8';
        $DB->insert_record('local_kbacompany_member', (object)['uuid' => substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20), 'companyuuid' => $cuuid,
            'userid' => $uid, 'membertype' => 'employee', 'active' => 1, 'isemployee' => 1]);
    }
    set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $uid);
    $out['userid'] = (int)$uid;
    $out['companies'] = [];
    foreach ([$auuid, $buuid] as $cuuid) {
        try {
            \local_kbacompany\local\company_context_service::context($cuuid, (int)$uid);
            $out['companies'][] = $cuuid;
        } catch (moodle_exception $e) {
            $out['companies_error'][] = $e->errorcode;
        }
    }
} else if ($phase === 'claim') {
    // One claim as the given account, started at a shared wall-clock instant (barrier) so that several processes
    // really contend for the lock. Reports the outcome and the measured interval of the claim call.
    $company = $argv[3]; $userid = (int)$argv[4]; $startat = (float)$argv[5]; $ids = explode(',', $argv[6]);
    \core\session\manager::set_user(core_user::get_user($userid, '*', MUST_EXIST));
    while (microtime(true) < $startat) {
        usleep(500);
    }
    $out['t0'] = microtime(true);
    $out['play'] = \local_kbacompany\local\notification_bell_service::claim_signal($company, $userid, $ids);
    $out['t1'] = microtime(true);
    $out['pid'] = getmypid();
    $out['company'] = $company;
} else if ($phase === 'pref' && in_array($argv[4] ?? '', ['0', '1'], true)) {
    set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, $argv[4], (int)$argv[3]);
    $out['pref'] = $argv[4];
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
