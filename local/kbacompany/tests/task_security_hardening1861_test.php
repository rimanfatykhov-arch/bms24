<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service as acl;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacompany\local\task_batch_actions_service as batch;
use local_kbacorporate\local\repository;

/** Security regression: viewing work never implies authorization to mutate or source it. */
final class task_security_hardening1861_test extends \advanced_testcase {
    private function add_member(int $companyid, int $userid): void {
        global $DB;
        if ($DB->record_exists('local_kbacorp_member', ['companyid' => $companyid, 'userid' => $userid])) {
            return;
        }
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $userid, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    private function taskdata(int $assigneeid, int $observerid = 0): array {
        return [
            'title' => 'Security boundary', 'description' => 'Test', 'product' => 'Checked result',
            'assigneeid' => $assigneeid, 'reviewerid' => 0, 'observerid' => $observerid,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ];
    }

    public function test_department_view_only_cannot_post_to_task_discussion(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $manager = $this->getDataGenerator()->create_user();
        $assignee = $this->getDataGenerator()->create_user();
        $companyid = repository::save_company((object)['name' => 'Security1861 View', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $this->add_member($companyid, (int)$manager->id);
        $this->add_member($companyid, (int)$assignee->id);
        $now = time();
        $departmentid = $DB->insert_record('local_kbacorp_department', (object)[
            'companyid' => $companyid, 'name' => 'Operations', 'code' => 'OPS', 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ([(int)$manager->id => 'manager', (int)$assignee->id => 'employee'] as $id => $role) {
            $DB->insert_record('local_kbacorp_dept_member', (object)[
                'departmentid' => $departmentid, 'userid' => $id, 'deptrole' => $role,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $taskuuid = task_service::create($company, $admin, $this->taskdata((int)$assignee->id));
        $roleid = acl::create_role($company, $admin, 'Department task read only', '');
        acl::set_grant($company, $admin, $roleid, 'task', 'view', true, 'department');
        acl::assign($company, $admin, $roleid, (int)$manager->id, true);
        $this->assertNotNull(task_service::readable_projection($company, (int)$manager->id, $taskuuid));
        $discussion = task_service::discussion_context($company, (int)$manager->id, $taskuuid);
        $this->assertFalse($discussion['can_post']);
        $this->assertSame('', $discussion['thread_uuid']);
        try {
            task_service::post_discussion_message($company, (int)$manager->id, $taskuuid, 'Not authorized');
            $this->fail('Read-only department user posted into a task discussion');
        } catch (\moodle_exception $e) {
            $this->assertSame('taskcenter_permission', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_kbacompany_commthread', [
            'companyuuid' => $company, 'contextkind' => 'task', 'contextuuid' => $taskuuid,
        ]));
        // A separate approver grant deliberately permits posting; view-only does not.
        acl::set_grant($company, $admin, $roleid, 'task', 'approve', true, 'department');
        $this->assertTrue(task_service::discussion_context($company, (int)$manager->id, $taskuuid)['can_post']);
    }

    public function test_revoked_observer_cannot_delete_previous_result_file(): void {
        global $USER, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $assignee = $this->getDataGenerator()->create_user();
        $former = $this->getDataGenerator()->create_user();
        $companyid = repository::save_company((object)['name' => 'Security1861 File', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $this->add_member($companyid, (int)$assignee->id);
        $this->add_member($companyid, (int)$former->id);
        $taskuuid = task_service::create($company, $admin,
            $this->taskdata((int)$assignee->id, (int)$former->id));
        $this->assertTrue(task_service::can_view_task($company, (int)$former->id, $taskuuid));
        $task = $DB->get_record('local_kbacompany_task', ['companyuuid' => $company, 'uuid' => $taskuuid], '*', MUST_EXIST);
        $context = \context_system::instance();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'local_kbacompany', 'filearea' => 'task_result',
            'itemid' => (int)$task->id, 'filepath' => '/', 'filename' => 'prior-evidence.txt',
            'userid' => (int)$former->id,
        ], 'Preserved result evidence');
        task_service::set_observer($company, $admin, $taskuuid, (int)$task->revision, 0);
        $this->assertFalse(task_service::can_view_task($company, (int)$former->id, $taskuuid));
        try {
            task_service::delete_result_attachment($company, (int)$former->id,
                $taskuuid, 'prior-evidence.txt');
            $this->fail('Revoked observer deleted evidence after losing task visibility');
        } catch (\moodle_exception $e) {
            $this->assertSame('taskcenter_permission', $e->errorcode);
        }
        $this->assertTrue($file->get_content() === 'Preserved result evidence');
    }

    public function test_private_message_cannot_be_used_as_unreadable_task_source(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $participant = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $companyid = repository::save_company((object)['name' => 'Security1861 Message', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $this->add_member($companyid, $admin);
        $this->add_member($companyid, (int)$participant->id);
        $this->add_member($companyid, (int)$outsider->id);
        $threaduuid = communication_service::create_thread($company, $admin, [
            'threadtype' => 'direct', 'participants' => [(int)$participant->id], 'title' => '',
        ]);
        $messageuuid = communication_service::send_message($company, $admin,
            $threaduuid, 'Private discussion');
        $this->assertFalse(communication_service::can_view_message($company, (int)$outsider->id, $messageuuid));
        try {
            task_service::create_from_communication($company, (int)$outsider->id,
                $messageuuid, $this->taskdata((int)$outsider->id));
            $this->fail('A nonparticipant created a task from a private source message');
        } catch (\moodle_exception $e) {
            $this->assertSame('taskcenter_sourceerror', $e->errorcode);
        }
        $taskuuid = task_service::create_from_communication($company, $admin,
            $messageuuid, $this->taskdata((int)$participant->id));
        $this->assertNotEmpty($taskuuid);
    }

    public function test_batch_operation_rechecks_company_and_expected_revision(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $employee = $this->getDataGenerator()->create_user();
        $firstid = repository::save_company((object)['name' => 'Security1861 Batch A', 'active' => 1]);
        $secondid = repository::save_company((object)['name' => 'Security1861 Batch B', 'active' => 1]);
        $first = identity_registry::company_uuid($firstid);
        $second = identity_registry::company_uuid($secondid);
        $this->add_member($firstid, (int)$employee->id);
        $this->add_member($secondid, (int)$employee->id);
        $firstuuid = task_service::create($first, $admin, $this->taskdata((int)$employee->id));
        $seconduuid = task_service::create($second, $admin, $this->taskdata((int)$employee->id));
        $result = batch::run($first, $admin, [$seconduuid => 1], 'start');
        $this->assertSame(['updated' => 0, 'skipped' => 1, 'total' => 1], $result);
        $this->assertSame('open', (string)$DB->get_field('local_kbacompany_task',
            'status', ['companyuuid' => $second, 'uuid' => $seconduuid]));
        $result = batch::run($first, $admin, [$firstuuid => 777], 'start');
        $this->assertSame(['updated' => 0, 'skipped' => 1, 'total' => 1], $result);
        $result = batch::run($first, $admin, [$firstuuid => 1], 'start');
        $this->assertSame(['updated' => 1, 'skipped' => 0, 'total' => 1], $result);
        $this->assertSame('doing', (string)$DB->get_field('local_kbacompany_task',
            'status', ['companyuuid' => $first, 'uuid' => $firstuuid]));
    }

}
