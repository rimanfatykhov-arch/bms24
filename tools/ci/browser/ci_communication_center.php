<?php
// CI-only helper for communication_center.py on the disposable Moodle (synthetic data only). Phases:
//   viewer                          -> creates once the synthetic company-A task viewer VA (task manager grant, NOT a task
//                                      participant) and prints its login; reuses it on repeat calls
//   task_thread <company> <task>    -> JSON: canonical task discussion thread uuid (read-only)
//   find <thread> <body>            -> JSON: uuid of the message with exactly this body in that thread (read-only)
//   message <uuid>                  -> JSON: body/status/reply target/mentions of one message (read-only)
//   parts <thread>                  -> JSON: participants (userid, active, role) and read-state user ids (read-only)
//   notifications <message> <user> -> JSON: notification kinds of that user for that message (read-only)
//   messages <thread>               -> JSON: number of message rows in the thread (read-only)
//   directs <company> <u1> <u2>     -> JSON: active direct threads in which both users are active participants (read-only)
// Never used outside BMS_CI_BROWSER.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
require_once($CFG->dirroot . '/user/lib.php');
$phase = $argv[2] ?? '';
$out = ['phase' => $phase];
if ($phase === 'viewer') {
    $evidence = rtrim((string)getenv('EVIDENCE'), '/');
    $fixtures = json_decode((string)file_get_contents($evidence . '/actors.private.json'), true);
    // Not *.json: evidence/*.json is uploaded as a CI artifact (only actors.private.json is excluded).
    $private = $evidence . '/comm-viewer.private';
    if (is_file($private)) {
        $out += json_decode((string)file_get_contents($private), true);
    } else {
        $admin = get_admin();
        \core\session\manager::set_user($admin);
        $company = $fixtures['companies']['A'];
        $password = 'Cc!' . bin2hex(random_bytes(18)) . 'a9';
        $username = 'commviewer' . bin2hex(random_bytes(4));
        $uid = user_create_user((object)['username' => $username, 'password' => $password, 'auth' => 'manual',
            'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic', 'lastname' => 'VA',
            'email' => $username . '@example.invalid', 'lang' => 'en', 'maildisplay' => 0], true, false);
        role_assign((int)$fixtures['roles']['view'], $uid, context_system::instance()->id);
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $company['id'], 'userid' => $uid,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $DB->insert_record('local_kbacompany_member', (object)['uuid' => substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20), 'companyuuid' => $company['uuid'],
            'userid' => $uid, 'membertype' => 'employee', 'active' => 1, 'isemployee' => 1]);
        // Same BMS task-manager grant as MA: may open every company task, is not a participant of task A.
        \local_kbacompany\local\access_role_service::assign($company['uuid'], (int)$admin->id,
            $fixtures['roles']['bms_MA'], $uid, true);
        if (is_siteadmin($uid)) { throw new RuntimeException('Unexpected broad viewer permission'); }
        $login = ['userid' => $uid, 'username' => $username, 'password' => $password];
        umask(0077);
        file_put_contents($private, json_encode($login));
        chmod($private, 0600);
        $out += $login;
    }
} else if ($phase === 'task_thread') {
    $row = $DB->get_records('local_kbacompany_commthread', ['companyuuid' => $argv[3], 'threadtype' => 'object',
        'contextkind' => 'task', 'contextuuid' => $argv[4], 'status' => 'active'], 'id ASC', 'id, uuid', 0, 1);
    $out['thread'] = $row ? (string)reset($row)->uuid : '';
} else if ($phase === 'find') {
    $uuids = [];
    foreach ($DB->get_records('local_kbacompany_commmsg', ['threaduuid' => $argv[3]], 'id ASC', 'id, uuid, body') as $r) {
        if ((string)$r->body === (string)$argv[4]) { $uuids[] = (string)$r->uuid; }
    }
    $out['uuids'] = $uuids;
} else if ($phase === 'message') {
    $m = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $argv[3]], '*', MUST_EXIST);
    $out += ['body' => (string)$m->body, 'status' => (string)$m->status, 'thread' => (string)$m->threaduuid,
        'reply_to' => (string)$m->replytouuid, 'authorid' => (int)$m->authorid,
        'mentions' => array_values(array_map('intval', $DB->get_fieldset_select('local_kbacompany_commmention', 'userid',
            'messageuuid = ?', [$argv[3]])))];
} else if ($phase === 'parts') {
    $out['parts'] = array_values(array_map(static function($p) {
        return ['userid' => (int)$p->userid, 'active' => (int)$p->active, 'role' => (string)$p->role];
    }, $DB->get_records('local_kbacompany_commpart', ['threaduuid' => $argv[3]], 'id ASC')));
    $out['reads'] = array_values(array_map('intval', $DB->get_fieldset_select('local_kbacompany_commread', 'userid',
        'threaduuid = ?', [$argv[3]])));
} else if ($phase === 'notifications') {
    // notifications <message> <userid> -> kinds of notifications that user received for that message (read-only)
    $out['kinds'] = array_values(array_map('strval', $DB->get_fieldset_select('local_kbacompany_commnotif', 'kind',
        'messageuuid = ? AND userid = ?', [$argv[3], (int)$argv[4]])));
} else if ($phase === 'messages') {
    $out['count'] = $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $argv[3]]);
} else if ($phase === 'directs') {
    $out['threads'] = array_values(array_map(static function($r) { return (string)$r->uuid; },
        $DB->get_records_sql("SELECT t.id, t.uuid FROM {local_kbacompany_commthread} t
            JOIN {local_kbacompany_commpart} a ON a.threaduuid = t.uuid AND a.userid = :a AND a.active = 1
            JOIN {local_kbacompany_commpart} b ON b.threaduuid = t.uuid AND b.userid = :b AND b.active = 1
           WHERE t.companyuuid = :c AND t.threadtype = 'direct' AND t.status = 'active'",
            ['a' => (int)$argv[4], 'b' => (int)$argv[5], 'c' => $argv[3]])));
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
