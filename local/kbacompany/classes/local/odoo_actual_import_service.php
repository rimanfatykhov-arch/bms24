<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Human-confirmed import of one revalidated Odoo actual into canonical BMS fact history. */
class odoo_actual_import_service {
    public const CONTRACT_VERSION = '0.9.1.3';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'source' => 'odoo_actual_staging_service_0.9.1.2',
            'write_target' => 'canonical_product_service_record_value',
            'human_confirmation_required' => true,
            'remote_revalidation_required' => true,
            'automatic_background_import' => false,
            'missing_actual_is_zero' => false,
            'rules' => [
                'stage_again_immediately_before_write',
                'expected_provenance_hash_must_match',
                'expected_idempotency_key_must_match',
                'expected_candidate_must_match',
                'expected_canonical_revision_must_match',
                'missing_candidate_cannot_be_imported',
                'same_value_is_noop',
                'different_value_requires_human_reason',
                'canonical_writer_enforces_idempotency_inside_statistic_lock',
            ],
        ];
    }

    /** Re-stage and, only after exact match + confirmation, append canonical fact. */
    public static function confirm(string $companyuuid, int $userid, string $environment,
            string $statuuid, string $periodstart, string $baseurl, string $apikey, string $database,
            string $expectedprovenancehash, string $expectedidempotencykey, string $expectedcandidate,
            int $expectedrevision, string $reason, bool $confirmed, callable $requester = null): array {
        if (!$confirmed) {
            throw new \moodle_exception('bms_odoo_import_confirmation_required', 'local_kbacompany');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedprovenancehash) ||
                !preg_match('/^[a-f0-9]{64}$/D', $expectedidempotencykey)) {
            throw new \moodle_exception('bms_odoo_import_stale', 'local_kbacompany');
        }

        $stage = odoo_actual_staging_service::stage($companyuuid, $userid, $environment,
            $statuuid, $periodstart, $baseurl, $apikey, $database, $requester);
        $candidate = $stage['candidate_value'];
        if ($candidate === null) {
            throw new \moodle_exception('bms_odoo_import_missing', 'local_kbacompany');
        }
        if (!hash_equals((string)$stage['provenance_hash'], $expectedprovenancehash) ||
                !hash_equals((string)$stage['idempotency_key'], $expectedidempotencykey) ||
                (string)$candidate !== trim($expectedcandidate) ||
                (int)$stage['canonical_fact']['current_revision'] !== $expectedrevision) {
            throw new \moodle_exception('bms_odoo_import_stale', 'local_kbacompany');
        }

        $state = (string)$stage['canonical_fact']['conflict_state'];
        if ($state === 'same_value') {
            return [
                'status' => 'noop_same_value',
                'written' => false,
                'fact_id' => null,
                'stage' => $stage,
            ];
        }
        $reason = trim($reason);
        if ($state === 'different_value' && $reason === '') {
            throw new \moodle_exception('bms_odoo_import_reason_required', 'local_kbacompany');
        }
        $auditreason = 'Odoo confirmed actual import';
        if ($reason !== '') {
            $auditreason .= ': ' . $reason;
        }
        $auditreason .= ' [bms-odoo-prov:' . $stage['provenance_hash'] . ']';
        $id = product_service::record_value($companyuuid, $userid, $statuuid, $periodstart,
            'fact', (string)$candidate, $auditreason, (string)$stage['idempotency_key']);
        return [
            'status' => 'written',
            'written' => true,
            'fact_id' => $id,
            'stage' => $stage,
        ];
    }
}
