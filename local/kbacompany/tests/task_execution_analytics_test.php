<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** build2026101836: permission-scoped task analytics regression tests. */
final class task_execution_analytics_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Analytics test','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        if (!$DB->record_exists('local_kbacorp_member',['companyid'=>$companyid,'userid'=>$actor])) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid,'userid'=>$actor,'membertype'=>'employee',
                'isemployee'=>1,'ismanager'=>1,'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>time(),'timemodified'=>time(),
            ]);
        }
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$employee->id, 'membertype'=>'employee',
            'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
            'timecreated'=>time(),'timemodified'=>time(),
        ]);
        return ['actor'=>$actor,'company'=>$company,'worker'=>(int)$employee->id];
    }

    private function make_task(array $fixture, string $name, int $assignee, string $due = ''): string {
        return task_service::create($fixture['company'],$fixture['actor'],[
            'title'=>$name,'product'=>'Outcome of '.$name,'description'=>'',
            'assigneeid'=>$assignee,'reviewerid'=>0,'priority'=>'normal','stream'=>'operate',
            'startdate'=>'','duedate'=>$due,'planminutes'=>0,
        ]);
    }

    public function test_aggregate_totals_are_not_limited_to_first_page(): void {
        $f = $this->fixture();
        for ($n=0; $n<28; $n++) {
            $this->make_task($f,'Task '.$n,$f['worker'],'2020-01-01');
        }
        $filters=['scope'=>'all'];
        $list=task_service::list_page($f['company'],$f['actor'],$filters);
        $report=task_service::execution_report($f['company'],$f['actor'],$filters);
        $this->assertCount(25,$list['tasks']);
        $this->assertSame(28,$report['summary']['total']);
        $this->assertSame(28,$report['assignees'][0]['overdue']);
    }

    public function test_assignee_filter_restricts_every_report_section(): void {
        $f=$this->fixture();
        $this->make_task($f,'Assigned employee',$f['worker'],'2020-01-01');
        $this->make_task($f,'Assigned manager',$f['actor'],'2020-01-01');
        $report=task_service::execution_report($f['company'],$f['actor'],
            ['scope'=>'all','assignee'=>$f['worker']]);
        $this->assertSame(1,$report['summary']['total']);
        $this->assertCount(1,$report['risks']);
        $this->assertSame($f['worker'],$report['assignees'][0]['userid']);
    }

    public function test_analytics_preserves_personal_scope_and_only_direct_project_context(): void {
        global $DB;
        $f=$this->fixture();
        $one=$this->make_task($f,'Own responsibility',$f['actor']);
        $two=$this->make_task($f,'Delegated responsibility',$f['worker']);
        $personal=task_service::execution_report($f['company'],$f['actor'],['scope'=>'my']);
        $this->assertSame(1,$personal['summary']['total']);
        $projectuuid='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        // Data fixture simulates an already linked historical record, no writes via report.
        $row=$DB->get_record('local_kbacompany_task',['uuid'=>$one], '*', MUST_EXIST);
        $row->contextkind='project'; $row->contextuuid=$projectuuid; $row->contextrevision=1;
        $DB->update_record('local_kbacompany_task',$row);
        $filtered=task_service::execution_report($f['company'],$f['actor'],
            ['scope'=>'all','project'=>$projectuuid]);
        $this->assertSame(1,$filtered['summary']['total']);
        $this->assertSame($projectuuid,$filtered['projects'][0]['uuid']);
    }

    public function test_overdue_subtask_signal_has_drilldown_to_only_child(): void {
        global $DB;
        $f=$this->fixture();
        $parent=$this->make_task($f,'Parent',$f['worker'],'2020-01-01');
        $child=task_service::create($f['company'],$f['actor'],[
            'title'=>'Child','product'=>'Outcome','assigneeid'=>$f['worker'],
            'reviewerid'=>0,'priority'=>'normal','stream'=>'operate','startdate'=>'',
            'duedate'=>'2020-01-01','planminutes'=>0,'parentuuid'=>$parent,
        ]);
        $report=task_service::execution_report($f['company'],$f['actor'],['scope'=>'all']);
        $this->assertSame(1,$report['summary']['subtaskoverdue']);
        $page=task_service::list_page($f['company'],$f['actor'],
            ['scope'=>'all','signal'=>'subtaskoverdue']);
        $this->assertSame(1,$page['total']);
        $this->assertSame($child,$page['tasks'][0]['uuid']);
    }

    /** build2026101837: the Open card must drill down to open tasks only. */
    public function test_open_summary_drilldown_excludes_done_and_cancelled(): void {
        $f = $this->fixture();
        $this->make_task($f, 'Not finished', $f['worker']);
        $finished = $this->make_task($f, 'Finished', $f['worker']);
        $cancelled = $this->make_task($f, 'Cancelled', $f['worker']);
        global $DB;
        // A report read must use the persisted status, not inferred UI state.
        foreach ([$finished => 'done', $cancelled => 'cancelled'] as $uuid => $state) {
            $record = $DB->get_record('local_kbacompany_task', ['uuid' => $uuid], '*', MUST_EXIST);
            $record->status = $state;
            $DB->update_record('local_kbacompany_task', $record);
        }
        $counts = task_service::list_page($f['company'], $f['actor'], ['scope'=>'all'])['counts'];
        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['open']);
        $this->assertSame(1, $counts['cancelled']);
        $page = task_service::list_page($f['company'], $f['actor'],
            ['scope'=>'all', 'signal'=>'open']);
        $this->assertSame(1, $page['total']);
        $this->assertSame('Not finished', $page['tasks'][0]['title']);
    }

    /** Drill-down never discards an explicit assignee restriction. */
    public function test_open_drilldown_preserves_assignee_scope(): void {
        $f = $this->fixture();
        $this->make_task($f, 'Employee pending', $f['worker']);
        $this->make_task($f, 'Manager pending', $f['actor']);
        $page = task_service::list_page($f['company'], $f['actor'],
            ['scope'=>'all', 'assignee'=>$f['worker'], 'signal'=>'open']);
        $this->assertSame(1, $page['total']);
        $this->assertSame('Employee pending', $page['tasks'][0]['title']);
    }

    /** Build1839: list drill-down dates must agree with the summary calendar window. */
    public function test_seven_calendar_days_drilldown_matches_report(): void {
        $f = $this->fixture();
        $dates = task_service::reporting_dates();
        $today = new \DateTimeImmutable($dates['today'], new \DateTimeZone('UTC'));
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $lastincluded = $today->modify('+6 days')->format('Y-m-d');
        $firstexcluded = $today->modify('+7 days')->format('Y-m-d');
        $this->make_task($f, 'Yesterday overdue', $f['worker'], $yesterday);
        $this->make_task($f, 'Today due', $f['worker'], $dates['today']);
        $this->make_task($f, 'Six days later', $f['worker'], $lastincluded);
        $this->make_task($f, 'Seven days later', $f['worker'], $firstexcluded);
        $filters = ['scope' => 'all'];
        $report = task_service::execution_report($f['company'], $f['actor'], $filters);
        $this->assertSame(2, $report['summary']['soon']);
        $this->assertSame(1, $report['assignees'][0]['overdue']);
        $soon = task_service::list_page($f['company'], $f['actor'],
            $filters + ['signal' => 'soon']);
        $this->assertSame(2, $soon['total']);
        $overdue = task_service::list_page($f['company'], $f['actor'],
            $filters + ['signal' => 'overdue']);
        $this->assertSame(1, $overdue['total']);
        $this->assertSame('Yesterday overdue', $overdue['tasks'][0]['title']);
    }

}
