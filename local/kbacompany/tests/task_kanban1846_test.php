<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_kanban_preferences_service as prefs;
use local_kbacompany\local\task_ui_service as ui;

/** Build1846: cosmetic Kanban preferences may not conceal or mutate canonical task statuses. */
final class task_kanban1846_test extends \advanced_testcase {
    public function test_default_statuses_and_order_remain_unchanged(): void {
        $defaults = prefs::defaults();
        $this->assertSame(prefs::STATUSES, array_keys($defaults['columns']));
        foreach (prefs::STATUSES as $status) {
            $this->assertSame($status, $defaults['mapping'][$status]);
            $this->assertTrue($defaults['columns'][$status]['visible']);
        }
    }

    public function test_labels_and_status_mappings_are_whitelisted_and_hidden_targets_remapped(): void {
        $settings = prefs::normalise([
            'columns'=>[
                'open'=>['label'=>'<b>Входящие</b><script>bad</script>', 'visible'=>true, 'order'=>3],
                'doing'=>['label'=>'Работа', 'visible'=>false, 'order'=>1],
                'review'=>['label'=>'Проверка', 'visible'=>true, 'order'=>2],
                'injected'=>['label'=>'Unsafe', 'visible'=>true, 'order'=>1],
            ],
            'mapping'=>['open'=>'doing', 'review'=>'review', 'done'=>'DROP', 'injected'=>'open'],
        ]);
        $this->assertCount(6, $settings['columns']);
        $this->assertArrayNotHasKey('injected', $settings['columns']);
        $this->assertArrayNotHasKey('injected', $settings['mapping']);
        $this->assertSame('Входящиеbad', $settings['columns']['open']['label']);
        $this->assertSame('review', $settings['mapping']['review']);
        $this->assertFalse($settings['columns']['doing']['visible']);
        $this->assertContains($settings['mapping']['open'], ['open', 'review']);
        $this->assertTrue($settings['columns'][$settings['mapping']['open']]['visible']);
        foreach (prefs::STATUSES as $status) {
            $this->assertTrue($settings['columns'][$settings['mapping'][$status]]['visible']);
        }
    }

    public function test_disabling_all_slots_still_leaves_visible_board_and_every_status_mapped(): void {
        $hidden = [];
        foreach (prefs::STATUSES as $id) {
            $hidden[$id] = ['visible'=>false, 'label'=>'', 'order'=>1];
        }
        $saved = prefs::normalise(['columns'=>$hidden, 'mapping'=>[]]);
        $this->assertCount(1, array_filter($saved['columns'],
            static function(array $column): bool { return $column['visible']; }));
        foreach ($saved['mapping'] as $target) {
            $this->assertTrue($saved['columns'][$target]['visible']);
        }
    }

    public function test_layout_is_isolated_per_employee_and_company(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $company = '74c48c36-d1bf-4ba0-b80f-3e7f7e8e9777';
        $othercompany = '6eb4434a-c851-4c7c-aa7c-2d2de770fd60';
        $columns = prefs::defaults()['columns'];
        $columns['open']['label'] = 'Команда';
        prefs::save($company, (int)$one->id, $columns, prefs::defaults()['mapping']);
        $this->assertSame('Команда', prefs::current($company, (int)$one->id)['columns']['open']['label']);
        $this->assertSame('', prefs::current($company, (int)$two->id)['columns']['open']['label']);
        $this->assertSame('', prefs::current($othercompany, (int)$one->id)['columns']['open']['label']);
    }

    public function test_editor_and_columns_render_without_raw_html_or_missing_tasks(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $base = [
            'uuid'=>'15151515-1515-4151-8151-151515151515', 'title'=>'<script>Unsafe</script>',
            'product'=>'Product', 'status'=>'open', 'stream'=>'operate', 'due_date'=>'',
            'assignee_id'=>0, 'assignee_name'=>'', 'effective_assignee_id'=>0,
            'effective_assignee_name'=>'', 'creator_id'=>0, 'creator_name'=>'',
            'reviewer_id'=>0, 'reviewer_name'=>'', 'observer_id'=>0,
            'observer_name'=>'', 'priority'=>'normal', 'source_kind'=>'manual',
            'revision'=>1, 'time_modified'=>0, 'time_created'=>0,
        ];
        $returned = $base;
        $returned['uuid'] = '25252525-2525-4252-8252-252525252525';
        $returned['title'] = 'Returned task';
        $returned['status'] = 'returned';
        $columns = prefs::defaults()['columns'];
        $columns['open']['label'] = 'Мой <b>список</b>';
        $columns['returned']['visible'] = false;
        $config = prefs::normalise(['columns'=>$columns, 'mapping'=>['returned'=>'open']]);
        $model = [
            'can_manage'=>false, 'total'=>2, 'page'=>0, 'pagesize'=>25,
            'actor'=>10, 'tasks'=>[$base,$returned], 'scope_counts'=>[],
            'plan_memberships'=>[], 'employees'=>[], 'kanban_preferences'=>$config,
        ];
        $filters = [
            'scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'', 'sourcekind'=>'',
            'battleplan'=>'', 'signal'=>'', 'assignee'=>0, 'project'=>'',
            'sort'=>'duedate', 'dir'=>'asc',
        ];
        $html = ui::listing('test-company', $model, $filters, 'kanban');
        $this->assertStringContainsString('task_kanban_preferences.php', $html);
        $this->assertStringContainsString('data-kanban-column="open"', $html);
        $this->assertStringContainsString('Returned task', $html);
        $this->assertStringNotContainsString('<script>Unsafe</script>', $html);
        $this->assertStringNotContainsString('Мой <b>список</b>', $html);
        $this->assertStringContainsString('bms-task1846-settings', $html);
        $this->assertStringContainsString('bms-task1846-board', $html);
    }
}
