<?php
namespace local_bitrixsync\rules;

defined('MOODLE_INTERNAL') || die();

class rule_test_service {

    public function test(int $dealid): array {
        $engine = new rule_engine();

        return $engine->evaluate([
            'ID' => $dealid,
            'STAGE_ID' => 'C3:EXECUTING',
        ]);
    }
}
