<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Provider-neutral durable event outbox for future BMS Integration Hub consumers.
 *
 * The existing local_kbacompany_syncout command outbox remains the compatibility
 * transport for KBA Connect. This service is deliberately provider-neutral and
 * stores only the event envelope plus lifecycle metadata.
 */
class integration_event_service {
    public const CONTRACT_VERSION = '0.6.6';
    public const TABLE = 'local_kbacompany_eventout';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'local_kbacompany',
            'consumer_owner' => 'future_bms_integration_hub',
            'storage' => self::TABLE,
            'delivery' => 'at_least_once',
            'ordering' => 'entity_revision_when_available',
            'statuses' => ['pending', 'error', 'done'],
            'operations' => [
                'publish', 'pending_events', 'complete_event', 'fail_event', 'retry_event', 'diagnostics',
            ],
            'rules' => [
                'provider_neutral_event_names',
                'company_uuid_is_security_and_routing_boundary',
                'provider_specific_ids_are_not_stored_in_event_payload',
                'event_payload_contains_change_notification_not_provider_command',
                'same_idempotency_key_produces_one_outbox_event',
                'legacy_kba_connect_command_outbox_remains_unchanged',
            ],
        ];
    }

    public static function ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table(self::TABLE));
    }

    /** Publish one canonical provider-neutral event and return its stable outbox event UUID. */
    public static function publish(string $eventtype, string $companyuuid, string $entitytype,
            string $entityuuid, int $revision, array $payload, string $correlationid = '',
            int $occurredat = 0): string {
        global $DB;
        if (!self::ready()) {
            throw new \moodle_exception('bmsintegrationnotready', 'local_kbacompany');
        }
        self::assert_provider_neutral_event_type($eventtype);
        self::assert_provider_neutral_payload($payload);
        $envelope = platform_event_envelope::create($eventtype, $companyuuid, $entitytype,
            $entityuuid, $revision, $payload, $occurredat, $correlationid);
        $existing = $DB->get_record(self::TABLE, ['idempotencykey' => $envelope['idempotency_key']]);
        if ($existing) {
            return (string)$existing->eventuuid;
        }

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \invalid_parameter_exception('BMS integration event envelope is not JSON serializable.');
        }
        $now = time();
        $DB->insert_record(self::TABLE, (object)[
            'eventuuid' => (string)$envelope['event_id'],
            'companyuuid' => (string)$envelope['company_uuid'],
            'eventtype' => (string)$envelope['event_type'],
            'entitytype' => (string)$envelope['entity_type'],
            'entityuuid' => (string)$envelope['entity_uuid'],
            'entityrevision' => (int)$envelope['revision'],
            'idempotencykey' => (string)$envelope['idempotency_key'],
            'correlationid' => (string)$envelope['correlation_id'],
            'sourcecommanduuid' => '',
            'envelope' => $encoded,
            'status' => 'pending',
            'attempts' => 0,
            'lasterror' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return (string)$envelope['event_id'];
    }

    /**
     * Mirror one legacy KBA Connect command into the provider-neutral event outbox.
     *
     * No Bitrix IDs, Moodle user IDs, email, phone or Corporate IDs are copied.
     * The future consumer refreshes the canonical Public API snapshot instead.
     */
    public static function ensure_legacy_command_event(string $commanduuid, string $commandkind,
            string $companyuuid): string {
        global $DB;
        if (!self::ready() || !company_context_service::exists($companyuuid)) {
            return '';
        }
        $existing = $DB->get_record(self::TABLE, ['sourcecommanduuid' => $commanduuid]);
        if ($existing) {
            return (string)$existing->eventuuid;
        }

        $eventtype = '';
        $payload = [];
        switch ($commandkind) {
            case 'company_reconcile':
                $eventtype = 'integration.company_reconciliation.requested';
                $payload = [
                    'resource' => 'company_and_employees',
                    'change' => 'reconcile_requested',
                    'refresh_via' => 'bms_public_api_v1',
                ];
                break;
            case 'employee_upsert':
                $eventtype = 'people.employee_projection.changed';
                $payload = [
                    'resource' => 'employees',
                    'change' => 'upsert',
                    'refresh_via' => 'bms_public_api_v1',
                ];
                break;
            case 'employee_close':
                $eventtype = 'people.employee_projection.changed';
                $payload = [
                    'resource' => 'employees',
                    'change' => 'close',
                    'refresh_via' => 'bms_public_api_v1',
                ];
                break;
            default:
                return '';
        }

        $envelope = platform_event_envelope::create($eventtype, $companyuuid, 'company',
            $companyuuid, 0, $payload, 0, $commanduuid);
        $byidempotency = $DB->get_record(self::TABLE, ['idempotencykey' => $envelope['idempotency_key']]);
        if ($byidempotency) {
            if ((string)$byidempotency->sourcecommanduuid === '') {
                $byidempotency->sourcecommanduuid = $commanduuid;
                $byidempotency->timemodified = time();
                $DB->update_record(self::TABLE, $byidempotency);
            }
            return (string)$byidempotency->eventuuid;
        }

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \invalid_parameter_exception('BMS integration event envelope is not JSON serializable.');
        }
        $now = time();
        $DB->insert_record(self::TABLE, (object)[
            'eventuuid' => (string)$envelope['event_id'],
            'companyuuid' => (string)$envelope['company_uuid'],
            'eventtype' => (string)$envelope['event_type'],
            'entitytype' => (string)$envelope['entity_type'],
            'entityuuid' => (string)$envelope['entity_uuid'],
            'entityrevision' => (int)$envelope['revision'],
            'idempotencykey' => (string)$envelope['idempotency_key'],
            'correlationid' => (string)$envelope['correlation_id'],
            'sourcecommanduuid' => $commanduuid,
            'envelope' => $encoded,
            'status' => 'pending',
            'attempts' => 0,
            'lasterror' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return (string)$envelope['event_id'];
    }

    public static function pending_events(int $limit = 50): array {
        global $DB;
        if (!self::ready()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return array_values($DB->get_records(self::TABLE, ['status' => 'pending'],
            'timecreated ASC, id ASC', '*', 0, $limit));
    }

    public static function complete_event(string $eventuuid): void {
        global $DB;
        if (!self::ready()) {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['eventuuid' => $eventuuid]);
        if (!$row || (string)$row->status === 'done') {
            return;
        }
        $row->status = 'done';
        $row->attempts = (int)$row->attempts + 1;
        $row->lasterror = null;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    public static function fail_event(string $eventuuid, string $error): void {
        global $DB;
        if (!self::ready()) {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['eventuuid' => $eventuuid]);
        if (!$row) {
            return;
        }
        $row->status = 'error';
        $row->attempts = (int)$row->attempts + 1;
        $row->lasterror = \core_text::substr(trim($error), 0, 2000);
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    public static function retry_event(string $eventuuid): void {
        global $DB;
        if (!self::ready()) {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['eventuuid' => $eventuuid]);
        if (!$row || (string)$row->status === 'done') {
            return;
        }
        $row->status = 'pending';
        $row->lasterror = null;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    private static function assert_provider_neutral_event_type(string $eventtype): void {
        $lower = strtolower($eventtype);
        foreach (['bitrix', 'bitrix24', 'odoo'] as $provider) {
            if (strpos($lower, $provider) !== false) {
                throw new \invalid_parameter_exception('Provider-specific BMS event type is not allowed.');
            }
        }
    }

    private static function assert_provider_neutral_payload(array $payload): void {
        $forbidden = [
            'external_company_id', 'external_contact_id', 'bitrix_company_id', 'bitrix_contact_id',
            'moodle_user_id', 'legacy_corporate_company_id', 'email', 'phone', 'required_result',
        ];
        $walk = function(array $value) use (&$walk, $forbidden): void {
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    throw new \invalid_parameter_exception(
                        'Provider-specific or contact PII field is not allowed in BMS integration events.');
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($payload);
    }

    public static function diagnostics(string $companyuuid): array {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        $summary = ['pending' => 0, 'error' => 0, 'done' => 0];
        if (!self::ready()) {
            return ['ready' => false, 'summary' => $summary, 'recent' => []];
        }
        $records = array_values($DB->get_records(self::TABLE, ['companyuuid' => $companyuuid],
            'timecreated DESC, id DESC', '*', 0, 100));
        foreach ($records as $row) {
            if (isset($summary[$row->status])) {
                $summary[$row->status]++;
            }
        }
        return ['ready' => true, 'summary' => $summary, 'recent' => $records];
    }
}
