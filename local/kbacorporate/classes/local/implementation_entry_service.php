<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company selection for the implementation entry point.
 *
 * A department-scoped manager may see a corporate company elsewhere,
 * but must not open its company-wide implementation program.
 */
final class implementation_entry_service {
    /**
     * Return only active companies with full company-wide visibility.
     *
     * @param int $userid Moodle user ID.
     * @return array<int, \stdClass> Companies keyed by legacy numeric ID.
     */
    public static function manageable_companies(int $userid): array {
        $companies = access::get_allowed_companies($userid);
        return array_filter($companies, static function($company) use ($userid): bool {
            return access::has_full_company_access((int)$company->id, $userid);
        });
    }
}
