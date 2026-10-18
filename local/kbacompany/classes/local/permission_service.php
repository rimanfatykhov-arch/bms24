<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Centralised company-scoped authorisation policy for BMS management actions. */
class permission_service {
    public const CONTRACT_VERSION = '0.7.0';
    public const CAPABILITY_MANAGE = 'local/kbacompany:manageorganization';
    public const CAPABILITY_MANAGE_PEOPLE = 'local/kbacompany:managepeople';

    /** Machine-readable permission policy; service callers should use these methods instead of role checks. */
    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'capability' => self::CAPABILITY_MANAGE,
            'policies' => [
                'manage_organization' => ['owner', 'manager', 'hr'],
                'manage_people' => ['owner', 'manager', 'hr'],
                'view_people_private' => ['explicit_capability'],
                'manage_management' => ['owner', 'manager'],
                'manage_owner_private' => ['owner'],
                'review_owner_private' => ['explicit_capability'],
                'manage_tasks' => ['owner', 'manager'],
                'manage_communications' => ['owner', 'manager'],
                'manage_indicators' => ['owner', 'manager', 'business_role:Управляющий', 'business_role:Супервайзер'],
            ],
            'rules' => [
                'site_admin_bypass',
                'company_context_required',
                'standard_company_roles_require_moodle_capability',
                'indicator_business_role_grant_is_module_scoped',
                'owner_private_review_requires_explicit_capability',
                'no_service_specific_role_checks',
            ],
        ];
    }

    /** Organisational design, staffing, positions, products/statistics and related structural data. */
    public static function can_manage_organization(string $companyuuid, int $userid): bool {
        return self::allowed($companyuuid, $userid, ['owner', 'manager', 'hr']);
    }

    /** People architecture, Role DNA and competency governance. */
    public static function can_manage_people(string $companyuuid, int $userid): bool {
        $company = company_context_service::context($companyuuid, $userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        if (!has_capability(self::CAPABILITY_MANAGE_PEOPLE, \context_system::instance(), $userid)) {
            return false;
        }
        return (bool)array_intersect(['owner', 'manager', 'hr'], $company['roles']);
    }

    /** Sensitive people evidence is explicit and never implied by ordinary People management. */
    public static function can_view_people_private(string $companyuuid, int $userid): bool {
        company_context_service::context($companyuuid, $userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        return has_capability('local/kbacompany:viewpeopleprivate', \context_system::instance(), $userid);
    }

    /** Private ownership/LPF contour. Managers never inherit access to owner-private content. */
    public static function can_manage_owner_private(string $companyuuid, int $userid): bool {
        return self::allowed($companyuuid, $userid, ['owner']);
    }

    /** Consultant review access is explicit and never inherited from the manager role. */
    public static function can_review_owner_private(string $companyuuid, int $userid): bool {
        company_context_service::context($companyuuid, $userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        return has_capability('local/kbacompany:reviewownerprivate', \context_system::instance(), $userid);
    }

    /** Administrative cascade, policies/directives, product flows and deviation management. */
    public static function can_manage_management(string $companyuuid, int $userid): bool {
        return self::allowed($companyuuid, $userid, ['owner', 'manager']);
    }

    /** Company-wide task and Battle Plan administration; employees can still manage their own assigned work. */
    public static function can_manage_tasks(string $companyuuid, int $userid): bool {
        return self::allowed($companyuuid, $userid, ['owner', 'manager']);
    }

    /** Communication administration does not imply access to private thread content. */
    public static function can_manage_communications(string $companyuuid, int $userid): bool {
        return self::allowed($companyuuid, $userid, ['owner', 'manager']);
    }

    /** Indicator registry administration also supports explicit operational Business Roles. */
    public static function can_manage_indicators(string $companyuuid, int $userid): bool {
        $company = company_context_service::context($companyuuid, $userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        if (has_capability(self::CAPABILITY_MANAGE, \context_system::instance(), $userid) &&
                array_intersect(['owner', 'manager'], $company['roles'])) {
            return true;
        }
        return business_role_service::user_has_named_role($companyuuid, $userid, ['Управляющий', 'Супервайзер']);
    }

    private static function allowed(string $companyuuid, int $userid, array $roles): bool {
        $company = company_context_service::context($companyuuid, $userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        if (!has_capability(self::CAPABILITY_MANAGE, \context_system::instance(), $userid)) {
            return false;
        }
        return (bool)array_intersect($roles, $company['roles']);
    }
}
