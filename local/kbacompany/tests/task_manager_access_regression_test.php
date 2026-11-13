<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service as acl;
use local_kbacompany\local\task_service as tasks;
use local_kbacompany\local\task_ui_service as ui;

/** Real service boundaries behind manager/list/analytics; no mocked authorization. */
final class task_manager_access_regression_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companies = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
        foreach ($companies as $i=>$company) {
            $DB->insert_record('local_kbacompany_company', (object)[
                'uuid'=>$company, 'name'=>'Workspace tenant '.$i, 'sourcekind'=>'bms',
                'legacycompanyid'=>0, 'bmsclient'=>1, 'active'=>1,
            ]);
        }
        $users = []; $ids = []; $projects = [];
        foreach ([0, 0, 1] as $i=>$tenant) {
            $user = (int)$this->getDataGenerator()->create_user()->id;
            $users[] = $user;
            $DB->insert_record('local_kbacompany_member', (object)[
                'uuid'=>sprintf('33333333-3333-4333-8333-%012d', $i + 1),
                'companyuuid'=>$companies[$tenant], 'userid'=>$user, 'active'=>1, 'isemployee'=>1,
            ]);
            $this->assertFalse(is_siteadmin($user));
            $this->assertFalse(has_capability('local/kbacompany:viewallcompanies', \context_system::instance(), $user));
            $ids[] = tasks::create($companies[$tenant], $admin, [
                'title'=>'Private task '.$i, 'product'=>'Private result '.$i, 'description'=>'',
                'assigneeid'=>$user, 'reviewerid'=>0, 'observerid'=>0, 'priority'=>'normal',
                'stream'=>'operate', 'startdate'=>'', 'duedate'=>'2020-01-01', 'planminutes'=>0,
            ]);
            // Historical project reference fixture, as in task_execution_analytics_test.
            $projects[] = sprintf('44444444-4444-4444-8444-%012d', $i + 1);
            $DB->set_field('local_kbacompany_task', 'contextkind', 'project', ['uuid'=>$ids[$i]]);
            $DB->set_field('local_kbacompany_task', 'contextuuid', $projects[$i], ['uuid'=>$ids[$i]]);
            $DB->set_field('local_kbacompany_task', 'contextrevision', 1, ['uuid'=>$ids[$i]]);
        }
        return compact('admin', 'companies', 'users', 'ids', 'projects');
    }

    private function scoped(array $f, array $visible, bool $manager): void {
        global $DB;
        $company = $f['companies'][0]; $actor = $f['users'][0];
        $before = $DB->get_records('local_kbacompany_task', null, 'id ASC');
        $audit = $DB->get_records('local_kbacompany_tasklog', null, 'id ASC');
        $this->assertSame($manager, tasks::can_manage($company, $actor));
        // A crafted manager/all request must not widen service visibility.
        $filters = ['scope'=>'all', 'display'=>'manager'];
        $list = tasks::list_page($company, $actor, $filters);
        $report = tasks::execution_report($company, $actor, $filters);
        $this->assertSame($manager, $list['can_manage']);
        $this->assertSame(count($visible), $list['total']);
        $this->assertSame(count($visible), $report['summary']['total']);
        $this->assertSame(count($visible), $list['counts']['overdue']);
        $this->assertSame(count($visible), array_sum(array_column($report['assignees'], 'overdue')));
        foreach ([['tasks','uuid',$list,'ids'], ['risks','uuid',$report,'ids'],
                ['assignees','userid',$report,'users'], ['projects','uuid',$report,'projects']] as $spec) {
            [$key, $field, $source, $expectedkey] = $spec;
            $expected = array_map(static function($i) use ($f, $expectedkey) { return $f[$expectedkey][$i]; }, $visible);
            $actual = array_column($source[$key], $field);
            sort($expected); sort($actual);
            $this->assertSame($expected, $actual, $key.' must use the same tenant/permission scope');
        }
        // Exercise the renderer with real permission-scoped model data, not a fake grant.
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $model = $list + ['actor'=>$actor, 'execution_report'=>$report,
            'scope_counts'=>tasks::scope_counts($company, $actor), 'filter_plans'=>[], 'plan_memberships'=>[]];
        $renderfilters = $filters + ['q'=>'', 'status'=>'', 'stream'=>'', 'sourcekind'=>'',
            'battleplan'=>'', 'signal'=>'', 'assignee'=>0, 'project'=>'', 'sort'=>'duedate', 'dir'=>'asc'];
        $html = ui::listing($company, $model, $renderfilters, 'manager');
        $this->assertSame($manager, strpos($html, 'bms-task1853-workspace') !== false);
        $this->assertSame($manager, strpos($html, 'display=manager') !== false);
        $this->assertStringContainsString('Private task 0', $html);
        $this->assertSame($manager, strpos($html, 'Private task 1') !== false);
        $this->assertStringNotContainsString('Private task 2', $html);
        $this->assertStringNotContainsString($f['ids'][2], $html);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_task', null, 'id ASC'));
        $this->assertEquals($audit, $DB->get_records('local_kbacompany_tasklog', null, 'id ASC'));
    }

    private function grant(array $f): int {
        $role = acl::create_role($f['companies'][0], $f['admin'], 'Workspace task editor', '');
        acl::set_grant($f['companies'][0], $f['admin'], $role, 'task', 'edit', true);
        acl::assign($f['companies'][0], $f['admin'], $role, $f['users'][0], true);
        return $role;
    }

    private function denied(callable $call): void {
        try {
            $call();
            $this->fail('Unavailable company must reject the read');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
    }

    public function test_non_manager_all_filter_keeps_personal_data_and_denies_foreign_company(): void {
        $f = $this->fixture();
        $this->setUser($f['users'][0]);
        $this->scoped($f, [0], false);
        $this->denied(static function() use ($f): void {
            tasks::list_page($f['companies'][1], $f['users'][0], ['scope'=>'all']);
        });
        $this->denied(static function() use ($f): void {
            tasks::execution_report($f['companies'][1], $f['users'][0], ['scope'=>'all']);
        });
    }

    public static function revoked_grants(): array {
        return ['assignment'=>['assignment'], 'grant'=>['grant'], 'inactive role'=>['role']];
    }

    /** @dataProvider revoked_grants */
    public function test_explicit_manager_grant_is_company_scoped_and_revocation_is_immediate(string $kind): void {
        global $DB;
        $f = $this->fixture(); $role = $this->grant($f);
        $this->setUser($f['users'][0]);
        $this->scoped($f, [0, 1], true);
        $this->denied(static function() use ($f): void {
            tasks::execution_report($f['companies'][1], $f['users'][0], ['scope'=>'all']);
        });
        if ($kind === 'assignment') {
            acl::assign($f['companies'][0], $f['admin'], $role, $f['users'][0], false);
        } else if ($kind === 'grant') {
            acl::set_grant($f['companies'][0], $f['admin'], $role, 'task', 'edit', false);
        } else {
            $DB->set_field(acl::ROLE_TABLE, 'active', 0, ['id'=>$role]);
        }
        // Same actor/session; do not clear permission caches to make this pass.
        $this->scoped($f, [0], false);
    }

    public static function revoked_memberships(): array {
        return ['inactive'=>[false], 'removed'=>[true]];
    }

    /** @dataProvider revoked_memberships */
    public function test_membership_revocation_denies_reports_despite_remaining_acl_and_task(bool $remove): void {
        global $DB;
        $f = $this->fixture(); $this->grant($f);
        $this->setUser($f['users'][0]);
        $this->scoped($f, [0, 1], true);
        $where = ['companyuuid'=>$f['companies'][0], 'userid'=>$f['users'][0]];
        if ($remove) { $DB->delete_records('local_kbacompany_member', $where); }
        else { $DB->set_field('local_kbacompany_member', 'active', 0, $where); }
        $before = $DB->get_records('local_kbacompany_task', null, 'id ASC');
        $audit = $DB->get_records('local_kbacompany_tasklog', null, 'id ASC');
        $this->denied(static function() use ($f): void {
            tasks::list_page($f['companies'][0], $f['users'][0], ['scope'=>'all']);
        });
        $this->denied(static function() use ($f): void {
            tasks::execution_report($f['companies'][0], $f['users'][0], ['scope'=>'all']);
        });
        $this->assertEquals($before, $DB->get_records('local_kbacompany_task', null, 'id ASC'));
        $this->assertEquals($audit, $DB->get_records('local_kbacompany_tasklog', null, 'id ASC'));
    }
}
