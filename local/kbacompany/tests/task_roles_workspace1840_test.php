<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacorporate\local\repository;

/** Build1840: one company-safe task row across all of the actor's task roles. */
final class task_roles_workspace1840_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Build1840 roles','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $assignee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now=time();
        foreach ([$assignee,$reviewer] as $user) {
            $DB->insert_record('local_kbacorp_member',(object)[
                'companyid'=>$companyid,'userid'=>(int)$user->id,'membertype'=>'employee',
                'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>$now,'timemodified'=>$now,
            ]);
        }
        return compact('company','companyid','admin','assignee','reviewer');
    }

    private function create(array $f): string {
        return task_service::create($f['company'],$f['admin'],[
            'title'=>'Review the monthly management report',
            'description'=>'Review the results before Friday',
            'product'=>'Accepted monthly report',
            'assigneeid'=>(int)$f['assignee']->id,
            'reviewerid'=>(int)$f['reviewer']->id,
            'observerid'=>(int)$f['reviewer']->id,
            'priority'=>'normal','stream'=>'operate',
            'startdate'=>'','duedate'=>'','planminutes'=>30,'parentuuid'=>'',
        ]);
    }

    public function test_role_union_includes_reviewer_before_acceptance_and_deduplicates_multiple_roles(): void {
        $f=$this->fixture();
        $uuid=$this->create($f);
        $actor=(int)$f['reviewer']->id;
        $this->setUser($f['reviewer']);
        $roles=task_service::list_page($f['company'],$actor,['scope'=>'roles']);
        $this->assertSame(1,$roles['total']);
        $this->assertSame([$uuid],array_column($roles['tasks'],'uuid'));
        $this->assertSame('open',$roles['tasks'][0]['status']);
        $this->assertSame(['reviewer','observer'],task_service::role_keys($roles['tasks'][0],$actor));
        $pending=task_service::list_page($f['company'],$actor,['scope'=>'review']);
        $this->assertSame(0,$pending['total']);
        $counts=task_service::scope_counts($f['company'],$actor);
        $this->assertSame(1,$counts['roles']);
        $this->assertSame(0,$counts['review']);
        $this->assertSame(1,$counts['watching']);
        $this->assertSame(1,$counts['all']);
    }

    public function test_manager_all_company_scope_never_leaks_into_personal_role_union(): void {
        global $DB;
        $f=$this->fixture();
        $uuid=$this->create($f);
        // Remove the admin's only personal relationship, retaining his manager access.
        $DB->set_field('local_kbacompany_task','creatorid',(int)$f['assignee']->id,
            ['companyuuid'=>$f['company'],'uuid'=>$uuid]);
        $this->assertTrue(task_service::can_manage($f['company'],$f['admin']));
        $personal=task_service::list_page($f['company'],$f['admin'],['scope'=>'roles']);
        $all=task_service::list_page($f['company'],$f['admin'],['scope'=>'all']);
        $this->assertSame(0,$personal['total']);
        $this->assertSame(1,$all['total']);
        $counts=task_service::scope_counts($f['company'],$f['admin']);
        $this->assertSame(0,$counts['roles']);
        $this->assertSame(1,$counts['all']);
        $this->assertSame('roles',task_service::normalise_scope('all',false));
    }

    public function test_task_roles_are_unique_and_do_not_infer_management_rights(): void {
        $row=['assignee_id'=>17,'effective_assignee_id'=>17,'creator_id'=>17,
            'reviewer_id'=>17,'observer_id'=>17];
        $this->assertSame(['assignee','creator','reviewer','observer'],
            task_service::role_keys($row,17));
        $this->assertSame([],task_service::role_keys($row,99));
        $this->assertSame('roles',task_service::normalise_scope('unknown',true));
    }

    public function test_compact_workspace_escapes_search_and_has_progressive_get_fallback(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode','owned','local_kbacompany');
        $html=ui::listing('company-test',['can_manage'=>false,'total'=>0,'scope_counts'=>[
            'roles'=>3,'my'=>2,'created'=>1,'review'=>0,'watching'=>1,
        ]],[
            'scope'=>'roles','q'=>'<script>alert(1)</script>',
            'status'=>'','stream'=>'','sourcekind'=>'','battleplan'=>'','signal'=>'',
            'assignee'=>0,'project'=>'',
        ],'list');
        $this->assertStringContainsString('bms24-task-quicksearch',$html);
        $this->assertStringContainsString('data-bms-task-fast-search="1"',$html);
        $this->assertStringContainsString('bms24-task-advanced',$html);
        $this->assertStringContainsString('bms24-task-quickfilters',$html);
        $this->assertStringContainsString('name="scope" value="roles"',$html);
        $this->assertStringContainsString(get_string('taskcenter_scope_roles', 'local_kbacompany'), $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>',$html);
        $this->assertStringNotContainsString('scope=all',$html);
        $this->assertStringContainsString('method="get"',$html);
    }
}
