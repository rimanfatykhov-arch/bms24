<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Communication Core regression and privacy-boundary tests. */
final class communication_service_test extends \advanced_testcase {
    public function test_actor_fallback_supports_complete_moodle_name_fields_without_debugging(): void {
        global $CFG, $DB;
        $f = $this->fixture();
        // The admin is intentionally absent from staffing rows, exercising fallback.
        $DB->set_field('user', 'middlename', 'Middle', ['id' => $f['admin']]);
        $DB->set_field('user', 'alternatename', 'Alias', ['id' => $f['admin']]);
        $CFG->fullnamedisplay = 'firstname middlename lastname alternatename';
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'participants' => [(int)$f['employee']->id],
        ]);
        $this->assertNotEmpty($thread);
        $this->assertTrue($DB->record_exists('local_kbacompany_commthread', [
            'companyuuid' => $f['company'], 'uuid' => $thread,
        ]));
        $this->assertDebuggingNotCalled();
    }

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
        $this->assertSame('0.6.0', $contract['version']);
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

    public function test_author_edit_audits_previous_text_and_updates_projection(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $thread, 'First version');
        $expected = hash('sha256', "First version\nactive");
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'edit', 'Corrected version', $expected);
        $row = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('Corrected version', $row->body);
        $revisions = array_values($DB->get_records('local_kbacompany_commrev', ['messageuuid' => $uuid]));
        $this->assertCount(1, $revisions);
        $this->assertSame('First version', $revisions[0]->oldbody);
        $this->assertSame('edit', $revisions[0]->action);
        $context = communication_service::context($f['company'], $f['admin'], $thread, 'all',
            ['page' => 0, 'search' => '']);
        $this->assertTrue($context['messages'][0]['edited']);
    }

    public function test_author_can_withdraw_but_history_and_file_access_are_protected(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $thread, 'Delete me',
            [(int)$f['employee']->id]);
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'withdraw', '', hash('sha256', "Delete me\nactive"));
        $row = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('withdrawn', $row->status);
        $this->assertSame('', $row->body);
        $this->assertFalse(communication_service::can_view_message($f['company'], $f['admin'], $uuid));
        $this->assertEquals(0, $DB->count_records('local_kbacompany_commmention', ['messageuuid' => $uuid]));
        $this->assertEquals(1, $DB->count_records('local_kbacompany_commrev', ['messageuuid' => $uuid]));
    }

    public function test_non_author_and_stale_revision_cannot_change_message(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $thread, 'Message');
        $hash = hash('sha256', "Message\nactive");
        try {
            communication_service::change_message($f['company'], (int)$f['employee']->id,
                $thread, $uuid, 'edit', 'Foreign edit', $hash);
            $this->fail('Participant must not edit another author message.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('communication_permission', 'local_kbacompany'), $e->getMessage());
        }
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'edit', 'New text', $hash);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_conflict', 'local_kbacompany'));
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'edit', 'Stale edit', $hash);
    }

    public function test_linked_task_message_cannot_be_withdrawn(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $thread, 'Used in task');
        $row = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $uuid], '*', MUST_EXIST);
        $row->taskuuid = '11111111-1111-4111-8111-111111111111';
        $DB->update_record('local_kbacompany_commmsg', $row);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_linkedmessage', 'local_kbacompany'));
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'withdraw', '', hash('sha256', "Used in task\nactive"));
    }

    /** Regression 1829: decision.rationale is TEXT, never compare it via record_exists conditions. */
    public function test_decision_trace_blocks_withdrawal_without_text_comparison_error(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $message = communication_service::send_message($f['company'], $f['admin'], $thread, 'Used in decision');
        $DB->insert_record('local_kbacompany_decision', (object)[
            'companyuuid' => $f['company'],
            'uuid' => 'aaaaaaa1-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
            'meetinguuid' => 'bbbbbbb1-bbbb-4bbb-8bbb-bbbbbbbbbbb1',
            'title' => 'From communication',
            'statement' => 'Decision from communication',
            'rationale' => 'communication:' . $message,
        ]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_linkedmessage', 'local_kbacompany'));
        communication_service::change_message($f['company'], $f['admin'], $thread, $message,
            'withdraw', '', hash('sha256', "Used in decision\nactive"));
    }

    /** The same decision trace in another company must not block the author's withdrawal. */
    public function test_decision_trace_in_another_company_does_not_block_withdrawal(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $message = communication_service::send_message($f['company'], $f['admin'], $thread, 'Not a linked decision');
        $DB->insert_record('local_kbacompany_decision', (object)[
            'companyuuid' => 'ccccccc1-cccc-4ccc-8ccc-ccccccccccc1',
            'uuid' => 'ddddddd1-dddd-4ddd-8ddd-ddddddddddd1',
            'meetinguuid' => 'eeeeeee1-eeee-4eee-8eee-eeeeeeeeeee1',
            'title' => 'Other company',
            'statement' => 'Other company decision',
            'rationale' => 'communication:' . $message,
        ]);
        communication_service::change_message($f['company'], $f['admin'], $thread, $message,
            'withdraw', '', hash('sha256', "Not a linked decision\nactive"));
        $row = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $message], '*', MUST_EXIST);
        $this->assertSame('withdrawn', $row->status);
        $this->assertSame('', $row->body);
    }

    public function test_edit_rejects_wrong_thread_identifier(): void {
        global $DB;
        $f = $this->fixture();
        $first = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $second = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Other thread', 'participants' => [(int)$f['peer']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $first, 'Original');
        $this->expectException(\moodle_exception::class);
        communication_service::change_message($f['company'], $f['admin'], $second, $uuid,
            'edit', 'Attempted', hash('sha256', "Original\nactive"));
    }

    public function test_withdrawn_message_cannot_be_edited_again(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $uuid = communication_service::send_message($f['company'], $f['admin'], $thread, 'Original');
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'withdraw', '', hash('sha256', "Original\nactive"));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('communication_state', 'local_kbacompany'));
        communication_service::change_message($f['company'], $f['admin'], $thread, $uuid,
            'edit', 'Attempted', hash('sha256', "\nwithdrawn"));
    }

    /** RC31 Pilot 02: count messages of a thread (used to prove that a failed upload writes nothing). */
    private function messages_in(string $company, string $thread): int {
        global $DB;
        return $DB->count_records('local_kbacompany_commmsg', ['companyuuid' => $company, 'threaduuid' => $thread]);
    }

    public function test_multiple_upload_shape_preserves_order_and_rejects_nested_or_missing_fields(): void {
        $this->resetAfterTest();
        $upload = ['name' => ['one.txt', 'two.txt'], 'type' => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/one', '/tmp/two'], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK], 'size' => [3, 4]];
        $files = communication_service::normalise_uploads($upload);
        $this->assertSame([], communication_service::normalise_uploads(['error' => UPLOAD_ERR_NO_FILE]));
        $legacy = ['name' => 'legacy.txt', 'tmp_name' => '/tmp/legacy', 'error' => UPLOAD_ERR_OK, 'size' => 3];
        $this->assertSame([$legacy], communication_service::normalise_uploads($legacy));
        $this->assertSame(['one.txt', 'two.txt'], array_column($files, 'name'));
        $this->assertSame([3, 4], array_column($files, 'size'));
        $missing = $upload; unset($missing['size'][1]);
        $nested = $upload; $nested['name'][0] = ['forged.txt'];
        foreach ([$missing, $nested, [], ['name' => 'missing-error.txt']] as $invalid) {
            try {
                communication_service::normalise_uploads($invalid);
                $this->fail('Malformed multipart shape must fail closed');
            } catch (\moodle_exception $e) {
                $this->assertSame('communication_upload', $e->errorcode);
            }
        }
    }

    public function test_multi_upload_limits_and_partial_uploads_never_create_a_message(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'participants' => [(int)$f['employee']->id],
        ]);
        $nofile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $partial = ['name' => 'partial.txt', 'type' => 'text/plain', 'tmp_name' => '',
            'error' => UPLOAD_ERR_PARTIAL, 'size' => 7];
        foreach ([[$nofile, $partial], array_fill(0, max(1, (int)ini_get('max_file_uploads')) + 1, $nofile)] as $uploads) {
            try {
                communication_service::send_message_with_attachments($f['company'], $f['admin'], $thread,
                    'This message must not survive failed upload validation', $uploads);
                $this->fail('The entire batch must be rejected');
            } catch (\moodle_exception $e) {
                $this->assertSame('communication_upload', $e->errorcode);
            }
        }
        $this->assertSame(0, $this->messages_in($f['company'], $thread));
        $this->assertCount(0, get_file_storage()->get_area_files(\context_system::instance()->id,
            'local_kbacompany', 'communication_attachment', false, 'id', false));
    }

    public function test_send_with_attachment_without_file_behaves_like_text_message(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $nofile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $uuid = communication_service::send_message_with_attachment($f['company'], $f['admin'], $thread, 'Text only', $nofile);
        $this->assertNotEmpty($uuid);
        $uuid2 = communication_service::send_message_with_attachment($f['company'], $f['admin'], $thread, 'Text again', null);
        $this->assertNotSame($uuid, $uuid2);
        $this->assertSame(2, $this->messages_in($f['company'], $thread));
    }

    public function test_empty_message_without_file_is_rejected_and_not_stored(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $nofile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        try {
            communication_service::send_message_with_attachment($f['company'], $f['admin'], $thread, "  \n ", $nofile);
            $this->fail('An empty message without a file must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('communication_invalid', $e->errorcode);
        }
        $this->assertSame(0, $this->messages_in($f['company'], $thread));
    }

    public function test_failed_upload_never_leaves_a_message_without_its_file(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $cases = [
            'communication_filesize' => ['name' => 'big.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
            'communication_upload' => ['name' => 'partial.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 3],
            // A path that is not a PHP upload (e.g. a forged tmp_name) is refused before anything is stored.
            'communication_upload ' => ['name' => 'forged.txt', 'type' => 'text/plain', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10],
        ];
        foreach ($cases as $expected => $upload) {
            foreach (['With text', ''] as $body) {
                try {
                    communication_service::send_message_with_attachment($f['company'], $f['admin'], $thread, $body, $upload);
                    $this->fail('Upload must be rejected: ' . $expected);
                } catch (\moodle_exception $e) {
                    $this->assertSame(trim($expected), $e->errorcode);
                }
            }
        }
        $this->assertSame(0, $this->messages_in($f['company'], $thread));
        $fs = get_file_storage();
        $this->assertCount(0, $fs->get_area_files(\context_system::instance()->id, 'local_kbacompany',
            'communication_attachment', false, 'id', false));
    }

    public function test_non_participant_and_foreign_company_cannot_send_with_attachment(): void {
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['company'], $f['admin'], [
            'threadtype' => 'direct', 'title' => '', 'participants' => [(int)$f['employee']->id],
        ]);
        $nofile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        try {
            communication_service::send_message_with_attachment($f['company'], (int)$f['peer']->id, $thread, 'Intrusion', $nofile);
            $this->fail('A non-participant must not post');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $othercompanyid = repository::save_company((object)['name' => 'Foreign Company', 'active' => 1]);
        $other = identity_registry::company_uuid($othercompanyid);
        try {
            communication_service::send_message_with_attachment($other, $f['admin'], $thread, 'Cross-company', $nofile);
            $this->fail('A thread must not be reachable through another company');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->assertSame(0, $this->messages_in($f['company'], $thread));
    }
}
