<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_batch_actions_service as batch;
use local_kbacompany\local\task_kanban_preferences_service as kanban;
use local_kbacorporate\local\repository;

/** RC30: one task stays consistent across its Battle Plan, Kanban and batch workflow. */
final class task_rc30_plan_lifecycle1862_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'RC30 plan regression 1862', 'active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([$employee, $reviewer] as $user) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid, 'userid'=>(int)$user->id,
                'membertype'=>'employee','isemployee'=>1,'ismanager'=>0,
                'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>$now,'timemodified'=>$now,
            ]);
        }
        $position = '10000000-0000-4000-8000-000000001862';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company,'branchuuid'=>'10000000-0000-4000-8000-000000001863',
            'orgnodeuuid'=>'10000000-0000-4000-8000-000000001864',
            'uuid'=>$position,'code'=>'QA-1862','name'=>'Control position',
            'purpose'=>'Plan output','ismanager'=>0,'plannedcount'=>1,
            'sortorder'=>0,'active'=>1,'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company,'positionuuid'=>$position,'slotuuid'=>'',
            'userid'=>(int)$employee->id,'assignmenttype'=>'primary',
            'effectivefrom'=>$now-60,'effectiveto'=>0,'active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $plan = battle_plan_service::create_plan($company,$admin,(int)$employee->id,
            $position,'2026-10-26',(int)$reviewer->id);
        $task = battle_plan_service::create_manual_task($company,$admin,$plan,[
            'title'=>'RC30 assigned task','description'=>'Plan lifecycle','product'=>'Accepted output',
            'reviewerid'=>(int)$reviewer->id,'observerid'=>0,
            'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'',
            'planminutes'=>60,'parentuuid'=>'',
        ]);
        return compact('company','admin','employee','reviewer','plan','task');
    }

    /** Simulate a migrated link carrying the BMS UUID only in the legacy column. */
    private function make_legacy(array $f): void {
        global $DB;
        $DB->set_field('local_kbacompany_bplanitem','externalid','',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],
            'provider'=>'bms','taskuuid'=>$f['task'],
        ]);
    }

    public function test_legacy_draft_item_is_idempotent_and_task_delete_cleans_it(): void {
        global $DB;
        $f=$this->fixture();
        $this->make_legacy($f);
        battle_plan_service::add_task($f['company'],$f['admin'],$f['plan'],$f['task']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],
            'provider'=>'bms','taskuuid'=>$f['task'],
        ]));
        task_service::delete_task($f['company'],$f['admin'],$f['task'],1);
        $this->assertSame('deleted',(string)$DB->get_field('local_kbacompany_task','status',[
            'companyuuid'=>$f['company'],'uuid'=>$f['task'],
        ]));
        $this->assertSame(0,$DB->count_records('local_kbacompany_bplanitem',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],
        ]));
    }

    public function test_published_plan_with_legacy_link_prevents_task_delete(): void {
        global $DB;
        $f=$this->fixture();
        $this->make_legacy($f);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'],(int)$f['employee']->id,$f['plan'],'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::transition($f['company'],(int)$f['reviewer']->id,$f['plan'],'approve');
        $this->setAdminUser();
        try {
            task_service::delete_task($f['company'],$f['admin'],$f['task'],1);
            $this->fail('Approved legacy plan item must protect its canonical task');
        } catch (\moodle_exception $e) {
            $this->assertSame('tui_delete_linked_plan',$e->errorcode);
        }
        $this->assertSame('open',(string)$DB->get_field('local_kbacompany_task','status',[
            'companyuuid'=>$f['company'],'uuid'=>$f['task'],
        ]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],
            'taskuuid'=>$f['task'],
        ]));
    }

    public function test_published_plan_with_current_link_also_prevents_task_delete(): void {
        global $DB;
        $f=$this->fixture();
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'],(int)$f['employee']->id,$f['plan'],'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::transition($f['company'],(int)$f['reviewer']->id,$f['plan'],'approve');
        $this->setAdminUser();
        try {
            task_service::delete_task($f['company'],$f['admin'],$f['task'],1);
            $this->fail('Approved current plan item must protect its canonical task');
        } catch (\moodle_exception $e) {
            $this->assertSame('tui_delete_linked_plan',$e->errorcode);
        }
        $this->assertSame('open',(string)$DB->get_field('local_kbacompany_task','status',[
            'companyuuid'=>$f['company'],'uuid'=>$f['task'],
        ]));
    }

    public function test_batch_reassignment_is_guarded_and_kanban_keeps_native_workflow(): void {
        global $DB;
        $f=$this->fixture();
        $this->make_legacy($f);
        $result=batch::run($f['company'],$f['admin'],[$f['task']=>1],'assignee','',(int)$f['reviewer']->id);
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1],$result);
        $this->assertSame((int)$f['employee']->id,(int)$DB->get_field('local_kbacompany_task','assigneeid',[
            'companyuuid'=>$f['company'],'uuid'=>$f['task'],
        ]));
        $prefs=kanban::defaults();
        $this->assertSame('start',kanban::drop_operation('open','doing',['start'=>true]));
        $this->assertSame('submit',kanban::drop_operation('doing','review',['submit'=>true],true));
        $this->assertSame('accept',kanban::drop_operation('review','done',['accept'=>true],true));
        $this->assertSame('',kanban::drop_operation('review','open',['save'=>true],true));
        $this->assertSame('doing',kanban::target_status('doing',$prefs));
        $started=batch::run($f['company'],$f['admin'],[$f['task']=>1],'start');
        $this->assertSame(['updated'=>1,'skipped'=>0,'total'=>1],$started);
        task_service::transition($f['company'],$f['admin'],$f['task'],2,'submit',['result'=>'Evidence recorded']);
        $review=task_service::readable_projection($f['company'],$f['admin'],$f['task']);
        $this->assertSame('review',$review['status']);
        $this->assertSame(3,(int)$review['revision']);
        $accepted=batch::run($f['company'],$f['admin'],[$f['task']=>3],'accept');
        $this->assertSame(['updated'=>1,'skipped'=>0,'total'=>1],$accepted);
        $this->assertSame('done',(string)$DB->get_field('local_kbacompany_task','status',[
            'companyuuid'=>$f['company'],'uuid'=>$f['task'],
        ]));
    }
}
