<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Aggregated, read-only People Foundation workspace context. */
class people_foundation_service {
    public const CONTRACT_VERSION = '0.1.0';

    public static function context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $frameworks = competency_catalog_service::frameworks($companyuuid, $userid);
        $competencies = competency_catalog_service::competencies($companyuuid, $userid);
        $roledna = role_dna_service::list_current($companyuuid, $userid);
        global $DB;
        $summary = [
            'employees' => (int)$DB->count_records('local_kbacompany_member', ['companyuuid' => $companyuuid, 'active' => 1, 'isemployee' => 1]),
            'positions' => (int)$DB->count_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1]),
            'published_profiles' => (int)$DB->count_records('local_kbacompany_roledna', ['companyuuid' => $companyuuid, 'status' => 'published']),
            'assessments_waiting' => (int)$DB->count_records('local_kbacompany_assess', ['companyuuid' => $companyuuid, 'status' => 'submitted']),
            'development_active' => (int)$DB->count_records('local_kbacompany_devplan', ['companyuuid' => $companyuuid, 'status' => 'active']),
            'feedback360_open' => (int)$DB->count_records('local_kbacompany_360cycle', ['companyuuid' => $companyuuid, 'status' => 'open']),
        ];
        $summary['attention'] = $summary['assessments_waiting'] + $summary['feedback360_open'];
        return [
            'company' => $company,
            'can_manage' => permission_service::can_manage_people($companyuuid, $userid),
            'can_view_private' => permission_service::can_view_people_private($companyuuid, $userid),
            'frameworks' => $frameworks,
            'competencies' => $competencies,
            'role_dna' => $roledna,
            'summary' => array_merge($summary, [
                'frameworks' => count($frameworks),
                'competencies' => count($competencies),
                'role_dna' => count($roledna),
            ]),
        ];
    }
}
