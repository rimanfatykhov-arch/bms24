<?php
/** CI-only helper for office_workspace.py (DOC-W2) on the disposable Moodle. Synthetic data only. Phases:
 *   switch <0|1>             -> sets local_kbacompany/office_workspace_enabled
 *   seed                     -> company A: an effective document EA must acknowledge, a document in review assigned to EA,
 *                               an overdue decision task of EA; company B: a document in review assigned to EB and an
 *                               overdue decision task of EB. Created by the company's own fixture manager (MA / MB), as the
 *                               shared fixture creates its tasks. Prints titles (no secrets); a failure prints only the
 *                               exception class and error code to STDERR.
 *   model <A|B> <actor>      -> counters of office_workspace_service::overview for that actor (ground truth)
 *   revoke|reopen <actor>    -> canonical membership revocation in company A (membership_access_service::change)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_CI_BROWSER') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
define('CLI_SCRIPT', true);
require (string)getenv('MOODLE_ROOT') . '/config.php';
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || strpos($CFG->dbname, 'bmsci_') !== 0) { exit(2); }
use local_kbacompany\local\document_service;
use local_kbacompany\local\office_workspace_service;
use local_kbacompany\local\task_service;

$f = json_decode(file_get_contents(rtrim((string)getenv('EVIDENCE'), '/') . '/actors.private.json'), true, 512, JSON_THROW_ON_ERROR);
$phase = $argv[1] ?? '';
$admin = get_admin();
\core\session\manager::set_user($admin);
$out = ['phase' => $phase];
$company = static function(string $tenant) use ($f): string { return (string)$f['companies'][$tenant]['uuid']; };
$actor = static function(string $label) use ($f): int {
    if (!isset($f['actors'][$label])) { fwrite(STDERR, "unknown actor\n"); exit(2); }
    return (int)$f['actors'][$label]['userid'];
};
if ($phase === 'switch' && in_array($argv[2] ?? '', ['0', '1'], true)) {
    set_config(office_workspace_service::SETTING, (int)$argv[2], 'local_kbacompany');
    $out['enabled'] = office_workspace_service::enabled();
} else if ($phase === 'seed') {
    $nonce = bin2hex(random_bytes(3));
    $as = static function(int $user): void { \core\session\manager::set_user(\core_user::get_user($user, '*', MUST_EXIST)); };
    $doc = static function(string $c, int $m, string $code, string $title) use ($as): string {
        $as($m);
        return document_service::create_manual($c, $m, ['code' => $code, 'title' => $title,
            'doctype' => 'procedure', 'doclevel' => 2, 'content' => 'Synthetic CI ' . $code, 'changereason' => 'CI']);
    };
    $review = static function(string $c, int $m, string $uuid, int $reviewer) use ($as): void {
        $as($m);
        document_service::add_reviewer($c, $m, $uuid, 1, $reviewer);
        document_service::submit_review($c, $m, $uuid, 1);
    };
    $task = static function(string $c, int $m, int $assignee, string $title) use ($as): string {
        global $DB;
        $as($m);
        $uuid = task_service::create($c, $m, ['title' => $title, 'product' => 'Synthetic CI result',
            'description' => '', 'assigneeid' => $assignee, 'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 0]);
        // Fixture only: a task created from a meeting decision, overdue by ten days.
        $DB->set_field('local_kbacompany_task', 'sourcekind', 'decision', ['companyuuid' => $c, 'uuid' => $uuid]);
        $DB->set_field('local_kbacompany_task', 'duedate', date('Y-m-d', time() - 10 * DAYSECS), ['companyuuid' => $c, 'uuid' => $uuid]);
        return $uuid;
    };
    $a = $company('A'); $b = $company('B'); $ea = $actor('EA'); $eb = $actor('EB');
    $ma = $actor('MA'); $mb = $actor('MB');
    // Earlier scenarios (access.py, communication_center.py) revoke and reopen EA: the canonical reopen lifts the
    // revocation but keeps the closed membership row inactive, so EA is no longer an eligible document reviewer.
    // Fixture only: give the employee a fresh active membership row (same shape as ci_fixtures.php). The office
    // scenario's own revocation at the end closes it again, so later scenarios see the same state as before.
    $member = static function(string $c, int $user): void {
        global $DB;
        if (\local_kbacompany\local\membership_access_service::is_revoked($c, $user)) { fwrite(STDERR, "revoked actor\n"); exit(1); }
        if ($DB->record_exists('local_kbacompany_member', ['companyuuid' => $c, 'userid' => $user, 'active' => 1])) { return; }
        $DB->insert_record('local_kbacompany_member', (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $c,
            'userid' => $user, 'membertype' => 'employee', 'active' => 1, 'isemployee' => 1]);
    };
    $member($a, $ea); $member($b, $eb);
    $titles = ['effective' => 'OFFICE CI EFFECTIVE A ' . $nonce, 'review' => 'OFFICE CI REVIEW A ' . $nonce,
        'task' => 'OFFICE CI OVERDUE A ' . $nonce, 'review_b' => 'OFFICE CI REVIEW B ' . $nonce,
        'task_b' => 'OFFICE CI OVERDUE B ' . $nonce];
    try {
        $effective = $doc($a, $ma, 'OCI-E-' . $nonce, $titles['effective']);
        $review($a, $ma, $effective, $ea);
        $as($ea);
        document_service::review_decision($a, $ea, $effective, 1, 'approved', 'CI');
        $as($ma);
        document_service::approve($a, $ma, $effective, 1, 'CI');
        document_service::publish($a, $ma, $effective, 1, 0);
        document_service::activate($a, $ma, $effective, 1);
        document_service::require_acknowledgement($a, $ma, $effective, 1, $ea);
        $review($a, $ma, $doc($a, $ma, 'OCI-R-' . $nonce, $titles['review']), $ea);
        $task($a, $ma, $ea, $titles['task']);
        $review($b, $mb, $doc($b, $mb, 'OCI-RB-' . $nonce, $titles['review_b']), $eb);
        $task($b, $mb, $eb, $titles['task_b']);
    } catch (\Throwable $e) {
        // Diagnostics without data: class, Moodle error code and the failing source line only.
        fwrite(STDERR, 'seed failed: ' . get_class($e) . ' ' . (string)($e->errorcode ?? '') . ' at '
            . basename($e->getFile()) . ':' . $e->getLine() . "\n");
        exit(1);
    }
    $out['titles'] = $titles;
    // Titles that must never appear on company A pages of EA: company B objects and the fixture's private drafts.
    $out['private'] = [$titles['review_b'], $titles['task_b'], (string)$f['documents']['A']['title'],
        (string)$f['documents']['B']['title']];
} else if ($phase === 'model' && in_array($argv[2] ?? '', ['A', 'B'], true)) {
    $user = $actor((string)($argv[3] ?? ''));
    \core\session\manager::set_user(\core_user::get_user($user, '*', MUST_EXIST));
    $out['counts'] = office_workspace_service::overview($company($argv[2]), $user)['counts'];
} else if (in_array($phase, ['revoke', 'reopen'], true)) {
    $user = $actor((string)($argv[2] ?? ''));
    \local_kbacompany\local\membership_access_service::change($company('A'), $user, (int)$admin->id, $phase === 'reopen');
    $out['revoked'] = \local_kbacompany\local\membership_access_service::is_revoked($company('A'), $user);
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
