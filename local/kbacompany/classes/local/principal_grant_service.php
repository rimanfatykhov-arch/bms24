<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 work principal grants in RBAC 2.0 form (A0_02 §4): (company, object_type, action, scope, constraint),
 * deny by default. Issued and extended only by the separate grant authority (bmos_registry::require_grant_manager);
 * revoked by that authority or by an organisation manager, because removing a right never escalates. A principal
 * never grants, including to itself. Pairs come from the existing access_role_service dictionary; project pairs are added by a later decision.
 */
final class principal_grant_service {
    /** Scopes issued in A1. delegated_team is reserved for A3; department, subtree and company are never issued. */
    public const SCOPES = ['own', 'position', 'explicit_object'];
    public const RISKS = ['R0', 'R1', 'R2', 'R3'];
    private const COMMAND = '/^[a-z][a-z0-9_]{0,48}\.[a-z][a-z0-9_]{0,48}$/D';
    private const MAX_OBJECTS = 200;

    /**
     * @param array $spec command, objecttype, action, scope, risklimit, expiresat; optional ratelimit,
     *        occupancyuuid (scope position), objects (scope explicit_object: list of object UUIDs)
     */
    public static function grant(string $companyuuid, string $principaluuid, array $spec, int $actorid): string {
        global $DB;
        $companyuuid = bmos_registry::require_grant_manager($companyuuid, $actorid);
        $principaluuid = bmos_registry::principal($companyuuid, $principaluuid)->uuid;
        $clean = self::normalise($spec);
        $uuid = bmos_registry::new_uuid();
        return bmos_registry::locked([$principaluuid], function(int $now) use ($DB, $companyuuid, $principaluuid,
                $clean, $uuid, $actorid) {
            $principal = bmos_registry::principal($companyuuid, $principaluuid);
            if ((string)$principal->principaltype !== principal_registry_service::TYPE_AGENT ||
                    in_array((string)$principal->status, ['revoked', 'archived'], true)) {
                bmos_registry::fail('state');
            }
            self::check_expiry($clean['expiresat'], $now);
            if ($clean['scope'] === 'position') {
                self::require_occupancy($companyuuid, $principaluuid, $clean['occupancyuuid']);
            }
            $DB->insert_record(bmos_registry::GRANT, (object)['uuid' => $uuid, 'companyuuid' => $companyuuid,
                'principaluuid' => $principaluuid, 'command' => $clean['command'], 'objecttype' => $clean['objecttype'],
                'action' => $clean['action'], 'scope' => $clean['scope'], 'constraintjson' => $clean['constraintjson'],
                'occupancyuuid' => $clean['occupancyuuid'], 'delegationuuid' => '', 'risklimit' => $clean['risklimit'],
                'ratelimit' => $clean['ratelimit'], 'expiresat' => $clean['expiresat'], 'status' => 'active',
                'grantedby' => $actorid, 'supersedesuuid' => '', 'revision' => 1, 'revokedby' => 0, 'revokedat' => 0,
                'timecreated' => $now, 'timemodified' => $now]);
            bmos_registry::audit($companyuuid, $principaluuid, 'grant_issued', $actorid, ['grantuuid' => $uuid,
                'objecttype' => $clean['objecttype'], 'action' => $clean['action'], 'scope' => $clean['scope'],
                'expiresat' => $clean['expiresat']], $now);
            return $uuid;
        });
    }

    public static function revoke(string $companyuuid, string $grantuuid, int $actorid): void {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $grant = self::load($companyuuid, $grantuuid);
        bmos_registry::locked([(string)$grant->principaluuid], function(int $now) use ($DB, $companyuuid, $grant,
                $actorid) {
            $row = self::load($companyuuid, (string)$grant->uuid);
            if ((string)$row->status !== 'active') {
                bmos_registry::fail('state');
            }
            $DB->update_record(bmos_registry::GRANT, (object)['id' => $row->id, 'status' => 'revoked',
                'revokedby' => $actorid, 'revokedat' => $now, 'timemodified' => $now]);
            bmos_registry::audit($companyuuid, (string)$row->principaluuid, 'grant_revoked', $actorid,
                ['grantuuid' => (string)$row->uuid], $now);
        });
    }

