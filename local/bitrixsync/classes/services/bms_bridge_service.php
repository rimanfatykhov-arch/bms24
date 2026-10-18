<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\adapters\bitrix24\deal_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Companion bridge for local_kbacompany v0.5.16.
 *
 * Bitrix24 transport stays owned by KBA Connect. BMS is accessed only through
 * its internal PHP integration_service contract; KBA Corporate is accessed
 * only through the existing public bridge adapter.
 */
class bms_bridge_service {
    /** @var client */
    private $client;
    /** @var deal_client */
    private $bitrix;
    /** @var contact_service */
    private $contacts;
    /** @var corporate_employee_sync_service */
    private $corporate;

    public function __construct(
        ?client $client = null,
        ?deal_client $bitrix = null,
        ?contact_service $contacts = null,
        ?corporate_employee_sync_service $corporate = null
    ) {
        $this->client = $client ?? new client();
        $this->bitrix = $bitrix ?? new deal_client($this->client);
        $this->contacts = $contacts ?? new contact_service();
        $this->corporate = $corporate ?? new corporate_employee_sync_service();
    }

    public function is_enabled(): bool {
        return (bool)get_config('local_bitrixsync', 'bms_bridge_enabled');
    }

    public function is_available(): bool {
        return class_exists('\\local_kbacompany\\local\\integration_service');
    }

    public function contract(): array {
        return [
            'version' => '5.0.36.1.23',
            'bms_required' => '0.6.9.2',
            'company_admission' => 'bitrix_company_bms_client_flag_true',
            'outbox_commands' => ['company_reconcile', 'employee_upsert', 'employee_close'],
            'course_enrolment' => 'unchanged_deal_driven',
            'corporate_learning' => 'local_kbacorporate_bridge_only',
        ];
    }

    /** Process pending BMS outbox commands without touching Deal enrolments. */
    public function process_pending_commands(int $limit = 20): array {
        $stats = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'deferred' => 0];
        if (!$this->is_enabled() || !$this->is_available()) {
            return $stats;
        }

