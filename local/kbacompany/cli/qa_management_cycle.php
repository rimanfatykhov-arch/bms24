<?php
/**
 * READ-ONLY QA smoke on an installed Moodle site, NOT a transactional E2E test.
 * Example:
 *   php local/kbacompany/cli/qa_management_cycle.php \
 *     --company=<company-uuid> --actor=<manager-user-id> --week=2026-10-19 \
 *     --denied-company=<other-company-uuid> --denied-actor=<other-company-member-user-id>
 *
 * Use ONLY staging/test accounts where actor A is in company A, actor B in
 * company B, and neither is cross-listed or privileged. The check probes
 * A->A, B->B, A->B, B->A and rejects missing positive controls.
 * No Moodle session impersonation; actor passed explicitly to each provider.
 */
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\management_execution_service;
use local_kbacompany\local\management_leader_dashboard_service;
use local_kbacompany\local\management_cycle_acceptance_service;
use local_kbacompany\local\permission_service;
use local_kbacompany\local\rc30_company_isolation_service;

list($options, $unrecognized) = cli_get_params([
    'help'=>false, 'company'=>'', 'actor'=>0, 'week'=>'',
    'denied-company'=>'', 'denied-actor'=>0,
], ['h'=>'help']);
if ($options['help'] || $unrecognized || !$options['company'] || (int)$options['actor'] < 1) {
    echo "Read-only management-cycle audit. Required --company UUID --actor USERID; ";
    echo "optional --week YYYY-MM-DD --denied-company UUID --denied-actor USERID (both together)\n";
    exit(2);
}
try {
    $companyuuid = company_context_service::normalise_uuid((string)$options['company']);
    $actor = (int)$options['actor'];
    // Explicit checks must precede all canonical read model calls.
    company_context_service::context($companyuuid, $actor);
    if (!permission_service::can_manage_management($companyuuid, $actor)) {
        throw new \moodle_exception('nopermissions', 'error');
    }
    $base = management_execution_service::context($companyuuid, $actor, (string)$options['week']);
    $dashboard = management_leader_dashboard_service::context($companyuuid, $actor, $base);
    $result = management_cycle_acceptance_service::inspect($companyuuid, $base, $dashboard);
    $result['live_read_smoke'] = 'EXECUTED';
    $result['cross_company_denial'] = 'NOT_RUN';
    $deniedcompany = (string)$options['denied-company'];
    $deniedactor = (int)$options['denied-actor'];
    if (($deniedcompany !== '') !== ($deniedactor > 0)) {
        $result['cross_company_denial'] = 'INCOMPLETE_FIXTURE';
        $result['status'] = 'HOLD';
    } else if ($deniedcompany !== '' && $deniedactor > 0) {
        $deniedcompany = company_context_service::normalise_uuid($deniedcompany);
        // Site administrators and users with view-all cannot serve as
        // negative isolation fixtures: their access is intentionally broad.
        $privileged = is_siteadmin($actor) || is_siteadmin($deniedactor) ||
            has_capability('local/kbacompany:viewallcompanies', \context_system::instance(), $actor) ||
            has_capability('local/kbacompany:viewallcompanies', \context_system::instance(), $deniedactor);
        if ($privileged) {
            $result['cross_company_denial'] = 'HOLD_PRIVILEGED_FIXTURE';
            $result['status'] = 'HOLD';
        } else {
            $access = static function(string $target, int $userid): bool {
                try {
                    company_context_service::context($target, $userid);
                    return true;
                } catch (\moodle_exception $e) {
                    // Only an explicit company-not-available denial counts as
                    // denied. Unexpected failures are NOT successful isolation.
                    if ($e->errorcode === 'companynotavailable') {
                        return false;
                    }
                    throw $e;
                }
            };
            $isolation = rc30_company_isolation_service::verify(
                $companyuuid, $actor, $deniedcompany, $deniedactor, $access);
            $result['cross_company_denial'] = $isolation['status'] === 'PASS' ?
                'PASS_RECIPROCAL_DENIAL' : 'HOLD_' . $isolation['reason'];
            $result['isolation_checks'] = $isolation['checks'];
            if ($isolation['status'] !== 'PASS') {
                $result['status'] = 'HOLD';
            }
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($result['status'] === 'HOLD' ? 2 : 0);
} catch (\Throwable $e) {
    // Do not print raw exception messages containing company/employee data.
    echo json_encode(['status'=>'HOLD', 'live_read_smoke'=>'FAILED', 'exception_class'=>get_class($e)]) . "\n";
    exit(2);
}
