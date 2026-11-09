<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_batch_actions_service as batch;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Task membership, revision and source guards through actual domain services. */
final class task_membership_regression_test extends \advanced_testcase {
    private function fixture(bool $legacy = false): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $users = [];
        $companyid = 0;
        $company = '11111111-1111-4111-8111-111111111111';
        if ($legacy) {
            $companyid = repository::save_company((object)['name'=>'Task membership legacy', 'active'=>1]);
            $company = identity_registry::company_uuid($companyid);
        } else {
            $DB->insert_record('local_kbacompany_company', (object)[
                'uuid'=>$company, 'name'=>'Task membership native', 'sourcekind'=>'bms',
                'legacycompanyid'=>0, 'bmsclient'=>1, 'active'=>1,
            ]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = (int)$user->id;
            if ($legacy) {
                $DB->insert_record('local_kbacorp_member', (object)[
                    'companyid'=>$companyid, 'userid'=>$user->id, 'membertype'=>'employee',
                    'isemployee'=>1, 'ismanager'=>0, 'isdepartmentmanager'=>0, 'ishr'=>0,
                    'timecreated'=>time(), 'timemodified'=>time(),
                ]);
            } else {
                $DB->insert_record('local_kbacompany_member', (object)[
                    'uuid'=>sprintf('22222222-2222-4222-8222-%012d', $i),
                    'companyuuid'=>$company, 'userid'=>$user->id, 'active'=>1, 'isemployee'=>1,
                ]);
            }
            $this->assertFalse(is_siteadmin($user->id));
            $this->assertFalse(has_capability('local/kbacompany:viewallcompanies', \context_system::instance(), $user->id));
        }
        return [$company, $companyid, $admin, $users];
    }

