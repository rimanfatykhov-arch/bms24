<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_list_preferences_service as prefs;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** build2026101841: sort/columns are presentation-only and must not bypass the task ACL. */
final class task_list1841_test extends \advanced_testcase {
    private function company(): array {
        global $USER, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name'=>'Task list 1841', 'active'=>1]);
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>(int)$employee->id,
            'membertype'=>'employee', 'isemployee'=>1, 'ismanager'=>0,
            'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time(),
        ]);
        return [identity_registry::company_uuid($companyid), (int)$USER->id, (int)$employee->id];
    }

    public function test_whitelists_never_accept_client_columns_or_sql(): void {
        $this->assertSame(['title', 'assignee', 'status'], prefs::columns([
            'status', 'assignee', 'title', 'id);DROP TABLE user;--', 'assignee']));
        $this->assertSame('duedate', prefs::sort('timemodified DESC, t.id'));
        $this->assertSame('asc', prefs::direction('desc; DELETE FROM'));
        foreach (['title','duedate','assignee','creator','priority','updated'] as $sort) {
            foreach (['asc','desc'] as $dir) {
                [$join, $sql] = prefs::sql_order($sort, $dir);
                $this->assertStringContainsString('t.id ASC', $sql);
                $this->assertStringNotContainsString(';', $sql.$join);
            }
        }
        [$join, $sql] = prefs::sql_order('duedate', 'desc');
        $this->assertSame('', $join);
        $this->assertStringContainsString("CASE WHEN t.duedate = '' THEN 1 ELSE 0 END", $sql);
        $this->assertStringContainsString('t.duedate DESC', $sql);
    }

    public function test_preferences_are_isolated_by_company_and_user(): void {
        [$company, $actor] = $this->company();
        $other = $this->getDataGenerator()->create_user();
        $othercompany = 'e80c3803-8c1e-4968-872e-e31e50d129cb';
        prefs::save($company, $actor, ['status','updated'], 'updated', 'desc');
        $one = prefs::current($company, $actor);
        $this->assertSame(['title','status','updated'], $one['columns']);
        $this->assertSame('updated', $one['sort']);
        $this->assertSame('desc', $one['dir']);
        $this->assertSame(prefs::DEFAULT_COLUMNS, prefs::current($othercompany, $actor)['columns']);
        $this->assertSame(prefs::DEFAULT_COLUMNS, prefs::current($company, (int)$other->id)['columns']);
    }

    public function test_sorting_happens_before_paging_without_changing_counts_or_scope(): void {
        [$company, $actor, $assignee] = $this->company();
        foreach (['Zulu task', 'Alpha task', 'Beta task'] as $title) {
            task_service::create($company, $actor, [
                'title'=>$title, 'description'=>'Sort check', 'product'=>'Accepted',
                'assigneeid'=>$assignee, 'reviewerid'=>0, 'observerid'=>0,
                'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
                'planminutes'=>0, 'parentuuid'=>'',
            ]);
        }
        $f = ['scope'=>'roles', 'sort'=>'title', 'dir'=>'asc'];
        $page0 = task_service::list_page($company, $actor, $f, 0, 2);
        $page1 = task_service::list_page($company, $actor, $f, 1, 2);
        $this->assertSame(3, $page0['total']);
        $this->assertSame(['Alpha task','Beta task'], array_column($page0['tasks'], 'title'));
        $this->assertSame(['Zulu task'], array_column($page1['tasks'], 'title'));
        $f['dir'] = 'desc';
        $reversed = task_service::list_page($company, $actor, $f, 0, 2);
        $this->assertSame(['Zulu task','Beta task'], array_column($reversed['tasks'], 'title'));
        $f['sort'] = 'title;DROP';
        $invalid = task_service::list_page($company, $actor, $f, 0, 2);
        $this->assertSame(3, $invalid['total']);
    }

    public function test_sortable_th_and_selected_columns_render_with_escaped_markup(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'00000000-0000-4000-8000-000000000001', 'title'=>'<script>alert(1)</script>',
            'product'=>'Result <unsafe>', 'status'=>'open', 'stream'=>'operate',
            'due_date'=>'', 'assignee_id'=>0, 'assignee_name'=>'', 'effective_assignee_id'=>0,
            'effective_assignee_name'=>'', 'creator_id'=>0, 'creator_name'=>'',
            'reviewer_id'=>0, 'reviewer_name'=>'', 'observer_id'=>0, 'observer_name'=>'',
            'priority'=>'normal', 'source_kind'=>'manual', 'time_modified'=>0, 'time_created'=>0,
        ];
        $model = ['can_manage'=>false,'total'=>1,'page'=>0,'pagesize'=>25,'actor'=>10,
            'tasks'=>[$task], 'scope_counts'=>[], 'plan_memberships'=>[],
            'list_preferences'=>['columns'=>['title','status','updated'], 'sort'=>'title','dir'=>'asc']];
        $filters = ['scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'',
            'battleplan'=>'','signal'=>'','assignee'=>0,'project'=>'', 'sort'=>'title','dir'=>'asc'];
        $html = ui::listing('company-test', $model, $filters, 'list');
        $this->assertStringContainsString('bms24-task-preferences', $html);
        $this->assertStringContainsString('task_list_preferences.php', $html);
        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $this->assertSame(3, substr_count($html, 'data-column='));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<unsafe>', $html);
        $this->assertStringContainsString('name="columns[status]"', $html);
    }
}
