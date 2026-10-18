<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Odoo operational-actual staging read-model.
 *
 * This service reads one human-mapped Odoo record field and returns a provenance
 * packet for review. It never writes local_kbacompany_statval and never mutates Odoo.
 */
class odoo_actual_staging_service {
    public const CONTRACT_VERSION = '0.9.1.2';
    public const MODE = 'mapped_record_field';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'provider' => 'odoo',
            'mode' => self::MODE,
            'source_configuration' => 'latest_active_indicator_passport.sourceconfig_json',
            'identity_mapping' => 'local_kbacompany_extmap_human_confirmed',
            'remote_read' => 'odoo_json2_mapped_record_read',
            'staging_persisted' => false,
            'canonical_fact_write_enabled' => false,
            'human_confirmation_required_before_future_write' => true,
            'missing_actual_is_zero' => false,
            'conflict_states' => [
                'missing_remote_value', 'no_existing_fact', 'same_value', 'different_value',
            ],
            'provenance' => [
                'company_uuid', 'indicator_uuid', 'indicator_code', 'period_start',
                'mapping_uuid', 'external_model', 'external_id', 'value_field',
                'source_timestamp_field', 'source_timestamp', 'sourceconfig_revision',
            ],
            'rules' => [
                'company_scope_before_database_read',
                'indicator_mapping_must_be_active_and_company_scoped',
                'sourcekind_must_be_integration',
                'sourceconfig_environment_must_match_requested_environment',
                'remote_record_id_must_be_positive_numeric',
                'remote_value_must_be_decimal_or_null',
                'missing_remote_value_is_not_zero',
                'idempotency_key_is_deterministic_from_provenance_and_candidate',
                'existing_canonical_fact_is_read_only_conflict_context',
                'no_odoo_write',
                'no_bms_fact_write',
            ],
        ];
    }

    /**
     * Read and stage one Odoo actual candidate for an indicator period.
     *
     * $requester is an optional isolated-test transport double.
     */
    public static function stage(string $companyuuid, int $userid, string $environment,
            string $statuuid, string $periodstart, string $baseurl, string $apikey,
            string $database = '', callable $requester = null): array {
        global $DB;

        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_indicators($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $environment = self::environment($environment);
        $stat = $DB->get_record('local_kbacompany_stat', [
            'companyuuid' => $companyuuid,
            'uuid' => $statuuid,
        ], 'id,companyuuid,uuid,code,name,unit,periodtype,direction', MUST_EXIST);
        self::validate_period($periodstart, (string)$stat->periodtype);

        $metas = $DB->get_records('local_kbacompany_statmeta', [
            'companyuuid' => $companyuuid,
            'statuuid' => $statuuid,
        ], 'revision DESC, id DESC', 'id,revision,status,sourcekind,sourceconfig', 0, 1);
        $meta = $metas ? reset($metas) : null;
        if (!$meta || (string)$meta->status !== 'active' || (string)$meta->sourcekind !== 'integration') {
            throw new \moodle_exception('bms_odoo_actual_source_required', 'local_kbacompany');
        }
        $config = self::parse_source_config((string)$meta->sourceconfig, $environment);

        $mapping = $DB->get_record('local_kbacompany_extmap', [
            'companyuuid' => $companyuuid,
            'provider' => 'odoo',
            'environment' => $environment,
            'entitykind' => 'indicator',
            'localref' => $statuuid,
            'active' => 1,
        ], 'id,mappinguuid,namespacekey,externalmodel,externalid,timecreated,timemodified');
        if (!$mapping || empty($mapping->mappinguuid) || empty($mapping->namespacekey)) {
            throw new \moodle_exception('bms_odoo_actual_mapping_required', 'local_kbacompany');
        }

        $fields = [$config['value_field'], $config['source_timestamp_field']];
        if ($config['label_field'] !== '') {
            $fields[] = $config['label_field'];
        }
        $remote = odoo_json2_transport::read_record_fields($baseurl, $apikey, $database,
            (string)$mapping->externalmodel, (string)$mapping->externalid, array_values(array_unique($fields)),
            $requester);

        $candidate = self::decimal_or_null($remote[$config['value_field']] ?? null);
        $sourcets = self::source_timestamp($remote[$config['source_timestamp_field']] ?? null);
        $label = $config['label_field'] === '' ? '' : self::scalar_label($remote[$config['label_field']] ?? '');

        $facts = $DB->get_records('local_kbacompany_statval', [
            'companyuuid' => $companyuuid,
            'statuuid' => $statuuid,
            'periodstart' => $periodstart,
            'kind' => 'fact',
        ], 'revision DESC, id DESC', 'id,amount,revision,reason,writerid,timecreated', 0, 1);
        $existing = $facts ? reset($facts) : null;
        $current = $existing ? self::decimal_or_null($existing->amount) : null;

        $provenance = [
            'provider' => 'odoo',
            'environment' => $environment,
            'company_uuid' => $companyuuid,
            'indicator_uuid' => (string)$stat->uuid,
            'indicator_code' => (string)$stat->code,
            'period_start' => $periodstart,
            'mapping_uuid' => (string)$mapping->mappinguuid,
            'external_model' => (string)$mapping->externalmodel,
            'external_id' => (string)$mapping->externalid,
            'value_field' => $config['value_field'],
            'source_timestamp_field' => $config['source_timestamp_field'],
            'source_timestamp' => $sourcets,
            'sourceconfig_revision' => (int)$meta->revision,
        ];
        $packet = self::packet($provenance, $candidate, $current, $existing ? (int)$existing->revision : 0);
        $packet['indicator'] = [
            'uuid' => (string)$stat->uuid,
            'code' => (string)$stat->code,
            'name' => (string)$stat->name,
            'unit' => (string)$stat->unit,
            'period_type' => (string)$stat->periodtype,
        ];
        $packet['remote_record'] = [
            'id' => (int)($remote['id'] ?? 0),
            'label' => $label,
        ];
        $packet['source_config'] = $config;
        $packet['generated_at'] = gmdate('c');
        return $packet;
    }

    /** Pure packet builder used by isolated QA. */
    public static function packet(array $provenance, ?string $candidate, ?string $current,
            int $currentrevision = 0): array {
        $state = 'missing_remote_value';
        if ($candidate !== null) {
            if ($current === null) {
                $state = 'no_existing_fact';
            } else if (self::decimal_equal($candidate, $current)) {
                $state = 'same_value';
            } else {
                $state = 'different_value';
            }
        }
        $identity = [
            'provenance' => $provenance,
            'candidate_value' => $candidate,
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'stage_status' => $candidate === null ? 'missing' : 'candidate',
            'candidate_value' => $candidate,
            'canonical_fact' => [
                'current_value' => $current,
                'current_revision' => max(0, $currentrevision),
                'conflict_state' => $state,
            ],
            'provenance' => $provenance,
            'provenance_hash' => hash('sha256', self::json($provenance)),
            'idempotency_key' => hash('sha256', self::json($identity)),
            'human_confirmation_required' => true,
            'canonical_fact_write_performed' => false,
            'remote_write_performed' => false,
        ];
    }

    public static function parse_source_config(string $raw, string $environment): array {
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
        }
        $provider = trim((string)($decoded['provider'] ?? ''));
        $env = trim((string)($decoded['environment'] ?? ''));
        $mode = trim((string)($decoded['mode'] ?? ''));
        $valuefield = self::field((string)($decoded['value_field'] ?? ''));
        $timestampfield = self::field((string)($decoded['source_timestamp_field'] ?? 'write_date'));
        $labelfield = trim((string)($decoded['label_field'] ?? 'display_name'));
        if ($labelfield !== '') {
            $labelfield = self::field($labelfield);
        }
        if ($provider !== 'odoo' || $env !== $environment || $mode !== self::MODE || $valuefield === '') {
            throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
        }
        return [
            'provider' => $provider,
            'environment' => $env,
            'mode' => $mode,
            'value_field' => $valuefield,
            'source_timestamp_field' => $timestampfield,
            'label_field' => $labelfield,
        ];
    }

    private static function environment(string $value): string {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value)) {
            throw new \moodle_exception('bms_odoo_invalid_environment', 'local_kbacompany');
        }
        return $value;
    }

    private static function field(string $value): string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D', $value)) {
            throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
        }
        return $value;
    }

    private static function decimal_or_null($value): ?string {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.');
            if ($value === '-0') { $value = '0'; }
        } else {
            $value = trim((string)$value);
        }
        if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $value)) {
            throw new \moodle_exception('bms_odoo_actual_value_invalid', 'local_kbacompany');
        }
        return self::normalise_decimal($value);
    }

    private static function normalise_decimal(string $value): string {
        $negative = isset($value[0]) && $value[0] === '-';
        if ($negative) { $value = substr($value, 1); }
        $parts = explode('.', $value, 2);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') { $integer = '0'; }
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        $value = $integer . ($fraction === '' ? '' : '.' . $fraction);
        if ($negative && $value !== '0') { $value = '-' . $value; }
        return $value;
    }

    private static function decimal_equal(string $left, string $right): bool {
        return self::normalise_decimal($left) === self::normalise_decimal($right);
    }

    private static function source_timestamp($value): string {
        if (!is_string($value) && !is_int($value)) {
            throw new \moodle_exception('bms_odoo_actual_timestamp_missing', 'local_kbacompany');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 64 || strtotime($value) === false) {
            throw new \moodle_exception('bms_odoo_actual_timestamp_missing', 'local_kbacompany');
        }
        return $value;
    }

    private static function scalar_label($value): string {
        if (is_array($value)) {
            if (isset($value[1]) && is_scalar($value[1])) { $value = $value[1]; }
            else { return ''; }
        }
        if (!is_scalar($value)) { return ''; }
        return clean_param(substr((string)$value, 0, 255), PARAM_TEXT);
    }

    private static function validate_period(string $date, string $periodtype): void {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) || ($errors['error_count'] ?? 0))) ||
                $parsed->format('Y-m-d') !== $date) {
            throw new \moodle_exception('productinvalidperiod', 'local_kbacompany');
        }
        if (($periodtype === 'weekly' && $parsed->format('N') !== '1') ||
                ($periodtype === 'monthly' && $parsed->format('d') !== '01') ||
                ($periodtype === 'quarterly' && ($parsed->format('d') !== '01' ||
                    !in_array((int)$parsed->format('n'), [1, 4, 7, 10], true))) ||
                ($periodtype === 'annual' && $parsed->format('m-d') !== '01-01')) {
            throw new \moodle_exception('productinvalidperiod', 'local_kbacompany');
        }
    }

    private static function json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) { throw new \coding_exception('Unable to encode Odoo staging packet.'); }
        return $json;
    }
}
