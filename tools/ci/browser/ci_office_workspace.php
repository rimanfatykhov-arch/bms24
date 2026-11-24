<?php
/** CI-only helper for office_workspace.py (DOC-W2) on the disposable Moodle. Synthetic data only. Phases:
 *   switch <0|1>             -> sets local_kbacompany/office_workspace_enabled
 *   seed                     -> company A: an effective document EA must acknowledge, a document in review assigned to EA,
 *                               an overdue decision task of EA; company B: a document in review assigned to EB and an
 *                               overdue decision task of EB. Prints titles (no secrets).
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
    $doc = static function(string $c, string $code, string $title) use ($admin): string {
        return document_service::create_manual($c, (int)$admin->id, ['code' => $code, 'title' => $title,
            'doctype' => 'procedure', 'doclevel' => 2, 'content' => 'Synthetic CI ' . $code, 'changereason' => 'CI']);
    };
    $review = static function(string $c, string $uuid, int $reviewer) use ($admin): void {
        document_service::add_reviewer($c, (int)$admin->id, $uuid, 1, $reviewer);
        document_service::submit_review($c, (int)$admin->id, $uuid, 1);
    };
    $task = static function(string $c, int $assignee, string $title) use ($admin): string {
        global $DB;
        $uuid = task_service::create($c, (int)$admin->id, ['title' => $title, 'product' => 'Synthetic CI result',
            'description' => '', 'assigneeid' => $assignee, 'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 0]);
        // Fixture only: a task created from a meeting decision, overdue by ten days.
        $DB->set_field('local_kbacompany_task', 'sourcekind', 'decision', ['companyuuid' => $c, 'uuid' => $uuid]);
        $DB->set_field('local_kbacompany_task', 'duedate', date('Y-m-d', time() - 10 * DAYSECS), ['companyuuid' => $c, 'uuid' => $uuid]);
        return $uuid;
    };
    $a = $company('A'); $b = $company('B'); $ea = $actor('EA'); $eb = $actor('EB');
    $titles = ['effective' => 'OFFICE CI EFFECTIVE A ' . $nonce, 'review' => 'OFFICE CI REVIEW A ' . $nonce,
        'task' => 'OFFICE CI OVERDUE A ' . $nonce, 'review_b' => 'OFFICE CI REVIEW B ' . $nonce,
        'task_b' => 'OFFICE CI OVERDUE B ' . $nonce];
    $effective = $doc($a, 'OCI-E-' . $nonce, $titles['effective']);
    $review($a, $effective, $ea);
    document_service::review_decision($a, $ea, $effective, 1, 'approved', 'CI');
    document_service::approve($a, (int)$admin->id, $effective, 1, 'CI');
    document_service::publish($a, (int)$admin->id, $effective, 1, 0);
    document_service::activate($a, (int)$admin->id, $effective, 1);
    document_service::require_acknowledgement($a, (int)$admin->id, $effective, 1, $ea);
    $review($a, $doc($a, 'OCI-R-' . $nonce, $titles['review']), $ea);
    $task($a, $ea, $titles['task']);
    $review($b, $doc($b, 'OCI-RB-' . $nonce, $titles['review_b']), $eb);
    $task($b, $eb, $titles['task_b']);
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
