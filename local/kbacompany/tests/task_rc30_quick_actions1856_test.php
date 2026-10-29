<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;
use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** RC30 1856: list shortcuts must mirror the canonical task operation rights. */
final class task_rc30_quick_actions1856_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $creator = (int)$USER->id;
        $id = repository::save_company((object)['name'=>'RC30 quick actions 1856', 'active'=>1]);
        $company = identity_registry::company_uuid($id);
        $worker = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        foreach ([$worker, $reviewer] as $user) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$id, 'userid'=>(int)$user->id,
                'membertype'=>'employee', 'isemployee'=>1, 'ismanager'=>0,
                'isdepartmentmanager'=>0, 'ishr'=>0,
                'timecreated'=>time(), 'timemodified'=>time(),
            ]);
        }
        $uuid = task_service::create($company, $creator, [
            'title'=>'Approval & <sensitive> task', 'description'=>'Internal', 'product'=>'Signed report',
            'assigneeid'=>(int)$worker->id, 'reviewerid'=>(int)$reviewer->id, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
        return [$company, $creator, $worker, $reviewer, $uuid];
    }

    private function render(string $company, int $actor): string {
        $filters = ['scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'', 'assignee'=>0,
            'project'=>'', 'sort'=>'duedate', 'dir'=>'asc'];
        $list = task_service::list_page($company, $actor, $filters);
        $list['actor'] = $actor;
        $list['scope_counts'] = task_service::scope_counts($company, $actor);
        $list['plan_memberships'] = [];
        return ui::listing($company, $list, $filters, 'list');
    }

    public function test_shortcuts_match_task_state_and_server_permissions(): void {
        [$company, $creator, $worker, $reviewer, $uuid] = $this->fixture();
        $this->setUser($worker);
        $open = $this->render($company, (int)$worker->id);
        $this->assertStringContainsString('operation=start', $open);
        $this->assertStringContainsString('operation=submit', $open);
        $this->assertStringNotContainsString('operation=accept', $open);
        $this->assertStringNotContainsString('operation=return', $open);
        $this->assertStringNotContainsString('<sensitive>', $open);

        $this->setAdminUser();
        task_service::transition($company, $creator, $uuid, 1, 'submit', ['result'=>'Report attached']);

        $this->setUser($reviewer);
        $review = $this->render($company, (int)$reviewer->id);
        $this->assertStringContainsString('operation=accept', $review);
        $this->assertStringContainsString('operation=return', $review);
        $this->assertStringNotContainsString('operation=start', $review);

        $this->setUser($worker);
        $workerreview = $this->render($company, (int)$worker->id);
        $this->assertStringNotContainsString('operation=accept', $workerreview);
        $this->assertStringNotContainsString('operation=return', $workerreview);

        $this->setAdminUser();
        $current = task_service::readable_projection($company, $creator, $uuid);
        task_service::transition($company, $creator, $uuid, (int)$current['revision'], 'accept');
        $this->setUser($reviewer);
        $closed = $this->render($company, (int)$reviewer->id);
        foreach (['start','submit','accept','return'] as $operation) {
            $this->assertStringNotContainsString('operation='.$operation, $closed);
        }
    }
}
