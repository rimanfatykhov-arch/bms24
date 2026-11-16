<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_live_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/**
 * Communication Center cleanup (owner decision 2026-11-15): one task discussion readable by task viewers,
 * task events inline, replies, inline mentions, unique direct conversations and group membership.
 * Real domain services, no mocked permission checks.
 */
final class communication_center_cleanup_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $companyid = repository::save_company((object)['name' => 'Cleanup A', 'active' => 1]);
        $otherid = repository::save_company((object)['name' => 'Cleanup B', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $other = identity_registry::company_uuid($otherid);
        $gen = $this->getDataGenerator();
        $assignee = $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Assignee']);
        $reviewer = $gen->create_user(['firstname' => 'Rita', 'lastname' => 'Reviewer']);
        $unrelated = $gen->create_user(['firstname' => 'Uma', 'lastname' => 'Unrelated']);
        $viewer = $gen->create_user(['firstname' => 'Vera', 'lastname' => 'Viewer']);
        $outsider = $gen->create_user(['firstname' => 'Otto', 'lastname' => 'Outsider']);
        foreach ([$assignee, $reviewer, $unrelated, $viewer] as $user) {
            repository::add_member($companyid, (int)$user->id, 'employee');
            employee_service::ensure_membership($company, (int)$user->id, [], 'bms');
        }
        repository::add_member($otherid, (int)$outsider->id, 'employee');
        employee_service::ensure_membership($other, (int)$outsider->id, [], 'bms');
        // A company-wide task viewer who is not a task participant (site administrator manages tasks).
        set_config('siteadmins', $CFG->siteadmins . ',' . (int)$viewer->id);
        $task = task_service::create($company, $admin, [
            'title' => 'Shared task', 'description' => 'One conversation', 'product' => 'Agreed result',
            'assigneeid' => (int)$assignee->id, 'reviewerid' => (int)$reviewer->id,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        return compact('admin', 'company', 'other', 'assignee', 'reviewer', 'unrelated', 'viewer', 'outsider', 'task');
    }

    public function test_task_viewer_reads_the_same_discussion_without_joining(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $message = task_service::post_discussion_message($f['company'], (int)$f['assignee']->id, $f['task'], 'Status update');

        $this->setUser($f['viewer']);
        $viewer = (int)$f['viewer']->id;
        $context = task_service::discussion_context($f['company'], $viewer, $f['task']);
        $this->assertFalse($context['restricted']);
        $this->assertFalse($context['is_participant']);
        $this->assertSame([$message], array_column($context['messages'], 'uuid'));
        // Reading neither joins the thread nor creates read state or notifications.
        $this->assertFalse($DB->record_exists('local_kbacompany_commpart',
            ['threaduuid' => $context['thread_uuid'], 'userid' => $viewer]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commread',
            ['threaduuid' => $context['thread_uuid'], 'userid' => $viewer]));

        // The Center projects the same canonical thread for the viewer, read-only.
        $center = communication_center_service::workspace_context($f['company'], $viewer, 'discussions', $context['thread_uuid']);
        $this->assertSame($context['thread_uuid'], $center['selected']['uuid']);
        $this->assertFalse($center['selected']['is_participant']);
        $this->assertTrue($center['selected']['can_join']);
        $this->assertSame([$message], array_column($center['messages'], 'uuid'));
        $this->assertNotEmpty(communication_live_service::snapshot($f['company'], $viewer, $context['thread_uuid']));
        // Discussion files follow the discussion: the task viewer may open them, a user without task access may not.
        $this->assertTrue(communication_service::can_view_message($f['company'], $viewer, $message));
        $this->assertFalse(communication_service::can_view_message($f['company'], (int)$f['unrelated']->id, $message));

        // Posting from the task joins explicitly.
        task_service::post_discussion_message($f['company'], $viewer, $f['task'], 'Manager reply');
        $this->assertTrue(task_service::discussion_context($f['company'], $viewer, $f['task'])['is_participant']);
    }

    public function test_task_discussion_is_closed_to_other_company_and_users_without_task_access(): void {
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        task_service::post_discussion_message($f['company'], (int)$f['assignee']->id, $f['task'], 'Private to task');
        $thread = task_service::discussion_context($f['company'], (int)$f['assignee']->id, $f['task'])['thread_uuid'];
        $attempts = [
            [$f['unrelated'], $f['company']],   // same company, no task access
            [$f['outsider'], $f['company']],    // other company user
            [$f['outsider'], $f['other']],      // other company context with a foreign thread
        ];
        foreach ($attempts as [$user, $company]) {
            $this->setUser($user);
            foreach ([
                static function() use ($company, $user, $f): void {
                    task_service::discussion_context($company, (int)$user->id, $f['task']);
                },
                static function() use ($company, $user, $thread): void {
                    communication_service::context($company, (int)$user->id, $thread, 'all');
                },
                static function() use ($company, $user, $f): void {
                    task_service::discussion_events($company, (int)$user->id, $f['task']);
                },
            ] as $call) {
                try {
                    $call();
                    $this->fail('Task discussion leaked outside task access');
                } catch (\moodle_exception $e) {
                    $this->assertNotEmpty($e->errorcode);
                }
            }
            $this->assertFalse(task_service::can_post_discussion_for($company, (int)$user->id, $f['task']));
        }
    }

    public function test_viewer_read_is_limited_to_task_discussions(): void {
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $group = communication_service::create_thread($f['company'], (int)$f['assignee']->id, [
            'threadtype' => 'group', 'title' => 'Private group', 'participants' => [(int)$f['reviewer']->id],
        ]);
        // A manager/administrator never reads a private conversation without participating.
        $this->setUser($f['viewer']);
        $this->expectException(\moodle_exception::class);
        communication_service::context($f['company'], (int)$f['viewer']->id, $group, 'all');
    }

    public function test_task_events_are_inline_but_are_not_messages(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        task_service::post_discussion_message($f['company'], (int)$f['assignee']->id, $f['task'], 'Started thinking');
        $context = task_service::discussion_context($f['company'], (int)$f['assignee']->id, $f['task']);
        $actions = array_column($context['events'], 'action');
        $this->assertContains('create', $actions);
        $this->assertNotContains('discussion_post', $actions);
        $this->assertCount(1, $context['messages']);
        // Events are a projection of task History only: no communication rows are written for them.
        $this->assertSame(1, $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $context['thread_uuid']]));
        foreach ($context['events'] as $event) {
            $this->assertContains($event['action'], task_service::DISCUSSION_EVENT_ACTIONS);
        }
    }

    public function test_reply_quotes_original_in_same_thread_and_hides_withdrawn_text(): void {
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $assignee = (int)$f['assignee']->id;
        $original = task_service::post_discussion_message($f['company'], $assignee, $f['task'], 'Question?');
        $this->setUser($f['reviewer']);
        $reply = task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'Answer', null, $original);
        $messages = array_column(task_service::discussion_context($f['company'], (int)$f['reviewer']->id, $f['task'])['messages'],
            null, 'uuid');
        $this->assertSame($original, $messages[$reply]['reply_to']['uuid']);
        $this->assertSame('Question?', $messages[$reply]['reply_to']['excerpt']);

        $this->setUser($f['assignee']);
        task_service::change_discussion_message($f['company'], $assignee, $f['task'], $original, 'withdraw', '',
            hash('sha256', "Question?\nactive"));
        $messages = array_column(task_service::discussion_context($f['company'], $assignee, $f['task'])['messages'],
            null, 'uuid');
        $this->assertSame('withdrawn', $messages[$reply]['reply_to']['status']);
        $this->assertSame('', $messages[$reply]['reply_to']['excerpt']);

        // A reply target from another thread is rejected by the canonical service.
        $direct = communication_service::create_thread($f['company'], $assignee, [
            'threadtype' => 'direct', 'participants' => [(int)$f['reviewer']->id]]);
        $this->expectException(\moodle_exception::class);
        communication_service::send_message($f['company'], $assignee, $direct, 'Cross', [], $reply);
    }

    public function test_inline_mentions_resolve_only_active_participants(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $assignee = (int)$f['assignee']->id;
        $reviewername = fullname($f['reviewer']);
        $outsidername = fullname($f['unrelated']);
        $uuid = task_service::post_discussion_message($f['company'], $assignee, $f['task'],
            "Please check @{$reviewername} and @{$outsidername}");
        $mentioned = $DB->get_fieldset_select('local_kbacompany_commmention', 'userid', 'messageuuid = ?', [$uuid]);
        $this->assertEquals([(int)$f['reviewer']->id], array_map('intval', $mentioned));
        $this->assertTrue($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $uuid, 'userid' => (int)$f['reviewer']->id, 'kind' => 'mention']));
        $this->assertFalse($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $uuid, 'userid' => (int)$f['unrelated']->id]));
    }

    public function test_direct_conversation_is_reused_for_the_same_pair(): void {
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $first = communication_service::create_thread($f['company'], (int)$f['assignee']->id, [
            'threadtype' => 'direct', 'participants' => [(int)$f['reviewer']->id]]);
        $again = communication_service::create_thread($f['company'], (int)$f['assignee']->id, [
            'threadtype' => 'direct', 'participants' => [(int)$f['reviewer']->id]]);
        $this->setUser($f['reviewer']);
        $reverse = communication_service::create_thread($f['company'], (int)$f['reviewer']->id, [
            'threadtype' => 'direct', 'participants' => [(int)$f['assignee']->id]]);
        $this->assertSame($first, $again);
        $this->assertSame($first, $reverse);
        $this->setUser($f['assignee']);
        $third = communication_service::create_thread($f['company'], (int)$f['assignee']->id, [
            'threadtype' => 'direct', 'participants' => [(int)$f['unrelated']->id]]);
        $this->assertNotSame($first, $third);
    }

    public function test_group_member_leaves_owner_removes_and_ownership_passes(): void {
        global $DB;
        $f = $this->fixture();
        $owner = (int)$f['assignee']->id;
        $this->setUser($f['assignee']);
        $group = communication_service::create_thread($f['company'], $owner, [
            'threadtype' => 'group', 'title' => 'Team',
            'participants' => [(int)$f['reviewer']->id, (int)$f['unrelated']->id]]);

        // A member cannot remove others; the owner can; direct conversations have no removal.
        $this->setUser($f['reviewer']);
        try {
            communication_service::remove_participant($f['company'], (int)$f['reviewer']->id, $group, (int)$f['unrelated']->id);
            $this->fail('Member removed another member');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->setUser($f['assignee']);
        communication_service::remove_participant($f['company'], $owner, $group, (int)$f['unrelated']->id);
        $this->setUser($f['unrelated']);
        try {
            communication_service::context($f['company'], (int)$f['unrelated']->id, $group, 'all');
            $this->fail('Removed member still reads the group');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }

        // The owner leaves: the remaining member becomes owner; the history stays.
        $this->setUser($f['assignee']);
        communication_service::send_message($f['company'], $owner, $group, 'Before leaving');
        communication_service::leave_thread($f['company'], $owner, $group);
        $this->assertSame('owner', $DB->get_field('local_kbacompany_commpart', 'role',
            ['threaduuid' => $group, 'userid' => (int)$f['reviewer']->id]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $group]));
        $this->expectException(\moodle_exception::class);
        communication_service::context($f['company'], $owner, $group, 'all');
    }

    public function test_leave_and_remove_reject_direct_and_object_threads(): void {
        $f = $this->fixture();
        $this->setUser($f['assignee']);
        $assignee = (int)$f['assignee']->id;
        $direct = communication_service::create_thread($f['company'], $assignee, [
            'threadtype' => 'direct', 'participants' => [(int)$f['reviewer']->id]]);
        task_service::post_discussion_message($f['company'], $assignee, $f['task'], 'Hello');
        $taskthread = task_service::discussion_context($f['company'], $assignee, $f['task'])['thread_uuid'];
        foreach ([$direct, $taskthread] as $thread) {
            foreach ([
                static function() use ($f, $assignee, $thread): void {
                    communication_service::leave_thread($f['company'], $assignee, $thread);
                },
                static function() use ($f, $assignee, $thread): void {
                    communication_service::remove_participant($f['company'], $assignee, $thread, (int)$f['reviewer']->id);
                },
            ] as $call) {
                try {
                    $call();
                    $this->fail('Membership change allowed outside group conversations');
                } catch (\moodle_exception $e) {
                    $this->assertNotEmpty($e->errorcode);
                }
            }
        }
    }
}
