<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_batch_actions_service as batch;
use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Build1842: batch edits reuse canonical ACL/revision checks, never a fast-path mutation. */
final class task_batch_actions1842_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $id = repository::save_company((object)['name'=>'1842 batch actions', 'active'=>1]);
        $company = identity_registry::company_uuid($id);
        $worker = $this->getDataGenerator()->create_user();
        $replacement = $this->getDataGenerator()->create_user();
        foreach ([$worker, $replacement] as $user) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$id, 'userid'=>(int)$user->id,
                'membertype'=>'employee', 'isemployee'=>1, 'ismanager'=>0,
                'isdepartmentmanager'=>0, 'ishr'=>0,
                'timecreated'=>time(), 'timemodified'=>time(),
            ]);
        }
        return [$company, $actor, (int)$worker->id, (int)$replacement->id];
    }

    private function task(string $company, int $actor, int $assignee, int $reviewer = 0): string {
        return task_service::create($company, $actor, [
            'title'=>'1842 task', 'description'=>'Safe bulk test', 'product'=>'Approved result',
            'assigneeid'=>$assignee, 'reviewerid'=>$reviewer, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
    }

    public function test_selection_rejects_arbitrary_keys_zero_revisions_and_more_than_page_size(): void {
        $one='11111111-1111-4111-8111-111111111111';
        $this->assertSame([$one=>1], batch::selection([$one=>1]));
        foreach ([[],['other'=>1],[$one=>0],[$one=>'-9'],[$one=>'1;DROP']] as $bad) {
            try { batch::selection($bad); $this->fail('Invalid selection accepted'); }
            catch (\moodle_exception $e) { $this->assertSame('bms_task1842_invalid_selection',$e->errorcode); }
        }
        $many=[];
        for ($n=0; $n<26; $n++) {
            $many[sprintf('00000000-0000-4000-8000-%012d',$n)] = 1;
        }
        $this->expectException(\moodle_exception::class);
        batch::selection($many);
    }

    public function test_batch_start_accept_and_stale_revisions(): void {
        global $DB;
        [$company,$actor,$worker,$reviewer] = $this->fixture();
        $first=$this->task($company,$actor,$worker);
        $second=$this->task($company,$actor,$worker,$reviewer);
        $started=batch::run($company,$actor,[$first=>1,$second=>1],'start');
        $this->assertSame(['updated'=>2,'skipped'=>0,'total'=>2],$started);
        $this->assertSame('doing',task_service::readable_projection($company,$actor,$first)['status']);
        $before = $this->snapshot($company, $first);
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1],
            batch::run($company,$actor,[$first=>1],'start'));
        $this->assertEquals($before, $this->snapshot($company, $first));
        try {
            task_service::transition($company, $actor, $first, 1, 'submit', ['result'=>'Stale evidence']);
            $this->fail('The domain service must also reject stale revisions.');
        } catch (\moodle_exception $error) {
            $this->assertSame('taskcenter_conflict', $error->errorcode);
        }
        $this->assertEquals($before, $this->snapshot($company, $first));
        // The submit step still requires the canonical result; it is never bulk-skipped.
        task_service::transition($company,$actor,$second,2,'submit',['result'=>'Accepted evidence']);
        $revision=task_service::readable_projection($company,$actor,$second)['revision'];
        $before = $this->snapshot($company, $second);
        $this->setUser($worker);
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1],
            batch::run($company,$worker,[$second=>$revision],'accept'));
        $this->assertEquals($before, $this->snapshot($company, $second));
        $this->setUser($reviewer);
        $accepted=batch::run($company,$reviewer,[$second=>$revision],'accept');
        $this->assertSame(1,$accepted['updated']);
        $this->assertSame('done',task_service::readable_projection($company,$reviewer,$second)['status']);
        $after = $this->snapshot($company, $second);
        $this->assertSame($second, $after['task']->uuid);
        $this->assertSame($revision + 1, (int)$after['task']->revision);
        $this->assertSame(['create','start','submit','accept'], array_values(array_column($after['log'], 'action')));
        $last = end($after['log']);
        $this->assertSame($reviewer, (int)$last->actorid);
        $this->assertSame($company, $last->companyuuid);
        $this->assertSame($second, $last->taskuuid);
    }

    public function test_due_date_and_assignee_use_domain_save_with_revision_checks(): void {
        [$company,$actor,$worker,$replacement]=$this->fixture();
        $task=$this->task($company,$actor,$worker);
        $due=batch::run($company,$actor,[$task=>1],'duedate','2026-12-10');
        $this->assertSame(1,$due['updated']);
        $state=task_service::readable_projection($company,$actor,$task);
        $this->assertSame('2026-12-10',$state['due_date']);
        $changed=batch::run($company,$actor,[$task=>$state['revision']],'assignee','',$replacement);
        $this->assertSame(1,$changed['updated']);
        $this->assertSame($replacement,task_service::readable_projection($company,$actor,$task)['assignee_id']);
        $this->assertSame(0,batch::run($company,$actor,[$task=>1],'duedate','2026-12-11')['updated']);
    }

    public function test_generated_tasks_cannot_be_bulk_reassigned_and_cross_company_task_is_not_updated(): void {
        global $DB;
        [$company,$actor,$worker,$replacement] = $this->fixture();
        $task=$this->task($company,$actor,$worker);
        $DB->set_field('local_kbacompany_task','sourcekind','template',
            ['companyuuid'=>$company,'uuid'=>$task]);
        $before = $this->snapshot($company, $task);
        $skip=batch::run($company,$actor,[$task=>1],'assignee','',$replacement);
        $this->assertSame(0,$skip['updated']);
        $this->assertSame(1,$skip['skipped']);
        $this->assertSame($worker,task_service::readable_projection($company,$actor,$task)['assignee_id']);
        $this->assertEquals($before, $this->snapshot($company, $task));
        $otherid=repository::save_company((object)['name'=>'Other batch company','active'=>1]);
        $otheruuid=identity_registry::company_uuid($otherid);
        $otherworker = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$otherid, 'userid'=>$otherworker->id, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time(),
        ]);
        $other=$this->task($otheruuid,$actor,(int)$otherworker->id);
        $otherbefore = $this->snapshot($otheruuid, $other);
        $result=batch::run($company,$actor,[$other=>1],'start');
        $this->assertSame(0,$result['updated']);
        $this->assertSame('open',task_service::readable_projection($otheruuid,$actor,$other)['status']);
        $this->assertEquals($otherbefore, $this->snapshot($otheruuid, $other));
        $this->setUser($worker);
        $this->assertNull(task_service::readable_projection($company, $worker, $other));
        $this->assertSame(['updated'=>1,'skipped'=>1,'total'=>2],
            batch::run($company, $worker, [$task=>1, $other=>1], 'start'));
        $this->assertEquals($otherbefore, $this->snapshot($otheruuid, $other));
        $this->assertSame($task, $this->snapshot($company, $task)['task']->uuid);
    }

    private function snapshot(string $company, string $task): array {
        global $DB;
        return ['task'=>$DB->get_record('local_kbacompany_task',
            ['companyuuid'=>$company, 'uuid'=>$task], '*', MUST_EXIST),
            'log'=>$DB->get_records('local_kbacompany_tasklog',
                ['companyuuid'=>$company, 'taskuuid'=>$task], 'id ASC')];
    }
}
