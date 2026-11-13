<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_kanban_preferences_service as prefs;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\task_service;

/** Build1850: presentation grouping, per-company WIP limits, and guarded drag navigation. */
final class task_kanban1850_test extends \advanced_testcase {
    public function test_preferences_validate_group_and_limit_and_keep_old_format(): void {
        $old = prefs::normalise(['columns'=>prefs::defaults()['columns'],
            'mapping'=>prefs::defaults()['mapping']]);
        $this->assertSame('none', $old['groupby']);
        $this->assertSame(0, $old['limits']['doing']);
        $saved = prefs::normalise(['columns'=>prefs::defaults()['columns'],
            'mapping'=>prefs::defaults()['mapping'], 'groupby'=>'project',
            'limits'=>['doing'=>5000,'open'=>-5,'review'=>'DROP TABLE']]);
        $this->assertSame('project', $saved['groupby']);
        $this->assertSame(100, $saved['limits']['doing']);
        $this->assertSame(0, $saved['limits']['open']);
        $this->assertSame(0, $saved['limits']['review']);
        $this->assertSame('none', prefs::normalise(['groupby'=>'admin'])['groupby']);
    }

    public function test_only_unambiguous_allowed_transitions_generate_actions(): void {
        $p = prefs::defaults();
        $this->assertSame('doing', prefs::target_status('doing', $p));
        $this->assertSame('start', prefs::drop_operation('open', 'doing', ['start'=>true]));
        $this->assertSame('', prefs::drop_operation('open', 'doing', ['start'=>false]));
        $this->assertSame('submit', prefs::drop_operation('doing', 'review', ['submit'=>true]));
        $this->assertSame('accept', prefs::drop_operation('review', 'done', ['accept'=>true]));
        $this->assertSame('return', prefs::drop_operation('review', 'returned', ['return'=>true]));
        $this->assertSame('', prefs::drop_operation('done', 'open', ['save'=>true]));
        $this->assertSame('', prefs::drop_operation('doing', 'review', ['submit'=>true], false));
        $this->assertSame('submit', prefs::drop_operation('doing', 'done', ['submit'=>true], false));
        $p['mapping']['returned'] = 'doing';
        $this->assertSame('', prefs::target_status('doing', $p));
    }

    public function test_user_company_wip_settings_are_isolated(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $company = '74c48c36-d1bf-4ba0-b80f-3e7f7e8e9777';
        $othercompany = '6eb4434a-c851-4c7c-aa7c-2d2de770fd60';
        $p = prefs::defaults();
        prefs::save($company, (int)$one->id, $p['columns'], $p['mapping'], 'assignee', ['doing'=>3]);
        $this->assertSame(3, prefs::current($company, (int)$one->id)['limits']['doing']);
        $this->assertSame('assignee', prefs::current($company, (int)$one->id)['groupby']);
        $this->assertSame(0, prefs::current($company, (int)$two->id)['limits']['doing']);
        $this->assertSame('none', prefs::current($othercompany, (int)$one->id)['groupby']);
    }

    public function test_full_column_counts_use_same_company_and_role_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER, $DB;
        $companyid = \local_kbacorporate\local\repository::save_company((object)[
            'name'=>'Kanban 1850 company', 'active'=>1]);
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid'=>$companyid, 'userid'=>(int)$employee->id,
            'membertype'=>'employee', 'isemployee'=>1, 'ismanager'=>0,
            'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time(),
        ]);
        $company = \local_kbacompany\local\identity_registry::company_uuid($companyid);
        task_service::create($company, (int)$USER->id, [
            'title'=>'Kanban counts task', 'description'=>'Count verification', 'product'=>'Result',
            'assigneeid'=>(int)$employee->id, 'reviewerid'=>0, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
        $total = task_service::kanban_status_counts($company, (int)$USER->id, ['scope'=>'roles']);
        $this->assertSame(1, $total['open']);
        $employeeview = task_service::kanban_status_counts($company, (int)$employee->id, ['scope'=>'my']);
        $this->assertSame(1, $employeeview['open']);
        $filtered = task_service::kanban_status_counts($company, (int)$employee->id, [
            'scope'=>'my', 'status'=>'done']);
        $this->assertSame(0, $filtered['open']);
    }

    public function test_page_scoped_grouping_and_full_counts_in_board_html(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        // Rendering resolves real company ACL; a model actor is not a membership grant.
        global $DB;
        $company = '74c48c36-d1bf-4ba0-b80f-3e7f7e8e9777';
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid'=>$company, 'name'=>'Kanban fixture', 'sourcekind'=>'bms',
            'legacycompanyid'=>0, 'bmsclient'=>1, 'active'=>1,
        ]);
        $employee = $this->getDataGenerator()->create_user();
        $actor = (int)$employee->id;
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid'=>'22222222-2222-4222-8222-222222222222',
            'companyuuid'=>$company, 'userid'=>$actor, 'active'=>1, 'isemployee'=>1,
        ]);
        $this->setUser($employee);
        $this->assertFalse(is_siteadmin($actor));
        $this->assertFalse(has_capability('local/kbacompany:viewallcompanies',
            \context_system::instance(), $actor));
        $this->assertFalse(\local_kbacompany\local\task_service::can_manage($company, $actor));
        $task = [
            'uuid'=>'11111111-1111-4111-8111-111111111111','title'=>'<strong>Task</strong>',
            'revision'=>1,'product'=>'Result','status'=>'open','stream'=>'operate',
            'due_date'=>'','assignee_id'=>$actor,'assignee_name'=>'John',
            'effective_assignee_id'=>$actor,'effective_assignee_name'=>'John',
            'creator_id'=>$actor,'creator_name'=>'John','reviewer_id'=>0,'reviewer_name'=>'',
            'observer_id'=>0,'observer_name'=>'','priority'=>'normal','source_kind'=>'manual',
            'time_modified'=>0,'time_created'=>0,'context_kind'=>'','context_uuid'=>'','context_label'=>'',
        ];
        $p = prefs::defaults();
        $p['groupby'] = 'assignee';
        $p['limits']['open'] = 1;
        $model = ['can_manage'=>false,'total'=>1,'page'=>0,'pagesize'=>25,'actor'=>$actor,
            'tasks'=>[$task], 'scope_counts'=>[], 'plan_memberships'=>[], 'employees'=>[],
            'kanban_preferences'=>$p, 'kanban_status_counts'=>['open'=>5]];
        $f = ['scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'',
            'battleplan'=>'','signal'=>'','assignee'=>0,'project'=>''];
        $html = ui::listing($company, $model, $f, 'kanban');
        $this->assertStringContainsString('bms-task1850-board', $html);
        $this->assertStringContainsString('bms-task1850-group', $html);
        $this->assertStringContainsString('bms-task1850-overlimit', $html);
        $this->assertStringContainsString('data-kanban-target="doing"', $html);
        $this->assertStringContainsString('data-kanban-routes=', $html);
        $this->assertStringNotContainsString('<strong>Task</strong>', $html);
        $this->assertStringContainsString('&lt;strong&gt;Task&lt;/strong&gt;', $html);
        $this->assertSame(1, substr_count($html, 'data-kanban-task="'.$task['uuid'].'"'));
        $this->assertStringContainsString('class="bms-task1846-count">5</span>', $html);
        $this->assertStringContainsString('5 / 1', $html);
        $this->assertStringContainsString('operation=start', $html);
        $this->assertStringNotContainsString('operation=accept', $html);
    }
}