    private function task(string $company, int $admin, int $worker, int $reviewer = 0): string {
        return task_service::create($company, $admin, [
            'title'=>'Membership regression', 'description'=>'Guarded task', 'product'=>'Reviewed evidence',
            'assigneeid'=>$worker, 'reviewerid'=>$reviewer, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
    }

    private function snapshot(): array {
        global $DB;
        return ['tasks'=>$DB->get_records('local_kbacompany_task', null, 'id ASC'),
            'audit'=>$DB->get_records('local_kbacompany_tasklog', null, 'id ASC')];
    }

    private function denied(callable $operation, string $code): void {
        $before = $this->snapshot();
        try {
            $operation();
            $this->fail('Operation must be denied.');
        } catch (\moodle_exception $error) {
            $this->assertSame($code, $error->errorcode);
        }
        $this->assertEquals($before, $this->snapshot());
    }

    public static function revocation_cases(): array {
        return ['native inactive'=>[false, false], 'native removed'=>[false, true], 'legacy removed'=>[true, true]];
    }

    /** @dataProvider revocation_cases */
    public function test_revocation_blocks_reads_batches_and_deadlines_without_new_session(bool $legacy, bool $remove): void {
        global $DB;
        [$company, $companyid, $admin, $users] = $this->fixture($legacy);
        $worker = $users[0];
        $task = $this->task($company, $admin, $worker);
        $this->setUser($worker);
        $this->assertSame($task, task_service::readable_projection($company, $worker, $task)['uuid']);
        $this->assertSame(1, task_service::deadline_summary($company, $worker)['total']);
        $this->assertSame(1, task_service::list_page($company, $worker)['total']);
        $this->assertSame(['updated'=>1,'skipped'=>0,'total'=>1], batch::run($company, $worker, [$task=>1], 'start'));
        if ($legacy) {
            $DB->delete_records('local_kbacorp_member', ['companyid'=>$companyid, 'userid'=>$worker]);
        } else {
            $scope = ['companyuuid'=>$company, 'userid'=>$worker];
            if ($remove) { $DB->delete_records('local_kbacompany_member', $scope); }
            else { $DB->set_field('local_kbacompany_member', 'active', 0, $scope); }
        }
        // Preserve the session, task assignee and audit: none is a substitute for membership.
        $this->denied(static function() use ($company, $worker, $task): void {
            task_service::readable_projection($company, $worker, $task);
        }, 'companynotavailable');
        $this->denied(static function() use ($company, $worker, $task): void {
            batch::run($company, $worker, [$task=>2], 'start');
        }, 'companynotavailable');
        $this->denied(static function() use ($company, $worker): void {
            task_service::deadline_summary($company, $worker);
        }, 'companynotavailable');
        $this->denied(static function() use ($company, $worker): void {
            task_service::list_page($company, $worker);
        }, 'companynotavailable');
    }

    public static function unavailable_participants(): array {
        return ['assignee'=>[false], 'reviewer'=>[true]];
    }

    /** @dataProvider unavailable_participants */
    public function test_nonmember_participant_cannot_be_assigned_even_by_admin(bool $reviewer): void {
        [$company, , $admin, $users] = $this->fixture();
        $outsider = (int)$this->getDataGenerator()->create_user()->id;
        $this->denied(function() use ($company, $admin, $users, $outsider, $reviewer): void {
            $this->task($company, $admin, $reviewer ? $users[0] : $outsider, $reviewer ? $outsider : 0);
        }, 'taskcenter_employee');
    }

    public function test_observer_visibility_does_not_allow_start_or_accept(): void {
        [$company, , $admin, $users] = $this->fixture();
        [$worker, $reviewer, $observer] = $users;
        $task = $this->task($company, $admin, $worker, $reviewer);
        task_service::set_observer($company, $admin, $task, 1, $observer);
        $this->setUser($observer);
        $this->assertSame($task, task_service::readable_projection($company, $observer, $task)['uuid']);
        $before = $this->snapshot();
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1], batch::run($company, $observer, [$task=>2], 'start'));
        $this->assertEquals($before, $this->snapshot());
        $this->setUser($worker);
        task_service::transition($company, $worker, $task, 2, 'submit', ['result'=>'Worker evidence']);
        $this->setUser($observer);
        $before = $this->snapshot();
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1], batch::run($company, $observer, [$task=>3], 'accept'));
        $this->assertEquals($before, $this->snapshot());
        $this->setUser($reviewer);
        $this->assertSame(['updated'=>1,'skipped'=>0,'total'=>1], batch::run($company, $reviewer, [$task=>3], 'accept'));
    }

    public function test_generated_task_retains_source_uuid_revision_and_audit_after_reassignment_denial(): void {
        global $DB;
        [$company, , $admin, $users] = $this->fixture();
        $data = ['product'=>'Weekly result', 'description'=>'From real template', 'stream'=>'operate',
            'priority'=>'normal', 'startdate'=>'2026-09-14', 'duedate'=>'2026-09-20', 'planminutes'=>30];
        $task = task_service::ensure_from_library_template($company, $admin, 'rtpl_executive_10', $users[0], 0, '2026-09-14', $data);
        $row = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$company, 'uuid'=>$task], '*', MUST_EXIST);
        $this->assertSame('template', $row->sourcekind);
        $this->assertNotEmpty($row->sourceuuid);
        $this->assertGreaterThan(0, (int)$row->sourcerevision);
        $before = $this->snapshot();
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1], batch::run($company, $admin, [$task=>1], 'assignee', '', $users[1]));
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($task, task_service::ensure_from_library_template($company, $admin, 'rtpl_executive_10', $users[0], 0, '2026-09-14', $data));
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_company_grant_requires_moodle_capability_and_revocation_is_effective(): void {
        [$company, , $admin, $users] = $this->fixture();
        [$editor, $worker] = $users;
        $task = $this->task($company, $admin, $worker);
        $role = access_role_service::create_role($company, $admin, 'Task editor regression', '');
        access_role_service::set_grant($company, $admin, $role, 'task', 'edit', true);
        access_role_service::assign($company, $admin, $role, $editor, true);
        $this->setUser($editor);
        $this->assertTrue(task_service::can_manage($company, $editor));
        $this->assertSame(['updated'=>1,'skipped'=>0,'total'=>1], batch::run($company, $editor, [$task=>1], 'duedate', '2026-12-10'));
        $before = $this->snapshot();
        $context = \context_system::instance();
        $denyrole = create_role('Deny base task visibility', 'taskbaseprohibit', 'Regression only');
        assign_capability('local/kbacompany:viewowncompany', CAP_PROHIBIT, $denyrole, $context->id);
        role_assign($denyrole, $editor, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(task_service::can_manage($company, $editor));
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1], batch::run($company, $editor, [$task=>2], 'duedate', '2026-12-11'));
        $this->assertEquals($before, $this->snapshot());
        role_unassign($denyrole, $editor, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(task_service::can_manage($company, $editor));
        $this->setAdminUser();
        access_role_service::assign($company, $admin, $role, $editor, false);
        $this->setUser($editor);
        $this->assertFalse(task_service::can_manage($company, $editor));
        $this->assertSame(['updated'=>0,'skipped'=>1,'total'=>1], batch::run($company, $editor, [$task=>2], 'duedate', '2026-12-11'));
        $this->assertEquals($before, $this->snapshot());
    }
}
