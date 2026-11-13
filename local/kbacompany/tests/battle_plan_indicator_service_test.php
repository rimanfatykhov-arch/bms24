<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_indicator_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\indicator_condition_execution_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

final class battle_plan_indicator_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Indicator BP','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([[$employee,0],[$reviewer,1]] as $pair) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid,'userid'=>$pair[0]->id,'membertype'=>'employee','isemployee'=>1,
                'ismanager'=>$pair[1],'isdepartmentmanager'=>0,'ishr'=>0,'timecreated'=>$now,'timemodified'=>$now,
            ]);
        }
        $positionuuid = '61000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company,'branchuuid'=>'61000000-0000-4000-8000-000000000002',
            'orgnodeuuid'=>'61000000-0000-4000-8000-000000000003','uuid'=>$positionuuid,
            'code'=>'P-IND','name'=>'Indicator position','purpose'=>'Produce','ismanager'=>0,'plannedcount'=>1,
            'sortorder'=>0,'active'=>1,'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company,'positionuuid'=>$positionuuid,'slotuuid'=>'','userid'=>$employee->id,
            'assignmenttype'=>'primary','effectivefrom'=>$now-60,'effectiveto'=>0,'active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $statuuid = '62000000-0000-4000-8000-000000000001';
        $binduuid = '63000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_stat', (object)[
            'companyuuid'=>$company,'uuid'=>$statuuid,'productuuid'=>'','code'=>'KPI-1','name'=>'Weekly output',
            'unit'=>'pcs','periodtype'=>'weekly','direction'=>'up','timecreated'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_statbind', (object)[
            'companyuuid'=>$company,'uuid'=>$binduuid,'revision'=>1,'status'=>'active','statuuid'=>$statuuid,
            'contextkind'=>'position','contextuuid'=>$positionuuid,'contextrevision'=>0,'isprimary'=>1,'critical'=>1,
            // The sample Battle Plan belongs to September; the position binding must
            // exist BEFORE that historic week for as-of projections to include it.
            'weight'=>1,'sortorder'=>10,'createdby'=>$admin,
            'timecreated'=>strtotime('2026-08-01 00:00:00 UTC'),
        ]);
        $DB->insert_record('local_kbacompany_statnorm', (object)[
            'companyuuid'=>$company,'uuid'=>'64000000-0000-4000-8000-000000000001','revision'=>1,'status'=>'active',
            'binduuid'=>$binduuid,'bindrevision'=>1,'normkind'=>'target','targetvalue'=>100,'minval'=>null,'maxval'=>null,
            'effectivefrom'=>'2026-01-01','effectiveto'=>'','createdby'=>$admin,'timecreated'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_statpolicy', (object)[
            'companyuuid'=>$company,'uuid'=>'65000000-0000-4000-8000-000000000001','revision'=>1,'status'=>'active',
            'binduuid'=>$binduuid,'bindrevision'=>1,'dangerdecline'=>20,'dangerperiods'=>3,'affluencegrowth'=>20,
            'powerlevel'=>150,'powerperiods'=>2,'effectivefrom'=>'2026-01-01','effectiveto'=>'',
            'createdby'=>$admin,'timecreated'=>$now,
        ]);
        foreach ([['2026-08-31',80],['2026-09-07',90]] as $i=>$fact) {
            $DB->insert_record('local_kbacompany_statval', (object)[
                'companyuuid'=>$company,'statuuid'=>$statuuid,'periodstart'=>$fact[0],'kind'=>'fact',
                'amount'=>$fact[1],'revision'=>1,'reason'=>'','writerid'=>$admin,'timecreated'=>$now+$i,
            ]);
        }
        $plan = battle_plan_service::create_plan($company,$admin,(int)$employee->id,$positionuuid,
            '2026-09-14',(int)$reviewer->id);
        return compact('admin','company','employee','reviewer','positionuuid','statuuid','binduuid','plan');
    }

    public function test_projection_uses_canonical_binding_norm_and_history(): void {
        global $DB;
        $f=$this->fixture();
        $rows=battle_plan_indicator_service::projection($f['company'],$f['admin'],$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertCount(1,$rows);
        $this->assertSame($f['statuuid'],$rows[0]['stat_uuid']);
        $this->assertSame(90.0,$rows[0]['previous_fact']);
        $this->assertNull($rows[0]['current_fact']);
        $this->assertSame(100.0,(float)$rows[0]['norm_label']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_stat',['companyuuid'=>$f['company']]));
    }

    public function test_historical_projection_uses_last_52_periods_relative_to_plan_week(): void {
        global $DB;
        $f=$this->fixture();
        $before=battle_plan_indicator_service::projection($f['company'],$f['admin'],$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertSame('insufficient', $before[0]['trend']['movement']);
        // The classifier requires three real observations, not two plus future data.
        $DB->insert_record('local_kbacompany_statval', (object)[
            'companyuuid'=>$f['company'],'statuuid'=>$f['statuuid'],'periodstart'=>'2026-08-24','kind'=>'fact',
            'amount'=>70,'revision'=>1,'reason'=>'third historical point','writerid'=>$f['admin'],'timecreated'=>time(),
        ]);
        $date=new \DateTimeImmutable('2026-09-21', new \DateTimeZone('UTC'));
        for ($i=0; $i<60; $i++) {
            $DB->insert_record('local_kbacompany_statval', (object)[
                'companyuuid'=>$f['company'],'statuuid'=>$f['statuuid'],'periodstart'=>$date->format('Y-m-d'),'kind'=>'fact',
                'amount'=>200+$i,'revision'=>1,'reason'=>'future fixture','writerid'=>$f['admin'],'timecreated'=>time()+100+$i,
            ]);
            $date=$date->modify('+7 days');
        }
        $rows=battle_plan_indicator_service::projection($f['company'],$f['admin'],$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertCount(1,$rows);
        $this->assertSame(90.0,$rows[0]['previous_fact']);
        $this->assertNull($rows[0]['current_fact']);
        $this->assertNotSame('insufficient',$rows[0]['trend']['movement']);
    }

    public function test_employee_fact_entry_appends_to_statval_and_updates_trend(): void {
        global $DB;
        $f=$this->fixture();
        $this->setUser($f['employee']);
        battle_plan_indicator_service::record_fact($f['company'],(int)$f['employee']->id,$f['plan'],$f['binduuid'],'105','');
        $rows=battle_plan_indicator_service::projection($f['company'],(int)$f['employee']->id,$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertSame(105.0,$rows[0]['current_fact']);
        $this->assertSame('sustained_up',$rows[0]['trend']['movement']);
        $this->assertSame('improving',$rows[0]['trend']['quality']);
        $this->assertSame('normal',$rows[0]['condition']);
        $this->assertSame(3,$DB->count_records('local_kbacompany_statval',['companyuuid'=>$f['company'],'statuuid'=>$f['statuuid']]));

        try {
            battle_plan_indicator_service::record_fact($f['company'],(int)$f['employee']->id,$f['plan'],$f['binduuid'],'106','');
            $this->fail('Correction without reason must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('battleplan_indicator_correctionreason','local_kbacompany'),$e->getMessage());
        }
        battle_plan_indicator_service::record_fact($f['company'],(int)$f['employee']->id,$f['plan'],$f['binduuid'],'106','Correction');
        $this->assertSame(2,$DB->count_records('local_kbacompany_statval',[
            'companyuuid'=>$f['company'],'statuuid'=>$f['statuuid'],'periodstart'=>'2026-09-14','kind'=>'fact']));
        $this->assertSame(1,$DB->count_records('local_kbacompany_stat',['companyuuid'=>$f['company']]));
    }

    public function test_daily_fact_in_own_plan_writes_exact_date_and_requires_reason_for_correction(): void {
        global $DB;
        $f = $this->fixture();
        $DB->delete_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
        ]);
        $stat = $DB->get_record('local_kbacompany_stat', [
            'companyuuid'=>$f['company'], 'uuid'=>$f['statuuid'],
        ], '*', MUST_EXIST);
        $stat->periodtype = 'daily';
        $DB->update_record('local_kbacompany_stat', $stat);
        $this->setUser($f['employee']);
        $actor = (int)$f['employee']->id;
        battle_plan_indicator_service::record_fact($f['company'], $actor, $f['plan'],
            $f['binduuid'], '42000', '', '2026-09-16');
        $this->assertTrue($DB->record_exists('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
            'periodstart'=>'2026-09-16', 'kind'=>'fact', 'revision'=>1,
        ]));
        $rows = battle_plan_indicator_service::projection($f['company'], $actor,
            $f['positionuuid'], '2026-09-14', $f['plan']);
        $this->assertSame(42000.0, $rows[0]['current_fact']);
        $this->assertSame(1, $rows[0]['fact_days']);
        $this->assertNull($rows[0]['current_plan']);
        $this->assertSame('nodata', $rows[0]['performance']['status']);
        $this->assertFalse($rows[0]['daily_aligned']);

        try {
            battle_plan_indicator_service::record_fact($f['company'], $actor, $f['plan'],
                $f['binduuid'], '43000', '', '2026-09-16');
            $this->fail('Correction without reason should fail');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('battleplan_indicator_correctionreason','local_kbacompany'),
                $e->getMessage());
        }
        battle_plan_indicator_service::record_fact($f['company'], $actor, $f['plan'],
            $f['binduuid'], '43000', 'Исправление чека', '2026-09-16');
        $this->assertSame(2, $DB->count_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
            'periodstart'=>'2026-09-16', 'kind'=>'fact',
        ]));
        $rows = battle_plan_indicator_service::projection($f['company'], $actor,
            $f['positionuuid'], '2026-09-14', $f['plan']);
        $this->assertSame(43000.0, $rows[0]['current_fact']);
    }

    public function test_daily_fact_rejects_out_of_week_and_other_metric_binding(): void {
        global $DB;
        $f = $this->fixture();
        $DB->delete_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
        ]);
        $stat = $DB->get_record('local_kbacompany_stat', [
            'companyuuid'=>$f['company'], 'uuid'=>$f['statuuid'],
        ], '*', MUST_EXIST);
        $stat->periodtype = 'daily';
        $DB->update_record('local_kbacompany_stat', $stat);
        $this->setUser($f['employee']);
        $actor = (int)$f['employee']->id;
        try {
            battle_plan_indicator_service::record_fact($f['company'], $actor, $f['plan'],
                $f['binduuid'], '50', '', '2026-09-21');
            $this->fail('Other week must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('battleplan_indicator_invalid', 'local_kbacompany'),
                $e->getMessage());
        }
        try {
            battle_plan_indicator_service::record_fact($f['company'], $actor, $f['plan'],
                '00000000-0000-4000-8000-000000000000', '50', '', '2026-09-16');
            $this->fail('Unbound metric must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('battleplan_indicator_binding', 'local_kbacompany'),
                $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
        ]));
    }

    public function test_manager_can_set_weekly_target_without_granting_employee_plan_permission(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        $changed = battle_plan_indicator_service::record_plan($f['company'], $f['admin'], $f['plan'],
            $f['binduuid'], ['2026-09-14'=>'120'], '');
        $this->assertSame(1, $changed);
        $this->assertTrue($DB->record_exists('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
            'periodstart'=>'2026-09-14', 'kind'=>'plan', 'revision'=>1,
        ]));
        // Identical POST is a no-op, not a duplicate revision.
        $this->assertSame(0, battle_plan_indicator_service::record_plan($f['company'], $f['admin'],
            $f['plan'], $f['binduuid'], ['2026-09-14'=>'120.00']));
        try {
            battle_plan_indicator_service::record_plan($f['company'], $f['admin'], $f['plan'],
                $f['binduuid'], ['2026-09-14'=>'130']);
            $this->fail('A target correction must include a reason');
        } catch (\moodle_exception $e) {
            $this->assertSame('battleplan_indicator_correctionreason', $e->errorcode);
        }
        battle_plan_indicator_service::record_plan($f['company'], $f['admin'], $f['plan'],
            $f['binduuid'], ['2026-09-14'=>'130'], 'Approved revised target');
        $this->assertSame(2, $DB->count_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
            'periodstart'=>'2026-09-14', 'kind'=>'plan',
        ]));
        $this->setUser($f['employee']);
        try {
            battle_plan_indicator_service::record_plan($f['company'], (int)$f['employee']->id, $f['plan'],
                $f['binduuid'], ['2026-09-14'=>'999']);
            $this->fail('The employee must not edit targets');
        } catch (\moodle_exception $e) {
            $this->assertSame('battleplan_indicator_permission', $e->errorcode);
        }
    }

    public function test_day_matched_progress_ignores_future_targets_and_preserves_week_total(): void {
        global $DB;
        $f = $this->fixture();
        $DB->delete_records('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
        ]);
        $stat = $DB->get_record('local_kbacompany_stat', [
            'companyuuid'=>$f['company'], 'uuid'=>$f['statuuid'],
        ], '*', MUST_EXIST);
        $stat->periodtype = 'daily';
        $DB->update_record('local_kbacompany_stat', $stat);
        $this->setAdminUser();
        $this->assertSame(3, battle_plan_indicator_service::record_plan($f['company'], $f['admin'], $f['plan'],
            $f['binduuid'], ['2026-09-14'=>'30000', '2026-09-15'=>'40000', '2026-09-16'=>'50000']));
        $this->setUser($f['employee']);
        battle_plan_indicator_service::record_fact($f['company'], (int)$f['employee']->id,
            $f['plan'], $f['binduuid'], '20000', '', '2026-09-14');
        $rows = battle_plan_indicator_service::projection($f['company'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', $f['plan']);
        $this->assertCount(1, $rows);
        $this->assertSame(120000.0, $rows[0]['current_plan']);
        $this->assertSame(20000.0, $rows[0]['current_fact']);
        $this->assertSame(1, $rows[0]['comparison_days']);
        $this->assertSame(30000.0, $rows[0]['comparison_plan']);
        $this->assertSame(20000.0, $rows[0]['comparison_fact']);
        $this->assertSame(-10000.0, $rows[0]['performance']['variance']);
        $this->assertFalse($rows[0]['daily_aligned']);
    }

    public function test_daily_plan_rejects_out_of_week_keys_atomically(): void {
        global $DB;
        $f = $this->fixture();
        $stat = $DB->get_record('local_kbacompany_stat', [
            'companyuuid'=>$f['company'], 'uuid'=>$f['statuuid'],
        ], '*', MUST_EXIST);
        $stat->periodtype = 'daily';
        $DB->update_record('local_kbacompany_stat', $stat);
        $this->setAdminUser();
        try {
            battle_plan_indicator_service::record_plan($f['company'], $f['admin'], $f['plan'],
                $f['binduuid'], ['2026-09-14'=>'200', '2026-09-21'=>'999']);
            $this->fail('Out-of-week targets must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('battleplan_indicator_invalid', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_kbacompany_statval', [
            'companyuuid'=>$f['company'], 'statuuid'=>$f['statuuid'],
            'periodstart'=>'2026-09-14', 'kind'=>'plan',
        ]));
    }

    public function test_manager_confirmation_and_canonical_task_are_projected_without_copy(): void {
        global $DB;
        $f=$this->fixture();
        battle_plan_indicator_service::record_fact($f['company'],$f['admin'],$f['plan'],$f['binduuid'],'105','');
        $result=indicator_condition_execution_service::confirm_to_execution($f['company'],$f['admin'],$f['binduuid'],
            'normal','',[
                'title'=>'Correct weekly output','description'=>'','product'=>'Stable weekly output',
                'stream'=>'change','priority'=>'high','assigneeid'=>(int)$f['employee']->id,
                'reviewerid'=>(int)$f['reviewer']->id,'observerid'=>0,'parentuuid'=>'','contextkind'=>'',
                'contextuuid'=>'','contextrevision'=>0,'startdate'=>'2026-09-14','duedate'=>'2026-09-20','planminutes'=>30,
            ],$f['plan']);
        $this->assertNotSame('',$result['task_uuid']);
        $this->setUser($f['employee']);
        $rows=battle_plan_indicator_service::projection($f['company'],(int)$f['employee']->id,$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertTrue($rows[0]['condition_confirmed']);
        $this->assertSame('normal',$rows[0]['condition']);
        $this->assertTrue($rows[0]['task']['visible']);
        $this->assertTrue($rows[0]['task']['in_plan']);
        $this->assertSame($result['task_uuid'],$rows[0]['task']['uuid']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_task',['companyuuid'=>$f['company']]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',['companyuuid'=>$f['company'],'planuuid'=>$f['plan']]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_stat',['companyuuid'=>$f['company']]));
    }

    public function test_direct_management_action_uses_plan_snapshot_and_is_idempotent(): void {
        global $DB;
        $f=$this->fixture();
        battle_plan_indicator_service::record_fact($f['company'],$f['admin'],$f['plan'],$f['binduuid'],'105','');
        $future='2026-09-21';
        $DB->insert_record('local_kbacompany_statval', (object)[
            'companyuuid'=>$f['company'],'statuuid'=>$f['statuuid'],'periodstart'=>$future,'kind'=>'fact',
            'amount'=>500,'revision'=>1,'reason'=>'future fixture','writerid'=>$f['admin'],'timecreated'=>time()+100,
        ]);
        $taskdata=[
            'title'=>'Handle weekly indicator','description'=>'From Battle Plan row','product'=>'Weekly indicator handled',
            'priority'=>'high','duedate'=>'2026-09-20','planminutes'=>30,
        ];
        $first=indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
            $f['binduuid'],'normal','',$taskdata);
        $event=$DB->get_record('local_kbacompany_stateevt',['uuid'=>$first['event_uuid']], '*', MUST_EXIST);
        $task=$DB->get_record('local_kbacompany_task',['uuid'=>$first['task_uuid']], '*', MUST_EXIST);
        $this->assertSame('2026-09-14',(string)$event->periodkey);
        $this->assertSame('normal',(string)$event->conditioncode);
        $this->assertSame('condition',(string)$task->sourcekind);
        $this->assertSame((int)$f['employee']->id,(int)$task->assigneeid);
        $this->assertSame((int)$f['reviewer']->id,(int)$task->reviewerid);
        $this->assertTrue($DB->record_exists('local_kbacompany_bplanitem',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],'provider'=>'bms','externalid'=>$first['task_uuid'],
        ]));

        $second=indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
            $f['binduuid'],'normal','',$taskdata);
        $this->assertSame($first['event_uuid'],$second['event_uuid']);
        $this->assertSame($first['task_uuid'],$second['task_uuid']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_stateevt',[
            'companyuuid'=>$f['company'],'binduuid'=>$f['binduuid'],'periodkey'=>'2026-09-14',
        ]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_task',[
            'companyuuid'=>$f['company'],'sourcekind'=>'condition','sourceuuid'=>$first['event_uuid'],
        ]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_bplanitem',[
            'companyuuid'=>$f['company'],'planuuid'=>$f['plan'],'provider'=>'bms','externalid'=>$first['task_uuid'],
        ]));
    }

    public function test_direct_context_action_requires_comment_and_no_fact_uses_plan_week(): void {
        global $DB;
        $f=$this->fixture();
        $DB->delete_records('local_kbacompany_statval',['companyuuid'=>$f['company'],'statuuid'=>$f['statuuid']]);
        $taskdata=[
            'title'=>'Start indicator production','description'=>'','product'=>'Indicator production started',
            'priority'=>'normal','duedate'=>'2026-09-20','planminutes'=>15,
        ];
        try {
            indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
                $f['binduuid'],'nonexistence','',$taskdata);
            $this->fail('Context condition without comment must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('indicator_condition_execution_invalid','local_kbacompany'),$e->getMessage());
        }
        $result=indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
            $f['binduuid'],'nonexistence','No fact exists for the role yet',$taskdata);
        $event=$DB->get_record('local_kbacompany_stateevt',['uuid'=>$result['event_uuid']], '*', MUST_EXIST);
        $this->assertSame('context',(string)$event->eventkind);
        $this->assertSame('2026-09-14',(string)$event->periodkey);
        $this->assertSame('',(string)$event->analyticcondition);
    }

    public function test_task_control_exposes_status_result_and_real_checklist_progress(): void {
        global $DB;
        $f=$this->fixture();
        battle_plan_indicator_service::record_fact($f['company'],$f['admin'],$f['plan'],$f['binduuid'],'105','');
        $result=indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
            $f['binduuid'],'normal','',[
                'title'=>'Control indicator action','description'=>'','product'=>'Action completed',
                'priority'=>'normal','duedate'=>'2026-09-20','planminutes'=>30,
            ]);
        $task=$DB->get_record('local_kbacompany_task',['uuid'=>$result['task_uuid']], '*', MUST_EXIST);
        $task->status='done';
        $task->result='Implemented and checked';
        $task->completedat=time();
        $DB->update_record('local_kbacompany_task',$task);
        foreach ([1,0] as $i=>$done) {
            $DB->insert_record('local_kbacompany_taskcheck',(object)[
                'companyuuid'=>$f['company'],'taskuuid'=>$result['task_uuid'],
                'uuid'=>'66000000-0000-4000-8000-00000000000'.($i+1),
                'itemtext'=>'Step '.($i+1),'assigneeid'=>(int)$f['employee']->id,'done'=>$done,
                'sortorder'=>$i,'timecreated'=>time(),'timemodified'=>time(),
            ]);
        }
        $rows=battle_plan_indicator_service::projection($f['company'],$f['admin'],$f['positionuuid'],'2026-09-14',$f['plan']);
        $this->assertSame('done',$rows[0]['task']['status']);
        $this->assertSame('Implemented and checked',$rows[0]['task']['result']);
        $this->assertSame(1,$rows[0]['task']['checklist_done']);
        $this->assertSame(2,$rows[0]['task']['checklist_total']);
        $this->assertSame(50.0,$rows[0]['task']['checklist_percent']);
    }

    public function test_next_week_observes_fact_change_after_previous_management_action_without_copy(): void {
        global $DB;
        $f=$this->fixture();
        battle_plan_indicator_service::record_fact($f['company'],$f['admin'],$f['plan'],$f['binduuid'],'105','');
        $result=indicator_condition_execution_service::confirm_from_battle_plan($f['company'],$f['admin'],$f['plan'],
            $f['binduuid'],'normal','',[
                'title'=>'Improve weekly output','description'=>'','product'=>'Weekly output improved',
                'priority'=>'high','duedate'=>'2026-09-20','planminutes'=>45,
            ]);
        $DB->insert_record('local_kbacompany_statval',(object)[
            'companyuuid'=>$f['company'],'statuuid'=>$f['statuuid'],'periodstart'=>'2026-09-21','kind'=>'fact',
            'amount'=>115,'revision'=>1,'reason'=>'','writerid'=>$f['admin'],'timecreated'=>time()+10,
        ]);
        $nextplan=battle_plan_service::create_plan($f['company'],$f['admin'],(int)$f['employee']->id,$f['positionuuid'],
            '2026-09-21',(int)$f['reviewer']->id);
        $rows=battle_plan_indicator_service::projection($f['company'],$f['admin'],$f['positionuuid'],'2026-09-21',$nextplan);
        $this->assertFalse($rows[0]['condition_confirmed']);
        $this->assertTrue($rows[0]['previous_action']['exists']);
        $this->assertSame($result['task_uuid'],$rows[0]['previous_action']['task']['uuid']);
        $this->assertSame(105.0,$rows[0]['previous_action']['observation']['baseline_fact']);
        $this->assertSame(115.0,$rows[0]['previous_action']['observation']['followup_fact']);
        $this->assertSame('up',$rows[0]['previous_action']['observation']['change']);
        $this->assertSame('improving',$rows[0]['previous_action']['observation']['quality']);
        $this->assertSame(1,$DB->count_records('local_kbacompany_stat',['companyuuid'=>$f['company']]));
        $this->assertSame(1,$DB->count_records('local_kbacompany_task',[
            'companyuuid'=>$f['company'],'sourcekind'=>'condition','sourceuuid'=>$result['event_uuid'],
        ]));
    }

}
