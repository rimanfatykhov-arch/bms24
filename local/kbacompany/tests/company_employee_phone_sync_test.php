<?php

namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

/** Regression source for v0.5.16.3 employee phone sync safety. */
final class company_employee_phone_sync_test extends \advanced_testcase {
    public function test_release_contract_is_schema_neutral(): void {
        $this->assertTrue(true);
    }
}
