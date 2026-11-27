<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 shared registry rules (A0 PASS 68b1dd0, A0_02 and A0_03 §2).
 *
 * Internal helper for the work principal, grant and occupancy registries. Every write goes through
 * {@see self::require_writer()}: the flag is on, the actor is the logged-in Moodle user and that human has
 * permission_service::can_manage_organization in the company. Principals never write registries: no method accepts a
 * principal context as the writer. Every change that can grant or remove authority runs under the principal lock
 * (bmos_principal_<uuid>) and one transaction that also increments wprincipal.authzrevision.
 *
 * @internal
 */
final class bmos_registry {
    public const PRINCIPAL = 'local_kbacompany_wprincipal';
    public const AUDIT = 'local_kbacompany_wpaudit';
    public const GRANT = 'local_kbacompany_wpgrant';
    public const DELEGATION = 'local_kbacompany_wpdeleg';
    public const OCCUPANCY = 'local_kbacompany_posoccup';
    public const OCCUPANCYLOG = 'local_kbacompany_posoccuplog';
    /** Grant lifetime limit (owner decision Q-10). */
    public const MAX_GRANT_SECONDS = 90 * 86400;
    private const LOCK_TIMEOUT = 10;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public static function enabled(): bool {
        return (int)get_config('local_kbacompany', 'bmos_enabled') === 1;
    }

    public static function require_enabled(): void {
        if (!self::enabled()) {
            self::fail('disabled');
        }
    }

    /** @return never */
    public static function fail(string $code): void {
        throw new \moodle_exception('bmos_error_' . $code, 'local_kbacompany');
    }

    public static function is_uuid(string $value): bool {
        return (bool)preg_match(self::UUID, $value);
    }

    /** A malformed or foreign reference is answered exactly like a missing one. */
    public static function uuid_or_unavailable(string $value): string {
        $value = strtolower(trim($value));
        if (!self::is_uuid($value)) {
            self::fail('notavailable');
        }
        return $value;
    }

    public static function company(string $companyuuid): string {
        try {
            return company_context_service::require_exists($companyuuid);
        } catch (\moodle_exception $e) {
            self::fail('notavailable');
        }
    }

    public static function new_uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Only an authorised human writes registries: the flag is on, the actor is the current session user and holds
     * can_manage_organization in this company (which itself requires company access and honours ADR-0006 revocation).
     */
    public static function require_writer(string $companyuuid, int $actorid): string {
        global $USER;
        self::require_enabled();
        if ($actorid <= 0 || isguestuser($actorid) || empty($USER->id) || (int)$USER->id !== $actorid) {
            self::fail('denied');
        }
        $companyuuid = self::company($companyuuid);
        try {
            $allowed = permission_service::can_manage_organization($companyuuid, $actorid);
        } catch (\moodle_exception $e) {
            $allowed = false;
        }
        if (!$allowed) {
            self::fail('denied');
        }
        return $companyuuid;
    }

    /**
     * Whether a Moodle user is a current, not revoked member of the company. Site administrator or
     * viewallcompanies access is not membership. Reads fresh rows; no cache.
     */
    public static function is_member(string $companyuuid, int $userid): bool {
        global $DB;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, suspended, auth');
        if (!$user || (int)$user->deleted !== 0 || (int)$user->suspended !== 0 || (string)$user->auth === 'nologin') {
            return false;
        }
        if (membership_access_service::is_revoked($companyuuid, $userid)) {
            return false;
        }
        if ($DB->get_manager()->table_exists('local_kbacompany_member') && $DB->record_exists('local_kbacompany_member',
                ['companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1])) {
            return true;
        }
        $legacyid = 0;
        $canonical = company_registry_service::by_uuid($companyuuid);
        if ($canonical && (int)$canonical->legacycompanyid > 0) {
            $legacyid = (int)$canonical->legacycompanyid;
        } else {
            $identity = identity_registry::resolve($companyuuid);
            if ($identity && (string)$identity->sourcecomponent === identity_registry::SOURCE_COMPONENT &&
                    (string)$identity->sourceentity === identity_registry::COMPANY_ENTITY) {
                $legacyid = (int)$identity->sourceid;
            }
        }
        if ($legacyid <= 0 || !legacy_company_adapter::is_ready()) {
            return false;
        }
        $company = legacy_company_adapter::company($legacyid);
        return $company && !empty($company->active) && legacy_company_adapter::membership($legacyid, $userid) !== null;
    }

    /** Principal row of this company, or the same error as a missing one. Company filter is in SQL. */
    public static function principal(string $companyuuid, string $principaluuid): \stdClass {
        global $DB;
        $row = $DB->get_record(self::PRINCIPAL, ['companyuuid' => $companyuuid,
            'uuid' => self::uuid_or_unavailable($principaluuid)]);
        if (!$row) {
            self::fail('notavailable');
        }
        return $row;
    }

    /**
     * Runs $change under the locks of the affected principals (taken first, in uuid order) and one transaction
     * that also increments their authzrevision (A0_03 §2 revocation protocol).
     *
     * @param string[] $principaluuids
     * @return mixed
     */
    public static function locked(array $principaluuids, callable $change, bool $bumpauthz = true) {
        global $DB;
        $principaluuids = array_values(array_unique($principaluuids));
        sort($principaluuids, SORT_STRING);
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $locks = [];
        $tx = null;
        try {
            foreach ($principaluuids as $uuid) {
                $lock = $factory->get_lock('bmos_principal_' . $uuid, self::LOCK_TIMEOUT);
                if (!$lock) {
                    self::fail('lock');
                }
                $locks[] = $lock;
            }
            $tx = $DB->start_delegated_transaction();
            $now = time();
            $result = $change($now);
            if ($bumpauthz) {
                foreach ($principaluuids as $uuid) {
                    $DB->execute('UPDATE {' . self::PRINCIPAL . '} SET authzrevision = authzrevision + 1, timemodified = :now
                        WHERE uuid = :uuid', ['now' => $now, 'uuid' => $uuid]);
                }
            }
            $tx->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            if ($tx) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** Append-only principal audit; the per-principal sequence is safe because the principal lock is held. */
    public static function audit(string $companyuuid, string $principaluuid, string $event, int $actorid,
            array $details, int $now): void {
        global $DB;
        $next = (int)$DB->get_field_sql('SELECT MAX(revision) FROM {' . self::AUDIT . '} WHERE principaluuid = ?',
            [$principaluuid]) + 1;
        $DB->insert_record(self::AUDIT, (object)['companyuuid' => $companyuuid, 'principaluuid' => $principaluuid,
            'revision' => $next, 'eventname' => $event, 'actorprincipaluuid' => '', 'actoruserid' => $actorid,
            'details' => self::json($details), 'timecreated' => $now]);
    }

    public static function json(array $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Same now for every expiry comparison of one check (A0_03 §2). */
    public static function is_current(int $from, int $to, int $now): bool {
        return $from <= $now && ($to === 0 || $to > $now);
    }
}
