<?php
namespace local_bitrixsync\queue;

defined('MOODLE_INTERNAL') || die();

class queue_manager {

    public function status(string $state = ''): array {
        global $DB;

        $params = [];
        $where = '';

        if ($state !== '') {
            $where = ' AND status = :status';
            $params['status'] = $state;
        }

        $count = $DB->count_records_select(
            'local_bitrixsync_syncqueue',
            '1 = 1'.$where,
            $params
        );

        return [
            'state' => $state ?: 'all',
            'count' => $count,
        ];
    }

    public function retry(int $id): array {
        return [
            'id' => $id,
            'status' => 'RETRY',
            'next' => 'PENDING',
        ];
    }
}
