<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_entry_service;
use local_kbacorporate\local\repository;

/**
 * Access regression tests for the BMS implementation company selector.
 */
final class implementation_entry_service_test extends \advanced_testcase {
    /**
     * @param string $name
     * @return int
     */
    private function create_company(string $name): int {
        global $DB;
        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => $name,
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public function test_user_without_companies_gets_empty_selection(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], implementation_entry_service::manageable_companies((int)$user->id));
    }

    public function test_company_manager_sees_only_own_company(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $owncompany = $this->create_company('Manager Company');
        $othercompany = $this->create_company('Other Company');
        repository::add_member($owncompany, (int)$manager->id, 'manager');

        $companies = implementation_entry_service::manageable_companies((int)$manager->id);
        $this->assertArrayHasKey($owncompany, $companies);
        $this->assertArrayNotHasKey($othercompany, $companies);
        $this->assertCount(1, $companies);
    }

    public function test_regular_employee_cannot_select_company_implementation(): void {
        $this->resetAfterTest();
        $employee = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Employee Company');
        repository::add_member($companyid, (int)$employee->id, 'employee');

        $this->assertSame([], implementation_entry_service::manageable_companies((int)$employee->id));
    }
}
