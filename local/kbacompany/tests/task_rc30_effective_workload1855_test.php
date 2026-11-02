<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** RC30: management drill-down, list, and risks must identify the same effective executor. */
final class task_rc30_effective_workload1855_test extends \advanced_testcase {
    private function member(int $companyid, int $userid, bool $manager = false): void {
        global $DB;
        if ($DB->record_exists('local_kbacorp_member', ['companyid'=>$companyid, 'userid'=>$userid])) {
            return;
        }
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>$userid, 'membertype'=>'employee',
            'isemployee'=>1, 'ismanager'=>$manager ? 1 : 0,
            'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time(),
        ]);
    }

    public function test_manager_report_and_drilldown_use_effective_executor_without_tenant_leak(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $cid = repository::save_company((object)['name'=>'RC30 delegate tenant A','active'=>1]);
        $company = identity_registry::company_uuid($cid);
        $otherid = repository::save_company((object)['name'=>'RC30 delegate tenant B','active'=>1]);
        $other = identity_registry::company_uuid($otherid);
        $this->member($cid, $actor, true);
        $this->member($otherid, $actor, true);
        $primary = $this->getDataGenerator()->create_user(['firstname'=>'Primary', 'lastname'=>'Employee']);
        $delegate = $this->getDataGenerator()->create_user(['firstname'=>'Delegate', 'lastname'=>'Employee']);
        $this->member($cid, (int)$primary->id);
        $this->member($cid, (int)$delegate->id);
        $position = '55555555-5555-4555-8555-555555555555';
        $now = time();
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company,'branchuuid'=>'','orgnodeuuid'=>'',
            'uuid'=>$position,'code'=>'RC30-TEST','name'=>'Temporary coverage role',
            'ismanager'=>0,'plannedcount'=>1,'sortorder'=>0,'active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company,'positionuuid'=>$position,'slotuuid'=>'',
            'userid'=>(int)$primary->id,'assignmenttype'=>'primary','assignmentfte'=>1,
            'effectivefrom'=>0,'effectiveto'=>0,'active'=>1,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $date = \local_kbacompany\local\position_coverage_service::day(gmdate('Y-m-d'));
        $DB->insert_record('local_kbacompany_poscover', (object)[
            'companyuuid'=>$company,'uuid'=>'66666666-6666-4666-8666-666666666666',
            'positionuuid'=>$position,'primaryid'=>(int)$primary->id,
            'delegateid'=>(int)$delegate->id,'scope'=>'position_work',
            'startdate'=>$date,'enddate'=>$date+86400,'reason'=>'RC30 coverage test',
            'status'=>'approved','revision'=>1,'createdby'=>$actor,
            'approvedby'=>$actor,'approvedat'=>$now,
            'timecreated'=>$now,'timemodified'=>$now,
        ]);
        $taskdata = static function(int $userid, string $title) use ($actor): array {
            return ['title'=>$title,'product'=>'Expected result','description'=>'',
                'assigneeid'=>$userid,'reviewerid'=>$actor,'observerid'=>0,
                'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'2020-01-01',
                'planminutes'=>0,'parentuuid'=>''];
        };
        $new = task_service::create($company,$actor,$taskdata((int)$primary->id,'Delegated task'));
        $row = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company,'uuid'=>$new], '*', MUST_EXIST);
        $row->dutypositionuuid = $position;
        $DB->update_record('local_kbacompany_task', $row);
        $historic = task_service::create($company,$actor,$taskdata((int)$primary->id,'Historical task'));
        $historyrow = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company,'uuid'=>$historic], '*', MUST_EXIST);
        $historyrow->dutypositionuuid = $position;
        $historyrow->status = 'done';
        $DB->update_record('local_kbacompany_task', $historyrow);
        task_service::create($other,$actor,$taskdata($actor,'Other company task'));

        $report = task_service::execution_report($company,$actor,['scope'=>'all']);
        $this->assertSame(2,$report['summary']['total']);
        $byid = array_column($report['assignees'], null, 'userid');
        $this->assertSame(1, $byid[(int)$delegate->id]['active']);
        $this->assertSame(1, $byid[(int)$delegate->id]['overdue']);
        $this->assertSame(1, $byid[(int)$primary->id]['done']);
        $this->assertSame($byid[(int)$delegate->id]['name'], $report['risks'][0]['assignee']);
        $delegatepage = task_service::list_page($company,$actor,
            ['scope'=>'all','assignee'=>(int)$delegate->id]);
        $this->assertSame(1, $delegatepage['total']);
        $this->assertSame($new, $delegatepage['tasks'][0]['uuid']);
        $primarypage = task_service::list_page($company,$actor,
            ['scope'=>'all','assignee'=>(int)$primary->id]);
        $this->assertSame(1, $primarypage['total']);
        $this->assertSame($historic, $primarypage['tasks'][0]['uuid']);
    }
}
