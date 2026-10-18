<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_week_launch_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Regression source for explicit, idempotent management-week launch. */
final class battle_plan_week_launch_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Week launch', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([[$employee, 0], [$reviewer, 1]] as [$user, $manager]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => $manager, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $positionuuid = '12000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $company, 'branchuuid' => '12000000-0000-4000-8000-000000000002',
            'orgnodeuuid' => '12000000-0000-4000-8000-000000000003', 'uuid' => $positionuuid,
            'code' => 'P-WEEK', 'name' => 'Historical position', 'purpose' => 'Weekly result', 'ismanager' => 0,
            'plannedcount' => 1, 'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $from = strtotime('2026-08-01 00:00:00 UTC');
        $to = strtotime('2026-08-31 23:59:59 UTC');
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid' => $company, 'positionuuid' => $positionuuid, 'slotuuid' => '',
            'userid' => (int)$employee->id, 'assignmenttype' => 'primary', 'effectivefrom' => $from,
            'effectiveto' => $to, 'active' => 0, 'timecreated' => $from, 'timemodified' => $to,
        ]);
        return compact('admin', 'company', 'employee', 'reviewer', 'positionuuid');
    }

    public function test_contract_requires_explicit_selection_and_no_second_registry(): void {
        $contract = battle_plan_week_launch_service::contract();
        $this->assertSame('0.5.12', $contract['version']);
        $this->assertSame('local_kbacompany_bplan only', $contract['storage']);
        $this->assertContains('launch_requires_explicit_selected_assignments', $contract['rules']);
        $this->assertContains('assignment_presence_never_auto_creates_plan', $contract['rules']);
        $this->assertContains('repeated_launch_is_idempotent', $contract['rules']);
    }

    public function test_historical_assignment_launch_is_idempotent(): void {
        global $DB;
        $f = $this->fixture();
        $token = battle_plan_week_launch_service::candidate_token((int)$f['employee']->id, $f['positionuuid']);
        $first = battle_plan_week_launch_service::launch($f['company'], $f['admin'], '2026-08-12', [$token],
            (int)$f['reviewer']->id, 0);
        $this->assertSame(1, $first['created_count']);
        $this->assertSame(0, $first['reused_count']);
        $plan = $DB->get_record('local_kbacompany_bplan', ['uuid' => $first['created'][0]['uuid']], '*', MUST_EXIST);
        $this->assertSame('2026-08-10', $plan->periodstart);
        $this->assertSame((int)$f['employee']->id, (int)$plan->userid);
        $this->assertSame((int)$f['reviewer']->id, (int)$plan->reviewerid);

        $second = battle_plan_week_launch_service::launch($f['company'], $f['admin'], '2026-08-12', [$token],
            (int)$f['reviewer']->id, 0);
        $this->assertSame(0, $second['created_count']);
        $this->assertSame(1, $second['reused_count']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_bplan', [
            'companyuuid' => $f['company'], 'userid' => (int)$f['employee']->id,
            'positionuuid' => $f['positionuuid'], 'periodstart' => '2026-08-10',
        ]));
    }
}
