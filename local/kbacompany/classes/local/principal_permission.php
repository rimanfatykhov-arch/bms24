<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 principal_permission::can (A0_02 §4, §4.2, §4.3), deny by default in RBAC 2.0 form:
 * (company, object_type, action, scope, constraint).
 *
 * Every call rebuilds the principal context from fresh rows (status, sponsor membership, authzrevision) and reads the
 * grant and occupancy with the company filter in SQL, comparing expiry with one now. Unknown object_type/action, no
 * grant, an expired, revoked or superseded grant, a foreign company, delegated_team (A3) or any unexpected state
 * deny. The principal is never passed to human checks as a userid. Object ownership rules of the canonical services
 * (principal_object_acl for real objects) arrive with the first command in A1-04; here a resource is judged only by
 * the explicit fields below and a personal object (no duty position) is always denied.
 */
final class principal_permission {
    /**
     * @param array|null $resource optional ['companyuuid' => ..., 'uuid' => ..., 'ownerprincipaluuid' => ...,
     *        'positionuuid' => ...]; null asks whether an applicable grant exists without an object
     */
    public static function can(principal_context $context, string $objecttype, string $action,
            ?array $resource = null): bool {
        global $DB;
        if (!array_key_exists($objecttype, access_role_service::OBJECTS) ||
                !in_array($action, access_role_service::ACTIONS, true)) {
            return false;
        }
        try {
            $fresh = principal_context_service::context($context->companyuuid(), $context->principaluuid());
        } catch (\moodle_exception $e) {
            return false;
        }
        // A context built before a revocation, grant change or sponsor change is stale: rebuild and check again.
        if ($fresh->authzrevision() !== $context->authzrevision()) {
            return false;
        }
        $companyuuid = $fresh->companyuuid();
        if ($resource !== null && (string)($resource['companyuuid'] ?? '') !== $companyuuid) {
            return false;
        }
        $now = time();
        $grants = $DB->get_records_select(bmos_registry::GRANT,
            "companyuuid = :c AND principaluuid = :p AND status = 'active' AND objecttype = :o AND action = :a
             AND expiresat > :now", ['c' => $companyuuid, 'p' => $fresh->principaluuid(), 'o' => $objecttype,
             'a' => $action, 'now' => $now], 'id ASC');
        foreach ($grants as $grant) {
            if (self::grant_applies($fresh, $grant, $resource, $now)) {
                return true;
            }
        }
        return false;
    }

    private static function grant_applies(principal_context $context, \stdClass $grant, ?array $resource,
            int $now): bool {
        global $DB;
        $scope = (string)$grant->scope;
        if (!in_array($scope, principal_grant_service::SCOPES, true) || (int)$grant->expiresat > $now +
                bmos_registry::MAX_GRANT_SECONDS) {
            return false;
        }
        $uuid = $resource !== null ? strtolower((string)($resource['uuid'] ?? '')) : '';
        if ($scope === 'own') {
            return $resource === null ||
                (string)($resource['ownerprincipaluuid'] ?? '') === $context->principaluuid();
        }
        if ($scope === 'explicit_object') {
            if ($resource === null) {
                return true;
            }
            $constraint = json_decode((string)$grant->constraintjson, true);
            $objects = is_array($constraint) && isset($constraint['objects']) && is_array($constraint['objects'])
                ? $constraint['objects'] : [];
            return $uuid !== '' && in_array($uuid, $objects, true);
        }
        // Position: an active, current occupancy of this principal in this company; agent mode keeps a supervisor.
        $occupancy = $DB->get_record_select(bmos_registry::OCCUPANCY,
            "companyuuid = :c AND uuid = :o AND principaluuid = :p AND status = 'active'",
            ['c' => $context->companyuuid(), 'o' => (string)$grant->occupancyuuid, 'p' => $context->principaluuid()]);
        if (!$occupancy || !bmos_registry::is_current((int)$occupancy->effectivefrom, (int)$occupancy->effectiveto, $now)) {
            return false;
        }
        if ((string)$occupancy->occupancymode === 'agent' &&
                !bmos_registry::is_member($context->companyuuid(), (int)$occupancy->supervisoruserid)) {
            return false;
        }
        if ($resource === null) {
            return true;
        }
        $position = (string)($resource['positionuuid'] ?? '');
        // A personal object (empty duty position) is never available to an agent.
        return $position !== '' && $position === (string)$occupancy->positionuuid;
    }
}
