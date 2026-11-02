<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacorporate\local\repository;

/** Task Deadlines 1.0: calendar correctness, ACL isolation and pre-pagination ordering. */
final class task_deadlines1843_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Task deadlines 1843', 'active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$employee->id, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time(),
        ]);
        return [$company, $actor, (int)$employee->id];
    }

    private function create(string $company, int $actor, int $assignee, string $title,
            string $duedate = '', string $priority = 'normal'): string {
        return task_service::create($company, $actor, [
            'title'=>$title, 'description'=>'Deadline acceptance', 'product'=>'Result',
            'assigneeid'=>$assignee, 'reviewerid'=>0, 'observerid'=>0,
            'priority'=>$priority, 'stream'=>'operate', 'startdate'=>'',
            'duedate'=>$duedate, 'planminutes'=>0, 'parentuuid'=>'',
        ]);
    }

    public function test_pure_bucket_boundaries_and_closed_semantics(): void {
        $dates = ['today'=>'2026-10-27', 'until'=>'2026-11-02'];
        foreach ([
            ['2026-10-26','open','overdue'], ['2026-10-27','open','today'],
            ['2026-10-28','doing','week'], ['2026-11-02','review','week'],
            ['2026-11-03','returned','later'], ['','open','undated'],
            ['2026-09-01','done','closed'], ['','cancelled','closed'],
        ] as [$due,$status,$expected]) {
            $this->assertSame($expected, task_service::deadline_bucket($due, $status, $dates));
        }
        $this->assertSame('', task_service::normalise_duegroup('closed; DROP TABLE tasks'));
        foreach (['overdue','today','week','later','undated','closed'] as $value) {
            $this->assertSame($value, task_service::normalise_duegroup($value));
        }
    }

    public function test_bucket_filter_and_global_counts_are_separate_and_acl_scoped(): void {
        global $DB;
        [$company,$actor,$worker] = $this->fixture();
        $dates = task_service::reporting_dates();
        $today = $dates['today'];
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $later = (new \DateTimeImmutable($dates['until']))->modify('+1 day')->format('Y-m-d');
        $this->create($company,$actor,$worker,'Overdue B',$yesterday);
        $this->create($company,$actor,$worker,'Overdue A',$yesterday,'high');
        $this->create($company,$actor,$worker,'Today',$today);
        $this->create($company,$actor,$worker,'Week',$tomorrow);
        $this->create($company,$actor,$worker,'Later',$later);
        $this->create($company,$actor,$worker,'Undated');
        $closed = $this->create($company,$actor,$worker,'Closed',$yesterday);
        $DB->set_field('local_kbacompany_task','status','done',
            ['companyuuid'=>$company,'uuid'=>$closed]);
        $otherid = repository::save_company((object)['name'=>'Isolated tasks','active'=>1]);
        $other = identity_registry::company_uuid($otherid);
        $this->create($other,$actor,$actor,'Other company task',$yesterday);
        $filter=['scope'=>'roles','deadlineview'=>true];
        $summary = task_service::deadline_summary($company,$actor,$filter);
        $this->assertSame(['total'=>7, 'overdue'=>2, 'today'=>1, 'week'=>1,
            'later'=>1, 'undated'=>1, 'closed'=>1], $summary);
        $first=task_service::list_page($company,$actor,$filter,0,3);
        $second=task_service::list_page($company,$actor,$filter,1,3);
        $third=task_service::list_page($company,$actor,$filter,2,3);
        $this->assertSame(7,$first['total']);
        $this->assertSame(['Overdue A','Overdue B','Today'],array_column($first['tasks'],'title'));
        $this->assertSame(['Week','Later','Undated'],array_column($second['tasks'],'title'));
        $this->assertSame(['Closed'],array_column($third['tasks'],'title'));
        $filter['duegroup']='overdue';
        $only=task_service::list_page($company,$actor,$filter);
        $this->assertSame(2,$only['total']);
        $this->assertSame(7,task_service::deadline_summary($company,$actor,$filter)['total']);
        $filter['duegroup']='closed';
        $this->assertSame(['Closed'],array_column(task_service::list_page($company,$actor,$filter)['tasks'],'title'));
    }

    public function test_deadlines_view_escapes_task_titles_and_preserves_scope_and_group(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode','owned','local_kbacompany');
        $dto = [
            'uuid'=>'11111111-1111-4111-8111-111111111111', 'title'=>'<script>evil()</script>',
            'product'=>'Product <unsafe>', 'status'=>'open', 'due_date'=>'',
            'priority'=>'high', 'effective_assignee_id'=>0, 'effective_assignee_name'=>'',
        ];
        $model = ['can_manage'=>false,'total'=>1,'page'=>0,'pagesize'=>25,
            'actor'=>2,'tasks'=>[$dto], 'scope_counts'=>[], 'filter_plans'=>[],
            'deadline_summary'=>['total'=>1,'overdue'=>0,'today'=>0,'week'=>0,
                'later'=>0,'undated'=>1,'closed'=>0]];
        $filters=['scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'',
            'battleplan'=>'','signal'=>'','assignee'=>0,'project'=>'','sort'=>'duedate','dir'=>'asc',
            'duegroup'=>'undated'];
        $html=ui::listing('company-test',$model,$filters,'deadlines');
        $this->assertStringContainsString('bms-task1843-workspace',$html);
        $this->assertStringContainsString('duegroup=undated',$html);
        $this->assertStringContainsString('bms-task1843-priority--high',$html);
        $this->assertStringNotContainsString('<script>evil()</script>',$html);
        $this->assertStringNotContainsString('<unsafe>',$html);
        $this->assertStringContainsString('bms-task1843-card--undated',$html);
    }
}
