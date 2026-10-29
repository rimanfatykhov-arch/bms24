<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\weekly_management_acceptance_service;

final class weekly_management_acceptance_service_test extends \advanced_testcase {
    public function test_contract_is_schema_neutral(): void {
        $contract = weekly_management_acceptance_service::contract();
        $this->assertSame('0.5.15', $contract['version']);
        $this->assertSame('none', $contract['storage']);
    }

    public function test_classification_separates_hard_blockers_and_attention(): void {
        $accepted = weekly_management_acceptance_service::classify([]);
        $this->assertSame('accepted', $accepted['state']);

        $attention = weekly_management_acceptance_service::classify([
            ['level'=>'attention','count'=>2],
        ]);
        $this->assertSame('accepted_with_attention', $attention['state']);
        $this->assertSame(0, $attention['hard_count']);
        $this->assertSame(2, $attention['attention_count']);

        $blocked = weekly_management_acceptance_service::classify([
            ['level'=>'hard','count'=>1], ['level'=>'attention','count'=>3],
        ]);
        $this->assertSame('blocked', $blocked['state']);
        $this->assertSame(1, $blocked['hard_count']);
        $this->assertSame(3, $blocked['attention_count']);
    }
}
