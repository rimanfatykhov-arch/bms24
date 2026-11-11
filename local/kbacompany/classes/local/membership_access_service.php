<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/** Explicit company access revocations; memberships and business history are retained. */
final class membership_access_service {
    public static function ready(): bool {
        global $DB;
        $ready = $DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_revoke'));
        if (!$ready && (int)get_config('local_kbacompany', 'version') >= 2026111100) {
            throw new \coding_exception('Company revocation schema is unavailable');
        }
        return $ready;
    }

    private static function expression(string $value): string {
        if (!preg_match('/^(?:[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*|[1-9][0-9]*)$/D', $value)) {
            throw new \coding_exception('Invalid internal company SQL expression');
        }
        return $value;
    }

    /** Query-boundary predicate. Arguments are internal identifiers, never request SQL. */
    public static function canonical_sql(string $companyexpression, int $userid): string {
        $companyexpression = self::expression($companyexpression);
        if (!self::ready()) { return '1=1'; }
        return "NOT EXISTS (SELECT 1 FROM {local_kbacompany_revoke} rv
            WHERE rv.companyuuid = {$companyexpression} AND rv.userid = " . (int)$userid . " AND rv.timereopened = 0)";
    }

    /** Corporate adapter uses the existing immutable identity registry, without creating IDs. */
    public static function legacy_sql(string $companyexpression, int $userid): string {
        $companyexpression = self::expression($companyexpression);
        if (!self::ready()) { return '1=1'; }
        return "NOT EXISTS (SELECT 1 FROM {local_kbacompany_revoke} rv
            WHERE rv.userid = " . (int)$userid . " AND rv.timereopened = 0 AND (
              EXISTS (SELECT 1 FROM {local_kbacompany_idreg} ri WHERE ri.uuid = rv.companyuuid
                AND ri.sourcecomponent = 'local_kbacorporate' AND ri.sourceentity = 'company'
                AND ri.sourceid = {$companyexpression})
              OR EXISTS (SELECT 1 FROM {local_kbacompany_company} rc WHERE rc.uuid = rv.companyuuid
                AND rc.legacycompanyid = {$companyexpression})))";
    }

    public static function is_revoked(string $companyuuid, int $userid): bool {
        global $DB;
        return self::ready() && $DB->record_exists('local_kbacompany_revoke',
            ['companyuuid'=>$companyuuid, 'userid'=>$userid, 'timereopened'=>0]);
    }

    public static function is_legacy_revoked(int $companyid, int $userid): bool {
        global $DB;
        if ($companyid <= 0 || $userid <= 0 || !self::ready()) { return false; }
        return !$DB->record_exists_sql('SELECT 1 WHERE ' . self::legacy_sql((string)$companyid, $userid));
    }

    public static function require_access(string $companyuuid, int $userid): void {
        if (self::is_revoked($companyuuid, $userid)) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
    }

    /** Explicit administrative revoke/reopen. Never deletes Corporate rows or history. */
    public static function change(string $companyuuid, int $userid, int $actorid, bool $reopen = false): void {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        if (!is_siteadmin($actorid) && !permission_service::can_manage_organization($companyuuid, $actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if ($userid <= 0 || !$DB->record_exists('user', ['id'=>$userid, 'deleted'=>0])) {
            throw new \invalid_parameter_exception('Invalid membership user');
        }
        if (!self::ready()) { throw new \coding_exception('Revocation upgrade required'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('member:' . sha1($companyuuid . "\n" . $userid), 10);
        if (!$lock) { throw new \moodle_exception('identitylockfailed', 'local_kbacompany'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $where = ['companyuuid'=>$companyuuid, 'userid'=>$userid, 'timereopened'=>0];
            $now = time();
            if ($reopen) {
                foreach ($DB->get_records('local_kbacompany_revoke', $where) as $row) {
                    $row->timereopened = $now; $row->reopenedby = $actorid;
                    $DB->update_record('local_kbacompany_revoke', $row);
                }
            } else {
                if (!$DB->record_exists('local_kbacompany_revoke', $where)) {
                    $DB->insert_record('local_kbacompany_revoke', (object)($where +
                        ['revokedby'=>$actorid, 'timecreated'=>$now, 'reopenedby'=>0]));
                }
                foreach ($DB->get_records('local_kbacompany_member',
                        ['companyuuid'=>$companyuuid,'userid'=>$userid,'active'=>1]) as $row) {
                    $row->active = 0; $row->effectiveto = $now; $row->timemodified = $now;
                    $DB->update_record('local_kbacompany_member', $row);
                }
                integration_service::enqueue('employee_close', $companyuuid, $userid,
                    ['company_uuid'=>$companyuuid,'moodle_user_id'=>$userid,
                     'external_contact_id'=>integration_service::external_id(
                         integration_service::BITRIX_PROVIDER,'user',(string)$userid)]);
            }
            $tx->allow_commit();
        } finally { $lock->release(); }
    }
}