        $integration = '\\local_kbacompany\\local\\integration_service';
        $commands = $integration::pending_commands(max(1, min(100, $limit)));
        foreach ($commands as $command) {
            $stats['processed']++;
            try {
                $result = $this->process_command($command);
                if (!empty($result['deferred'])) {
                    $stats['deferred']++;
                    continue;
                }
                $integration::complete_command((string)$command->commanduuid, $result);
                $stats['completed']++;
            } catch (\Throwable $e) {
                $integration::fail_command((string)$command->commanduuid, $e->getMessage());
                $stats['failed']++;
            }
        }
        return $stats;
    }

    public function process_command(\stdClass $command): array {
        $payload = [];
        if (!empty($command->payload)) {
            $decoded = json_decode((string)$command->payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $kind = (string)$command->commandkind;
        if ($kind === 'employee_upsert') {
            return $this->process_employee_upsert($command, $payload);
        }
        if ($kind === 'employee_close') {
            return $this->process_employee_close($command, $payload);
        }
        if ($kind === 'company_reconcile') {
            return $this->process_company_reconcile($command, $payload);
        }
        throw new \moodle_exception('bms_bridge_unknown_command', 'local_bitrixsync', '', $kind);
    }

    /** Sync one Bitrix company into BMS. Contacts are optional. */
    public function sync_company(int $bitrixcompanyid, bool $synccontacts = false): array {
        if (!$this->is_enabled() || !$this->is_available()) {
            return ['status' => 'skipped', 'reason' => 'bms_bridge_unavailable'];
        }
        if ($bitrixcompanyid <= 0) {
            throw new \invalid_parameter_exception('Invalid Bitrix company id.');
        }

        $company = $this->bitrix->get_company($bitrixcompanyid);
        if (!$company) {
            throw new \moodle_exception('bms_bridge_company_load_failed', 'local_bitrixsync', '', $bitrixcompanyid);
        }

        $field = $this->resolve_bms_client_field();
        if (empty($field['ready']) || empty($field['field_name'])) {
            return ['status' => 'deferred', 'reason' => 'bms_client_field_missing', 'deferred' => true];
        }
        $fieldname = (string)$field['field_name'];
        $isbmsclient = $this->to_bool($company[$fieldname] ?? null);
        $legacycorporateid = $this->legacy_corporate_company_id($bitrixcompanyid);
        $integration = '\\local_kbacompany\\local\\integration_service';
        $result = $integration::upsert_company_from_external(
            (string)$bitrixcompanyid,
            $isbmsclient,
            [
                'name' => trim((string)($company['TITLE'] ?? '')),
                'code' => trim((string)($company['ID'] ?? '')),
                'legacycompanyid' => $legacycorporateid,
            ],
            0
        );

        $result['bitrix_company_id'] = $bitrixcompanyid;
        $result['bms_client'] = $isbmsclient;
        $result['contacts'] = [];
        if ($isbmsclient && $synccontacts) {
            foreach ($this->bitrix->get_company_contacts($bitrixcompanyid) as $item) {
                $contactid = (int)($item['id'] ?? 0);
                $contact = is_array($item['contact'] ?? null) ? $item['contact'] : [];
                $hydration = (string)($item['hydration'] ?? ($contact['_KBA_HYDRATION_SOURCE'] ?? 'unknown'));
                if ($contactid <= 0) {
                    continue;
                }
                if (!$contact) {
                    $result['contacts'][] = [
                        'contactid' => $contactid,
                        'status' => 'error',
                        'hydration' => $hydration,
                        'error' => 'contact_hydration_empty',
                    ];
                    continue;
                }
                try {
                    $contactresult = $this->sync_contact_to_bms($contactid, $contact, $company, true, true);
                    $contactresult['hydration'] = $hydration;
                    $result['contacts'][] = $contactresult;
                } catch (\Throwable $e) {
                    $result['contacts'][] = [
                        'contactid' => $contactid,
                        'status' => 'error',
                        'hydration' => $hydration,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }
        return $result;
    }

    /** Sync one hydrated Bitrix Contact to BMS if its company is a BMS client. */
    public function sync_contact_to_bms(int $bitrixcontactid, array $contact = [], array $company = [],
            bool $allowusercreate = false, bool $ensurecorporate = true): array {
        if (!$this->is_enabled() || !$this->is_available()) {
            return ['status' => 'skipped', 'reason' => 'bms_bridge_unavailable'];
        }
        if (!$contact) {
            $contact = $this->bitrix->get_contact($bitrixcontactid);
        }
        if (!$contact) {
            throw new \moodle_exception('contact_profile_load_failed', 'local_bitrixsync', '', $bitrixcontactid);
        }
        $companyid = (int)($contact['COMPANY_ID'] ?? 0);
        if ($companyid <= 0) {
            return ['status' => 'skipped', 'reason' => 'contact_company_missing'];
        }
        if (!$company) {
            $company = $this->bitrix->get_company($companyid);
        }
        $companysync = $this->sync_company($companyid, false);
        if (empty($companysync['bms_client'])) {
            return ['status' => 'skipped', 'reason' => 'company_not_bms_client', 'company' => $companysync];
        }

        // Do not create an LMS account merely because a Contact webhook fired.
        $resolution = $this->contacts->resolve_user($bitrixcontactid, $contact, !$allowusercreate, null);
        $userid = (int)($resolution['userid'] ?? 0);
        if ($userid <= 0) {
            return [
                'contactid' => $bitrixcontactid,
                'status' => 'skipped',
                'reason' => 'moodle_user_not_resolved',
                'resolution' => [
                    'status' => (string)($resolution['status'] ?? 'unknown'),
                    'email' => (string)($resolution['email'] ?? ''),
                    'wouldcreate' => !empty($resolution['wouldcreate']),
                ],
            ];
        }

        $integration = '\\local_kbacompany\\local\\integration_service';
        $email = $this->extract_contact_email($contact);
        $phone = $this->extract_contact_phone($contact);
        $bms = $integration::upsert_employee_from_external(
            (string)$companyid,
            (string)$bitrixcontactid,
            [
                'email' => $email,
                'firstname' => trim((string)($contact['NAME'] ?? '')),
                'lastname' => trim((string)($contact['LAST_NAME'] ?? '')),
                'phone' => $phone,
                'position' => trim((string)($contact['POST'] ?? '')),
                'allow_create_user' => false,
            ],
            ['employee']
        );

        $corporateresult = ['status' => 'skipped', 'attached' => false];
        if ($ensurecorporate) {
            $companytitle = trim((string)($company['TITLE'] ?? ''));
            if ($companytitle === '') {
                $companytitle = 'Bitrix24 Company #' . $companyid;
            }
            $corporateresult = $this->corporate->sync($userid, [
                'bitrixcompanyid' => $companyid,
                'companytitle' => $companytitle,
                'bitrixcontactid' => $bitrixcontactid,
                'contact' => $contact,
            ]);
            $corporatecompanyid = (int)($corporateresult['corporate_company_id'] ?? 0);
            if ($corporatecompanyid > 0) {
                (new company_service())->record_corporate_company_mapping($companyid, $corporatecompanyid);
            }
        }

        return [
            'contactid' => $bitrixcontactid,
            'status' => 'synced',
            'userid' => $userid,
            'resolution' => [
                'status' => (string)($resolution['status'] ?? 'unknown'),
                'email' => (string)($resolution['email'] ?? ''),
            ],
            'bms' => $bms,
            'company' => $companysync,
            'corporate' => $corporateresult,
        ];
    }

    private function process_employee_upsert(\stdClass $command, array $payload): array {
        global $DB;
        $companyid = $this->resolve_external_company_id($command, $payload);
        if ($companyid <= 0) {
            return ['deferred' => true, 'reason' => 'company_mapping_missing'];
        }

        $companysync = $this->sync_company($companyid, false);
        if (empty($companysync['bms_client'])) {
            throw new \moodle_exception('bms_bridge_company_not_client', 'local_bitrixsync', '', $companyid);
        }

        $userid = (int)$command->userid;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $contactid = $this->resolve_or_create_contact($userid, $companyid, $payload, $user);
        $contact = $this->bitrix->get_contact($contactid);
        if (!$contact) {
            throw new \moodle_exception('contact_profile_load_failed', 'local_bitrixsync', '', $contactid);
        }

        $company = $this->bitrix->get_company($companyid);
        $companytitle = trim((string)($company['TITLE'] ?? ''));
        if ($companytitle === '') {
            $companytitle = 'Bitrix24 Company #' . $companyid;
        }
        $corporate = $this->corporate->sync($userid, [
            'bitrixcompanyid' => $companyid,
            'companytitle' => $companytitle,
            'bitrixcontactid' => $contactid,
            'contact' => $contact,
        ]);
        if (empty($corporate['attached'])) {
            throw new \moodle_exception('bms_bridge_corporate_member_failed', 'local_bitrixsync', '',
                (string)($corporate['error'] ?? $corporate['status'] ?? 'unknown'));
        }

        return [
            'external_contact_id' => $contactid,
            'corporate_member_reconciled' => true,
            'bitrix_company_id' => $companyid,
            'corporate_company_id' => (int)($corporate['corporate_company_id'] ?? 0),
        ];
    }

    private function process_employee_close(\stdClass $command, array $payload): array {
        $companyid = $this->resolve_external_company_id($command, $payload);
        if ($companyid <= 0) {
            return ['deferred' => true, 'reason' => 'company_mapping_missing'];
        }
        $userid = (int)$command->userid;
        $api = new \local_bitrixsync\adapters\corporate\api_client();
        $result = $api->is_available() && $api->is_api_compatible()
            ? $api->remove_employee($userid, $companyid)
            : ['success' => false, 'reason' => 'corporate_unavailable'];
        if (empty($result['success'])) {
            throw new \moodle_exception('bms_bridge_corporate_member_failed', 'local_bitrixsync');
        }
        return ['employee_closed' => true, 'bitrix_company_id' => $companyid];
    }

    private function process_company_reconcile(\stdClass $command, array $payload): array {
        $companyid = $this->resolve_external_company_id($command, $payload);
        if ($companyid <= 0) {
            return ['deferred' => true, 'reason' => 'company_mapping_missing'];
        }
        $result = $this->sync_company($companyid, true);
        if (!empty($result['deferred'])) {
            return $result;
        }
        return ['company_reconciled' => true, 'bitrix_company_id' => $companyid];
    }

    private function resolve_external_company_id(\stdClass $command, array $payload): int {
        $integration = '\\local_kbacompany\\local\\integration_service';
        $companyuuid = trim((string)($command->companyuuid ?? $payload['company_uuid'] ?? ''));
        if ($companyuuid !== '' && $this->is_available()) {
            $mapped = (int)$integration::external_id('bitrix24', 'company', $companyuuid);
            if ($mapped > 0) {
                return $mapped;
            }
        }
        $payloadid = (int)($payload['external_company_id'] ?? 0);
        if ($payloadid > 0) {
            return $payloadid;
        }
        $legacyid = (int)($payload['legacy_corporate_company_id'] ?? 0);
        if ($legacyid > 0) {
            global $DB;
            $row = $DB->get_record('local_bitrixsync_company', ['corporate_company_id' => $legacyid], '*', IGNORE_MULTIPLE);
            if ($row && (int)$row->bitrix_company_id > 0) {
                return (int)$row->bitrix_company_id;
            }
        }
        return 0;
    }

    private function resolve_or_create_contact(int $userid, int $companyid, array $payload, \stdClass $user): int {
        $integration = '\\local_kbacompany\\local\\integration_service';
        $mapped = (int)$integration::external_id('bitrix24', 'user', (string)$userid);
        if ($mapped > 0 && $this->bitrix->get_contact($mapped)) {
            $this->ensure_contact_profile($mapped, $companyid, $payload, $user);
            return $mapped;
        }

        global $DB;
        $bridge = $DB->get_record('local_bitrixsync_contact', ['userid' => $userid], '*', IGNORE_MULTIPLE);
        if ($bridge && (int)$bridge->bitrix_contact_id > 0 && $this->bitrix->get_contact((int)$bridge->bitrix_contact_id)) {
            $contactid = (int)$bridge->bitrix_contact_id;
            $this->ensure_contact_profile($contactid, $companyid, $payload, $user);
            return $contactid;
        }

        $email = \core_text::strtolower(trim((string)($payload['email'] ?? $user->email ?? '')));
        if (!validate_email($email)) {
            throw new \moodle_exception('contact_email_required_for_create', 'local_bitrixsync', '', $userid);
        }
        $matches = $this->find_contacts_by_email($email);
        if (count($matches) > 1) {
            throw new \moodle_exception('contact_email_ambiguous', 'local_bitrixsync', '', $email);
        }
        if (count($matches) === 1) {
            $contactid = (int)$matches[0];
            $this->ensure_contact_profile($contactid, $companyid, $payload, $user);
            return $contactid;
        }

        $fields = $this->contact_fields($companyid, $payload, $user);
        $response = $this->client->call('crm.contact.add', ['fields' => $fields]);
        $contactid = (int)($response['result'] ?? 0);
        if ($contactid <= 0) {
            throw new \moodle_exception('bms_bridge_contact_create_failed', 'local_bitrixsync');
        }
        return $contactid;
    }

    private function ensure_contact_profile(int $contactid, int $companyid, array $payload, \stdClass $user): void {
        $contact = $this->bitrix->get_contact($contactid);
        if (!$contact) {
            throw new \moodle_exception('contact_profile_load_failed', 'local_bitrixsync', '', $contactid);
        }
        $fields = $this->contact_fields($companyid, $payload, $user);
        $changed = ((int)($contact['COMPANY_ID'] ?? 0) !== $companyid);
        if (!$changed) {
            $currentemail = $this->extract_contact_email($contact);
            $changed = $currentemail === '' || \core_text::strtolower($currentemail) !== \core_text::strtolower((string)$user->email);
        }
        $desiredphone = $this->safe_phone(trim((string)($payload['phone'] ?? $user->phone1 ?? '')));
        $currentphone = trim($this->extract_contact_phone($contact));
        $currentphoneisemail = $currentphone !== '' && validate_email($currentphone);
        if (!$changed && $desiredphone !== '' && $currentphone !== $desiredphone) {
            $changed = true;
        }
        if ($currentphoneisemail && $desiredphone === '') {
            // Explicitly clear only an obviously invalid historical phone value.
            $fields['PHONE'] = [];
            $changed = true;
        }
        if ($changed) {
            $this->client->call('crm.contact.update', ['id' => $contactid, 'fields' => $fields]);
        }
    }

    private function contact_fields(int $companyid, array $payload, \stdClass $user): array {
        $firstname = trim((string)($payload['firstname'] ?? $user->firstname ?? ''));
        $lastname = trim((string)($payload['lastname'] ?? $user->lastname ?? ''));
        $email = \core_text::strtolower(trim((string)($payload['email'] ?? $user->email ?? '')));
        $phone = $this->safe_phone(trim((string)($payload['phone'] ?? $user->phone1 ?? '')));
        $fields = [
            'NAME' => $firstname !== '' ? $firstname : 'Moodle',
            'LAST_NAME' => $lastname !== '' ? $lastname : 'User ' . (int)$user->id,
            'COMPANY_ID' => $companyid,
            'EMAIL' => [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']],
        ];
        if ($phone !== '') {
            $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }
        return $fields;
    }


    /** Prevent accidental propagation of an email address through Bitrix PHONE. */
    private function safe_phone(string $value): string {
        $value = trim($value);
        if ($value === '' || validate_email($value)) {
            return '';
        }
        return $value;
    }

    private function find_contacts_by_email(string $email): array {
        $response = $this->client->call('crm.contact.list', [
            'filter' => ['EMAIL' => $email],
            'select' => ['ID', 'EMAIL', 'COMPANY_ID'],
            'order' => ['ID' => 'ASC'],
        ]);
        $ids = [];
        foreach (($response['result'] ?? []) as $row) {
            $id = (int)($row['ID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function resolve_bms_client_field(): array {
        $configured = trim((string)get_config('local_bitrixsync', 'bms_client_company_field'));
        if ($configured === '') {
            return [
                'ready' => false,
                'field_name' => '',
                'source' => 'not_configured',
                'error' => get_string('bms_client_company_field_required', 'local_bitrixsync'),
            ];
        }
        try {
            $resolved = (new crm_field_manager())->resolve_explicit_field('company', $configured);
            if (empty($resolved['ready'])) {
                $resolved['error'] = get_string('bms_client_company_field_missing', 'local_bitrixsync', $configured);
                return $resolved;
            }
            if ((string)($resolved['user_type_id'] ?? '') !== 'boolean' || !empty($resolved['multiple'])) {
                $resolved['ready'] = false;
                $resolved['error'] = get_string('bms_client_company_field_invalid_type', 'local_bitrixsync', $configured);
            }
            return $resolved;
        } catch (\Throwable $e) {
            return ['ready' => false, 'field_name' => $configured, 'error' => $e->getMessage()];
        }
    }

    private function legacy_corporate_company_id(int $bitrixcompanyid): int {
        global $DB;
        $row = $DB->get_record('local_bitrixsync_company', ['bitrix_company_id' => $bitrixcompanyid]);
        if ($row && (int)$row->corporate_company_id > 0) {
            return (int)$row->corporate_company_id;
        }

        // Recover the mapping from the documented Corporate public API. This is
        // needed when Company sync predates the local KBA Connect bridge row.
        try {
            $api = new \local_bitrixsync\adapters\corporate\api_client();
            if ($api->is_available() && $api->is_api_compatible()) {
                $snapshot = $api->get_company_snapshot($bitrixcompanyid);
                $companyid = (int)($snapshot['companyid'] ?? 0);
                if (!empty($snapshot['found']) && $companyid > 0) {
                    (new company_service())->record_corporate_company_mapping($bitrixcompanyid, $companyid);
                    return $companyid;
                }
            }
        } catch (\Throwable $e) {
            // Read-only recovery must never break the primary BMS sync path.
        }
        return 0;
    }

    private function to_bool($value): bool {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }
        $normal = \core_text::strtolower(trim((string)$value));
        return in_array($normal, ['1', 'y', 'yes', 'true', 'да', 'on'], true);
    }

    private function extract_contact_email(array $contact): string {
        foreach ((array)($contact['EMAIL'] ?? []) as $row) {
            if (is_array($row)) {
                $value = trim((string)($row['VALUE'] ?? ''));
                if ($value !== '' && validate_email($value)) {
                    return \core_text::strtolower($value);
                }
            }
        }
        return '';
    }

    private function extract_contact_phone(array $contact): string {
        foreach ((array)($contact['PHONE'] ?? []) as $row) {
            if (is_array($row)) {
                $value = trim((string)($row['VALUE'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }
}
