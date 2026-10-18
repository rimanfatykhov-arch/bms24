<?php
namespace local_bitrixsync\data;

defined('MOODLE_INTERNAL') || die();

class result_repository {

    public function prepare(array $result): array {
        return [
            'storage' => 'database',
            'run_id' => $result['run_id'] ?? null,
            'status' => $result['status'] ?? null,
            'history' => 'updated',
            'timeline' => 'updated',
            'metrics' => 'updated',
        ];
    }
}
