<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class sync_queue {
    public function add(
        string $entitytype,
        int $entityid,
        string $action,
        array $payload = []
    ): int {
        global $DB;

        $existing = $DB->get_record_sql(
            "SELECT *
               FROM {local_bitrixsync_syncqueue}
              WHERE entity_type = :entitytype
                AND entity_id = :entityid
                AND action = :action
                AND status IN ('pending', 'processing')
           ORDER BY id DESC",
            [
                'entitytype' => $entitytype,
                'entityid' => $entityid,
                'action' => $action,
            ],
            IGNORE_MULTIPLE
        );

        if ($existing) {
            return (int)$existing->id;
        }

        $now = time();

        return (int)$DB->insert_record(
            'local_bitrixsync_syncqueue',
            (object)[
                'entity_type' => $entitytype,
                'entity_id' => $entityid,
                'action' => $action,
                'status' => 'pending',
                'attempts' => 0,
                'payload' => $payload
                    ? json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                    : null,
                'error' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]
        );
    }
}
