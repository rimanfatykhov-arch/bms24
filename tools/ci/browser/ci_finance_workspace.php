<?php
/** CI-only helper for finance_workspace.py (FIN-W2) on the disposable Moodle. Synthetic data only. Phases:
 *   switch <0|1>             -> sets local_kbacompany/finance_workspace_enabled
 *   seed                     -> company A: RUB and USD scenarios, an approved RUB budget with a fact on one of two lines,
 *                               an approved USD budget without fact, a RUB budget on approval, a published P&L and a draft
 *                               resource gate with pending items; company B: an approved EUR budget. Prints titles only.
 *   model <A|B> <actor>      -> what finance_workspace_service::overview returns for that actor (ground truth):
 *                               card counters, plan per currency and the number of budgets with / without a fact
 *   revoke|reopen <actor>    -> canonical membership revocation in company A (membership_access_service::change)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_CI_BROWSER') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
define('CLI_SCRIPT', true);
require (string)getenv('MOODLE_ROOT') . '/config.php';
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || strpos($CFG->dbname, 'bmsci_') !== 0) { exit(2); }
use local_kbacompany\local\budgeting_service;
use local_kbacompany\local\finance_workspace_service;
use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_statement_service;
use local_kbacompany\local\resource_gate_service;

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
    set_config(finance_workspace_service::SETTING, (int)$argv[2], 'local_kbacompany');
    $out['enabled'] = finance_workspace_service::enabled();
} else if ($phase === 'seed') {
    global $DB;
    $nonce = strtoupper(bin2hex(random_bytes(3)));
    $uid = (int)$admin->id;
    $scenario = static function(string $c, string $code, string $currency) use ($uid): string {
        $uuid = financial_model_service::save_scenario($c, $uid, ['code' => $code, 'name' => 'CI scenario ' . $code,
            'scenariotype' => 'base', 'periodstart' => '2027-01-01', 'periodend' => '2027-12-31', 'currency' => $currency,
            'description' => '', 'assumptions' => '', 'strategyuuid' => '', 'businessmodeluuid' => '']);
        financial_model_service::publish_scenario($c, $uid, $uuid, 1);
        return $uuid;
    };
    $org = static function(string $c) use ($DB): string {
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $c, 'branchuuid' => 'main', 'uuid' => $uuid,
            'parentuuid' => '', 'code' => 'FINCI', 'nodetype' => 'department', 'name' => 'Finance CI unit', 'sortorder' => 99,
            'color' => '', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        return $uuid;
    };
    $budget = static function(string $c, string $s, string $o, string $code, string $name, string $currency, array $lines,
            bool $approve) use ($uid): string {
        $rows = [];
        foreach ($lines as $linecode => $plan) {
            $rows[] = ['linecode' => $linecode, 'name' => 'CI line ' . $linecode, 'linetype' => 'expense',
                'periodstart' => '2027-01-01', 'periodend' => '2027-03-31', 'planamount' => $plan, 'limitamount' => $plan,
                'ownerpositionuuid' => '', 'indicatoruuid' => '', 'sourceinfo' => 'CI plan'];
        }
        $uuid = budgeting_service::save_budget($c, $uid, ['code' => $code, 'name' => $name, 'budgettype' => 'orgunit',
            'scenariouuid' => $s, 'centeruuid' => '', 'targetuuid' => $o, 'periodstart' => '2027-01-01',
            'periodend' => '2027-03-31', 'currency' => $currency, 'approvalrule' => 'CI', 'notes' => ''], $rows);
        budgeting_service::submit_budget($c, $uid, $uuid, 1);
        if ($approve) { budgeting_service::approve_budget($c, $uid, $uuid, 2, 'CI'); }
        return $uuid;
    };
    $a = $company('A'); $b = $company('B');
    $titles = ['fact' => 'FINANCE CI FACT A ' . $nonce, 'nofact' => 'FINANCE CI NOFACT A ' . $nonce,
        'submitted' => 'FINANCE CI SUBMITTED A ' . $nonce, 'statement' => 'FINANCE CI PLAN A ' . $nonce,
        'gate' => 'FINANCE CI GATE A ' . $nonce, 'budget_b' => 'FINANCE CI BUDGET B ' . $nonce];
    $rub = $scenario($a, 'FCI-RUB-' . $nonce, 'RUB');
    $usd = $scenario($a, 'FCI-USD-' . $nonce, 'USD');
    $orga = $org($a);
    $withfact = $budget($a, $rub, $orga, 'FCI-F-' . $nonce, $titles['fact'], 'RUB', ['MKT' => 1000, 'OPS' => 500], true);
    budgeting_service::record_fact($a, $uid, $withfact, 3, 'MKT', ['asofdate' => '2027-03-31', 'actualamount' => 900,
        'sourcekind' => 'manual', 'sourceinfo' => 'CI close']);
    $budget($a, $usd, $orga, 'FCI-N-' . $nonce, $titles['nofact'], 'USD', ['IT' => 200], true);
    $budget($a, $rub, $orga, 'FCI-S-' . $nonce, $titles['submitted'], 'RUB', ['HR' => 300], false);
    $statement = financial_statement_service::save_statement($a, $uid, ['code' => 'FCI-BDR-' . $nonce,
        'name' => $titles['statement'], 'statementtype' => 'bdr', 'scenariouuid' => $rub, 'centeruuid' => '',
        'periodstart' => '2027-01-01', 'periodend' => '2027-01-31', 'currency' => 'RUB', 'sourceinfo' => 'CI sales plan',
        'notes' => ''], ['REV' => [1 => 1000], 'VAR' => [1 => 300], 'FIX' => [1 => 200], 'OTH_INC' => [1 => 0],
        'OTH_EXP' => [1 => 0], 'TAX' => [1 => 50]], []);
    financial_statement_service::publish_statement($a, $uid, $statement, 1);
    $target = \core\uuid::generate();
    $DB->insert_record('local_kbacompany_adminitem', (object)['companyuuid' => $a, 'uuid' => $target, 'revision' => 1,
        'status' => 'published', 'kind' => 'project', 'code' => 'FCI-T-' . $nonce, 'name' => 'Finance CI target',
        'description' => '', 'expectedresult' => '', 'parentuuid' => '', 'parentrevision' => 0, 'sourcekind' => '',
        'sourceuuid' => '', 'sourcerevision' => 0, 'ownerpositionuuid' => '', 'productuuid' => '', 'metricuuid' => '',
        'targetvalue' => '', 'startdate' => '', 'duedate' => '', 'sortorder' => 0, 'createdby' => 0, 'modifiedby' => 0,
        'timecreated' => time(), 'timemodified' => time()]);
    $items = [];
    foreach (resource_gate_service::RESOURCE_KINDS as $kind) {
        $items[] = ['itemcode' => strtoupper(substr($kind, 0, 4)), 'resourcekind' => $kind, 'name' => 'CI ' . $kind,
            'required' => 1, 'status' => 'pending', 'referencekind' => 'none', 'referenceuuid' => '', 'referencerevision' => 0,
            'expected' => 'Required', 'available' => '', 'gap' => '', 'evidence' => '', 'evidenceurl' => '',
            'ownerpositionuuid' => '', 'duedate' => ''];
    }
    resource_gate_service::save_gate($a, $uid, ['code' => 'FCI-G-' . $nonce, 'name' => $titles['gate'],
        'targettype' => 'project', 'targetuuid' => $target, 'scenariouuid' => '', 'plannedstart' => '2027-02-01',
        'ownerpositionuuid' => ''], $items);
    $eur = $scenario($b, 'FCI-EUR-' . $nonce, 'EUR');
    $budget($b, $eur, $org($b), 'FCI-B-' . $nonce, $titles['budget_b'], 'EUR', ['X' => 777], true);
    $out['titles'] = $titles;
} else if ($phase === 'model' && in_array($argv[2] ?? '', ['A', 'B'], true)) {
    $user = $actor((string)($argv[3] ?? ''));
    \core\session\manager::set_user(\core_user::get_user($user, '*', MUST_EXIST));
    $m = finance_workspace_service::overview($company($argv[2]), $user);
    $c = $m['counts'];
    $out['counts'] = ['scenarios' => (int)$c['scenarios']['total'], 'centers' => (int)$c['centers']['total'],
        'statements' => (int)$c['statements']['total'], 'budgetsapproved' => (int)($c['budgets']['approved'] ?? 0),
        'budgetssubmitted' => (int)($c['budgets']['submitted'] ?? 0), 'gatesready' => (int)($c['gates']['ready'] ?? 0),
        'gatesblocked' => (int)($c['gates']['blocked'] ?? 0)];
    $out['plan'] = array_map('strval', $m['plan_by_currency']);
    $out['facts'] = ['confirmed' => count(array_filter($m['execution'], static function(array $r): bool { return $r['fact'] !== null; })),
        'none' => count(array_filter($m['execution'], static function(array $r): bool { return $r['fact'] === null; }))];
    $out['attention'] = array_column($m['attention'], 'key');
} else if (in_array($phase, ['revoke', 'reopen'], true)) {
    $user = $actor((string)($argv[2] ?? ''));
    \local_kbacompany\local\membership_access_service::change($company('A'), $user, (int)$admin->id, $phase === 'reopen');
    $out['revoked'] = \local_kbacompany\local\membership_access_service::is_revoked($company('A'), $user);
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
