<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class production_activation_service {

    public function controlled_deal_id(): int {
        $id = (int)get_config('local_bitrixsync', 'controlled_test_deal_id');
        return $id > 0 ? $id : 3761;
    }

    /**
     * Verify that the latest REAL controlled run proved idempotency and
     * Corporate safety before any automatic production settings are enabled.
     */
    public function verify_controlled_run(int $dealid): array {
        global $DB;

        $run = $DB->get_record_sql(
            "SELECT *
               FROM {local_bitrixsync_dealrun}
              WHERE dealid = :dealid
                AND dryrun = 0
           ORDER BY timecreated DESC, id DESC",
            ['dealid' => $dealid],
            IGNORE_MULTIPLE
        );

        if (!$run) {
            return [
                'ready' => false,
                'reason' => 'No REAL controlled run found',
                'run' => null,
                'checks' => [],
            ];
        }

        $payload = [];
        if (!empty($run->payload)) {
            $decoded = json_decode((string)$run->payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $productrows = (int)($payload['productrows'] ?? $run->productrows ?? 0);
        $resolved = (int)($payload['resolved'] ?? $run->resolved ?? 0);
        $enrolled = (int)($payload['enrolled'] ?? $run->enrolled ?? 0);
        $already = (int)($payload['already'] ?? $run->already ?? 0);
        $failed = (int)($payload['failed'] ?? $run->failed ?? 0);
        $unmapped = $payload['unmapped'] ?? [];

        $corporateok = true;
        $corporatedetail = [];
        foreach (($payload['items'] ?? []) as $item) {
            $required = !empty($item['corporate']['required']);
            $status = (string)($item['corporate']['status'] ?? 'not_applicable');
            $corporatedetail[] = [
                'productid' => (int)($item['productid'] ?? 0),
                'courseid' => (int)($item['courseid'] ?? 0),
                'required' => $required,
                'status' => $status,
                'licenseid' => (int)($item['corporate']['licenseid'] ?? 0),
                'allocationid' => (int)($item['corporate']['allocationid'] ?? 0),
            ];

            if ($required &&
                    !in_array($status, ['allocated', 'already_allocated'], true)) {
                $corporateok = false;
            }
        }

        // The final controlled run should be a repeat of the already proven
        // transaction: zero new enrolments and all resolved items already
        // processed. This proves the exact idempotency property we need
        // before automatic webhook traffic is enabled.
        $idempotencyok =
            $productrows > 0 &&
            $resolved === $productrows &&
            $enrolled === 0 &&
            $already === $resolved &&
            $failed === 0 &&
            empty($unmapped);

        $statusok = in_array(
            (string)$run->status,
            ['completed', 'completed_with_errors'],
            true
        ) && (int)$run->failed === 0;

        $checks = [
            'real_run_completed' => [
                'ok' => $statusok,
                'detail' => (string)$run->status,
            ],
            'all_products_resolved' => [
                'ok' => $productrows > 0 &&
                    $resolved === $productrows &&
                    empty($unmapped),
                'detail' => $resolved . ' / ' . $productrows,
            ],
            'zero_failures' => [
                'ok' => $failed === 0,
                'detail' => (string)$failed,
            ],
            'idempotency_proven' => [
                'ok' => $idempotencyok,
                'detail' =>
                    'enrolled=' . $enrolled .
                    '; already=' . $already .
                    '; resolved=' . $resolved,
            ],
            'corporate_allocations_safe' => [
                'ok' => $corporateok,
                'detail' => $corporatedetail,
            ],
        ];

        $ready = true;
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'run' => [
                'id' => (int)$run->id,
                'dealid' => (int)$run->dealid,
                'status' => (string)$run->status,
                'productrows' => $productrows,
                'resolved' => $resolved,
                'enrolled' => $enrolled,
                'already' => $already,
                'failed' => $failed,
            ],
            'checks' => $checks,
        ];
    }

    public function provision_crm(): array {
        $manager = new crm_field_manager();
        $out = [];
        $ready = true;

        foreach (['company', 'contact', 'deal'] as $entity) {
            $fields = $manager->provision_entity($entity);
            $entityready = true;

            foreach ($fields as $field) {
                if (empty($field['ready'])) {
                    $entityready = false;
                    $ready = false;
                }
            }

            $out[$entity] = [
                'ready' => $entityready,
                'fields' => $fields,
            ];
        }

        return [
            'ready' => $ready,
            'entities' => $out,
        ];
    }

    /**
     * Enable v5 engine, queue, CRM writeback and timeline, but keep the
     * public inbound endpoint disabled until the Bitrix event URL is actually
     * configured by the operator.
     */
    public function finalize(
        int $dealid,
        string $stageid,
        bool $provisioncrm = true
    ): array {
        $controlled = $this->controlled_deal_id();
        if ($dealid !== $controlled) {
            throw new \moodle_exception(
                'production_controlled_deal_only',
                'local_bitrixsync',
                '',
                $controlled
            );
        }

        $proof = $this->verify_controlled_run($dealid);
        if (empty($proof['ready'])) {
            throw new \moodle_exception(
                'production_activation_proof_failed',
                'local_bitrixsync'
            );
        }

        $stageid = trim($stageid);
        if ($stageid === '') {
            throw new \moodle_exception(
                'production_stage_required',
                'local_bitrixsync'
            );
        }

        $crm = $provisioncrm
            ? $this->provision_crm()
            : [
                'ready' => (new crm_field_manager())->is_ready(false),
                'entities' => [],
            ];

        if (empty($crm['ready'])) {
            throw new \moodle_exception(
                'production_crm_provision_failed',
                'local_bitrixsync'
            );
        }

        set_config('v5_allowed_stages', $stageid, 'local_bitrixsync');
        set_config('queue_enabled', 1, 'local_bitrixsync');
        set_config('crm_writeback_enable', 1, 'local_bitrixsync');
        set_config('crm_timeline_enable', 1, 'local_bitrixsync');
        set_config('engine_mode', 'v5', 'local_bitrixsync');

        // Deliberately OFF until Bitrix automation/webhook has been pointed
        // at the generated endpoint.
        set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        set_config(
            'production_wizard_bitrix_event_confirmed',
            0,
            'local_bitrixsync'
        );

        unset_config('production_readiness_cache', 'local_bitrixsync');

        $readiness = (new production_readiness_service())->refresh();

        return [
            'controlled_run' => $proof,
            'crm' => $crm,
            'settings' => [
                'v5_allowed_stages' => $stageid,
                'queue_enabled' => true,
                'crm_writeback_enable' => true,
                'crm_timeline_enable' => true,
                'engine_mode' => 'v5',
                'v5_inbound_enabled' => false,
            ],
            'readiness' => $readiness,
            'endpoint' => $this->endpoint(),
            'next' => 'Run CRM controlled test before enabling inbound',
        ];
    }

    /**
     * Safe CRM final test: all Moodle/Corporate rows should already be
     * idempotent, so this run primarily proves CRM fields + timeline.
     */
    public function crm_controlled_test(int $dealid): array {
        $controlled = $this->controlled_deal_id();
        if ($dealid !== $controlled) {
            throw new \moodle_exception(
                'production_controlled_deal_only',
                'local_bitrixsync',
                '',
                $controlled
            );
        }

        if (!get_config('local_bitrixsync', 'crm_writeback_enable') ||
                !get_config('local_bitrixsync', 'crm_timeline_enable')) {
            throw new \moodle_exception(
                'production_crm_not_enabled',
                'local_bitrixsync'
            );
        }

        return (new production_launch_service())->execute(
            $dealid,
            'EXECUTE_' . $dealid,
            null
        );
    }

    public function verify_crm_test(int $dealid): array {
        global $DB;

        $run = $DB->get_record_sql(
            "SELECT *
               FROM {local_bitrixsync_dealrun}
              WHERE dealid = :dealid
                AND dryrun = 0
           ORDER BY timecreated DESC, id DESC",
            ['dealid' => $dealid],
            IGNORE_MULTIPLE
        );

        if (!$run) {
            return ['ready' => false, 'reason' => 'No REAL run'];
        }

        $payload = [];
        if (!empty($run->payload)) {
            $decoded = json_decode((string)$run->payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $crm = $payload['crm'] ?? [];
        $statuses = [
            'company' => (string)($crm['company']['status'] ?? 'missing'),
            'contact' => (string)($crm['contact']['status'] ?? 'missing'),
            'deal' => (string)($crm['deal']['status'] ?? 'missing'),
            'deal_timeline' => (string)($crm['timeline']['deal']['status'] ?? 'missing'),
            'company_timeline' => (string)($crm['timeline']['company']['status'] ?? 'missing'),
        ];

        $timelineok = function(string $status): bool {
            return in_array($status, ['created', 'updated', 'skipped'], true);
        };

        $ready =
            !empty($crm['enabled']) &&
            !empty($crm['fields_ready']) &&
            empty($crm['errors']) &&
            $statuses['company'] === 'updated' &&
            $statuses['contact'] === 'updated' &&
            $statuses['deal'] === 'updated' &&
            $timelineok($statuses['deal_timeline']) &&
            $timelineok($statuses['company_timeline']) &&
            (int)$run->failed === 0 &&
            in_array((string)$run->status, ['completed', 'completed_with_errors'], true);

        return [
            'ready' => $ready,
            'runid' => (int)$run->id,
            'status' => (string)$run->status,
            'failed' => (int)$run->failed,
            'crm' => [
                'enabled' => !empty($crm['enabled']),
                'fields_ready' => !empty($crm['fields_ready']),
                'statuses' => $statuses,
                'errors' => $crm['errors'] ?? [],
            ],
        ];
    }

    public function enable_inbound(
        int $dealid,
        string $stageid
    ): array {
        $controlled = $this->controlled_deal_id();
        if ($dealid !== $controlled) {
            throw new \moodle_exception(
                'production_controlled_deal_only',
                'local_bitrixsync',
                '',
                $controlled
            );
        }

        $crmproof = $this->verify_crm_test($dealid);
        if (empty($crmproof['ready'])) {
            throw new \moodle_exception(
                'production_crm_test_failed',
                'local_bitrixsync'
            );
        }

        if (!get_config(
                'local_bitrixsync',
                'production_wizard_bitrix_event_confirmed'
            )) {
            throw new \moodle_exception(
                'production_bitrix_event_not_confirmed',
                'local_bitrixsync'
            );
        }

        $stageid = trim($stageid);
        if ($stageid === '') {
            throw new \moodle_exception(
                'production_stage_required',
                'local_bitrixsync'
            );
        }

        set_config('v5_allowed_stages', $stageid, 'local_bitrixsync');
        set_config('queue_enabled', 1, 'local_bitrixsync');
        set_config('engine_mode', 'v5', 'local_bitrixsync');
        set_config('crm_writeback_enable', 1, 'local_bitrixsync');
        set_config('crm_timeline_enable', 1, 'local_bitrixsync');
        set_config('v5_inbound_enabled', 1, 'local_bitrixsync');

        unset_config('production_readiness_cache', 'local_bitrixsync');
        $readiness = (new production_readiness_service())->refresh();

        return [
            'enabled' => true,
            'stage' => $stageid,
            'crm_test' => $crmproof,
            'endpoint' => $this->endpoint(),
            'readiness' => $readiness,
        ];
    }

    public function queue_smoke(int $dealid): array {
        $queueid = (new sync_queue())->add(
            'deal',
            $dealid,
            'full_sync',
            ['event' => 'KBA_CONNECT_PRODUCTION_SMOKE']
        );

        $stats = (new sync_manager())->run_batch(1);

        return [
            'queueid' => $queueid,
            'stats' => $stats,
        ];
    }

    public function endpoint(): string {
        global $CFG;

        $token = (string)get_config('local_bitrixsync', 'v5_inbound_token');
        if ($token === '') {
            return '';
        }

        return (new \moodle_url(
            rtrim($CFG->wwwroot, '/') . '/local/bitrixsync/webhook_v5.php',
            ['key' => $token]
        ))->out(false);
    }
/**
     * Unified status model for the Moodle admin production setup wizard.
     */
    public function wizard_status(int $dealid, string $stageid): array {
        $proof = $this->verify_controlled_run($dealid);
        $crmproof = $this->verify_crm_test($dealid);

        $finalized =
            get_config('local_bitrixsync', 'engine_mode') === 'v5' &&
            (bool)get_config('local_bitrixsync', 'queue_enabled') &&
            (bool)get_config('local_bitrixsync', 'crm_writeback_enable') &&
            (bool)get_config('local_bitrixsync', 'crm_timeline_enable') &&
            trim((string)get_config('local_bitrixsync', 'v5_allowed_stages')) === trim($stageid);

        $bitrixconfirmed = (bool)get_config(
            'local_bitrixsync',
            'production_wizard_bitrix_event_confirmed'
        );

        $inbound = (bool)get_config('local_bitrixsync', 'v5_inbound_enabled');

        $endpoint = $this->endpoint();

        return [
            'dealid' => $dealid,
            'stageid' => $stageid,
            'controlled_run' => $proof,
            'finalized' => $finalized,
            'crm_test' => $crmproof,
            'bitrix_event_confirmed' => $bitrixconfirmed,
            'endpoint_ready' => $endpoint !== '',
            'endpoint' => $endpoint,
            'inbound_enabled' => $inbound,
            'queue_enabled' => (bool)get_config('local_bitrixsync', 'queue_enabled'),
            'engine_mode' => (string)get_config('local_bitrixsync', 'engine_mode'),
            'crm_writeback_enable' => (bool)get_config('local_bitrixsync', 'crm_writeback_enable'),
            'crm_timeline_enable' => (bool)get_config('local_bitrixsync', 'crm_timeline_enable'),
        ];
    }

    /**
     * Operator confirms that the generated endpoint has been configured in
     * Bitrix24. No external change is made here; this only gates inbound enable.
     */
    public function confirm_bitrix_event(int $dealid): array {
        $controlled = $this->controlled_deal_id();
        if ($dealid !== $controlled) {
            throw new \moodle_exception(
                'production_controlled_deal_only',
                'local_bitrixsync',
                '',
                $controlled
            );
        }

        if ($this->endpoint() === '') {
            throw new \moodle_exception(
                'production_endpoint_missing',
                'local_bitrixsync'
            );
        }

        set_config(
            'production_wizard_bitrix_event_confirmed',
            1,
            'local_bitrixsync'
        );

        return [
            'confirmed' => true,
            'endpoint_ready' => true,
        ];
    }

    /**
     * Emergency safe stop from the admin wizard.
     * Keeps data and CRM field definitions intact, but stops automatic traffic.
     */
    public function safe_off(): array {
        set_config('v5_inbound_enabled', 0, 'local_bitrixsync');
        set_config('queue_enabled', 0, 'local_bitrixsync');
        set_config('engine_mode', 'legacy', 'local_bitrixsync');
        unset_config('production_readiness_cache', 'local_bitrixsync');

        return [
            'v5_inbound_enabled' => false,
            'queue_enabled' => false,
            'engine_mode' => 'legacy',
            'status' => 'SAFE_OFF',
        ];
    }

}
