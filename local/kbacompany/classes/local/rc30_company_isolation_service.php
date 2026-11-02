<?php
// RC30/1811: fail-closed, read-only test of company access isolation.
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies a two-actor, two-company ACL matrix using the platform's actual
 * company-context resolver. This class does NOT grant access or query records.
 * Any unexpected resolver failure must propagate and result in HOLD in the CLI.
 */
final class rc30_company_isolation_service {
    public const CONTRACT_VERSION = '1.0.0';

    /**
     * @param callable $allowed function(string $companyuuid, int $userid): bool
     * @return array Machine-readable decision without usernames or company names.
     */
    public static function verify(string $companya, int $actora, string $companyb, int $actorb,
            callable $allowed): array {
        if ($companya === '' || $companyb === '' || $companya === $companyb ||
                $actora < 1 || $actorb < 1 || $actora === $actorb) {
            return ['status' => 'HOLD', 'reason' => 'INVALID_TEST_FIXTURE', 'checks' => []];
        }
        // The positive controls prove each actor/company fixture actually works.
        // Without those controls, an outage can be misreported as successful isolation.
        $checks = [
            'actor_a_own_company' => (bool)$allowed($companya, $actora),
            'actor_b_own_company' => (bool)$allowed($companyb, $actorb),
            'actor_a_foreign_company' => (bool)$allowed($companyb, $actora),
            'actor_b_foreign_company' => (bool)$allowed($companya, $actorb),
        ];
        if (!$checks['actor_a_own_company'] || !$checks['actor_b_own_company']) {
            return ['status' => 'HOLD', 'reason' => 'INVALID_POSITIVE_CONTROL', 'checks' => $checks];
        }
        if ($checks['actor_a_foreign_company'] || $checks['actor_b_foreign_company']) {
            // Can be legitimate dual membership or viewallcompanies: never call it isolated.
            return ['status' => 'HOLD', 'reason' => 'FOREIGN_ACCESS_OR_PRIVILEGED_ACTOR', 'checks' => $checks];
        }
        return ['status' => 'PASS', 'reason' => 'CROSS_COMPANY_CONTEXT_DENIED', 'checks' => $checks];
    }
}
