<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Communication Core regression and privacy-boundary tests. */
final class communication_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Communication Company', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $peer = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([[$employee, 0], [$peer, 0], [$manager, 1]] as [$user, $ismanager]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => $ismanager, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return compact('admin', 'companyid', 'company', 'employee', 'peer', 'manager');
    }

    public function test_contract_separates_communication_from_tasks(): void {
        $contract = communication_service::contract();
        $this->assertSame('0.5.0', $contract['version']);
        $this->assertSame('bms_communication_core', $contract['contract']);
        $this->assertContains('message_is_not_task', $contract['rules']);
        $this->assertContains('thread_content_is_participant_scoped', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_direct_thread_message_unread_and_read_state(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $message = communication_service::send_message($f['company'], $f['admin'], $thread, 'Please review the plan.');
        $this->assertTrue($DB->record_exists('local_kbacompany_commmsg', ['uuid' => $message]));
        $this->assertEquals(1, $DB->count_records('local_kbacompany_commnotif', [
            'companyuuid' => $f['company'], 'userid' => (int)$f['employee']->id, 'kind' => 'message',
        ]));

        $this->setUser($f['employee']);
        $context = communication_service::context($f['company'], (int)$f['employee']->id, '', 'inbox');
        $this->assertCount(1, $context['threads']);
        $this->assertSame(1, $context['threads'][0]['unread_count']);
        $detail = communication_service::context($f['company'], (int)$f['employee']->id, $thread, 'inbox');
        $this->assertCount(1, $detail['messages']);
        $after = communication_service::context($f['company'], (int)$f['employee']->id, '', 'inbox');
        $this->assertSame(0, $after['threads'][0]['unread_count']);
    }

    public function test_manager_role_does_not_grant_private_thread_content(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        communication_service::send_message($f['company'], $f['admin'], $thread, 'Private participant content.');
        $this->setUser($f['manager']);
        $context = communication_service::context($f['company'], (int)$f['manager']->id, '', 'all');
        $this->assertSame([], $context['threads']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_permission', 'local_kbacompany'));
        communication_service::context($f['company'], (int)$f['manager']->id, $thread, 'all');
    }

    public function test_mentions_only_allow_active_participants(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Project discussion',
            'participants' => [(int)$f['employee']->id],
        ]);
        try {
            communication_service::send_message($f['company'], $f['admin'], $thread, 'Mention outsider.', [(int)$f['peer']->id]);
            $this->fail('Non-participant mention must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('communication_mention', 'local_kbacompany'), $e->getMessage());
        }
        communication_service::add_participant($f['company'], $f['admin'], $thread, (int)$f['peer']->id);
        communication_service::send_message($f['company'], $f['admin'], $thread, 'Mention participant.', [(int)$f['peer']->id]);
        global $DB;
        $this->assertEquals(1, $DB->count_records('local_kbacompany_commmention', [
            'companyuuid' => $f['company'], 'userid' => (int)$f['peer']->id,
        ]));
    }

    public function test_message_can_create_traceable_canonical_task(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'object', 'title' => 'Company discussion', 'contextkind' => 'company',
            'contextuuid' => $f['company'], 'participants' => [(int)$f['employee']->id],
        ]);
        $message = communication_service::send_message($f['company'], $f['admin'], $thread,
            'Analyse the reasons for the conversion drop.');
        $task = communication_service::create_task_from_message($f['company'], $f['admin'], $message, [
            'title' => 'Analyse conversion drop', 'description' => 'From the process discussion.',
            'product' => 'Documented root-cause analysis and proposed corrective action',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
            'priority' => 'high', 'stream' => 'operate', 'startdate' => '', 'duedate' => '2026-09-18',
            'planminutes' => 60, 'parentuuid' => '',
        ]);
        $taskrow = $DB->get_record('local_kbacompany_task', ['uuid' => $task], '*', MUST_EXIST);
        $messagerow = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $message], '*', MUST_EXIST);
        $this->assertSame('communication', $taskrow->sourcekind);
        $this->assertSame($message, $taskrow->sourceuuid);
        $this->assertSame($task, $messagerow->taskuuid);
        $this->assertSame('Documented root-cause analysis and proposed corrective action', $taskrow->product);
        $taskdto = task_service::row_dto($taskrow);
        $this->assertSame($thread, $taskdto['source_thread_uuid']);

        // Repeated conversion is idempotent even if the caller proposes a different assignee.
        $same = communication_service::create_task_from_message($f['company'], $f['admin'], $message, [
            'title' => 'Should not create a second task', 'description' => '',
            'product' => 'No duplicate', 'assigneeid' => (int)$f['peer']->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        $this->assertSame($task, $same);
        $this->assertEquals(1, $DB->count_records('local_kbacompany_task', [
            'companyuuid' => $f['company'], 'sourcekind' => 'communication', 'sourceuuid' => $message,
        ]));
    }

    public function test_direct_thread_requires_exactly_one_other_participant(): void {
        $f = $this->fixture();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_directparticipants', 'local_kbacompany'));
        communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'participants' => [(int)$f['employee']->id, (int)$f['peer']->id],
        ]);
    }

    public function test_object_thread_rejects_unresolved_context_without_leaking_existence(): void {
        $f = $this->fixture();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_contextunavailable', 'local_kbacompany'));
        communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'object', 'title' => '', 'contextkind' => 'process',
            'contextuuid' => '11111111-1111-4111-8111-111111111111', 'participants' => [],
        ]);
    }

    public function test_authorised_viewer_joins_single_canonical_object_history(): void {
        global $DB;
        $f = $this->fixture();
        $first = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'object', 'title' => '', 'contextkind' => 'company',
            'contextuuid' => $f['company'], 'participants' => [],
        ]);
        $this->setUser($f['employee']);
        $second = communication_service::create_thread($f['company'], (int)$f['employee']->id, [
            'threadtype' => 'object', 'title' => '', 'contextkind' => 'company',
            'contextuuid' => $f['company'], 'participants' => [],
        ]);
        $this->assertSame($first, $second);
        $this->assertEquals(1, $DB->count_records('local_kbacompany_commthread', [
            'companyuuid' => $f['company'], 'threadtype' => 'object', 'contextkind' => 'company',
            'contextuuid' => $f['company'], 'status' => 'active',
        ]));
        $this->assertTrue($DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $f['company'], 'threaduuid' => $first, 'userid' => (int)$f['employee']->id, 'active' => 1,
        ]));
    }

}
