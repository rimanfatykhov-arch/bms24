<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\position_coverage_service as coverage;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Run with Moodle PHPUnit DB; these tests must not be mistaken for production acceptance. */
final class position_coverage_handover_regression_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'Coverage end-to-end QA','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $primary = $this->getDataGenerator()->create_user();
        $delegate = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([$primary, $delegate] as $u) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid, 'userid'=>$u->id, 'membertype'=>'employee',
                'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
                'timecreated'=>$now, 'timemodified'=>$now,
            ]);
        }
        $position = '35d0e897-54f5-41e5-ad1a-359ee8d0edaa';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid'=>$company, 'uuid'=>$position, 'branchuuid'=>'', 'orgnodeuuid'=>'',
            'code'=>'COVERAGE_QA', 'name'=>'QA Position', 'active'=>1,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid'=>$company, 'positionuuid'=>$position, 'userid'=>$primary->id,
            'assignmenttype'=>'primary', 'assignmentfte'=>1, 'active'=>1,
            'effectivefrom'=>0, 'effectiveto'=>0,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        $task = task_service::create($company, $manager, [
            'title'=>'Company position work', 'product'=>'Completed report', 'description'=>'',
            'assigneeid'=>$primary->id, 'reviewerid'=>0, 'priority'=>'normal', 'stream'=>'run',
            'startdate'=>gmdate('Y-m-d'), 'duedate'=>gmdate('Y-m-d', $now + 86400),
            'dutypositionuuid'=>$position, 'dutyconfirmed'=>1,
        ]);
        $personal = task_service::create($company, $manager, [
            'title'=>'Personal task', 'product'=>'Personal result', 'description'=>'',
            'assigneeid'=>$primary->id, 'reviewerid'=>0, 'priority'=>'normal', 'stream'=>'run',
            'startdate'=>gmdate('Y-m-d'), 'duedate'=>gmdate('Y-m-d', $now + 86400),
        ]);
        $cov = '74e59873-56d0-4f3e-8aba-535019208e93';
        $DB->insert_record('local_kbacompany_poscover', (object)[
            'companyuuid'=>$company, 'uuid'=>$cov, 'positionuuid'=>$position,
            'primaryid'=>$primary->id, 'delegateid'=>$delegate->id, 'scope'=>'position_work',
            'startdate'=>coverage::day(gmdate('Y-m-d')),
            'enddate'=>coverage::day(gmdate('Y-m-d', $now + 2 * 86400)),
            'reason'=>'Acting coverage QA scenario', 'status'=>'approved', 'revision'=>2,
            'createdby'=>$manager, 'approvedby'=>$manager, 'approvedat'=>$now,
            'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        return compact('company','manager','primary','delegate','position','task','personal','cov');
    }

    public function test_personal_work_is_not_delegated_and_cancel_reverts_position_work(): void {
        global $DB;
        $f = $this->fixture();
        $duty = $DB->get_record('local_kbacompany_task', ['uuid'=>$f['task']], '*', MUST_EXIST);
        $personal = $DB->get_record('local_kbacompany_task', ['uuid'=>$f['personal']], '*', MUST_EXIST);
        $this->assertSame((int)$f['delegate']->id, task_service::effective_assignee($duty));
        $this->assertSame((int)$f['primary']->id, task_service::effective_assignee($personal));
        $this->setUser($f['delegate']);
        $mine = task_service::list_page($f['company'], (int)$f['delegate']->id, ['scope'=>'my']);
        $this->assertSame(1, $mine['total']);
        $this->assertSame($f['task'], $mine['tasks'][0]['uuid']);
        $this->setAdminUser();
        coverage::transition($f['company'], $f['manager'], $f['cov'], 2,
            'cancel', 'Coverage finished early');
        $updated = $DB->get_record('local_kbacompany_task', ['uuid'=>$f['task']], '*', MUST_EXIST);
        $this->assertSame((int)$f['primary']->id, task_service::effective_assignee($updated));
        $this->assertSame((int)$f['primary']->id, (int)$updated->assigneeid);
        $this->assertSame($f['task'], (string)$updated->uuid);
        $this->setUser($f['primary']);
        $mine = task_service::list_page($f['company'], (int)$f['primary']->id, ['scope'=>'my']);
        $this->assertSame(2, $mine['total']);
        $this->setUser($f['delegate']);
        $mine = task_service::list_page($f['company'], (int)$f['delegate']->id, ['scope'=>'my']);
        $this->assertSame(0, $mine['total']);
    }

    public function test_archived_position_stops_delegation_in_both_sql_and_task_card(): void {
        global $DB;
        $f = $this->fixture();
        $row = $DB->get_record('local_kbacompany_task', ['uuid'=>$f['task']], '*', MUST_EXIST);
        $this->assertSame((int)$f['delegate']->id, task_service::effective_assignee($row));
        $position = $DB->get_record('local_kbacompany_position', ['uuid'=>$f['position']], '*', MUST_EXIST);
        $position->active = 0;
        $DB->update_record('local_kbacompany_position', $position);
        $this->assertSame((int)$f['primary']->id, task_service::effective_assignee($row));
        $this->setUser($f['delegate']);
        $mine = task_service::list_page($f['company'], (int)$f['delegate']->id, ['scope'=>'my']);
        $this->assertSame(0, $mine['total']);
    }

    public function test_failed_approval_rolls_back_before_next_transaction(): void {
        global $DB;
        $f = $this->fixture();
        $dupe = '8bc83572-4d75-43a1-8c68-3640f8be17ee';
        $original = $DB->get_record('local_kbacompany_poscover', ['uuid'=>$f['cov']], '*', MUST_EXIST);
        $copy = clone $original;
        unset($copy->id);
        $copy->uuid = $dupe;
        $copy->status = 'requested';
        $copy->revision = 1;
        $copy->approvedby = 0;
        $copy->approvedat = 0;
        $DB->insert_record('local_kbacompany_poscover', $copy);
        try {
            coverage::transition($f['company'], $f['manager'], $dupe, 1, 'approve');
            $this->fail('Overlapping coverage must not be approved');
        } catch (\moodle_exception $ex) {
            $this->assertSame('coverage_overlap', $ex->errorcode);
        }
        $requested = $DB->get_record('local_kbacompany_poscover', ['uuid'=>$dupe], '*', MUST_EXIST);
        $this->assertSame('requested', (string)$requested->status);
        $this->assertSame(1, (int)$requested->revision);
        // A new DB write after a rejected approval must not run in a hanging transaction.
        $requested->reason = 'Still awaiting resolution';
        $DB->update_record('local_kbacompany_poscover', $requested);
        $this->assertSame('Still awaiting resolution',
            $DB->get_field('local_kbacompany_poscover', 'reason', ['uuid'=>$dupe]));
    }
}
