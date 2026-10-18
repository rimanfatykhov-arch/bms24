<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class crm_field_sync_service {
    private const ENTITIES = [
        'company' => 1,
        'contact' => 2,
        'deal' => 3,
    ];

    private const STALE_SECONDS = 180;

    public function enqueue_all(
        bool $create
    ): array {
        $this->recover_stale();

        $queue = new sync_queue();
        $action = $create
            ? 'crm_fields_provision_entity'
            : 'crm_fields_refresh_entity';

        $jobs = [];

        foreach (self::ENTITIES as $entity => $entityid) {
            $jobs[$entity] = $queue->add(
                'crmfields',
                $entityid,
                $action,
                ['entity' => $entity]
            );
        }

        set_config(
            'crm_field_background_last_requested',
            time(),
            'local_bitrixsync'
        );

        return [
            'action' => $action,
            'jobs' => $jobs,
        ];
    }

    public function queue_status(): array {
        global $DB;

        $this->recover_stale();

        $out = [];

        foreach (self::ENTITIES as $entity => $entityid) {
            $records = $DB->get_records(
                'local_bitrixsync_syncqueue',
                [
                    'entity_type' => 'crmfields',
                    'entity_id' => $entityid,
                ],
                'id DESC',
                '*',
                0,
                1
            );

            $record = $records
                ? reset($records)
                : false;

            $out[$entity] = $record
                ? [
                    'id' => (int)$record->id,
                    'action' => (string)$record->action,
                    'status' => (string)$record->status,
                    'attempts' => (int)$record->attempts,
                    'error' => (string)($record->error ?? ''),
                    'timecreated' => (int)$record->timecreated,
                    'timemodified' => (int)$record->timemodified,
                    'age' => max(
                        0,
                        time() - (int)$record->timemodified
                    ),
                ]
                : [
                    'id' => 0,
                    'action' => '',
                    'status' => 'idle',
                    'attempts' => 0,
                    'error' => '',
                    'timecreated' => 0,
                    'timemodified' => 0,
                    'age' => 0,
                ];
        }

        return $out;
    }

    public function has_active_jobs(): bool {
        foreach ($this->queue_status() as $item) {
            if (in_array(
                    $item['status'],
                    ['pending', 'processing'],
                    true
                )) {
                return true;
            }
        }

        return false;
    }

    /**
     * A cron/PHP process can be killed by hosting while a queue item is in
     * "processing". Do not leave the UI permanently hanging.
     */
    public function recover_stale(
        int $seconds = self::STALE_SECONDS
    ): int {
        global $DB;

        $threshold = time() - max(
            30,
            $seconds
        );

        $records = $DB->get_records_select(
            'local_bitrixsync_syncqueue',
            "entity_type = :entitytype
             AND status = :status
             AND timemodified > 0
             AND timemodified < :threshold",
            [
                'entitytype' => 'crmfields',
                'status' => 'processing',
                'threshold' => $threshold,
            ]
        );

        $count = 0;

        foreach ($records as $record) {
            $record->status = 'failed';
            $record->error =
                'CRM field worker timeout: задача была в статусе '
                . '"processing" более ' .
                max(30, $seconds) .
                ' секунд. Проверьте Bitrix24 REST/cron и повторите.';
            $record->timemodified = time();

            $DB->update_record(
                'local_bitrixsync_syncqueue',
                $record
            );

            $count++;
        }

        return $count;
    }

    public function fail_processing_now(): int {
        global $DB;

        $records = $DB->get_records(
            'local_bitrixsync_syncqueue',
            [
                'entity_type' => 'crmfields',
                'status' => 'processing',
            ]
        );

        $count = 0;

        foreach ($records as $record) {
            $record->status = 'failed';
            $record->error =
                'Задача вручную сброшена администратором как зависшая.';
            $record->timemodified = time();

            $DB->update_record(
                'local_bitrixsync_syncqueue',
                $record
            );
            $count++;
        }

        return $count;
    }

    public function retry_failed(): int {
        global $DB;

        $records = $DB->get_records(
            'local_bitrixsync_syncqueue',
            [
                'entity_type' => 'crmfields',
                'status' => 'failed',
            ]
        );

        $count = 0;

        foreach ($records as $record) {
            $record->status = 'pending';
            $record->error = null;
            $record->timemodified = time();

            $DB->update_record(
                'local_bitrixsync_syncqueue',
                $record
            );
            $count++;
        }

        return $count;
    }

    public function process_one(): array {
        global $DB;

        $this->recover_stale();

        $item = $DB->get_record_sql(
            "SELECT *
               FROM {local_bitrixsync_syncqueue}
              WHERE entity_type = :entitytype
                AND action IN (:refreshaction, :provisionaction)
                AND status = :status
           ORDER BY timecreated ASC, id ASC",
            [
                'entitytype' => 'crmfields',
                'refreshaction' => 'crm_fields_refresh_entity',
                'provisionaction' => 'crm_fields_provision_entity',
                'status' => 'pending',
            ],
            IGNORE_MULTIPLE
        );

        if (!$item) {
            return [
                'processed' => 0,
                'completed' => 0,
                'failed' => 0,
            ];
        }

        $item->status = 'processing';
        $item->attempts =
            ((int)$item->attempts) + 1;
        $item->error = null;
        $item->timemodified = time();

        $DB->update_record(
            'local_bitrixsync_syncqueue',
            $item
        );

        $stats = [
            'processed' => 1,
            'completed' => 0,
            'failed' => 0,
        ];

        try {
            (new sync_manager())->process_queue_item(
                $item
            );

            $item->status = 'completed';
            $item->error = null;
            $stats['completed'] = 1;
        } catch (\Throwable $e) {
            $item->status = 'failed';
            $item->error = $e->getMessage();
            $stats['failed'] = 1;
        }

        $item->timemodified = time();

        $DB->update_record(
            'local_bitrixsync_syncqueue',
            $item
        );

        return $stats;
    }
}
