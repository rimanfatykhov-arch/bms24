<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Build 1834: weekly Battle Plan coverage uses canonical child tasks, no copies. */
final class task_subtask_week_planning_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name'=>'Subtask weekly coverage', 'active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $admin = (int)$USER->id;
        $employee = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$employee->id, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $positionuuid = '10000000-0000-4000-8000-000000008341';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company, 'branchuuid'=>'10000000-0000-4000-8000-000000008342',
            'orgnodeuuid'=>'10000000-0000-4000-8000-000000008343', 'uuid'=>$positionuuid,
            'code'=>'TEST-1834', 'name'=>'Test position', 'purpose'=>'Test task work', 'ismanager'=>0,
            'plannedcount'=>1, 'sortorder'=>0, 'active'=>1,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company, 'positionuuid'=>$positionuuid, 'slotuuid'=>'',
            'userid'=>(int)$employee->id, 'assignmenttype'=>'primary',
            'effectivefrom'=>$now-60, 'effectiveto'=>0, 'active'=>1,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        return compact('company','admin','employee','outsider','positionuuid');
    }

    private function task(array $f, string $name, string $parent='', int $minutes=60): string {
        return task_service::create($f['company'], $f['admin'], [
            'title'=>$name, 'product'=>'Accepted result: '.$name, 'description'=>'',
            'assigneeid'=>(int)$f['employee']->id, 'reviewerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>$minutes, 'parentuuid'=>$parent,
        ]);
    }

    public function test_weekly_coverage_counts_only_planned_active_children(): void {
        global $DB;
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        $planned=$this->task($f,'Planned',$parent,75);
        $this->task($f,'Unplanned',$parent,35);
        $done=$this->task($f,'Done',$parent);
        task_service::transition($f['company'],$f['admin'],$done,1,'submit',['result'=>'Finished']);
        $plan=battle_plan_service::create_plan($f['company'],$f['admin'],(int)$f['employee']->id,
            $f['positionuuid'],'2026-10-28',(int)$f['employee']->id);
        battle_plan_service::add_task($f['company'],$f['admin'],$plan,$planned);
        $result=task_service::subtask_week_plan_projection($f['company'],$f['admin'],$parent,'2026-10-29');
        $this->assertSame('2026-10-26',$result['weekstart']);
        $this->assertSame(3,$result['visible_count']);
        $this->assertSame(2,$result['open_count']);
        $this->assertSame(1,$result['planned_count']);
        $this->assertSame(1,$result['unmatched_count']);
        $this->assertSame(1,$result['done_count']);
        $this->assertCount(1,$result['workload']);
        $this->assertSame(75,$result['workload'][0]['planned_minutes']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',
            ['planuuid'=>$plan,'taskuuid'=>$planned]));
    }

    public function test_repeated_add_is_idempotent_and_does_not_copy_child_task(): void {
        global $DB;
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        $child=$this->task($f,'Child',$parent);
        $plan=battle_plan_service::create_plan($f['company'],$f['admin'],(int)$f['employee']->id,
            $f['positionuuid'],'2026-10-26',(int)$f['employee']->id);
        battle_plan_service::add_task($f['company'],$f['admin'],$plan,$child);
        battle_plan_service::add_task($f['company'],$f['admin'],$plan,$child);
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',
            ['planuuid'=>$plan,'taskuuid'=>$child]));
        $this->assertSame(2,$DB->count_records('local_kbacompany_task',['companyuuid'=>$f['company']]));
    }

    public function test_employee_sees_owned_week_but_not_company_wide_plan_scope(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        $child=$this->task($f,'Child',$parent);
        $plan=battle_plan_service::create_plan($f['company'],$f['admin'],(int)$f['employee']->id,
            $f['positionuuid'],'2026-10-26',(int)$f['employee']->id);
        battle_plan_service::add_task($f['company'],$f['admin'],$plan,$child);
        $this->setUser($f['employee']);
        $view=task_service::subtask_week_plan_projection($f['company'],(int)$f['employee']->id,$parent,'2026-10-26');
        $this->assertSame(1,$view['planned_count']);
        $this->assertFalse($view['all_plans_visible']);
    }

    public function test_other_week_does_not_misreport_child_as_planned(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        $child=$this->task($f,'Child',$parent);
        $plan=battle_plan_service::create_plan($f['company'],$f['admin'],(int)$f['employee']->id,
            $f['positionuuid'],'2026-10-26',(int)$f['employee']->id);
        battle_plan_service::add_task($f['company'],$f['admin'],$plan,$child);
        $result=task_service::subtask_week_plan_projection($f['company'],$f['admin'],$parent,'2026-11-02');
        $this->assertSame(0,$result['planned_count']);
        $this->assertSame(1,$result['unmatched_count']);
    }

    public function test_coverage_counts_all_children_even_if_list_is_paginated(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        for ($i=0; $i<22; $i++) { $this->task($f,'Subtask '.$i,$parent); }
        $page=task_service::subtasks_page($f['company'],$f['admin'],$parent);
        $rollup=task_service::subtask_week_plan_projection($f['company'],$f['admin'],$parent,'2026-10-26');
        $this->assertCount(20,$page['items']);
        $this->assertSame(22,$rollup['visible_count']);
        $this->assertSame(22,$rollup['unmatched_count']);
    }

    public function test_company_outsider_cannot_get_weekly_coverage(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Parent');
        $this->setUser($f['outsider']);
        $this->expectException(\moodle_exception::class);
        task_service::subtask_week_plan_projection($f['company'],(int)$f['outsider']->id,
            $parent,'2026-10-26');
    }
}
