<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service as acl;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** build1857: no ACL assignment warnings, and department approval/edit implies task read. */
final class task_acl_stabilization1857_test extends \advanced_testcase {
    private function add_member(int $companyid, int $userid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$userid, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
    }

    public function test_assignment_is_idempotent_and_revocation_is_company_scoped(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid=(int)$USER->id;
        $employee=$this->getDataGenerator()->create_user();
        $companyid=repository::save_company((object)['name'=>'ACL1857 Repeat','active'=>1]);
        $company=identity_registry::company_uuid($companyid);
        $this->add_member($companyid,(int)$employee->id);
        $role=acl::create_role($company,$adminid,'Task limited reader','');
        acl::set_grant($company,$adminid,$role,'task','edit',true);
        acl::assign($company,$adminid,$role,(int)$employee->id,true);
        $count=$DB->count_records(acl::ASSIGN_TABLE,
            ['companyuuid'=>$company,'roleid'=>$role,'userid'=>(int)$employee->id]);
        $audits=$DB->count_records(acl::AUDIT_TABLE,
            ['companyuuid'=>$company,'eventkind'=>'assignment_created','objectid'=>$role]);
        // Repeating the same request cannot write aclgrant or the non-existent scope field.
        acl::assign($company,$adminid,$role,(int)$employee->id,true);
        $this->assertSame($count,$DB->count_records(acl::ASSIGN_TABLE,
            ['companyuuid'=>$company,'roleid'=>$role,'userid'=>(int)$employee->id]));
        $this->assertSame($audits,$DB->count_records(acl::AUDIT_TABLE,
            ['companyuuid'=>$company,'eventkind'=>'assignment_created','objectid'=>$role]));
        acl::assign($company,$adminid,$role,(int)$employee->id,false);
        acl::assign($company,$adminid,$role,(int)$employee->id,false);
        $this->assertFalse($DB->record_exists(acl::ASSIGN_TABLE,
            ['companyuuid'=>$company,'roleid'=>$role,'userid'=>(int)$employee->id]));
        // Revocation must remain possible after an employee is suspended.
        acl::assign($company,$adminid,$role,(int)$employee->id,true);
        $DB->set_field('user','suspended',1,['id'=>(int)$employee->id]);
        acl::assign($company,$adminid,$role,(int)$employee->id,false);
        $this->assertFalse($DB->record_exists(acl::ASSIGN_TABLE,
            ['companyuuid'=>$company,'roleid'=>$role,'userid'=>(int)$employee->id]));
    }

    public function test_department_task_approve_and_edit_grants_preserve_visibility_boundary(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid=(int)$USER->id;
        $manager=$this->getDataGenerator()->create_user();
        $worker=$this->getDataGenerator()->create_user();
        $outsider=$this->getDataGenerator()->create_user();
        $unmanaged=$this->getDataGenerator()->create_user();
        $companyid=repository::save_company((object)['name'=>'ACL1857 Department','active'=>1]);
        $otherid=repository::save_company((object)['name'=>'ACL1857 Other','active'=>1]);
        $company=identity_registry::company_uuid($companyid);
        $other=identity_registry::company_uuid($otherid);
        foreach ([$manager,$worker,$unmanaged] as $person) {
            $this->add_member($companyid,(int)$person->id);
        }
        $this->add_member($otherid,(int)$outsider->id);
        $now=time();
        $departmentid=$DB->insert_record('local_kbacorp_department',(object)[
            'companyid'=>$companyid,'name'=>'Delivery','code'=>'DEL','active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        foreach ([$manager->id=>'manager',$worker->id=>'employee'] as $userid=>$deptrole) {
            $DB->insert_record('local_kbacorp_dept_member',(object)[
                'departmentid'=>$departmentid,'userid'=>$userid,'deptrole'=>$deptrole,
                'timecreated'=>$now,'timemodified'=>$now,
            ]);
        }
        $task=task_service::create($company,$adminid,[
            'title'=>'Department review task','description'=>'Check ACL','product'=>'Result',
            'assigneeid'=>(int)$worker->id,'reviewerid'=>0,'observerid'=>0,
            'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'',
            'planminutes'=>0,'parentuuid'=>'',
        ]);
        // No personal role, department view grant or company-wide management right.
        $this->assertNull(task_service::readable_projection($company,(int)$manager->id,$task));
        // A worker in the same company but outside the managed unit is not visible.
        $unmanagedtask=task_service::create($company,$adminid,[
            'title'=>'Unmanaged department task','description'=>'Must stay private','product'=>'Other',
            'assigneeid'=>(int)$unmanaged->id,'reviewerid'=>0,'observerid'=>0,
            'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'',
            'planminutes'=>0,'parentuuid'=>'',
        ]);
        $role=acl::create_role($company,$adminid,'Department approver','');
        acl::set_grant($company,$adminid,$role,'task','approve',true,'department');
        acl::assign($company,$adminid,$role,(int)$manager->id,true);
        $this->assertContains((int)$worker->id,
            acl::department_task_visibility_userids($company,(int)$manager->id));
        $this->assertSame($task,
            task_service::readable_projection($company,(int)$manager->id,$task)['uuid']);
        $this->assertNull(task_service::readable_projection($company,(int)$manager->id,$unmanagedtask));
        $counts=task_service::scope_counts($company,(int)$manager->id);
        $this->assertTrue($counts['department_allowed']);
        $this->assertSame(1,$counts['department']);
        $this->assertSame(1,task_service::list_page($company,(int)$manager->id,
            ['scope'=>'department'],0,25)['total']);
        $this->assertSame(0,task_service::list_page($company,(int)$manager->id,
            ['scope'=>'roles'],0,25)['total']);
        // No leaked tasks from the second company even if a task shares a title.
        $othertask=task_service::create($other,$adminid,[
            'title'=>'Department review task','description'=>'Other company','product'=>'Other result',
            'assigneeid'=>(int)$outsider->id,'reviewerid'=>0,'observerid'=>0,
            'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'',
            'planminutes'=>0,'parentuuid'=>'',
        ]);
        $this->assertNull(task_service::readable_projection($company,(int)$manager->id,$othertask));
        acl::assign($company,$adminid,$role,(int)$manager->id,false);
        $this->assertNull(task_service::readable_projection($company,(int)$manager->id,$task));
        $this->assertSame(0,task_service::list_page($company,(int)$manager->id,
            ['scope'=>'department'],0,25)['total']);
        // Editing-only grant has exactly the same read boundary as approval.
        acl::set_grant($company,$adminid,$role,'task','edit',true,'department');
        acl::assign($company,$adminid,$role,(int)$manager->id,true);
        $this->assertNotNull(task_service::readable_projection($company,(int)$manager->id,$task));
        $this->assertSame(1,task_service::list_page($company,(int)$manager->id,
            ['scope'=>'department'],0,25)['total']);
    }
}
