<?php
namespace local_bitrixsync\shadow;

defined('MOODLE_INTERNAL') || die();

class deal_simulator {
    public function run(int $dealid): array {
        return [
            'dealid' => $dealid,
            'database_changes' => 0,
            'corporate_changes' => 0,
            'moodle_changes' => 0,
            'status' => 'shadow_only',
        ];
    }
}
