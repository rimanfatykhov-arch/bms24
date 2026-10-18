<?php
namespace local_bitrixsync;

defined('MOODLE_INTERNAL') || die();

class license_reconciliation_service_test extends \advanced_testcase {
    public function test_reconciliation_service_is_license_only_boundary(): void {
        $this->resetAfterTest(true);
        $class = '\\local_bitrixsync\\services\\license_reconciliation_service';
        $this->assertTrue(class_exists($class));
        $this->assertTrue(method_exists($class, 'reconcile'));
    }
}
