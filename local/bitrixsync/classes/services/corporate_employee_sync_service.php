<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\corporate\api_client;

defined('MOODLE_INTERNAL') || die();

/** Ensure the Moodle user is visible as an employee in KBA Corporate Dashboard. */
class corporate_employee_sync_service {
    /** @var api_client */
    private $api;
    public function __construct(?api_client $api = null) {
        $this->api = $api ?? new api_client();
    }

    public function sync(int $userid, array $context): array {
        if (!get_config('local_bitrixsync', 'corporate_employee_sync_enable')) {
            return ['status' => 'skipped', 'attached' => false, 'reason' => 'disabled'];
        }
        $companyid = (int)($context['bitrixcompanyid'] ?? 0);
        $contactid = (int)($context['bitrixcontactid'] ?? 0);
        if ($userid <= 0 || $companyid <= 0) {
            return ['status' => 'not_applicable', 'attached' => false];
        }

        $diag = [
            'enabled' => $this->api->is_enabled(),
            'available' => $this->api->is_available(),
            'compatible' => $this->api->is_api_compatible(),
            'status' => $this->api->status(),
        ];
        if (!$diag['enabled'] || !$diag['available'] || !$diag['compatible']) {
            return ['status' => 'unavailable', 'attached' => false, 'diagnostic' => $diag];
        }

        try {
            $result = $this->api->sync_employee(
                $userid,
                $companyid,
                (string)($context['companytitle'] ?? ''),
                $contactid
            );
            (new contact_service())->record_corporate_employee(
                $contactid,
                $userid,
                is_array($context['contact'] ?? null) ? $context['contact'] : []
            );

            // Public API v1.10+: role sync is MERGE-only and never rolls back
            // the already successful Moodle user/enrolment/corporate attachment.
            //
            // IMPORTANT: Bitrix Company ID is an external identifier. The
            // Corporate bridge owns the mapping to local_kbacorporate companyid.
            // Never pass the external Bitrix ID into merge_employee_roles().
            $corporatecompanyid = $this->extract_local_companyid($result);

            $rolesync = [
                'status' => 'skipped',
                'reason' => 'role_schema_api_unavailable',
                'bitrix_company_id' => $companyid,
                'corporate_company_id' => $corporatecompanyid,
            ];

            if ($contactid > 0 && $this->api->is_role_schema_compatible()) {
                if ($corporatecompanyid <= 0) {
                    $rolesync = [
                        'status' => 'skipped',
                        'reason' => 'corporate_company_mapping_missing',
                        'bitrix_company_id' => $companyid,
                        'corporate_company_id' => 0,
                        'sync_employee_result_keys' => is_array($result)
                            ? array_keys($result)
                            : [],
                    ];
                } else {
                    try {
                        $rolesync = (new corporate_role_sync_service())->merge(
                            $userid,
                            $corporatecompanyid,
                            $contactid
                        );
                        $rolesync['bitrix_company_id'] = $companyid;
                        $rolesync['corporate_company_id'] = $corporatecompanyid;
                    } catch (\Throwable $roleerror) {
                        $rolesync = [
                            'status' => 'error',
                            'error' => $roleerror->getMessage(),
                            'bitrix_company_id' => $companyid,
                            'corporate_company_id' => $corporatecompanyid,
                        ];
                    }
                }
            }

            return [
                'status' => 'attached',
                'attached' => true,
                'result' => $result,
                'bitrix_company_id' => $companyid,
                'corporate_company_id' => $corporatecompanyid,
                'roles' => $rolesync,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'attached' => false, 'error' => $e->getMessage()];
        }
    }
    /**
     * Extract local Corporate Dashboard companyid from the documented bridge
     * result without ever falling back to the external Bitrix company ID.
     *
     * Different Corporate Dashboard bridge revisions used slightly different
     * response envelopes, so this accepts only explicit local-id shaped keys.
     */
    private function extract_local_companyid($result): int {
        if (!is_array($result)) {
            return 0;
        }

        $candidates = [
            $result['corporate_company_id'] ?? null,
            $result['local_company_id'] ?? null,
            $result['localcompanyid'] ?? null,
            $result['companyid'] ?? null,
            $result['company_id'] ?? null,
        ];

        if (is_array($result['company'] ?? null)) {
            $company = $result['company'];
            $candidates[] = $company['corporate_company_id'] ?? null;
            $candidates[] = $company['local_company_id'] ?? null;
            $candidates[] = $company['companyid'] ?? null;
            $candidates[] = $company['id'] ?? null;
        }

        if (is_array($result['membership'] ?? null)) {
            $membership = $result['membership'];
            $candidates[] = $membership['companyid'] ?? null;
            $candidates[] = $membership['company_id'] ?? null;
        }

        if (is_array($result['member'] ?? null)) {
            $member = $result['member'];
            $candidates[] = $member['companyid'] ?? null;
            $candidates[] = $member['company_id'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $id = (int)$candidate;
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

}
