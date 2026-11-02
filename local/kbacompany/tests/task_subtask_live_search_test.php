<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** build1837: server-side candidate selection is scoped, bounded and revision-safe. */
final class task_subtask_live_search_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Subtask search fixture','active'=>1]);
        $companyuuid = identity_registry::company_uuid($companyid);
        $worker = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid,'userid'=>$worker->id,'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>time(),'timemodified'=>time(),
        ]);
        return compact('companyuuid','admin','worker','outsider');
    }

    private function task(array $f, string $title, array $extra=[]): string {
        return task_service::create($f['companyuuid'],$f['admin'],array_merge([
            'title'=>$title,'product'=>'Result '.$title,'description'=>'',
            'assigneeid'=>(int)$f['worker']->id,'reviewerid'=>0,'priority'=>'normal',
            'stream'=>'operate','startdate'=>'','duedate'=>'',
            'planminutes'=>0,'parentuuid'=>'',
        ],$extra));
    }

    public function test_partial_title_search_finds_only_matching_candidates(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Master');
        $target=$this->task($f,'Prepare commercial proposal');
        $this->task($f,'Hold weekly meeting');
        $items=task_service::subtask_candidates($f['companyuuid'],$f['admin'],$parent,'commercial',20);
        $this->assertSame([$target],array_column($items,'uuid'));
        $this->assertSame(1,(int)$items[0]['revision']);
    }

    public function test_search_limit_is_bounded_and_parent_is_excluded(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Plan');
        for ($n=0;$n<7;$n++) {$this->task($f,'Plan task '.$n);}
        $items=task_service::subtask_candidates($f['companyuuid'],$f['admin'],$parent,'Plan',3);
        $this->assertCount(3,$items);
        $this->assertNotContains($parent,array_column($items,'uuid'));
    }

    public function test_already_parented_tasks_cannot_appear_in_results(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Master');
        $otherparent=$this->task($f,'Other parent');
        $this->task($f,'Finance report',['parentuuid'=>$otherparent]);
        $candidate=$this->task($f,'Finance planning');
        $items=task_service::subtask_candidates($f['companyuuid'],$f['admin'],$parent,'Finance',20);
        $this->assertSame([$candidate],array_column($items,'uuid'));
    }

    public function test_outsider_cannot_search_subtasks(): void {
        $f=$this->fixture();
        $parent=$this->task($f,'Master');
        $this->setUser($f['outsider']);
        $this->expectException(\moodle_exception::class);
        task_service::subtask_candidates($f['companyuuid'],(int)$f['outsider']->id,$parent,'Master',20);
    }
}
