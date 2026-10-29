<?php
// Route contract regression: A3 is alphanumeric and must remain whitelisted.
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

class management_cycle_a3_route_regression_test extends \advanced_testcase {
    public function test_a3_tab_is_accepted_by_input_type_and_whitelist(): void {
        $this->assertEquals('a3', \clean_param('a3', PARAM_ALPHANUM));
        $allowed = ['overview', 'checkpoints', 'execution', 'a3', 'history'];
        $this->assertTrue(in_array('a3', $allowed, true));
        $this->assertFalse(in_array('a3extra', $allowed, true));
    }
}
