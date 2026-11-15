<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_open_service;
use local_kbacompany\local\task_service;

/**
 * "User opened the task" in the existing History: server-side access re-check,
 * 15-minute per user+task deduplication, no task state change, and no distortion of
 * business history or the list activity projection.
 * @covers \local_kbacompany\local\task_open_service
 */
final class task_open_history_test extends \advanced_testcase {
    private const COMPANY = '31111111-1111-4111-8111-111111111111';
    private const FOREIGN = '39999999-9999-4999-8999-999999999999';

    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        foreach ([self::COMPANY => 'Open history', self::FOREIGN => 'Foreign open history'] as $uuid => $name) {
            $DB->insert_record('local_kbacompany_company', (object)['uuid'=>$uuid, 'name'=>$name,
                'sourcekind'=>'bms', 'legacycompanyid'=>0, 'bmsclient'=>1, 'active'=>1]);
        }
        $users = [];
        foreach ([self::COMPANY, self::COMPANY, self::COMPANY, self::FOREIGN] as $i => $company) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = (int)$user->id;
            $DB->insert_record('local_kbacompany_member', (object)[
                'uuid'=>sprintf('32222222-2222-4222-8222-%012d', $i + 1),
                'companyuuid'=>$company, 'userid'=>$user->id, 'active'=>1, 'isemployee'=>1]);
        }
        [$worker, $reviewer, $unrelated, $foreign] = $users;
        $task = task_service::create(self::COMPANY, $admin, [
            'title'=>'Open history', 'description'=>'Guarded task', 'product'=>'Evidence',
            'assigneeid'=>$worker, 'reviewerid'=>$reviewer, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
        return [$admin, $worker, $reviewer, $unrelated, $foreign, $task];
    }

    private function opened(string $task, int $actor = 0): array {
        global $DB;
        $params = ['companyuuid'=>self::COMPANY, 'taskuuid'=>$task, 'action'=>task_open_service::ACTION];
        if ($actor) { $params['actorid'] = $actor; }
        return array_values($DB->get_records('local_kbacompany_tasklog', $params, 'id ASC'));
    }

    private function denied(callable $call): void {
        try {
            $call();
            $this->fail('Expected denial');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }
    }

    public function test_open_is_recorded_once_per_window_per_user_without_changing_the_task(): void {
        global $DB;
        [, $worker, $reviewer, , , $task] = $this->fixture();
        $before = $DB->get_record('local_kbacompany_task', ['companyuuid'=>self::COMPANY, 'uuid'=>$task]);
        $sink = $this->redirectMessages();
        $start = time();

        $this->setUser($worker);
        $this->assertSame('recorded', task_open_service::record(self::COMPANY, $worker, $task));
        // Refresh, card tabs and repeated requests inside the window are duplicates.
        $this->assertSame('duplicate', task_open_service::record(self::COMPANY, $worker, $task));
        $this->assertSame('duplicate', task_open_service::record(self::COMPANY, $worker, $task));
        $rows = $this->opened($task, $worker);
        $this->assertCount(1, $rows);
        $this->assertSame($worker, (int)$rows[0]->actorid);
        $this->assertGreaterThanOrEqual($start, (int)$rows[0]->timecreated);
        $this->assertSame('', (string)$rows[0]->oldstatus);
        $this->assertSame('', (string)$rows[0]->newstatus);
        $this->assertSame('{}', (string)$rows[0]->details);

        // Another user gets an own record.
        $this->setUser($reviewer);
        $this->assertSame('recorded', task_open_service::record(self::COMPANY, $reviewer, $task));
        $this->assertCount(1, $this->opened($task, $reviewer));

        // After the window a new opening is a new event; exactly at the boundary it is still a duplicate.
        $this->setUser($worker);
        $DB->set_field('local_kbacompany_tasklog', 'timecreated', time() - task_open_service::WINDOW + 5, ['id'=>$rows[0]->id]);
        $this->assertSame('duplicate', task_open_service::record(self::COMPANY, $worker, $task));
        $DB->set_field('local_kbacompany_tasklog', 'timecreated', time() - task_open_service::WINDOW - 1, ['id'=>$rows[0]->id]);
        $this->assertSame('recorded', task_open_service::record(self::COMPANY, $worker, $task));
        $this->assertCount(2, $this->opened($task, $worker));

        // No business change and no notification.
        $after = $DB->get_record('local_kbacompany_task', ['companyuuid'=>self::COMPANY, 'uuid'=>$task]);
        foreach (['status', 'assigneeid', 'reviewerid', 'revision', 'duedate', 'result', 'timemodified'] as $field) {
            if (property_exists($before, $field)) { $this->assertSame((string)$before->$field, (string)$after->$field, $field); }
        }
        $this->assertSame(0, $sink->count());
        $sink->close();
    }

    public function test_unreadable_foreign_and_revoked_access_is_rejected_without_a_record(): void {
        global $DB;
        [, $worker, , $unrelated, $foreign, $task] = $this->fixture();
        // Same-company employee without access to the task.
        $this->setUser($unrelated);
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::COMPANY, $unrelated, $task); });
        // Member of another company: neither its own company nor the task's company may be used.
        $this->setUser($foreign);
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::COMPANY, $foreign, $task); });
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::FOREIGN, $foreign, $task); });
        // Substituted / unknown task and empty identifiers.
        $this->setUser($worker);
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::COMPANY, $worker, '30000000-0000-4000-8000-000000000000'); });
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::COMPANY, 0, $task); });
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record('', $worker, $task); });
        // Revoked membership in an open session.
        $DB->set_field('local_kbacompany_member', 'active', 0, ['companyuuid'=>self::COMPANY, 'userid'=>$worker]);
        $this->denied(static function() use ($worker, $unrelated, $foreign, $task) { task_open_service::record(self::COMPANY, $worker, $task); });
        $this->assertSame([], $this->opened($task));
    }

    public function test_openings_do_not_displace_history_or_list_activity(): void {
        global $DB;
        [$admin, $worker, , , , $task] = $this->fixture();
        $this->setUser($worker);
        $this->assertSame('recorded', task_open_service::record(self::COMPANY, $worker, $task));
        // List "latest activity" keeps the last business action.
        $activity = task_service::latest_page_activity(self::COMPANY, $worker, [['uuid'=>$task]]);
        $this->assertSame('create', $activity[$task]['action']);

        // Many openings by different moments: the History keeps the business events and at most
        // HISTORY_LIMIT openings, newest first.
        for ($i = 0; $i < 30; $i++) {
            $DB->insert_record('local_kbacompany_tasklog', (object)['companyuuid'=>self::COMPANY, 'taskuuid'=>$task,
                'actorid'=>$worker, 'action'=>task_open_service::ACTION, 'oldstatus'=>'', 'newstatus'=>'',
                'details'=>'{}', 'timecreated'=>time() - 100000 + $i]);
        }
        $model = task_service::context(self::COMPANY, $worker, $task, 'roles', ['recordonly'=>true, 'nodiscussion'=>true]);
        $actions = array_column($model['history'], 'action');
        $this->assertContains('create', $actions);
        $this->assertSame(task_open_service::HISTORY_LIMIT,
            count(array_filter($actions, static function($a) { return $a === task_open_service::ACTION; })));
        $this->assertSame(range(0, count($actions) - 1), array_keys($model['history']));

        // History shows "Name opened the task", never "read". Model completed as tasks.php does.
        $model['plans'] = \local_kbacompany\local\battle_plan_service::task_plan_context(self::COMPANY, $worker, $task);
        $model['parent'] = null;
        $model['can_add_subtasks'] = task_service::can_add_subtasks(self::COMPANY, $worker, $task);
        $model['subtasks'] = task_service::subtasks_page(self::COMPANY, $worker, $task, 0);
        $weekstart = \local_kbacompany\local\battle_plan_service::week_start(\local_kbacompany\local\battle_plan_service::user_today());
        $model['subtask_week'] = task_service::subtask_week_plan_projection(self::COMPANY, $worker, $task, $weekstart);
        $model['subtask_plan_memberships'] = \local_kbacompany\local\battle_plan_service::task_plan_memberships(self::COMPANY,
            $worker, array_column($model['subtasks']['items'], 'uuid'));
        $html = \local_kbacompany\local\task_ui_service::detail(self::COMPANY, $model, 'history',
            task_service::record_actions($model['selected'], $worker, false, self::COMPANY));
        $this->assertStringContainsString('data-history-action="opened"', $html);
        $this->assertStringContainsString(s(get_string('taskcenter_history_opened', 'local_kbacompany', $model['history'][0]['actor_name'])), $html);
        $this->assertStringNotContainsStringIgnoringCase('прочитал', $html);
    }
}
