<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\employee_schedule_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Work schedule regression: capacity is advisory, company-scoped and adjustable. */
final class employee_schedule_service_test extends \advanced_testcase {
    public function test_individual_and_team_capacity_are_company_scoped(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $firstid = repository::save_company((object)['name'=>'Schedule company A', 'active'=>1]);
        $secondid = repository::save_company((object)['name'=>'Schedule company B', 'active'=>1]);
        $first = identity_registry::company_uuid($firstid);
        $second = identity_registry::company_uuid($secondid);
        $workers=[];
        foreach ([40,36,20] as $index => $hours) {
            $worker=$this->getDataGenerator()->create_user();
            $workers[]=(int)$worker->id;
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$firstid, 'userid'=>$worker->id, 'membertype'=>'employee',
                'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
                'timecreated'=>time(), 'timemodified'=>time(),
            ]);
            if ($index > 0) {
                employee_schedule_service::save($first, $admin, (int)$worker->id, $hours);
            }
        }
        $this->assertSame(40, employee_schedule_service::hours($first, $workers[0]));
        $this->assertSame(36, employee_schedule_service::hours($first, $workers[1]));
        $this->assertSame(20, employee_schedule_service::hours($first, $workers[2]));
        $capacities=employee_schedule_service::capacities($first, $workers);
        $this->assertSame(96 * 60, array_sum($capacities));
        $this->assertSame(3 * 40 * 60, array_sum(employee_schedule_service::capacities($second, $workers)));
        // Changing a weekly norm never removes, rejects or modifies canonical tasks.
        employee_schedule_service::save($first, $admin, $workers[0], 20);
        $this->assertSame(76 * 60, array_sum(employee_schedule_service::capacities($first, $workers)));
        $this->assertSame(0, $DB->count_records('local_kbacompany_task', ['companyuuid'=>$first]));
    }

    public function test_invalid_schedule_and_cross_company_assignment_are_rejected(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin=(int)$USER->id;
        $firstid=repository::save_company((object)['name'=>'Schedule A', 'active'=>1]);
        $secondid=repository::save_company((object)['name'=>'Schedule B', 'active'=>1]);
        $first=identity_registry::company_uuid($firstid);
        $outsider=$this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$secondid,'userid'=>$outsider->id,'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>time(),'timemodified'=>time(),
        ]);
        try {
            employee_schedule_service::save($first, $admin, (int)$outsider->id, 20);
            $this->fail('Cross-company schedule write must be denied');
        } catch (\moodle_exception $e) {
            $this->assertSame(0, $DB->count_records('local_kbacompany_wksched', ['companyuuid'=>$first]));
        }
        try {
            employee_schedule_service::save($first, $admin, $admin, 100);
            $this->fail('Unapproved weekly norm must be denied');
        } catch (\moodle_exception $e) {
            $this->assertSame(0, $DB->count_records('local_kbacompany_wksched', ['companyuuid'=>$first]));
        }
    }
}
