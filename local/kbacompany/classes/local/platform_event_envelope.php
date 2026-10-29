<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral integration event envelope. */
class platform_event_envelope {
    public const SCHEMA_VERSION = '1.1';
    public const PRODUCER = 'local_kbacompany';

    public static function contract(): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'producer' => self::PRODUCER,
            'delivery' => 'at_least_once_durable_outbox',
            'ordering' => 'entity_revision_when_available',
            'required' => [
                'schema_version', 'event_id', 'event_type', 'producer', 'company_uuid', 'entity_type',
                'entity_uuid', 'revision', 'occurred_at', 'idempotency_key', 'correlation_id', 'payload',
            ],
            'optional' => ['workspace_uuid'],
            'rules' => [
                'provider_neutral_event_names',
                'company_uuid_required',
                'idempotency_key_stable_for_same_entity_revision_payload_and_correlation',
                'provider_specific_ids_are_not_part_of_the_envelope_contract',
                'no_provider_rest_calls_from_bms_domain_services',
            ],
        ];
    }

    public static function create(string $eventtype, string $companyuuid, string $entitytype,
            string $entityuuid, int $revision, array $payload, int $occurredat = 0,
            string $correlationid = ''): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $eventtype = self::normalise_name($eventtype, 'event type');
        $entitytype = self::normalise_name($entitytype, 'entity type');
        $entityuuid = self::normalise_uuid($entityuuid, 'entity');
        if ($revision < 0) {
            throw new \invalid_parameter_exception('Invalid BMS event revision.');
        }
        $occurredat = $occurredat > 0 ? $occurredat : time();
        $correlationid = trim($correlationid);
        if (strlen($correlationid) > 100) {
            throw new \invalid_parameter_exception('Invalid BMS event correlation id.');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \invalid_parameter_exception('BMS event payload is not JSON serializable.');
        }
        $workspace = workspace_mapping_service::status($companyuuid);
        $workspaceuuid = !empty($workspace['mapped']) ? (string)$workspace['workspace_uuid'] : '';
        $idempotency = hash('sha256', implode("\n", [
            $eventtype, $companyuuid, $entitytype, $entityuuid, (string)$revision, $correlationid, $encoded,
        ]));
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => self::uuid_v4(),
            'event_type' => $eventtype,
            'producer' => self::PRODUCER,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => $workspaceuuid,
            'entity_type' => $entitytype,
            'entity_uuid' => $entityuuid,
            'revision' => $revision,
            'occurred_at' => $occurredat,
            'idempotency_key' => $idempotency,
            'correlation_id' => $correlationid,
            'payload' => $payload,
        ];
    }

    private static function normalise_name(string $value, string $label): string {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $value)) {
            throw new \invalid_parameter_exception('Invalid BMS ' . $label . '.');
        }
        return $value;
    }

    private static function normalise_uuid(string $uuid, string $label): string {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new \invalid_parameter_exception('Invalid BMS ' . $label . ' UUID.');
        }
        return $uuid;
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
