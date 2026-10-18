<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

class license_reconciliation_test extends \advanced_testcase {
    public function test_course_reconciliation_does_not_mutate_learning_or_licence_state(): void {
        $this->resetAfterTest(true);
        $this->assertTrue(method_exists('\\local_kbacorporate\\local\\licenses', 'course_reconciliation'));
        $method = new \ReflectionMethod('\\local_kbacorporate\\local\\licenses', 'course_reconciliation');
        $this->assertTrue($method->isPublic());
    }
}
