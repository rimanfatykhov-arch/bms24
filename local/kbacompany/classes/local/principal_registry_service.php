<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 work principal registry (A0_02 §1). Only an authorised human writes it (bmos_registry::require_writer).
 *
 * A1.1 registers only ai_agent principals: they never have a Moodle user, login, session, token, company role or
 * capability (userid = 0, transportuserid = 0). Human principals are not created: human rights stay with
 * permission_service. service_integration principals belong to A1-12 (ADR-0012) and are not registered here.
 * credentialref/credfingerprint stay empty: A1.1 has no keys.
 */
final class principal_registry_service {
    public const TYPE_AGENT = 'ai_agent';
    private const TRANSITIONS = [
        'activate' => ['draft', 'suspended'],
        'suspend' => ['active'],
        'revoke' => ['draft', 'active', 'suspended'],
        'archive' => ['revoked'],
    ];

    /** Register a draft AI agent principal with a responsible sponsor who is a current company member. */
    public static function register_agent(string $companyuuid, string $displayname, int $sponsoruserid,
            int $actorid): string {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $displayname = trim(clean_param($displayname, PARAM_TEXT));
        if ($displayname === '' || \core_text::strlen($displayname) > 255) {
            bmos_registry::fail('invalid');
        }
        if (!bmos_registry::is_member($companyuuid, $sponsoruserid)) {
            bmos_registry::fail('sponsor');
        }
        $uuid = bmos_registry::new_uuid();
        return bmos_registry::locked([$uuid], function(int $now) use ($DB, $companyuuid, $uuid, $displayname,
                $sponsoruserid, $actorid) {
            $DB->insert_record(bmos_registry::PRINCIPAL, (object)['uuid' => $uuid, 'companyuuid' => $companyuuid,
                'principaltype' => self::TYPE_AGENT, 'userid' => 0, 'memberuuid' => '', 'transportuserid' => 0,
                'displayname' => $displayname, 'sponsoruserid' => $sponsoruserid, 'status' => 'draft',
                'credentialref' => '', 'credfingerprint' => '', 'rotatedat' => 0, 'revision' => 1,
                'authzrevision' => 1, 'createdby' => $actorid, 'approvedby' => 0, 'approvedat' => 0, 'revokedby' => 0,
                'revokedat' => 0, 'revokereason' => null, 'timecreated' => $now, 'timemodified' => $now]);
            bmos_registry::audit($companyuuid, $uuid, 'registered', $actorid,
                ['principaltype' => self::TYPE_AGENT, 'sponsoruserid' => $sponsoruserid, 'status' => 'draft'], $now);
            return $uuid;
        }, false);
    }

    /** draft/suspended -> active; the sponsor must still be a current member (fail closed). */
    public static function activate(string $companyuuid, string $principaluuid, int $expectedrevision,
            int $actorid): void {
        self::transition($companyuuid, $principaluuid, $expectedrevision, $actorid, 'activate', 'active', '');
    }

    public static function suspend(string $companyuuid, string $principaluuid, int $expectedrevision,
            int $actorid): void {
        self::transition($companyuuid, $principaluuid, $expectedrevision, $actorid, 'suspend', 'suspended', '');
    }

    /** Terminal: a revoked principal is never reactivated; a new principal gets a new UUID. */
    public static function revoke(string $companyuuid, string $principaluuid, int $expectedrevision, string $reason,
            int $actorid): void {
        self::transition($companyuuid, $principaluuid, $expectedrevision, $actorid, 'revoke', 'revoked',
            trim(clean_param($reason, PARAM_TEXT)));
    }

    public static function archive(string $companyuuid, string $principaluuid, int $expectedrevision,
            int $actorid): void {
        self::transition($companyuuid, $principaluuid, $expectedrevision, $actorid, 'archive', 'archived', '');
    }

    /** Sponsor change is a separate action with its own revision and audit (A0_02 §1). */
    public static function change_sponsor(string $companyuuid, string $principaluuid, int $expectedrevision,
            int $sponsoruserid, int $actorid): void {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $principaluuid = bmos_registry::principal($companyuuid, $principaluuid)->uuid;
        if (!bmos_registry::is_member($companyuuid, $sponsoruserid)) {
            bmos_registry::fail('sponsor');
        }
        bmos_registry::locked([$principaluuid], function(int $now) use ($DB, $companyuuid, $principaluuid,
                $expectedrevision, $sponsoruserid, $actorid) {
            $row = bmos_registry::principal($companyuuid, $principaluuid);
            if ((int)$row->revision !== $expectedrevision) {
                bmos_registry::fail('conflict');
            }
            if (in_array((string)$row->status, ['revoked', 'archived'], true)) {
                bmos_registry::fail('state');
            }
            $DB->update_record(bmos_registry::PRINCIPAL, (object)['id' => $row->id, 'sponsoruserid' => $sponsoruserid,
                'revision' => (int)$row->revision + 1, 'timemodified' => $now]);
            bmos_registry::audit($companyuuid, $principaluuid, 'sponsor_changed', $actorid,
                ['from' => (int)$row->sponsoruserid, 'to' => $sponsoruserid], $now);
        });
    }

    private static function transition(string $companyuuid, string $principaluuid, int $expectedrevision,
            int $actorid, string $action, string $target, string $reason): void {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $principaluuid = bmos_registry::principal($companyuuid, $principaluuid)->uuid;
        bmos_registry::locked([$principaluuid], function(int $now) use ($DB, $companyuuid, $principaluuid,
                $expectedrevision, $actorid, $action, $target, $reason) {
            // Re-read under the lock: the decision uses the committed state, not the pre-lock read.
            $row = bmos_registry::principal($companyuuid, $principaluuid);
            if ((int)$row->revision !== $expectedrevision) {
                bmos_registry::fail('conflict');
            }
            if (!in_array((string)$row->status, self::TRANSITIONS[$action], true)) {
                bmos_registry::fail('state');
            }
            if ($target === 'active' && !bmos_registry::is_member($companyuuid, (int)$row->sponsoruserid)) {
                bmos_registry::fail('sponsor');
            }
            $update = (object)['id' => $row->id, 'status' => $target, 'revision' => (int)$row->revision + 1,
                'timemodified' => $now];
            if ($target === 'active' && (int)$row->approvedby === 0) {
                $update->approvedby = $actorid;
                $update->approvedat = $now;
            }
            if ($target === 'revoked') {
                $update->revokedby = $actorid;
                $update->revokedat = $now;
                $update->revokereason = $reason;
            }
            $DB->update_record(bmos_registry::PRINCIPAL, $update);
            bmos_registry::audit($companyuuid, $principaluuid, $action, $actorid,
                ['from' => (string)$row->status, 'to' => $target], $now);
        });
    }
}
