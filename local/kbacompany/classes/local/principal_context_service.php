<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 principal context, parallel to company_context_service (A0_00, A0_02 §2). The human path is unchanged:
 * people keep company_context_service and permission_service. This service takes no userid, token or session.
 *
 * Fail closed: the flag is off, the company or principal is unknown, the principal belongs to another company, is not
 * an active ai_agent, or its sponsor is no longer a current member — all give the same error, so the answer never
 * reveals whether a record of another company exists.
 */
final class principal_context_service {
    public static function context(string $companyuuid, string $principaluuid): principal_context {
        global $DB;
        bmos_registry::require_enabled();
        $companyuuid = bmos_registry::company($companyuuid);
        $principaluuid = bmos_registry::uuid_or_unavailable($principaluuid);
        // Company boundary in SQL: a principal of another company is never read.
        $row = $DB->get_record(bmos_registry::PRINCIPAL, ['companyuuid' => $companyuuid, 'uuid' => $principaluuid],
            'id, uuid, companyuuid, principaltype, userid, transportuserid, sponsoruserid, status, authzrevision');
        if (!$row || (string)$row->principaltype !== principal_registry_service::TYPE_AGENT ||
                (string)$row->status !== 'active' || (int)$row->userid !== 0 || (int)$row->transportuserid !== 0 ||
                !bmos_registry::is_member($companyuuid, (int)$row->sponsoruserid)) {
            bmos_registry::fail('notavailable');
        }
        return new principal_context($companyuuid, (string)$row->uuid, (string)$row->principaltype,
            (int)$row->sponsoruserid, (int)$row->authzrevision, time());
    }
}
