<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

class access {
    /**
     * Return active company memberships that may be used for a competency view.
     *
     * A self-view is based on the learner's own employee memberships. A
     * cross-user view is additionally restricted by the viewer's corporate
     * visibility. The returned array is keyed by company id.
     */
    public static function get_competency_company_options(int $employeeid, int $viewerid): array {
        global $DB;

        $sql = "SELECT c.*
                  FROM {local_kbacorp_company} c
                  JOIN {local_kbacorp_member} m ON m.companyid = c.id
                 WHERE m.userid = :employeeid
                   AND m.isemployee = 1
                   AND c.active = 1
              ORDER BY c.name ASC";
        $memberships = $DB->get_records_sql($sql, ['employeeid' => $employeeid]);
        if (!$memberships || $employeeid === $viewerid || is_siteadmin($viewerid)) {
            return $memberships;
        }

        $visible = [];
        foreach (self::get_allowed_companies($viewerid) as $company) {
            $companyid = (int)$company->id;
            if (isset($memberships[$companyid]) && self::can_view_employee($companyid, $viewerid, $employeeid)) {
                $visible[$companyid] = $memberships[$companyid];
            }
        }
        return $visible;
    }

    /**
     * Resolve one unambiguous company for the competency profile.
     *
     * Returns null when the learner has no accessible company or when a choice
     * is required. A requested company outside the allowed set is rejected.
     */
    public static function resolve_competency_company_context(int $employeeid, int $viewerid,
            int $requestedcompanyid = 0): ?\stdClass {
        $companies = self::get_competency_company_options($employeeid, $viewerid);
        if ($requestedcompanyid > 0) {
            if (!isset($companies[$requestedcompanyid])) {
                throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
            }
            return $companies[$requestedcompanyid];
        }
        if (count($companies) === 1) {
            return reset($companies);
        }
        return null;
    }

    public static function get_allowed_companies(int $userid): array {
        global $DB;
        $context = \context_system::instance();

        if (is_siteadmin($userid) || has_capability('local/kbacorporate:viewallcompanies', $context, $userid)) {
            return $DB->get_records('local_kbacorp_company', ['active' => 1], 'name ASC');
        }

        $sql = "SELECT DISTINCT c.*
                  FROM {local_kbacorp_company} c
             LEFT JOIN {local_kbacorp_member} m
                    ON m.companyid = c.id
                   AND m.userid = :manageruserid
                   AND m.ismanager = 1
             LEFT JOIN {local_kbacorp_department} d
                    ON d.companyid = c.id AND d.active = 1
             LEFT JOIN {local_kbacorp_dept_member} dm
                    ON dm.departmentid = d.id
                   AND dm.userid = :deptuserid
                   AND dm.deptrole = :deptrole
                 WHERE c.active = 1
                   AND (m.id IS NOT NULL OR dm.id IS NOT NULL)
              ORDER BY c.name ASC";
        return $DB->get_records_sql($sql, [
            'manageruserid' => $userid,
            'deptuserid' => $userid,
            'deptrole' => 'manager',
        ]);
    }

    public static function require_company_access(int $companyid, int $userid): \stdClass {
        global $DB;
        $company = $DB->get_record('local_kbacorp_company', ['id' => $companyid], '*', MUST_EXIST);
        $allowed = self::get_allowed_companies($userid);
        if (!isset($allowed[$companyid])) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        return $company;
    }

    /** Require company-wide rather than department-only access. */
    public static function require_full_company_access(int $companyid, int $userid): \stdClass {
        $company = self::require_company_access($companyid, $userid);
        if (!self::has_full_company_access($companyid, $userid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        return $company;
    }

    public static function has_full_company_access(int $companyid, int $userid): bool {
        global $DB;
        $context = \context_system::instance();
        if (is_siteadmin($userid) || has_capability('local/kbacorporate:viewallcompanies', $context, $userid)) {
            return true;
        }
        return $DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => $userid,
            'ismanager' => 1,
        ]);
    }

    public static function get_allowed_departments(int $companyid, int $userid): array {
        global $DB;
        if (self::has_full_company_access($companyid, $userid)) {
            return $DB->get_records('local_kbacorp_department', ['companyid' => $companyid, 'active' => 1], 'name ASC');
        }
        return departments::get_user_departments($companyid, $userid, 'manager');
    }

    /** Return company employee IDs visible to a viewer, optionally restricted to one department. */
    public static function get_visible_employee_ids(int $companyid, int $userid, int $departmentid = 0): array {
        global $DB;
        if ($departmentid < 0) {
            $departmentid = 0;
        }

        $full = self::has_full_company_access($companyid, $userid);
        $alloweddepartments = self::get_allowed_departments($companyid, $userid);
        if ($departmentid && !isset($alloweddepartments[$departmentid])) {
            throw new \moodle_exception('departmentnotallowed', 'local_kbacorporate');
        }

        if ($full && !$departmentid) {
            $ids = $DB->get_fieldset_select('local_kbacorp_member', 'userid', 'companyid = :companyid AND isemployee = :isemployee', [
                'companyid' => $companyid,
                'isemployee' => 1,
            ]);
            return array_values(array_unique(array_map('intval', $ids)));
        }

        $departmentids = $departmentid ? [$departmentid] : array_keys($alloweddepartments);
        if (!$departmentids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $departmentids), SQL_PARAMS_NAMED, 'dep');
        $params['companyid'] = $companyid;
        $params['deptrole'] = 'employee';
        $params['isemployee'] = 1;
        $sql = "SELECT DISTINCT m.userid
                  FROM {local_kbacorp_member} m
                  JOIN {local_kbacorp_dept_member} dm ON dm.userid = m.userid
                  JOIN {local_kbacorp_department} d ON d.id = dm.departmentid
                 WHERE m.companyid = :companyid
                   AND m.isemployee = :isemployee
                   AND d.companyid = m.companyid
                   AND d.active = 1
                   AND dm.deptrole = :deptrole
                   AND d.id {$insql}";
        return array_values(array_map('intval', $DB->get_fieldset_sql($sql, $params)));
    }

    public static function can_view_employee(int $companyid, int $viewerid, int $employeeid): bool {
        return in_array($employeeid, self::get_visible_employee_ids($companyid, $viewerid), true);
    }

    /**
     * Whether a user may manage the customer-facing BMS implementation workspace.
     *
     * Global methodology administration keeps using manageimplementationaudits.
     * Corporate managers receive the narrower managecompanyimplementation
     * capability and are always restricted to a company where they have full
     * membership access.
     */
    public static function can_manage_company_implementation(int $companyid, int $userid): bool {
        if ($companyid <= 0 || !self::has_full_company_access($companyid, $userid)) {
            return false;
        }
        $context = \context_system::instance();
        return has_capability('local/kbacorporate:manageimplementationaudits', $context, $userid) ||
            has_capability('local/kbacorporate:managecompanyimplementation', $context, $userid);
    }

    /** Require scoped access to a company's BMS implementation workspace. */
    public static function require_company_implementation_access(int $companyid, int $userid): \stdClass {
        $context = \context_system::instance();
        if (!has_capability('local/kbacorporate:manageimplementationaudits', $context, $userid) &&
                !has_capability('local/kbacorporate:managecompanyimplementation', $context, $userid)) {
            require_capability('local/kbacorporate:managecompanyimplementation', $context);
        }
        return self::require_full_company_access($companyid, $userid);
    }
}
