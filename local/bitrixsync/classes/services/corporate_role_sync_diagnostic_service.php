<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\deal_client;
use local_bitrixsync\logging\logger;

defined('MOODLE_INTERNAL') || die();

/**
 * Manual, production-safe trace for one Contact corporate role sync.
 *
 * Does not touch enrolments or access delivery. It reuses the same
 * Corporate employee/role MERGE flow as the Contact webhook path.
 */
class corporate_role_sync_diagnostic_service {
    /** @var deal_client */
    private $bitrix;
    /** @var contact_service */
    private $contacts;
    /** @var corporate_employee_sync_service */
    private $corporate;
    /** @var corporate_role_schema_service */
    private $schema;

    public function __construct(
        ?deal_client $bitrix = null,
        ?contact_service $contacts = null,
        ?corporate_employee_sync_service $corporate = null,
        ?corporate_role_schema_service $schema = null
    ) {
        $this->bitrix = $bitrix ?? new deal_client();
        $this->contacts = $contacts ?? new contact_service();
        $this->corporate = $corporate ?? new corporate_employee_sync_service();
        $this->schema = $schema ?? new corporate_role_schema_service();
    }

    public function run(int $contactid): array {
        $trace = [
            'contactid' => $contactid,
            'time' => time(),
            'steps' => [],
        ];

        try {
            $contact = $this->bitrix->get_contact($contactid);
            if (!$contact) {
                throw new \moodle_exception(
                    'contact_profile_load_failed',
                    'local_bitrixsync',
                    '',
                    $contactid
                );
            }

            $trace['steps']['contact_loaded'] = [
                'ok' => true,
                'company_id' => (int)($contact['COMPANY_ID'] ?? 0),
                'email' => $this->extract_email($contact),
            ];

            $resolution = $this->contacts->resolve_user(
                $contactid,
                $contact,
                true,
                null
            );

            $userid = (int)($resolution['userid'] ?? 0);
            $trace['steps']['moodle_user'] = [
                'ok' => $userid > 0,
                'userid' => $userid,
                'resolution' => $resolution,
            ];

            // corporate_role_schema_service::read_contact_roles() accepts
            // the Bitrix Contact ID and performs its own authoritative CRM read.
            $roleread = $this->schema->read_contact_roles($contactid);

            $roles = [];
            $fieldname = '';
            $rawvalues = [];
            $rolereadstatus = 'unknown';

            if (is_array($roleread)) {
                // Current schema service returns an envelope.
                if (isset($roleread['roles']) && is_array($roleread['roles'])) {
                    $roles = array_values($roleread['roles']);
                    $fieldname = (string)($roleread['field_name'] ?? $roleread['field'] ?? '');
                    $rawvalues = is_array($roleread['raw_values'] ?? null)
                        ? array_values($roleread['raw_values'])
                        : [];
                    $rolereadstatus = (string)($roleread['status'] ?? 'ok');
                } else {
                    // Backward-compatible fallback if an older bridge returns
                    // the rolecodes array directly.
                    $islist = array_keys($roleread) === range(0, count($roleread) - 1);
                    if ($islist) {
                        $roles = array_values($roleread);
                        $rolereadstatus = 'ok';
                    }
                }
            }

            $enum_mapping = [];
            if (method_exists($this->schema, 'enum_mapping')) {
                $enum_mapping = $this->schema->enum_mapping();
            }

            $trace['steps']['roles_from_bitrix'] = [
                'ok' => $rolereadstatus !== 'error',
                'status' => $rolereadstatus,
                'roles' => $roles,
                'field_name' => $fieldname,
                'raw_values' => $rawvalues,
                'enum_mapping' => $enum_mapping,
                'service_result' => $roleread,
            ];

            $bitrixcompanyid = (int)($contact['COMPANY_ID'] ?? 0);
            $company = $bitrixcompanyid > 0
                ? $this->bitrix->get_company($bitrixcompanyid)
                : [];

            $companytitle = trim((string)($company['TITLE'] ?? ''));
            $trace['steps']['company_from_bitrix'] = [
                'ok' => $bitrixcompanyid > 0,
                'bitrix_company_id' => $bitrixcompanyid,
                'title' => $companytitle,
            ];

            if ($userid <= 0 || $bitrixcompanyid <= 0) {
                $trace['summary'] = 'prerequisites_missing';
                return $this->finish($trace);
            }

            $context = [
                'bitrixcompanyid' => $bitrixcompanyid,
                'companytitle' => $companytitle,
                'bitrixcontactid' => $contactid,
                'contact' => $contact,
            ];

            $syncresult = $this->corporate->sync($userid, $context);

            $trace['steps']['corporate_sync'] = [
                'ok' => true,
                'result' => $syncresult,
            ];

            $trace['summary'] = 'completed';
            return $this->finish($trace);

        } catch (\Throwable $e) {
            $trace['summary'] = 'error';
            $trace['error_class'] = get_class($e);
            $trace['error'] = $e->getMessage();
            return $this->finish($trace);
        }
    }

    private function finish(array $trace): array {
        set_config(
            'corporate_role_sync_debug_last',
            json_encode(
                $trace,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        logger::corporate_role_sync(
            'Diagnostic run',
            [
                'contactid' => (int)($trace['contactid'] ?? 0),
                'summary' => (string)($trace['summary'] ?? ''),
                'error' => (string)($trace['error'] ?? ''),
            ]
        );

        return $trace;
    }

    private function extract_email(array $contact): string {
        foreach (($contact['EMAIL'] ?? []) as $row) {
            if (!empty($row['VALUE'])) {
                return (string)$row['VALUE'];
            }
        }
        return '';
    }
}
