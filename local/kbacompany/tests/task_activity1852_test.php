<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** build1852: task attention indicators and ACL-scoped existing activity history. */
final class task_activity1852_test extends \advanced_testcase {
    public function test_attention_signals_use_status_due_date_and_priority_without_a_new_log(): void {
        $today = ['today'=>'2026-10-28', 'until'=>'2026-11-03'];
        $task = ['status'=>'review', 'due_date'=>'2026-10-27', 'priority'=>'high'];
        $this->assertSame(['overdue', 'review', 'priority'], task_service::attention_signals($task, $today));
        $this->assertSame(['returned'], task_service::attention_signals([
            'status'=>'returned', 'due_date'=>'', 'priority'=>'normal'], $today));
        $this->assertSame([], task_service::attention_signals([
            'status'=>'done', 'due_date'=>'2026-10-01', 'priority'=>'high'], $today));
        $this->assertSame([], task_service::attention_signals([
            'status'=>'cancelled', 'due_date'=>'2026-10-01', 'priority'=>'normal'], $today));
    }

    public function test_latest_activity_is_limited_to_readable_tasks(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)[
            'name'=>'Task activity 1852', 'active'=>1,
        ]);
        $company = identity_registry::company_uuid($companyid);
        $assignee = $this->getDataGenerator()->create_user();
        $unrelated = $this->getDataGenerator()->create_user();
        foreach ([$assignee, $unrelated] as $employee) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid, 'userid'=>(int)$employee->id,
                'membertype'=>'employee', 'isemployee'=>1, 'ismanager'=>0,
                'isdepartmentmanager'=>0, 'ishr'=>0,
                'timecreated'=>time(), 'timemodified'=>time(),
            ]);
        }
        $uuid = task_service::create($company, $actor, [
            'title'=>'Activity should be scoped', 'description'=>'', 'product'=>'Accepted task',
            'assigneeid'=>(int)$assignee->id, 'reviewerid'=>0, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'', 'duedate'=>'',
            'planminutes'=>0, 'parentuuid'=>'',
        ]);
        $page = [['uuid'=>$uuid]];
        $visible = task_service::latest_page_activity($company, (int)$assignee->id, $page);
        $this->assertArrayHasKey($uuid, $visible);
        $this->assertSame('create', $visible[$uuid]['action']);
        $this->assertSame([], task_service::latest_page_activity($company, (int)$unrelated->id, $page));
    }
}
