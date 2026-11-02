<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Subtasks 2.0: execution metrics and server-side parent completion gate. */
final class task_subtasks20_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Subtasks execution','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $worker = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$worker->id, 'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>time(),'timemodified'=>time(),
        ]);
        return ['actor'=>$actor, 'company'=>$company, 'worker'=>(int)$worker->id];
    }
    private function create_task(array $f, string $name, string $parent='', int $reviewer=0,
            string $duedate=''): string {
        return task_service::create($f['company'],$f['actor'],[
            'title'=>$name, 'product'=>'Result of '.$name, 'description'=>'',
            'assigneeid'=>$f['worker'], 'reviewerid'=>$reviewer, 'priority'=>'normal',
            'stream'=>'operate','startdate'=>'','duedate'=>$duedate,
            'planminutes'=>0,'parentuuid'=>$parent,
        ]);
    }
    public function test_parent_cannot_submit_while_direct_child_is_open(): void {
        $f=$this->fixture();
        $parent=$this->create_task($f,'Parent');
        $this->create_task($f,'Open child',$parent);
        $this->expectException(\moodle_exception::class);
        task_service::transition($f['company'],$f['actor'],$parent,1,'submit',['result'=>'Ready']);
    }
    public function test_parent_can_submit_after_child_is_done(): void {
        $f=$this->fixture();
        $parent=$this->create_task($f,'Parent');
        $child=$this->create_task($f,'Child',$parent);
        task_service::transition($f['company'],$f['actor'],$child,1,'submit',['result'=>'Child result']);
        task_service::transition($f['company'],$f['actor'],$parent,1,'submit',['result'=>'Parent result']);
        $state=task_service::readable_projection($f['company'],$f['actor'],$parent);
        $this->assertSame('done',$state['status']);
    }
    public function test_parent_can_submit_when_child_is_cancelled(): void {
        $f=$this->fixture();
        $parent=$this->create_task($f,'Parent');
        $child=$this->create_task($f,'Child',$parent);
        task_service::transition($f['company'],$f['actor'],$child,1,'cancel',['reason'=>'No longer required']);
        task_service::transition($f['company'],$f['actor'],$parent,1,'submit',['result'=>'Parent result']);
        $this->assertSame('done',task_service::readable_projection($f['company'],$f['actor'],$parent)['status']);
    }
    public function test_child_under_review_still_blocks_parent(): void {
        $f=$this->fixture();
        $parent=$this->create_task($f,'Parent');
        $child=$this->create_task($f,'Child',$parent,$f['worker']);
        task_service::transition($f['company'],$f['actor'],$child,1,'submit',['result'=>'Waiting']);
        try {
            task_service::transition($f['company'],$f['actor'],$parent,1,'submit',['result'=>'Not yet']);
            $this->fail('Parent was submitted while its child was under review');
        } catch (\moodle_exception $e) {
            $this->assertSame('taskcenter_subtasksunfinished',$e->errorcode);
        }
        task_service::transition($f['company'],$f['actor'],$child,2,'accept');
        task_service::transition($f['company'],$f['actor'],$parent,1,'submit',['result'=>'Accepted child']);
        $this->assertSame('done',task_service::readable_projection($f['company'],$f['actor'],$parent)['status']);
    }
    public function test_progress_summary_includes_overdue_and_excludes_cancelled_from_denominator(): void {
        $f=$this->fixture();
        $parent=$this->create_task($f,'Parent');
        $done=$this->create_task($f,'Done',$parent);
        $cancelled=$this->create_task($f,'Cancelled',$parent);
        $this->create_task($f,'Late',$parent,0,'2020-01-01');
        task_service::transition($f['company'],$f['actor'],$done,1,'submit',['result'=>'Done']);
        task_service::transition($f['company'],$f['actor'],$cancelled,1,'cancel',['reason'=>'Not required']);
        $page=task_service::subtasks_page($f['company'],$f['actor'],$parent);
        $this->assertSame(3,$page['progress']['total']);
        $this->assertSame(1,$page['progress']['done']);
        $this->assertSame(1,$page['progress']['cancelled']);
        $this->assertSame(1,$page['progress']['overdue']);
        $this->assertSame(50,$page['progress']['percent']);
    }
    public function test_limited_visibility_never_reports_global_percent(): void {
        $progress=task_service::subtask_progress(['total'=>3,'done'=>3,'cancelled'=>0,'review'=>0,'overdue'=>0],true);
        $this->assertNull($progress['percent']);
        $this->assertTrue($progress['limited']);
        $this->assertSame(0,$progress['pending']);
    }
}
