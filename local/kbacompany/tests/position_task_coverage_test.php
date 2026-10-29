<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\position_coverage_service;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Integration acceptance to run with the Moodle PHPUnit DB, not a static smoke test. */
final class position_task_coverage_test extends \advanced_testcase {
    public function test_approved_position_coverage_moves_execution_not_task_identity(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Coverage test', 'active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $primary = $this->getDataGenerator()->create_user();
        $delegate = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([$primary, $delegate] as $u) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid,'userid'=>$u->id,'membertype'=>'employee',
                'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>$now,'timemodified'=>$now,
            ]);
        }
        $positionuuid = '726bc3de-1aa7-4db3-8396-a4f6c11a70a1';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company,'uuid'=>$positionuuid,'branchuuid'=>'','orgnodeuuid'=>'',
            'code'=>'COVERAGE_TEST','name'=>'Commercial director','active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company,'positionuuid'=>$positionuuid,'userid'=>$primary->id,
            'assignmenttype'=>'primary','assignmentfte'=>1,'active'=>1,
            'effectivefrom'=>0,'effectiveto'=>0,'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $uuid = task_service::create($company, $admin, [
            'title'=>'Prepare report','product'=>'Approved report','description'=>'Company position work',
            'assigneeid'=>$primary->id,'reviewerid'=>0,'priority'=>'normal','stream'=>'run',
            'startdate'=>gmdate('Y-m-d'),'duedate'=>gmdate('Y-m-d', $now + 86400),
        ]);
        $task = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company,'uuid'=>$uuid], '*', MUST_EXIST);
        $this->assertSame('', $task->dutypositionuuid);
        task_service::classify_duty($company, $admin, $uuid, (int)$task->revision, $positionuuid);
        $task = $DB->get_record('local_kbacompany_task', ['uuid'=>$uuid], '*', MUST_EXIST);
        $this->assertSame($positionuuid, $task->dutypositionuuid);
        $DB->insert_record('local_kbacompany_poscover', (object)[
            'companyuuid'=>$company, 'uuid'=>'b2880ae0-1fed-4dc7-92fb-a47f44cbe894',
            'positionuuid'=>$positionuuid,'primaryid'=>$primary->id,'delegateid'=>$delegate->id,
            'scope'=>'position_work','startdate'=>position_coverage_service::day(gmdate('Y-m-d')),
            'enddate'=>position_coverage_service::day(gmdate('Y-m-d',$now+2*86400)),
            'reason'=>'Annual leave substitution','status'=>'approved','revision'=>2,
            'createdby'=>$admin,'approvedby'=>$admin,'approvedat'=>$now,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        // The Battle Plan read-model sees approved coverage without modifying any Task ACL.
        $window = position_coverage_service::battle_plan_window($company, $admin, $positionuuid,
            (int)$primary->id, gmdate('Y-m-d'), gmdate('Y-m-d', $now + 6 * 86400));
        $this->assertCount(1, $window);
        $this->assertSame((int)$delegate->id, $window[0]['delegate_id']);
        $this->assertTrue($window[0]['active_now']);
        $this->assertSame('b2880ae0-1fed-4dc7-92fb-a47f44cbe894', $window[0]['uuid']);
        $this->setUser($delegate);
        $mine = task_service::list_page($company,(int)$delegate->id,['scope'=>'my']);
        $this->assertSame(1, $mine['total']);
        $this->assertSame($uuid, $mine['tasks'][0]['uuid']);
        $this->assertSame((int)$delegate->id, $mine['tasks'][0]['effective_assignee_id']);
        task_service::transition($company,(int)$delegate->id,$uuid,(int)$task->revision,'start');
        $row = $DB->get_record('local_kbacompany_task',['uuid'=>$uuid], '*', MUST_EXIST);
        $this->assertSame((int)$primary->id,(int)$row->assigneeid);
        $this->assertSame('doing',$row->status);
        $this->setUser($primary);
        $this->expectException(\moodle_exception::class);
        task_service::transition($company,(int)$primary->id,$uuid,(int)$row->revision,'submit',[
            'result'=>'Should be denied while covered',
        ]);
    }
    public function test_manager_can_classify_at_creation_and_edit_without_changing_assignee(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Responsibility CRUD test','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$employee->id, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $positionuuid='2838dd10-a6ab-41b1-b167-e0d1c17192b5';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company, 'uuid'=>$positionuuid, 'branchuuid'=>'', 'orgnodeuuid'=>'',
            'code'=>'CRUD_POSITION', 'name'=>'Sales manager', 'active'=>1,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company,'positionuuid'=>$positionuuid,'userid'=>$employee->id,
            'assignmenttype'=>'primary','assignmentfte'=>1,'active'=>1,
            'effectivefrom'=>0,'effectiveto'=>0,'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $fields = [
            'title'=>'Reconcile sales', 'product'=>'Reconciled sales report', 'description'=>'',
            'assigneeid'=>(int)$employee->id, 'reviewerid'=>0, 'priority'=>'normal',
            'stream'=>'run', 'startdate'=>gmdate('Y-m-d'), 'duedate'=>gmdate('Y-m-d', $now+86400),
            'dutypositionuuid'=>$positionuuid, 'dutyconfirmed'=>1,
        ];
        $uuid = task_service::create($company, $manager, $fields);
        $row = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company,'uuid'=>$uuid], '*', MUST_EXIST);
        $this->assertSame($positionuuid, $row->dutypositionuuid);
        $this->assertSame((int)$employee->id, (int)$row->assigneeid);
        $fields['dutypositionuuid']='';
        task_service::save($company, $manager, $uuid, (int)$row->revision, $fields);
        $row = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company,'uuid'=>$uuid], '*', MUST_EXIST);
        $this->assertSame('', $row->dutypositionuuid);
        $this->assertSame((int)$employee->id, (int)$row->assigneeid);
        $events=$DB->get_records('local_kbacompany_tasklog', ['companyuuid'=>$company,'taskuuid'=>$uuid]);
        $this->assertNotEmpty($events);
    }

    public function test_ordinary_employee_cannot_choose_position_on_personal_task_creation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name'=>'Responsibility security test','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid,'userid'=>$employee->id,'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $this->setUser($employee);
        $this->expectException(\moodle_exception::class);
        task_service::create($company, (int)$employee->id, [
            'title'=>'Should fail', 'product'=>'Not created', 'assigneeid'=>(int)$employee->id,
            'priority'=>'normal', 'stream'=>'run', 'dutypositionuuid'=>'aaaabbbb-cccc-dddd-eeee-ffffffffffff',
        ]);
    }

}
