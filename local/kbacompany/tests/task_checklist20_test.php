<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Checklist 2.0: mutations, order, isolation and closed-state protections. */
final class task_checklist20_test extends \advanced_testcase {
    private function setup_task(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Checklist 2.0 Test', 'active' => 1]);
        $companyuuid = identity_registry::company_uuid($companyid);
        $worker = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $worker->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $task = task_service::create($companyuuid, $admin, [
            'title' => 'Task with checklist', 'product' => 'Verified checklist result',
            'description' => '', 'assigneeid' => (int)$worker->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 60, 'parentuuid' => '',
        ]);
        return compact('companyuuid', 'admin', 'task', 'worker');
    }

    public function test_create_edit_reorder_delete_keep_same_uuid_and_log_changes(): void {
        global $DB;
        $f = $this->setup_task();
        $a = task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'Prepare');
        $b = task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'Check');
        $c = task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'Approve');
        task_service::update_checklist($f['companyuuid'], $f['admin'], $f['task'], $b,
            'Verify result', (int)$f['worker']->id);
        $same = $DB->get_record('local_kbacompany_taskcheck', ['uuid' => $b], '*', MUST_EXIST);
        $this->assertSame('Verify result', $same->itemtext);
        $this->assertSame((int)$f['worker']->id, (int)$same->assigneeid);
        task_service::move_checklist($f['companyuuid'], $f['admin'], $f['task'], $c, 'up');
        $items = array_values($DB->get_records('local_kbacompany_taskcheck',
            ['companyuuid' => $f['companyuuid'], 'taskuuid' => $f['task']], 'sortorder ASC, id ASC'));
        $this->assertSame([$a, $c, $b], array_map(static function($x) { return $x->uuid; }, $items));
        task_service::delete_checklist($f['companyuuid'], $f['admin'], $f['task'], $b);
        $this->assertFalse($DB->record_exists('local_kbacompany_taskcheck', ['uuid' => $b]));
        foreach (['checklist_edit', 'checklist_move', 'checklist_delete'] as $action) {
            $this->assertTrue($DB->record_exists('local_kbacompany_tasklog',
                ['companyuuid' => $f['companyuuid'], 'taskuuid' => $f['task'], 'action' => $action]));
        }
    }

    public function test_check_from_other_task_cannot_be_deleted(): void {
        $f = $this->setup_task();
        $another = task_service::create($f['companyuuid'], $f['admin'], [
            'title' => 'Another task', 'product' => 'Another product', 'description' => '',
            'assigneeid' => (int)$f['worker']->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        $checkuuid = task_service::add_checklist($f['companyuuid'], $f['admin'], $another, 'Private');
        $this->expectException(\moodle_exception::class);
        task_service::delete_checklist($f['companyuuid'], $f['admin'], $f['task'], $checkuuid);
    }

    public function test_closed_task_denies_edit(): void {
        $f = $this->setup_task();
        $checkuuid = task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'Completed');
        task_service::set_checklist_state($f['companyuuid'], $f['admin'], $f['task'], $checkuuid, true);
        task_service::transition($f['companyuuid'], $f['admin'], $f['task'], 1, 'submit', ['result' => 'Accepted']);
        $this->expectException(\moodle_exception::class);
        task_service::update_checklist($f['companyuuid'], $f['admin'], $f['task'], $checkuuid, 'Tampered');
    }

    public function test_non_participant_cannot_reorder(): void {
        $f = $this->setup_task();
        $a = task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'First');
        task_service::add_checklist($f['companyuuid'], $f['admin'], $f['task'], 'Second');
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        task_service::move_checklist($f['companyuuid'], (int)$outsider->id, $f['task'], $a, 'down');
    }
}
