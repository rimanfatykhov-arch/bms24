<?php
// CI-only helper for the open-conversation stability probe in communication_ux.py (synthetic data only). Phase:
//   seed <company> <owner> <userid> <tag> -> a new group conversation of owner and userid with 2 short messages, and
//                                           their personal (direct) conversation with 40 more messages of mixed length
//                                           from both (one direct conversation per pair: reused if it exists);
//                                           JSON {short, long} thread UUIDs
// Never used outside BMS_CI_BROWSER.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');

use local_kbacompany\local\communication_service;

$phase = $argv[2] ?? '';
if ($phase !== 'seed') { fwrite(STDERR, "unknown phase\n"); exit(2); }
$company = $argv[3]; $owner = (int)$argv[4]; $userid = (int)$argv[5]; $tag = $argv[6];
$as = static function(int $id) { \core\session\manager::set_user(core_user::get_user($id, '*', MUST_EXIST)); };
$as($owner);
$short = communication_service::create_thread($company, $owner, ['threadtype' => 'group',
    'title' => 'BMS stability short ' . $tag, 'participants' => [$userid]]);
communication_service::send_message($company, $owner, $short, 'BMS stability short 1 ' . $tag);
$as($userid);
communication_service::send_message($company, $userid, $short, 'BMS stability short 2 ' . $tag);
$as($owner);
$long = communication_service::create_thread($company, $owner, ['threadtype' => 'direct', 'participants' => [$userid]]);
for ($i = 1; $i <= 40; $i++) {
    $author = $i % 3 === 0 ? $userid : $owner;
    $as($author);
    $body = 'BMS stability long ' . $tag . ' #' . $i . ($i % 4 === 0
        ? "\n" . str_repeat('Synthetic paragraph text that wraps on a phone screen. ', 6) . "\nLast line " . $i
        : '');
    communication_service::send_message($company, $author, $long, $body);
}
echo json_encode(['phase' => $phase, 'short' => $short, 'long' => $long]) . PHP_EOL;
