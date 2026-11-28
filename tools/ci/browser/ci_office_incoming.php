<?php
/** CI-only helper for office_incoming.py (DOC-W3) on the disposable Moodle. Synthetic data only. Phases:
 *   switch <ws 0|1> <in 0|1>   -> sets office_workspace_enabled and office_incoming_enabled
 *   seed                       -> EA gets a fresh active membership row if needed (see ci_office_workspace.php) and a
 *                                 company A role with office_incoming.create; MB gets a company B registrar role.
 *                                 The registrar role for MA is created but NOT assigned (managers get nothing by default).
 *   grant|ungrant <MA|EA>      -> assign/revoke MA's registrar role or EA's author role (canonical access_role_service)
 *   rights <A|B> <actor>       -> office_incoming_service::rights (ground truth)
 *   card <A|B> <actor> <uuid>  -> status, number, lockversion, revisions, journal actions, original manifest SHA-256 and the
 *                                 SHA-256 of the stored original bytes (never content or names of other users)
 *   series <A|B>               -> last issued sequence of the incoming series of the current year
 *   count <A|B>                -> number of incoming cards of the company
 *   drafts <n>                 -> MA creates n drafts in company A (registration race input)
 *   register <uuid> <key>      -> MA registers one card (run as parallel processes by the race)
 *   revoke|reopen <actor>      -> canonical membership revocation in company A; reopen restores an active member row
 * A failure prints only the exception class, Moodle error code and source line to STDERR.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_CI_BROWSER') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
define('CLI_SCRIPT', true);
require (string)getenv('MOODLE_ROOT') . '/config.php';
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || strpos($CFG->dbname, 'bmsci_') !== 0) { exit(2); }
use local_kbacompany\local\access_role_service;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\office_incoming_service as incoming;
use local_kbacompany\local\office_workspace_service;

$evidence = rtrim((string)getenv('EVIDENCE'), '/');
$f = json_decode(file_get_contents($evidence . '/actors.private.json'), true, 512, JSON_THROW_ON_ERROR);
$rolesfile = $evidence . '/office-incoming-roles.private.json';
$phase = $argv[1] ?? '';
$admin = get_admin();
\core\session\manager::set_user($admin);
$out = ['phase' => $phase];
$company = static function(string $tenant) use ($f): string {
    if (!in_array($tenant, ['A', 'B'], true)) { fwrite(STDERR, "unknown tenant\n"); exit(2); }
    return (string)$f['companies'][$tenant]['uuid'];
};
$actor = static function(string $label) use ($f): int {
    if (!isset($f['actors'][$label])) { fwrite(STDERR, "unknown actor\n"); exit(2); }
    return (int)$f['actors'][$label]['userid'];
};
$as = static function(int $user): void { \core\session\manager::set_user(\core_user::get_user($user, '*', MUST_EXIST)); };
$member = static function(string $c, int $user): void {
    global $DB;
    if ($DB->record_exists('local_kbacompany_member', ['companyuuid' => $c, 'userid' => $user, 'active' => 1])) { return; }
    $DB->insert_record('local_kbacompany_member', (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $c,
        'userid' => $user, 'membertype' => 'employee', 'active' => 1, 'isemployee' => 1]);
};
$roles = static function() use ($rolesfile): array {
    return is_file($rolesfile) ? json_decode((string)file_get_contents($rolesfile), true, 512, JSON_THROW_ON_ERROR) : [];
};
try {
    if ($phase === 'switch' && in_array($argv[2] ?? '', ['0', '1'], true) && in_array($argv[3] ?? '', ['0', '1'], true)) {
        set_config(office_workspace_service::SETTING, (int)$argv[2], 'local_kbacompany');
        set_config(incoming::SETTING, (int)$argv[3], 'local_kbacompany');
        $out['workspace'] = office_workspace_service::enabled();
        $out['enabled'] = incoming::enabled();
    } else if ($phase === 'seed') {
        $a = $company('A'); $b = $company('B'); $id = (int)$admin->id;
        if (membership_access_service::is_revoked($a, $actor('EA'))) { fwrite(STDERR, "revoked actor\n"); exit(1); }
        $member($a, $actor('EA'));
        $nonce = bin2hex(random_bytes(3));
        $author = access_role_service::create_role($a, $id, 'CI incoming author ' . $nonce, '');
        access_role_service::set_grant($a, $id, $author, 'office_incoming', 'create', true);
        access_role_service::assign($a, $id, $author, $actor('EA'), true);
        $registrar = access_role_service::create_role($a, $id, 'CI incoming registrar ' . $nonce, '');
        access_role_service::set_grant($a, $id, $registrar, 'office_incoming', 'register', true);
        $registrarb = access_role_service::create_role($b, $id, 'CI incoming registrar B ' . $nonce, '');
        access_role_service::set_grant($b, $id, $registrarb, 'office_incoming', 'register', true);
        access_role_service::assign($b, $id, $registrarb, $actor('MB'), true);
        file_put_contents($rolesfile, json_encode(['EA' => $author, 'MA' => $registrar, 'MB' => $registrarb]));
        chmod($rolesfile, 0600);
        $out['nonce'] = $nonce;
    } else if (in_array($phase, ['grant', 'ungrant'], true) && in_array($argv[2] ?? '', ['MA', 'EA'], true)) {
        $role = (int)($roles()[$argv[2]] ?? 0);
        access_role_service::assign($company('A'), (int)$admin->id, $role, $actor($argv[2]), $phase === 'grant');
        $out['rights'] = incoming::rights($company('A'), $actor($argv[2]));
    } else if ($phase === 'rights') {
        $user = $actor((string)($argv[3] ?? '')); $as($user);
        $out['rights'] = incoming::rights($company((string)($argv[2] ?? '')), $user);
    } else if ($phase === 'card') {
        global $DB;
        $c = $company((string)($argv[2] ?? '')); $user = $actor((string)($argv[3] ?? '')); $as($user);
        $card = incoming::card($c, $user, (string)($argv[4] ?? ''));
        $stored = null;
        if ($card['original']) {
            $id = (int)$DB->get_field('local_kbacompany_offrec', 'id', ['companyuuid' => $c, 'uuid' => $card['uuid']]);
            $file = incoming::file_for_download($c, $user, 'office_original', $id, $card['original']['sha256'],
                $card['original']['filename']);
            $stored = hash('sha256', $file->get_content());
        }
        $out['card'] = ['status' => $card['status'], 'number' => $card['regnumber'], 'lockversion' => $card['lockversion'],
            'revision' => $card['currentrev'], 'registeredat' => $card['registeredat'],
            'journal' => array_column($card['journal'], 'action'),
            'corrected' => array_values(array_filter($card['journal'], static function($e) { return $e['action'] === 'corrected'; })),
            'original' => $card['original'] ? $card['original']['sha256'] : null, 'stored' => $stored,
            'attachments' => count($card['attachments']), 'cancelreason' => $card['cancelreason']];
    } else if ($phase === 'series') {
        global $DB;
        $year = (new DateTimeImmutable('now', core_date::get_server_timezone_object()))->format('Y');
        $out['year'] = $year;
        $out['lastseq'] = (int)$DB->get_field('local_kbacompany_offseries', 'lastseq',
            ['companyuuid' => $company((string)($argv[2] ?? '')), 'code' => incoming::SERIES, 'period' => $year]);
    } else if ($phase === 'count') {
        global $DB;
        $out['count'] = $DB->count_records('local_kbacompany_offrec', ['companyuuid' => $company((string)($argv[2] ?? ''))]);
    } else if ($phase === 'drafts' && ctype_digit($argv[2] ?? '') && (int)$argv[2] <= 12) {
        $a = $company('A'); $ma = $actor('MA'); $as($ma);
        $out['uuids'] = [];
        for ($i = 1; $i <= (int)$argv[2]; $i++) {
            $out['uuids'][] = incoming::create($a, $ma, \core\uuid::generate(), ['subject' => 'CI race letter ' . $i,
                'correspondent' => 'CI race sender', 'externalnumber' => '', 'externaldate' => '',
                'receivedon' => date('Y-m-d', time() - DAYSECS), 'channel' => 'email', 'summary' => '']);
        }
    } else if ($phase === 'register') {
        $a = $company('A'); $ma = $actor('MA'); $as($ma);
        $uuid = (string)($argv[2] ?? '');
        $lock = (int)incoming::card($a, $ma, $uuid)['lockversion'];
        $out['result'] = incoming::register($a, $ma, $uuid, $lock, (string)($argv[3] ?? ''));
        $out['uuid'] = $uuid;
    } else if (in_array($phase, ['revoke', 'reopen'], true)) {
        $user = $actor((string)($argv[2] ?? ''));
        membership_access_service::change($company('A'), $user, (int)$admin->id, $phase === 'reopen');
        if ($phase === 'reopen') { $member($company('A'), $user); }
        $out['revoked'] = membership_access_service::is_revoked($company('A'), $user);
    } else {
        fwrite(STDERR, "unknown phase\n"); exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $phase . ' failed: ' . get_class($e) . ' ' . (string)($e->errorcode ?? '') . ' at '
        . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
