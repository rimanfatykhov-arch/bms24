<?php
// CI-only helper for message_actions.py on the disposable Moodle. Phases:
//   state <messageuuid>  -> JSON: body/status/thread/revision audit/attachments of one synthetic message (read-only)
//   shell <owned|legacy> -> switches the BMS24 shell mode of the disposable site and reports it
// Never used outside BMS_CI_BROWSER; synthetic data only.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
$phase = $argv[2] ?? '';
$out = ['phase' => $phase];
if ($phase === 'state') {
    $uuid = (string)($argv[3] ?? '');
    $m = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $uuid], '*', MUST_EXIST);
    $out += ['uuid' => $uuid, 'body' => (string)$m->body, 'status' => (string)$m->status,
        'thread_uuid' => (string)$m->threaduuid, 'company_uuid' => (string)$m->companyuuid, 'authorid' => (int)$m->authorid,
        'revisions' => array_values(array_map(static function($r) {
            return ['action' => (string)$r->action, 'actorid' => (int)$r->actorid, 'oldbody' => (string)$r->oldbody];
        }, $DB->get_records('local_kbacompany_commrev', ['messageuuid' => $uuid], 'id ASC'))),
        'attachments' => $DB->count_records('local_kbacompany_commattach', ['messageuuid' => $uuid]),
        'stored_files' => count(get_file_storage()->get_area_files(context_system::instance()->id, 'local_kbacompany',
            'communication_attachment', (int)$m->id, 'id', false))];
} else if ($phase === 'shell' && in_array($argv[3] ?? '', ['owned', 'legacy'], true)) {
    set_config('bms24_shell_mode', $argv[3], 'local_kbacompany');
    $out['mode'] = get_config('local_kbacompany', 'bms24_shell_mode');
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
