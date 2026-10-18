<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\deal_client;
use local_bitrixsync\logging\logger;

defined('MOODLE_INTERNAL') || die();

/**
 * Process an authenticated ONCRMCONTACTUPDATE without touching enrolments.
 *
 * The current Contact card is hydrated from Bitrix24, mapped to an existing
 * Moodle user, then handed to the Corporate employee/role MERGE flow.
 */
class contact_profile_sync_service {
    /** @var deal_client */
    private $bitrix;
    /** @var contact_service */
    private $contacts;
    /** @var corporate_employee_sync_service */
    private $corporate;

    public function __construct(
        ?deal_client $bitrix = null,
        ?contact_service $contacts = null,
        ?corporate_employee_sync_service $corporate = null
    ) {
        $this->bitrix = $bitrix ?? new deal_client();
        $this->contacts = $contacts ?? new contact_service();
        $this->corporate = $corporate ?? new corporate_employee_sync_service();
    }

    public function process(int $bitrixcontactid): array {
        if ($bitrixcontactid <= 0) {
            throw new \moodle_exception(
                'deal_contact_missing',
                'local_bitrixsync'
            );
        }

        $contact = $this->bitrix->get_contact($bitrixcontactid);
        if (!$contact) {
            throw new \moodle_exception(
                'contact_profile_load_failed',
                'local_bitrixsync',
                '',
                $bitrixcontactid
            );
        }

        // Contact updates must not create an LMS account by themselves.
        // Resolve only an already mapped / uniquely matched Moodle user.
        $resolution = $this->contacts->resolve_user(
            $bitrixcontactid,
            $contact,
            true,
            null
        );

        $userid = (int)($resolution['userid'] ?? 0);
        if ($userid <= 0) {
            return $this->finish([
                'status' => 'skipped',
                'contactid' => $bitrixcontactid,
                'userid' => 0,
                'companyid' => 0,
                'roles' => [],
                'reason' => 'moodle_user_not_resolved',
            ]);
        }

        $companyid = (int)($contact['COMPANY_ID'] ?? 0);
        if ($companyid <= 0) {
            return $this->finish([
                'status' => 'skipped',
                'contactid' => $bitrixcontactid,
                'userid' => $userid,
                'companyid' => 0,
                'roles' => [],
                'reason' => 'contact_company_missing',
            ]);
        }

        $company = $this->bitrix->get_company($companyid);
        $companytitle = trim((string)($company['TITLE'] ?? ''));
        if ($companytitle === '') {
            $companytitle = 'Bitrix24 Company #' . $companyid;
        }

        $context = [
            'bitrixcompanyid' => $companyid,
            'companytitle' => $companytitle,
            'bitrixcontactid' => $bitrixcontactid,
            'contact' => $contact,
        ];

        // sync_employee() guarantees the one-user/one-company membership;
        // its role handoff is MERGE-only and never removes manual roles.
        $corporateresult = $this->corporate->sync($userid, $context);
        $rolesync = is_array($corporateresult['roles'] ?? null)
            ? $corporateresult['roles']
            : [];

        $roles = is_array($rolesync['roles'] ?? null)
            ? array_values($rolesync['roles'])
            : [];

        $status = (string)($rolesync['status'] ?? '');
        if ($status === '') {
            $status = (string)($corporateresult['status'] ?? 'unknown');
        }

        $bmsresult = ['status' => 'skipped', 'reason' => 'bms_bridge_disabled'];
        if (get_config('local_bitrixsync', 'bms_bridge_enabled')) {
            try {
                $bmsresult = (new bms_bridge_service())->sync_contact_to_bms(
                    $bitrixcontactid,
                    $contact,
                    $company,
                    false,
                    false
                );
            } catch (\Throwable $bmserror) {
                $bmsresult = ['status' => 'error', 'error' => $bmserror->getMessage()];
            }
        }

        return $this->finish([
            'status' => $status,
            'contactid' => $bitrixcontactid,
            'userid' => $userid,
            'bitrix_company_id' => $companyid,
            'corporate_company_id' => (int)(
                $corporateresult['corporate_company_id'] ??
                $rolesync['corporate_company_id'] ??
                0
            ),
            'company' => $companytitle,
            'roles' => $roles,
            'strategy' => (string)($rolesync['strategy'] ?? 'merge'),
            'corporate' => (string)($corporateresult['status'] ?? ''),
            'attached' => !empty($corporateresult['attached']),
            'role_result' => $rolesync,
            'employee_sync_result' => $corporateresult['result'] ?? null,
            'bms' => $bmsresult,
        ]);
    }

    private function finish(array $result): array {
        $result['time'] = time();

        set_config(
            'corporate_role_sync_last',
            json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        logger::corporate_role_sync(
            'Contact profile sync',
            [
                'contactid' => (int)($result['contactid'] ?? 0),
                'userid' => (int)($result['userid'] ?? 0),
                'bitrix_company_id' => (int)($result['bitrix_company_id'] ?? 0),
                'corporate_company_id' => (int)($result['corporate_company_id'] ?? 0),
                'roles' => (array)($result['roles'] ?? []),
                'strategy' => (string)($result['strategy'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'reason' => (string)($result['reason'] ?? ''),
            ]
        );

        return $result;
    }
}
