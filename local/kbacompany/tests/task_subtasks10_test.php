<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Canonical subtasks 1.0: hierarchy security, revision safety, no data duplication. */
final class task_subtasks10_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Subtask fixture','active'=>1]);
        $companyuuid = identity_registry::company_uuid($companyid);
        $worker = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$worker->id, 'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>time(),'timemodified'=>time(),
        ]);
        return compact('companyuuid','admin','worker','outsider');
    }
    private function task(array $f, string $title, array $extra=[]): string {
        return task_service::create($f['companyuuid'],$f['admin'],array_merge([
            'title'=>$title,'product'=>'Accepted result of '.$title,'description'=>'',
            'assigneeid'=>(int)$f['worker']->id,'reviewerid'=>0,'priority'=>'normal',
            'stream'=>'operate','startdate'=>'','duedate'=>'','planminutes'=>0,'parentuuid'=>'',
        ],$extra));
    }
    public function test_create_child_and_list_in_parent_without_copy(): void {
        global $DB;
        $f=$this->fixture();
        $parent=$this->task($f,'Master');
        $child=$this->task($f,'Deliverable',['parentuuid'=>$parent]);
        $page=task_service::subtasks_page($f['companyuuid'],$f['admin'],$parent);
        $this->assertSame(1,$page['total']);
        $this->assertSame($child,$page['items'][0]['uuid']);
        $this->assertSame(2,$DB->count_records('local_kbacompany_task',['companyuuid'=>$f['companyuuid']]));
    }
    public function test_link_existing_task_keeps_uuid_and_revision(): void {
        global $DB;
        $f=$this->fixture();
        $parent=$this->task($f,'Master');
        $child=$this->task($f,'Independent');
        $candidates=task_service::subtask_candidates($f['companyuuid'],$f['admin'],$parent);
        $this->assertContains($child,array_column($candidates,'uuid'));
        task_service::link_subtask($f['companyuuid'],$f['admin'],$parent,$child,1);
        $row=$DB->get_record('local_kbacompany_task',['uuid'=>$child],'*',MUST_EXIST);
        $this->assertSame($parent,$row->parentuuid);
        $this->assertSame(2,(int)$row->revision);
        $this->assertSame(2,$DB->count_records('local_kbacompany_task',['companyuuid'=>$f['companyuuid']]));
        $this->assertTrue($DB->record_exists('local_kbacompany_tasklog',
            ['taskuuid'=>$child,'action'=>'subtask_link']));
    }
    public function test_stale_revision_rejected(): void {
        $f=$this->fixture(); $parent=$this->task($f,'Master'); $child=$this->task($f,'Independent');
        $this->expectException(\moodle_exception::class);
        task_service::link_subtask($f['companyuuid'],$f['admin'],$parent,$child,42);
    }
    public function test_link_is_not_allowed_to_create_cycle(): void {
        $f=$this->fixture(); $parent=$this->task($f,'Master');
        $child=$this->task($f,'Child',['parentuuid'=>$parent]);
        $this->expectException(\moodle_exception::class);
        task_service::link_subtask($f['companyuuid'],$f['admin'],$child,$parent,1);
    }
    public function test_unrelated_user_cannot_link_task(): void {
        $f=$this->fixture(); $parent=$this->task($f,'Master');$child=$this->task($f,'Another');
        $this->setUser($f['outsider']);
        $this->expectException(\moodle_exception::class);
        task_service::link_subtask($f['companyuuid'],(int)$f['outsider']->id,$parent,$child,1);
    }
    public function test_closed_parent_denies_new_subtasks(): void {
        $f=$this->fixture(); $parent=$this->task($f,'Master');$child=$this->task($f,'Another');
        task_service::transition($f['companyuuid'],$f['admin'],$parent,1,'submit',['result'=>'Done']);
        $this->expectException(\moodle_exception::class);
        task_service::link_subtask($f['companyuuid'],$f['admin'],$parent,$child,1);
    }
}
