<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral BMS integration boundary. Bitrix24 transport remains in KBA Connect. */
class integration_service {
    public const CONTRACT_VERSION = '0.9.2';
    public const BITRIX_PROVIDER = 'bitrix24';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner_of_bitrix_rest' => 'local_bitrixsync',
            'bms_transport' => 'legacy_command_outbox_plus_provider_neutral_event_outbox',
            'legacy_command_outbox' => 'local_kbacompany_syncout_for_kba_connect',
            'provider_neutral_event_outbox' => integration_event_service::contract(),
            'bitrix_provider_extension' => bitrix_adapter_service::contract(),
            'scoped_identity' => self::scoped_mapping_contract(),
            'company_admission_rule' => 'explicit_bms_client_true',
            'course_enrolment_owner' => 'existing_deal_product_course_flow',
            'corporate_learning_owner' => 'local_kbacorporate',
            'operations' => [
                'upsert_company_from_external', 'upsert_employee_from_external',
                'pending_commands', 'complete_command', 'fail_command', 'diagnostics',
            ],
            'guarantees' => [
                'no_direct_bitrix_rest_from_local_kbacompany',
                'no_direct_local_kbacorp_table_writes',
                'outbound_employee_sync_is_durable_and_idempotent',
                'legacy_kba_connect_command_contract_is_preserved',
                'provider_neutral_events_contain_no_bitrix_ids_or_contact_pii',
                'integration_hub_can_refresh_state_through_bms_public_api_v1',
                'deal_course_enrolment_semantics_unchanged',
                'inbound_contact_phone_projection_never_overwrites_valid_moodle_phone',
                'moodle_company_display_projection_is_not_canonical_company_identity',
            ],
        ];
    }

    public static function mapping_by_external(string $provider, string $entitykind, string $externalid): ?\stdClass {
        global $DB;
        if (!company_registry_service::ready()) {
            return null;
        }
        $row = $DB->get_record('local_kbacompany_extmap', [
            'provider' => $provider, 'namespacekey' => '', 'entitykind' => $entitykind,
            'externalid' => trim($externalid), 'active' => 1,
        ]);
        return $row ?: null;
    }

    public static function mapping_by_local(string $provider, string $entitykind, string $localref): ?\stdClass {
        global $DB;
        if (!company_registry_service::ready()) {
            return null;
        }
        $row = $DB->get_record('local_kbacompany_extmap', [
            'provider' => $provider, 'namespacekey' => '', 'entitykind' => $entitykind,
            'localref' => trim($localref), 'active' => 1,
        ]);
        return $row ?: null;
    }

    public static function external_id(string $provider, string $entitykind, string $localref): string {
        $map = self::mapping_by_local($provider, $entitykind, $localref);
        return $map ? (string)$map->externalid : '';
    }

    public static function bind_external(string $provider, string $entitykind, string $localref, string $externalid): void {
        global $DB;
        $localref = trim($localref);
        $externalid = trim($externalid);
        if ($localref === '' || $externalid === '') {
            throw new \invalid_parameter_exception('External mapping identity is empty.');
        }
        $existingexternal = self::mapping_by_external($provider, $entitykind, $externalid);
        if ($existingexternal && (string)$existingexternal->localref !== $localref) {
            throw new \moodle_exception('bmsintegrationduplicateexternal', 'local_kbacompany');
        }
        $existinglocal = self::mapping_by_local($provider, $entitykind, $localref);
        $now = time();
        if ($existinglocal) {
            if ((string)$existinglocal->externalid !== $externalid) {
                throw new \moodle_exception('bmsintegrationduplicatelocal', 'local_kbacompany');
            }
            if (empty($existinglocal->active)) {
                $existinglocal->active = 1;
                $existinglocal->timemodified = $now;
                $DB->update_record('local_kbacompany_extmap', $existinglocal);
            }
            return;
        }
        $DB->insert_record('local_kbacompany_extmap', (object)[
            'provider' => $provider, 'entitykind' => $entitykind, 'localref' => $localref,
            'externalid' => $externalid, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /** Called by KBA Connect after it has evaluated the Bitrix Company «Клиент BMS» flag. */
    public static function upsert_company_from_external(string $externalcompanyid, bool $bmsclient,
            array $companydata, int $actorid = 0): array {
        return company_registry_service::upsert_external(self::BITRIX_PROVIDER, $externalcompanyid,
            $bmsclient, $companydata, $actorid);
    }

    /** Called by KBA Connect for Contacts belonging to a company admitted into BMS. */
    public static function upsert_employee_from_external(string $externalcompanyid, string $externalcontactid,
            array $userdata, array $roles = []): array {
        $companymap = self::mapping_by_external(self::BITRIX_PROVIDER, 'company', $externalcompanyid);
        if (!$companymap) {
            return ['status' => 'company_not_bms_client', 'userid' => 0, 'company_uuid' => ''];
        }
        $company = company_registry_service::by_uuid((string)$companymap->localref);
        if (!$company || empty($company->active) || empty($company->bmsclient)) {
            return ['status' => 'company_not_bms_client', 'userid' => 0, 'company_uuid' => ''];
        }
        return employee_service::upsert_external_employee((string)$company->uuid,
            $externalcontactid, $userdata, $roles);
    }

    /** Queue one outbound command for KBA Connect. No Bitrix REST is called here. */
    public static function enqueue(string $commandkind, string $companyuuid, int $userid, array $payload): string {
        global $DB;
        if (!company_registry_service::ready()) {
            throw new \moodle_exception('bmsintegrationnotready', 'local_kbacompany');
        }
        if (!in_array($commandkind, ['company_reconcile', 'employee_upsert', 'employee_close'], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS integration command.');
        }
        $encodedpayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedpayload === false) {
            throw new \invalid_parameter_exception('BMS integration payload is not JSON serializable.');
        }
        $pending = $DB->get_records('local_kbacompany_syncout', [
            'companyuuid' => $companyuuid, 'userid' => $userid, 'commandkind' => $commandkind,
            'status' => 'pending',
        ], 'id DESC', '*', 0, 20);
        foreach ($pending as $row) {
            if (hash_equals(hash('sha256', (string)$row->payload), hash('sha256', $encodedpayload))) {
                integration_event_service::ensure_legacy_command_event(
                    (string)$row->commanduuid, $commandkind, $companyuuid);
                return (string)$row->commanduuid;
            }
        }
        $now = time();
        $uuid = self::uuid_v4();
        $transaction = $DB->start_delegated_transaction();
        $DB->insert_record('local_kbacompany_syncout', (object)[
            'commanduuid' => $uuid, 'companyuuid' => $companyuuid, 'userid' => $userid,
            'commandkind' => $commandkind,
            'payload' => $encodedpayload,
            'status' => 'pending', 'attempts' => 0, 'lasterror' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        integration_event_service::ensure_legacy_command_event($uuid, $commandkind, $companyuuid);
        $transaction->allow_commit();
        return $uuid;
    }

    /** KBA Connect can call this internal PHP API to claim work. */
    public static function pending_commands(int $limit = 50): array {
        global $DB;
        $limit = max(1, min(200, $limit));
        return array_values($DB->get_records('local_kbacompany_syncout', ['status' => 'pending'],
            'timecreated ASC, id ASC', '*', 0, $limit));
    }

    /** Mark successful only after Bitrix Contact and Corporate membership are both reconciled. */
    public static function complete_command(string $commanduuid, array $result = []): void {
        global $DB;
        $row = $DB->get_record('local_kbacompany_syncout', ['commanduuid' => $commanduuid]);
        if (!$row || (string)$row->status === 'done') {
            return;
        }
        if ((string)$row->commandkind === 'employee_upsert') {
            if (empty($result['external_contact_id']) || empty($result['corporate_member_reconciled'])) {
                throw new \invalid_parameter_exception(
                    'Employee sync can complete only after Bitrix Contact and KBA Corporate membership reconciliation.');
            }
            self::bind_external(self::BITRIX_PROVIDER, 'user', (string)$row->userid,
                (string)$result['external_contact_id']);
        }
        $row->status = 'done';
        $row->attempts = (int)$row->attempts + 1;
        $row->lasterror = null;
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_syncout', $row);
    }

    public static function fail_command(string $commanduuid, string $error): void {
        global $DB;
        $row = $DB->get_record('local_kbacompany_syncout', ['commanduuid' => $commanduuid]);
        if (!$row) {
            return;
        }
        $row->status = 'error';
        $row->attempts = (int)$row->attempts + 1;
        $row->lasterror = \core_text::substr(trim($error), 0, 2000);
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_syncout', $row);
    }

    public static function retry_command(string $commanduuid): void {
        global $DB;
        $row = $DB->get_record('local_kbacompany_syncout', ['commanduuid' => $commanduuid]);
        if (!$row || (string)$row->status === 'done') {
            return;
        }
        $row->status = 'pending';
        $row->lasterror = null;
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_syncout', $row);
    }

    public static function diagnostics(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_organization($companyuuid, $userid) && !is_siteadmin($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $companyrow = company_registry_service::by_uuid($companyuuid);
        $externalcompanyid = self::external_id(self::BITRIX_PROVIDER, 'company', $companyuuid);
        $commands = array_values($DB->get_records('local_kbacompany_syncout', ['companyuuid' => $companyuuid],
            'timecreated DESC, id DESC', '*', 0, 100));
        $summary = ['pending' => 0, 'error' => 0, 'done' => 0];
        foreach ($commands as $command) {
            if (isset($summary[$command->status])) {
                $summary[$command->status]++;
            }
        }
        $events = integration_event_service::diagnostics($companyuuid);
        return [
            'company' => $company,
            'registry' => $companyrow,
            'external_company_id' => $externalcompanyid,
            'commands' => $commands,
            'summary' => $summary,
            'consumer_available' => class_exists('\\local_bitrixsync\\services\\bms_bridge_service') ||
                class_exists('\\local_bitrixsync\\task\\process_bms_bridge'),
            'event_outbox' => $events,
        ];
    }


    /** 0.9.0: identity registration only; not an integration transport or an actual ledger. */
    public static function scoped_mapping_contract(): array {
        return [
            'version' => '0.9.0',
            'storage' => 'existing_extmap',
            'providers' => ['odoo', 'bitrix24'],
            'entity_kinds' => ['company', 'position', 'indicator'],
            'external_namespace' => ['provider', 'environment', 'externalmodel'],
            'company_scope' => 'required_before_reads',
            'legacy_namespace' => '',
            'external_verification' => 'not_performed',
            'writes' => 'human_confirmed_identity_only',
            'transport_enabled' => false,
            'actual_ingestion_enabled' => false,
            'closed_identity_reuse' => 'blocked_pending_explicit_relink_workflow',
        ];
    }

    /** Reuse the existing company authorisation policy; never trust a submitted role. */
    public static function mapping_context(string $companyuuid, int $actorid): array {
        if ($actorid <= 0) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actorid);
        if (!permission_service::can_manage_organization($companyuuid, $actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        return $company;
    }

    /** Bounded form options. No company-wide or cross-company prefetch followed by filtering. */
    public static function mapping_targets(string $companyuuid, int $actorid): array {
        global $DB;
        $company = self::mapping_context($companyuuid, $actorid);
        $companyuuid = (string)$company['uuid'];
        $options = ['company:' . $companyuuid => get_string('bms_int_kind_company', 'local_kbacompany') .
            ' — ' . $company['name']];
        $truncated = false;
        $tables = ['position' => 'local_kbacompany_position'];
        if (permission_service::can_manage_indicators($companyuuid, $actorid)) {
            $tables['indicator'] = 'local_kbacompany_stat';
        }
        foreach ($tables as $kind => $table) {
            $conditions = ['companyuuid' => $companyuuid];
            if ($kind === 'position') {
                $conditions['active'] = 1;
            }
            $rows = array_values($DB->get_records($table, $conditions, 'name ASC, id ASC',
                'id,uuid,name,code', 0, 101));
            if (count($rows) > 100) {
                $truncated = true;
                array_pop($rows);
            }
            foreach ($rows as $row) {
                $options[$kind . ':' . $row->uuid] = get_string('bms_int_kind_' . $kind, 'local_kbacompany') .
                    ' — ' . $row->name . ' [' . $row->code . ']';
            }
        }
        return ['options' => $options, 'truncated' => $truncated];
    }

    /** Validate and preview WITHOUT writes, outbound queue changes or external calls. */
    public static function preview_mapping(string $companyuuid, int $actorid, array $data): array {
        global $DB;
        $company = self::mapping_context($companyuuid, $actorid);
        $companyuuid = (string)$company['uuid'];
        if (empty($company['active'])) {
            throw new \moodle_exception('bms_int_target_missing', 'local_kbacompany');
        }
        $normal = ['companyuuid' => $companyuuid];
        foreach (['provider', 'environment', 'externalmodel', 'entitykind', 'localref', 'externalid'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new \moodle_exception('bms_int_invalid', 'local_kbacompany');
            }
            $normal[$field] = trim($data[$field]);
        }
        if (!in_array($normal['provider'], ['odoo', 'bitrix24'], true) ||
                !in_array($normal['entitykind'], ['company', 'position', 'indicator'], true) ||
                !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $normal['environment']) ||
                !preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $normal['externalmodel']) ||
                !preg_match('/^[a-z0-9][a-z0-9_.:-]{0,99}$/D', $normal['externalid'])) {
            throw new \moodle_exception('bms_int_invalid', 'local_kbacompany');
        }
        $normal['localref'] = company_context_service::normalise_uuid($normal['localref']);
        if ($normal['entitykind'] === 'indicator' &&
                !permission_service::can_manage_indicators($companyuuid, $actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if ($normal['entitykind'] === 'company') {
            company_context_service::assert_same($companyuuid, $normal['localref']);
            $expectedmodel = $normal['provider'] === 'odoo' ? 'res.company' : 'crm.company';
            if ($normal['externalmodel'] !== $expectedmodel) {
                throw new \moodle_exception('bms_int_company_model', 'local_kbacompany', '', $expectedmodel);
            }
            $name = (string)$company['name'];
        } else {
            $table = $normal['entitykind'] === 'position' ? 'local_kbacompany_position' : 'local_kbacompany_stat';
            $conditions = ['companyuuid' => $companyuuid, 'uuid' => $normal['localref']];
            if ($normal['entitykind'] === 'position') {
                $conditions['active'] = 1;
            }
            $target = $DB->get_record($table, $conditions, 'id,name');
            if (!$target) {
                throw new \moodle_exception('bms_int_target_missing', 'local_kbacompany');
            }
            $name = (string)$target->name;
            // A child identity cannot bypass the explicit provider-company binding.
            $parent = $DB->get_record('local_kbacompany_extmap', [
                'companyuuid' => $companyuuid, 'provider' => $normal['provider'],
                'environment' => $normal['environment'], 'entitykind' => 'company',
                'externalmodel' => $normal['provider'] === 'odoo' ? 'res.company' : 'crm.company',
                'localref' => $companyuuid, 'active' => 1,
            ]);
            if (!$parent || empty($parent->namespacekey)) {
                throw new \moodle_exception('bms_int_parent_required', 'local_kbacompany');
            }
        }
        // Provider environment + model, not company: the unique external identity must
        // also reject cross-company collisions without reading the other company's rows.
        $normal['namespacekey'] = hash('sha256', json_encode([
            $normal['provider'], $normal['environment'], $normal['externalmodel'],
        ]));
        $normal['externalkey'] = hash('sha256', json_encode([
            $normal['provider'], $normal['environment'], $normal['externalmodel'], $normal['externalid'],
        ]));
        return ['values' => $normal, 'targetname' => $name,
            'fingerprint' => hash('sha256', json_encode($normal))];
    }

    /** An explicit, revalidated, idempotent registration in the EXISTING identity store. */
    public static function confirm_mapping(string $companyuuid, int $actorid, array $data,
            string $fingerprint, bool $confirmed): \stdClass {
        global $DB;
        if (!$confirmed) {
            throw new \moodle_exception('bms_int_confirmation_required', 'local_kbacompany');
        }
        $preview = self::preview_mapping($companyuuid, $actorid, $data);
        $normal = $preview['values'];
        $companyuuid = $normal['companyuuid'];
        $lock = self::mapping_lock($companyuuid, $normal['provider'], $normal['environment']);
        try {
            // Revalidate permissions, target and parent after taking the lock.
            $preview = self::preview_mapping($companyuuid, $actorid, $data);
            if (!hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new \moodle_exception('bms_int_preview_changed', 'local_kbacompany');
            }
            $normal = $preview['values'];
            $scope = ['companyuuid' => $companyuuid, 'provider' => $normal['provider'],
                'namespacekey' => $normal['namespacekey'], 'entitykind' => $normal['entitykind']];
            $existing = $DB->get_record('local_kbacompany_extmap', $scope + ['localref' => $normal['localref']]);
            if ($existing) {
                if ((string)$existing->externalid === $normal['externalid'] && !empty($existing->active)) {
                    return $existing;
                }
                throw new \moodle_exception('bms_int_conflict', 'local_kbacompany');
            }
            $external = $DB->get_record('local_kbacompany_extmap', [
                'companyuuid' => $companyuuid, 'externalkey' => $normal['externalkey'],
            ]);
            if ($external) {
                throw new \moodle_exception('bms_int_conflict', 'local_kbacompany');
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $row = (object)($normal + ['mappinguuid' => self::uuid_v4(), 'active' => 1,
                    'timecreated' => time(), 'timemodified' => time()]);
                $row->id = $DB->insert_record('local_kbacompany_extmap', $row);
                \local_kbacompany\event\integration_mapping_created::create([
                    'context' => \context_system::instance(), 'userid' => $actorid,
                    'other' => ['companyuuid' => $companyuuid, 'mappinguuid' => $row->mappinguuid],
                ])->trigger();
                $transaction->allow_commit();
            } catch (\dml_write_exception $e) {
                // Unique constraints cover concurrent/cross-company collisions. Do not
                // reveal SQL, external IDs, canonical IDs or ownership of another company.
                $transaction->rollback(new \moodle_exception('bms_int_write_failed', 'local_kbacompany'));
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return $row;
        } finally {
            $lock->release();
        }
    }

    /** Fetch a single scoped identity, never fetch by UUID then filter in PHP. */
    public static function scoped_mapping(string $companyuuid, int $actorid, string $mappinguuid): \stdClass {
        global $DB;
        $company = self::mapping_context($companyuuid, $actorid);
        $mappinguuid = company_context_service::normalise_uuid($mappinguuid);
        $row = $DB->get_record('local_kbacompany_extmap', [
            'companyuuid' => (string)$company['uuid'], 'mappinguuid' => $mappinguuid,
        ]);
        if (!$row || empty($row->namespacekey)) {
            throw new \moodle_exception('bms_int_target_missing', 'local_kbacompany');
        }
        return $row;
    }

    /** Close rather than delete/repoint. Reserved identities cannot silently be reused. */
    public static function close_mapping(string $companyuuid, int $actorid, string $mappinguuid, bool $confirmed): void {
        global $DB;
        if (!$confirmed) {
            throw new \moodle_exception('bms_int_confirmation_required', 'local_kbacompany');
        }
        $row = self::scoped_mapping($companyuuid, $actorid, $mappinguuid);
        $companyuuid = (string)$row->companyuuid;
        $lock = self::mapping_lock($companyuuid, (string)$row->provider, (string)$row->environment);
        try {
            $row = self::scoped_mapping($companyuuid, $actorid, $mappinguuid);
            if ($row->entitykind === 'indicator' && !permission_service::can_manage_indicators($companyuuid, $actorid)) {
                throw new \required_capability_exception(\context_system::instance(),
                    'local/kbacompany:manageorganization', 'nopermissions', '');
            }
            if (empty($row->active)) {
                return;
            }
            if ($row->entitykind === 'company' && $DB->count_records_select('local_kbacompany_extmap',
                    'companyuuid = :company AND provider = :provider AND environment = :environment ' .
                    'AND active = :active AND entitykind <> :kind', [
                        'company' => $companyuuid, 'provider' => $row->provider,
                        'environment' => $row->environment, 'active' => 1, 'kind' => 'company',
                    ])) {
                throw new \moodle_exception('bms_int_children_active', 'local_kbacompany');
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $row->active = 0;
                $row->timemodified = time();
                $DB->update_record('local_kbacompany_extmap', $row);
                \local_kbacompany\event\integration_mapping_closed::create([
                    'context' => \context_system::instance(), 'userid' => $actorid,
                    'other' => ['companyuuid' => $companyuuid, 'mappinguuid' => $mappinguuid],
                ])->trigger();
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    /** Bounded read model for Integration Center; exact count, not a capped-window total. */
    public static function scoped_mappings(string $companyuuid, int $actorid, string $status = 'all', int $page = 0): array {
        global $DB;
        $company = self::mapping_context($companyuuid, $actorid);
        if (!in_array($status, ['all', 'active', 'closed'], true) || $page < 0 || $page > 100000) {
            throw new \moodle_exception('bms_int_invalid', 'local_kbacompany');
        }
        $conditions = ['companyuuid' => (string)$company['uuid']];
        if ($status !== 'all') {
            $conditions['active'] = $status === 'active' ? 1 : 0;
        }
        return ['company' => $company, 'rows' => array_values($DB->get_records('local_kbacompany_extmap',
                $conditions, 'timecreated DESC, id DESC', '*', $page * 50, 50)),
            'total' => $DB->count_records('local_kbacompany_extmap', $conditions),
            'page' => $page, 'pagesize' => 50];
    }

    /** One lock namespace for a company's provider environment protects parent/child lifecycle. */
    private static function mapping_lock(string $companyuuid, string $provider, string $environment) {
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany_extmap');
        $lock = $factory->get_lock(hash('sha256', json_encode([$companyuuid, $provider, $environment])), 10);
        if (!$lock) {
            throw new \moodle_exception('bms_int_busy', 'local_kbacompany');
        }
        return $lock;
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
