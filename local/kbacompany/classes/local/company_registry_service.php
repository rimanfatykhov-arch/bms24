<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Canonical BMS company registry introduced by v0.5.16. */
class company_registry_service {
    public const CONTRACT_VERSION = '0.5.16';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'storage' => 'local_kbacompany_company',
            'identity' => 'uuid_v4',
            'external_identity' => 'local_kbacompany_extmap',
            'rules' => [
                'new_bitrix_company_requires_explicit_bms_client_true',
                'bitrix_company_id_is_external_identity_not_company_name',
                'client_flag_removed_archives_instead_of_deletes',
                'legacy_company_uuid_is_preserved_when_adopted',
            ],
        ];
    }

    public static function ready(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_kbacompany_company')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacompany_member')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacompany_extmap')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacompany_syncout'));
    }

    public static function by_uuid(string $uuid): ?\stdClass {
        global $DB;
        if (!self::ready()) {
            return null;
        }
        $record = $DB->get_record('local_kbacompany_company', ['uuid' => trim($uuid)]);
        return $record ?: null;
    }

    public static function external_id(string $companyuuid, string $provider = 'bitrix24'): string {
        return integration_service::external_id($provider, 'company', $companyuuid);
    }

    /** Adopt an existing Corporate company without changing its historical BMS UUID. */
    public static function adopt_legacy(\stdClass $legacy, int $actorid = 0): \stdClass {
        global $DB;
        if (!self::ready()) {
            throw new \moodle_exception('bmsintegrationnotready', 'local_kbacompany');
        }
        $uuid = identity_registry::company_uuid((int)$legacy->id);
        $existing = self::by_uuid($uuid);
        if ($existing) {
            return $existing;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('adopt_company:' . (int)$legacy->id, 10);
        if (!$lock) {
            throw new \moodle_exception('identitylockfailed', 'local_kbacompany');
        }
        try {
            $existing = self::by_uuid($uuid);
            if ($existing) {
                return $existing;
            }
            $now = time();
            $record = (object)[
                'uuid' => $uuid,
                'name' => (string)$legacy->name,
                'code' => (string)($legacy->code ?? ''),
                'primaryactivity' => (string)($legacy->primaryactivity ?? ''),
                'foundedyear' => (int)($legacy->foundedyear ?? 0),
                'bmsclient' => 0,
                'sourcekind' => 'legacy_grandfathered',
                'legacycompanyid' => (int)$legacy->id,
                'syncstate' => 'legacy',
                'active' => !empty($legacy->active) ? 1 : 0,
                'archivedat' => empty($legacy->active) ? $now : 0,
                'createdby' => $actorid,
                'modifiedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('local_kbacompany_company', $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * Upsert one external company. New companies are admitted only with an explicit BMS-client flag.
     */
    public static function upsert_external(string $provider, string $externalid, bool $bmsclient,
            array $data, int $actorid = 0): array {
        global $DB;
        self::validate_external($provider, $externalid);
        if (!self::ready()) {
            throw new \moodle_exception('bmsintegrationnotready', 'local_kbacompany');
        }
        $mapping = integration_service::mapping_by_external($provider, 'company', $externalid);
        if (!$bmsclient && !$mapping) {
            return ['status' => 'ignored_not_bms_client', 'company_uuid' => '', 'created' => false];
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('external_company:' . sha1($provider . "\n" . $externalid), 10);
        if (!$lock) {
            throw new \moodle_exception('identitylockfailed', 'local_kbacompany');
        }
        try {
            $mapping = integration_service::mapping_by_external($provider, 'company', $externalid);
            $company = $mapping ? self::by_uuid((string)$mapping->localref) : null;

            // If Corporate learning already has the same real company, the historical
            // legacy-preserved UUID wins over a fresh integration UUID. This repairs
            // the v0.5.16 race where Bitrix sync could arrive before the Corporate
            // Company mapping was available and create a second BMS Company row.
            if (!empty($data['legacycompanyid'])) {
                $legacyid = (int)$data['legacycompanyid'];
                $legacycompany = $DB->get_record('local_kbacompany_company',
                    ['legacycompanyid' => $legacyid], '*', IGNORE_MULTIPLE) ?: null;
                if (!$legacycompany && legacy_company_adapter::is_ready()) {
                    $legacy = legacy_company_adapter::company($legacyid);
                    if ($legacy) {
                        $legacycompany = self::adopt_legacy($legacy, $actorid);
                    }
                }

                if ($legacycompany && $company && (string)$legacycompany->uuid !== (string)$company->uuid) {
                    self::reconcile_integration_duplicate($provider, $externalid, $company, $legacycompany, $actorid);
                    $mapping = integration_service::mapping_by_external($provider, 'company', $externalid);
                    $company = $mapping ? self::by_uuid((string)$mapping->localref) : $legacycompany;
                } else if ($legacycompany && !$company) {
                    integration_service::bind_external($provider, 'company', (string)$legacycompany->uuid, $externalid);
                    $company = $legacycompany;
                }
            }
            $now = time();
            if (!$bmsclient) {
                if ($company) {
                    $company->bmsclient = 0;
                    $company->active = 0;
                    $company->archivedat = $now;
                    $company->syncstate = 'archived';
                    $company->modifiedby = $actorid;
                    $company->timemodified = $now;
                    $DB->update_record('local_kbacompany_company', $company);
                }
                return ['status' => 'archived', 'company_uuid' => $company ? (string)$company->uuid : '', 'created' => false];
            }
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                $name = 'Bitrix24 Company #' . $externalid;
            }
            $created = false;
            if (!$company) {
                $company = (object)[
                    'uuid' => self::uuid_v4(),
                    'name' => $name,
                    'code' => trim((string)($data['code'] ?? '')),
                    'primaryactivity' => (string)($data['primaryactivity'] ?? ''),
                    'foundedyear' => (int)($data['foundedyear'] ?? 0),
                    'bmsclient' => 1,
                    'sourcekind' => 'integration',
                    'legacycompanyid' => (int)($data['legacycompanyid'] ?? 0),
                    'syncstate' => 'synced',
                    'active' => 1,
                    'archivedat' => 0,
                    'createdby' => $actorid,
                    'modifiedby' => $actorid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $company->id = $DB->insert_record('local_kbacompany_company', $company);
                integration_service::bind_external($provider, 'company', (string)$company->uuid, $externalid);
                $created = true;
            } else {
                $company->name = $name;
                $company->code = trim((string)($data['code'] ?? $company->code));
                $company->primaryactivity = (string)($data['primaryactivity'] ?? $company->primaryactivity);
                $company->foundedyear = (int)($data['foundedyear'] ?? $company->foundedyear);
                $company->bmsclient = 1;
                $company->active = 1;
                $company->archivedat = 0;
                $company->syncstate = 'synced';
                $company->modifiedby = $actorid;
                $company->timemodified = $now;
                $DB->update_record('local_kbacompany_company', $company);
            }
            return ['status' => $created ? 'created' : 'updated',
                'company_uuid' => (string)$company->uuid, 'created' => $created];
        } finally {
            $lock->release();
        }
    }

    /**
     * Repoint one fresh integration duplicate to the legacy-preserved Company UUID.
     *
     * Only integration-owned memberships and unfinished outbox commands are moved.
     * Independent business data on the duplicate blocks automatic reconciliation.
     */
    private static function reconcile_integration_duplicate(string $provider, string $externalid,
            \stdClass $duplicate, \stdClass $target, int $actorid): void {
        global $DB;

        if ((string)$duplicate->sourcekind !== 'integration') {
            throw new \moodle_exception('bms_company_reconcile_conflict', 'local_kbacompany');
        }
        if ($DB->record_exists('local_kbacompany_extmap', ['companyuuid' => (string)$duplicate->uuid]) ||
                self::duplicate_has_business_data((string)$duplicate->uuid)) {
            throw new \moodle_exception('bms_company_reconcile_business_data', 'local_kbacompany');
        }

        $targetmap = $DB->get_record('local_kbacompany_extmap', [
            'provider' => $provider, 'namespacekey' => '', 'entitykind' => 'company', 'localref' => (string)$target->uuid,
        ], '*', IGNORE_MULTIPLE) ?: null;
        if ($targetmap && (string)$targetmap->externalid !== $externalid) {
            throw new \moodle_exception('bmsintegrationduplicatelocal', 'local_kbacompany');
        }
        $mapping = integration_service::mapping_by_external($provider, 'company', $externalid);
        if (!$mapping || (string)$mapping->localref !== (string)$duplicate->uuid) {
            throw new \moodle_exception('bms_company_reconcile_conflict', 'local_kbacompany');
        }

        $transaction = $DB->start_delegated_transaction();
        $now = time();

        // Preserve membership UUIDs when there is no active target membership yet.
        $members = $DB->get_records('local_kbacompany_member', ['companyuuid' => (string)$duplicate->uuid]);
        foreach ($members as $member) {
            if (empty($member->active)) {
                continue;
            }
            $existing = $DB->get_record('local_kbacompany_member', [
                'companyuuid' => (string)$target->uuid, 'userid' => (int)$member->userid, 'active' => 1,
            ], '*', IGNORE_MULTIPLE);
            if ($existing) {
                $member->active = 0;
                $member->effectiveto = $now;
            } else {
                $member->companyuuid = (string)$target->uuid;
            }
            $member->timemodified = $now;
            $DB->update_record('local_kbacompany_member', $member);
        }

        // Pending/error commands must follow the canonical Company. Completed history
        // remains attached to the superseded row for auditability.
        $commands = $DB->get_records_select('local_kbacompany_syncout',
            'companyuuid = :companyuuid AND status <> :done',
            ['companyuuid' => (string)$duplicate->uuid, 'done' => 'done']);
        foreach ($commands as $command) {
            $payload = json_decode((string)$command->payload, true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $payload['company_uuid'] = (string)$target->uuid;
            $payload['external_company_id'] = $externalid;
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \invalid_parameter_exception('BMS integration payload is not JSON serializable.');
            }
            $command->companyuuid = (string)$target->uuid;
            $command->payload = $encoded;
            $command->timemodified = $now;
            $DB->update_record('local_kbacompany_syncout', $command);
        }

        // Transfer the unique external identity.
        if ($targetmap && (int)$targetmap->id !== (int)$mapping->id) {
            $DB->delete_records('local_kbacompany_extmap', ['id' => (int)$mapping->id]);
            $targetmap->active = 1;
            $targetmap->timemodified = $now;
            $DB->update_record('local_kbacompany_extmap', $targetmap);
        } else {
            $mapping->localref = (string)$target->uuid;
            $mapping->active = 1;
            $mapping->timemodified = $now;
            $DB->update_record('local_kbacompany_extmap', $mapping);
        }

        $target->bmsclient = 1;
        $target->active = 1;
        $target->archivedat = 0;
        $target->syncstate = 'synced';
        $target->modifiedby = $actorid;
        $target->timemodified = $now;
        $DB->update_record('local_kbacompany_company', $target);

        $duplicate->bmsclient = 0;
        $duplicate->active = 0;
        $duplicate->syncstate = 'superseded';
        $duplicate->archivedat = $now;
        $duplicate->modifiedby = $actorid;
        $duplicate->timemodified = $now;
        $DB->update_record('local_kbacompany_company', $duplicate);

        $transaction->allow_commit();
    }

    /** True when a duplicate has business data beyond integration memberships/outbox. */
    private static function duplicate_has_business_data(string $companyuuid): bool {
        global $DB;
        $tables = [
            'local_kbacompany_orgnode', 'local_kbacompany_position', 'local_kbacompany_posassign',
            'local_kbacompany_product', 'local_kbacompany_stat', 'local_kbacompany_statval',
            'local_kbacompany_policy', 'local_kbacompany_process', 'local_kbacompany_function',
            'local_kbacompany_doc', 'local_kbacompany_strategy', 'local_kbacompany_task',
            'local_kbacompany_bplan', 'local_kbacompany_commthread', 'local_kbacompany_meeting',
            'local_kbacompany_statmeta', 'local_kbacompany_statbind', 'local_kbacompany_statnorm',
            'local_kbacompany_finscenario', 'local_kbacompany_fincenter', 'local_kbacompany_finstmt',
            'local_kbacompany_finbudget', 'local_kbacompany_resgate', 'local_kbacompany_cloudmap',
        ];
        foreach ($tables as $table) {
            if ($DB->record_exists($table, ['companyuuid' => $companyuuid])) {
                return true;
            }
        }
        return false;
    }

    private static function validate_external(string $provider, string $externalid): void {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,49}$/', $provider) || trim($externalid) === '' || strlen($externalid) > 100) {
            throw new \invalid_parameter_exception('Invalid external company identity.');
        }
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
