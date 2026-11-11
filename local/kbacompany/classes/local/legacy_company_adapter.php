<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Compatibility adapter for the current Corporate Dashboard source of truth.
 *
 * All legacy table knowledge is confined to this class so future storage
 * migration cannot leak into Company Core services or UI.
 */
class legacy_company_adapter {
    public const SOURCE_COMPONENT = 'local_kbacorporate';

    public static function is_ready(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_kbacorp_company')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacorp_member'));
    }


    /** Read-only bounded-directory SQL fragment. Corporate table names stay in this adapter. */
    public static function employee_directory_source(int $companyid, string $companyuuid): array {
        self::require_ready();
        return ["SELECT lm.userid, '' AS membershipuuid, 'local_kbacorporate_compatibility' AS sourcekind,
            lm.membertype, lm.isemployee, lm.ismanager, lm.isdepartmentmanager, lm.ishr,
            1 AS active, 0 AS effectivefrom, 0 AS effectiveto, 0 AS timemodified
            FROM {local_kbacorp_member} lm
            WHERE lm.companyid = :directorylegacy AND lm.isemployee = 1
              AND NOT EXISTS (SELECT 1 FROM {local_kbacorp_member} lm2 WHERE lm2.companyid = lm.companyid
                  AND lm2.userid = lm.userid AND lm2.isemployee = 1 AND lm2.id > lm.id)
              AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_member} cm WHERE cm.companyuuid = :directorycanonical
                  AND cm.userid = lm.userid)
              AND EXISTS (SELECT 1 FROM {user} lu WHERE lu.id = lm.userid AND lu.deleted = 0)",
            ['directorylegacy' => $companyid, 'directorycanonical' => $companyuuid]];
    }

    /** Return active companies to which the user belongs. */
    public static function companies_for_user(int $userid): array {
        global $DB;

        self::require_ready();
        $revocationwhere = membership_access_service::legacy_sql('c.id', $userid);
        $context = \context_system::instance();
        if (is_siteadmin($userid) || has_capability('local/kbacompany:viewallcompanies', $context, $userid)) {
            return array_values($DB->get_records_sql("SELECT c.* FROM {local_kbacorp_company} c WHERE c.active = 1 AND {$revocationwhere} ORDER BY c.name, c.id"));
        }
        $sql = "SELECT c.*
                  FROM {local_kbacorp_company} c
                  JOIN {local_kbacorp_member} m ON m.companyid = c.id
                 WHERE m.userid = :userid AND c.active = 1 AND {$revocationwhere}
              ORDER BY c.name ASC, c.id ASC";
        return array_values($DB->get_records_sql($sql, ['userid' => $userid]));
    }

    public static function company(int $companyid): ?\stdClass {
        global $DB;

        self::require_ready();
        $record = $DB->get_record('local_kbacorp_company', ['id' => $companyid]);
        return $record ?: null;
    }

    /** Return the provider CRM company id exposed by the legacy Corporate company row. */
    public static function bitrix_company_id(int $companyid): int {
        $company = self::company($companyid);
        return $company ? (int)($company->bitrixcompanyid ?? 0) : 0;
    }

    public static function membership(int $companyid, int $userid): ?\stdClass {
        global $DB;

        self::require_ready();
        $record = $DB->get_record('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => $userid,
        ]);
        return $record ?: null;
    }

    public static function can_access(int $companyid, int $userid): bool {
        if (membership_access_service::is_legacy_revoked($companyid, $userid)) { return false; }
        $company = self::company($companyid);
        if (!$company || empty($company->active)) {
            return false;
        }
        $context = \context_system::instance();
        if (is_siteadmin($userid) || has_capability('local/kbacompany:viewallcompanies', $context, $userid)) {
            return true;
        }
        return self::membership($companyid, $userid) !== null;
    }

    /**
     * Return a read-only organisation snapshot from the current source.
     *
     * Missing positions remain null. A missing department table produces an
     * empty unit projection and never fabricates an «unassigned» department.
     */
    public static function organization(int $companyid): array {
        global $DB;

        self::require_ready();
        $departments = [];
        $assignments = [];
        $dbman = $DB->get_manager();
        $hasdepartments = $dbman->table_exists(new \xmldb_table('local_kbacorp_department')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacorp_dept_member'));

        if ($hasdepartments) {
            $departments = array_values($DB->get_records('local_kbacorp_department', [
                'companyid' => $companyid,
                'active' => 1,
            ], 'name ASC, id ASC'));
            $assignments = array_values($DB->get_records_sql(
                "SELECT dm.id, dm.departmentid, dm.userid, dm.deptrole
                   FROM {local_kbacorp_dept_member} dm
                   JOIN {local_kbacorp_department} d ON d.id = dm.departmentid
                   JOIN {local_kbacorp_member} m
                     ON m.companyid = d.companyid AND m.userid = dm.userid
                  WHERE d.companyid = :companyid AND d.active = 1 AND m.isemployee = :isemployee
               ORDER BY dm.departmentid, dm.deptrole DESC, dm.userid",
                ['companyid' => $companyid, 'isemployee' => 1]
            ));
        }

        $members = array_values($DB->get_records_sql(
            "SELECT m.id AS membershipid, m.userid, m.membertype, m.isemployee,
                    m.ismanager, m.isdepartmentmanager, m.ishr,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.email, u.phone1
               FROM {local_kbacorp_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.companyid = :companyid AND m.isemployee = :isemployee AND u.deleted = 0
           ORDER BY u.lastname, u.firstname, u.id",
            ['companyid' => $companyid, 'isemployee' => 1]
        ));

        $positions = self::position_values(array_column($members, 'userid'));
        return [
            'departments_available' => $hasdepartments,
            'position_field_available' => $DB->record_exists('user_info_field', ['shortname' => 'position']),
            'departments' => $departments,
            'assignments' => $assignments,
            'members' => $members,
            'positions' => $positions,
        ];
    }

    /** Whether the current source exposes the complete business-role projection. */
    public static function business_roles_ready(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_kbacorp_busrole', 'local_kbacorp_rolecomp',
                'local_kbacorp_member_busrole'] as $tablename) {
            if (!$dbman->table_exists(new \xmldb_table($tablename))) {
                return false;
            }
        }
        return self::is_ready();
    }

    /** Return source records needed by the read-only business-role service. */
    public static function business_roles(int $companyid): array {
        global $DB;

        self::require_ready();
        if (!self::business_roles_ready()) {
            return ['available' => false, 'roles' => [], 'assignments' => [],
                'requirements' => [], 'members' => []];
        }
        $roles = array_values($DB->get_records_select('local_kbacorp_busrole',
            '(companyid = 0 OR companyid = :companyid) AND active = :active',
            ['companyid' => $companyid, 'active' => 1], 'companyid ASC, name ASC, id ASC'));
        $roleids = array_map(static function(\stdClass $role): int {
            return (int)$role->id;
        }, $roles);

        $assignments = array_values($DB->get_records_sql(
            "SELECT a.id, a.userid, a.roleid, a.source, a.effectivefrom, a.effectiveto,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.email, u.phone1
               FROM {local_kbacorp_member_busrole} a
               JOIN {local_kbacorp_member} m
                 ON m.companyid = a.companyid AND m.userid = a.userid
               JOIN {user} u ON u.id = a.userid
              WHERE a.companyid = :companyid AND a.active = :active
                AND m.isemployee = :isemployee AND u.deleted = 0
                AND (a.effectivefrom = 0 OR a.effectivefrom <= :nowfrom)
                AND (a.effectiveto = 0 OR a.effectiveto >= :nowto)
           ORDER BY u.lastname, u.firstname, a.roleid",
            ['companyid' => $companyid, 'active' => 1, 'isemployee' => 1,
                'nowfrom' => time(), 'nowto' => time()]
        ));

        $requirements = [];
        if ($roleids) {
            list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
            $requirements = array_values($DB->get_records_sql(
                "SELECT rc.id, rc.roleid, rc.competencyid, rc.targetlevel,
                        rc.priority, rc.required, rc.source,
                        c.shortname, c.idnumber
                   FROM {local_kbacorp_rolecomp} rc
                   JOIN {competency} c ON c.id = rc.competencyid
                  WHERE rc.roleid {$insql}
               ORDER BY rc.roleid, rc.priority, c.shortname, c.id",
                $params
            ));
        }

        $members = array_values($DB->get_records_sql(
            "SELECT m.id AS membershipid, m.userid,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.email, u.phone1
               FROM {local_kbacorp_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.companyid = :companyid AND m.isemployee = :isemployee AND u.deleted = 0
           ORDER BY u.lastname, u.firstname, u.id",
            ['companyid' => $companyid, 'isemployee' => 1]
        ));
        return ['available' => true, 'roles' => $roles, 'assignments' => $assignments,
            'requirements' => $requirements, 'members' => $members];
    }

    /** Return profile-position values keyed by Moodle user id. */
    private static function position_values(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return [];
        }
        $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'position']);
        if ($fieldid <= 0) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'positionuser');
        $params['fieldid'] = $fieldid;
        $rows = $DB->get_records_select('user_info_data',
            "fieldid = :fieldid AND userid {$insql}", $params, '', 'id, userid, data');
        $result = [];
        foreach ($rows as $row) {
            $value = trim((string)$row->data);
            if ($value !== '') {
                $result[(int)$row->userid] = $value;
            }
        }
        return $result;
    }

    private static function require_ready(): void {
        if (!self::is_ready()) {
            throw new \moodle_exception('corporatedependencyunavailable', 'local_kbacompany');
        }
    }
}