    /**
     * Renewal by a human only (Q-10): a new grant revision with a new expiry at most 90 days from now; the old row
     * becomes superseded. An expired, revoked or superseded grant is never renewed: a new grant is issued instead.
     */
    public static function extend(string $companyuuid, string $grantuuid, int $expiresat, int $actorid): string {
        global $DB;
        $companyuuid = bmos_registry::require_grant_manager($companyuuid, $actorid);
        $grant = self::load($companyuuid, $grantuuid);
        $uuid = bmos_registry::new_uuid();
        return bmos_registry::locked([(string)$grant->principaluuid], function(int $now) use ($DB, $companyuuid,
                $grant, $expiresat, $uuid, $actorid) {
            $row = self::load($companyuuid, (string)$grant->uuid);
            if ((string)$row->status !== 'active' || (int)$row->expiresat <= $now) {
                bmos_registry::fail('state');
            }
            self::check_expiry($expiresat, $now);
            if ($expiresat <= (int)$row->expiresat) {
                bmos_registry::fail('invalid');
            }
            $principal = bmos_registry::principal($companyuuid, (string)$row->principaluuid);
            if (in_array((string)$principal->status, ['revoked', 'archived'], true)) {
                bmos_registry::fail('state');
            }
            if ((string)$row->scope === 'position') {
                self::require_occupancy($companyuuid, (string)$row->principaluuid, (string)$row->occupancyuuid);
            }
            $DB->update_record(bmos_registry::GRANT, (object)['id' => $row->id, 'status' => 'superseded',
                'timemodified' => $now]);
            $next = clone $row;
            unset($next->id);
            $next->uuid = $uuid;
            $next->expiresat = $expiresat;
            $next->status = 'active';
            $next->grantedby = $actorid;
            $next->supersedesuuid = (string)$row->uuid;
            $next->revision = (int)$row->revision + 1;
            $next->timecreated = $now;
            $next->timemodified = $now;
            $DB->insert_record(bmos_registry::GRANT, $next);
            bmos_registry::audit($companyuuid, (string)$row->principaluuid, 'grant_extended', $actorid,
                ['grantuuid' => $uuid, 'supersedes' => (string)$row->uuid, 'expiresat' => $expiresat], $now);
            return $uuid;
        });
    }

    private static function load(string $companyuuid, string $grantuuid): \stdClass {
        global $DB;
        $row = $DB->get_record(bmos_registry::GRANT, ['companyuuid' => $companyuuid,
            'uuid' => bmos_registry::uuid_or_unavailable($grantuuid)]);
        if (!$row) {
            bmos_registry::fail('notavailable');
        }
        return $row;
    }

    private static function check_expiry(int $expiresat, int $now): void {
        if ($expiresat <= $now || $expiresat > $now + bmos_registry::MAX_GRANT_SECONDS) {
            bmos_registry::fail('invalid');
        }
    }

    /** A position grant is bound to an approved or active occupancy of the same principal and company. */
    private static function require_occupancy(string $companyuuid, string $principaluuid, string $occupancyuuid): void {
        global $DB;
        if (!$DB->record_exists_select(bmos_registry::OCCUPANCY,
                "companyuuid = :c AND uuid = :o AND principaluuid = :p AND status IN ('approved', 'active')",
                ['c' => $companyuuid, 'o' => $occupancyuuid, 'p' => $principaluuid])) {
            bmos_registry::fail('notavailable');
        }
    }

    private static function normalise(array $spec): array {
        $text = function(string $key) use ($spec): string {
            return isset($spec[$key]) && is_string($spec[$key]) ? trim($spec[$key]) : '';
        };
        $command = $text('command');
        $objecttype = $text('objecttype');
        $action = $text('action');
        $scope = $text('scope');
        $risk = $text('risklimit');
        if (!preg_match(self::COMMAND, $command) || !array_key_exists($objecttype, access_role_service::OBJECTS) ||
                !in_array($action, access_role_service::ACTIONS, true) || !in_array($scope, self::SCOPES, true) ||
                !in_array($risk, self::RISKS, true)) {
            bmos_registry::fail('invalid');
        }
        $ratelimit = isset($spec['ratelimit']) ? $spec['ratelimit'] : 0;
        $expiresat = isset($spec['expiresat']) ? $spec['expiresat'] : 0;
        if (!is_int($ratelimit) || $ratelimit < 0 || !is_int($expiresat) || $expiresat <= 0) {
            bmos_registry::fail('invalid');
        }
        $occupancyuuid = '';
        $constraint = null;
        if ($scope === 'position') {
            $occupancyuuid = strtolower($text('occupancyuuid'));
            if (!bmos_registry::is_uuid($occupancyuuid)) {
                bmos_registry::fail('invalid');
            }
        }
        if ($scope === 'explicit_object') {
            $objects = isset($spec['objects']) && is_array($spec['objects']) ? array_values($spec['objects']) : [];
            $clean = [];
            foreach ($objects as $object) {
                $object = is_string($object) ? strtolower(trim($object)) : '';
                if (!bmos_registry::is_uuid($object)) {
                    bmos_registry::fail('invalid');
                }
                $clean[$object] = $object;
            }
            if (!$clean || count($clean) > self::MAX_OBJECTS) {
                bmos_registry::fail('invalid');
            }
            sort($clean, SORT_STRING);
            $constraint = bmos_registry::json(['objects' => array_values($clean)]);
        } else if (!empty($spec['objects'])) {
            bmos_registry::fail('invalid');
        }
        return ['command' => $command, 'objecttype' => $objecttype, 'action' => $action, 'scope' => $scope,
            'risklimit' => $risk, 'ratelimit' => $ratelimit, 'expiresat' => $expiresat,
            'occupancyuuid' => $occupancyuuid, 'constraintjson' => $constraint];
    }
}
