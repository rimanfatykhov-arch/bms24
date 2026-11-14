<?php
/** RC31 Pilot 02: DB + Moodle File API state of synthetic P02 messages in company A (clone only). Prints counts/names only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_CI_BROWSER') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
define('CLI_SCRIPT', true);
require (string)getenv('MOODLE_ROOT') . '/config.php';
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || strpos($CFG->dbname, 'bmsci_') !== 0) { exit(2); }
$EV = rtrim((string)getenv('EVIDENCE'), '/') . '/';
$phase = $argv[2] ?? $argv[1] ?? 'repro';
$f = json_decode(file_get_contents($EV . 'actors.private.json'), true, 512, JSON_THROW_ON_ERROR);
$A = $f['companies']['A']['uuid'];
// Clone-only switches used by the acceptance sequence.
if (in_array($phase, ['limit_on', 'limit_off', 'revoke_ea', 'reopen_ea'], true)) {
    \core\session\manager::set_user(get_admin());
    $ea = (int)$f['actors']['EA']['userid']; $r = ['phase' => $phase];
    if ($phase === 'limit_on') { set_config('rc31p02_prevmaxbytes', (string)($CFG->maxbytes ?? 0)); set_config('maxbytes', 65536); }
    if ($phase === 'limit_off') { set_config('maxbytes', (int)get_config('core', 'rc31p02_prevmaxbytes')); unset_config('rc31p02_prevmaxbytes'); }
    if ($phase === 'revoke_ea') { \local_kbacompany\local\membership_access_service::change($A, $ea, (int)get_admin()->id); }
    if ($phase === 'reopen_ea') { \local_kbacompany\local\membership_access_service::change($A, $ea, (int)get_admin()->id, true); }
    $r['max_upload'] = get_max_upload_file_size(); $r['ea_revoked'] = \local_kbacompany\local\membership_access_service::is_revoked($A, $ea);
    file_put_contents($EV . 'p02-switch-' . $phase . '.json', json_encode($r)); echo json_encode($r) . PHP_EOL; exit(0);
}
$msgs = $DB->get_records_select('local_kbacompany_commmsg', "companyuuid = ? AND " . $DB->sql_like('body', '?'), [$A, 'RC31 P02%'], 'id ASC', 'id,uuid,threaduuid,body,status');
$empty = $DB->get_records_select('local_kbacompany_commmsg', "companyuuid = ? AND timecreated > ? AND " . $DB->sql_compare_text('body') . " = ''", [$A, time() - 7200], 'id ASC', 'id,uuid');
$fs = get_file_storage(); $ctx = context_system::instance(); $out = ['phase' => $phase, 'messages' => []];
foreach ($msgs + $empty as $m) {
    $files = [];
    foreach ($fs->get_area_files($ctx->id, 'local_kbacompany', 'communication_attachment', (int)$m->id, 'id', false) as $sf) {
        $files[] = ['name' => $sf->get_filename(), 'size' => $sf->get_filesize(), 'sha1' => $sf->get_contenthash()];
    }
    $refs = $DB->count_records('local_kbacompany_commattach', ['companyuuid' => $A, 'messageuuid' => $m->uuid]);
    $out['messages'][] = ['id' => (int)$m->id, 'body' => substr((string)$m->body, 0, 60), 'status' => $m->status, 'files' => $files, 'attach_refs' => $refs];
}
$out['orphan_files'] = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {files} f WHERE f.component='local_kbacompany' AND f.filearea='communication_attachment' AND f.filename <> '.'
    AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_commmsg} m WHERE m.id = f.itemid)");
$out['max_upload'] = get_max_upload_file_size();
if ($phase === 'hist') {
    // Content snapshot of company A's communication history (RC32 review): one stable hash per row, keyed by a stable id.
    // Compared by contract.access_checks() before revoke, after revoke and after reopen. No expected changes.
    $rowhash = static function(array $v): string { return hash('sha256', json_encode(array_values($v), JSON_UNESCAPED_UNICODE)); };
    $rows = ['local_kbacompany_commmsg' => [], 'local_kbacompany_commattach' => [], 'local_kbacompany_commrev' => [],
             'files:communication_attachment' => []];
    foreach ($DB->get_records('local_kbacompany_commmsg', ['companyuuid' => $A], 'id ASC') as $r) {
        $rows['local_kbacompany_commmsg'][$r->uuid] = $rowhash([(int)$r->id, $r->threaduuid, (int)$r->authorid, hash('sha256', (string)$r->body),
            $r->replytouuid, $r->taskuuid, $r->status, (int)$r->timecreated]);
    }
    foreach ($DB->get_records('local_kbacompany_commattach', ['companyuuid' => $A], 'id ASC') as $r) {
        $rows['local_kbacompany_commattach'][$r->uuid] = $rowhash([(int)$r->id, $r->messageuuid, $r->refkind, hash('sha256', (string)$r->refvalue),
            $r->label, (int)$r->timecreated]);
    }
    foreach ($DB->get_records('local_kbacompany_commrev', ['companyuuid' => $A], 'id ASC') as $r) {
        $rows['local_kbacompany_commrev']['rev' . $r->id] = $rowhash([$r->messageuuid, (int)$r->actorid, $r->action,
            hash('sha256', (string)$r->oldbody), (int)$r->timecreated]);
    }
    foreach ($DB->get_records_select('files', "component = 'local_kbacompany' AND filearea = 'communication_attachment' AND filename <> '.'",
            null, 'id ASC') as $r) {
        $rows['files:communication_attachment']['file' . $r->id] = $rowhash([(int)$r->contextid, (int)$r->itemid, $r->filepath, $r->filename,
            $r->contenthash, (int)$r->filesize, (int)$r->userid, (int)$r->timecreated]);
    }
    $h = hash_init('sha256');
    ksort($rows, SORT_STRING);
    foreach ($rows as $table => $t) {
        ksort($t, SORT_STRING);
        foreach ($t as $key => $v) { hash_update($h, $table . '|' . $key . '|' . $v . "\n"); }
    }
    $out['history'] = ['tables' => array_keys($rows), 'rows' => $rows, 'digest' => hash_final($h)];
}
file_put_contents($EV . 'p02-state-' . $phase . '.json', json_encode($out));
echo json_encode($out) . PHP_EOL;
