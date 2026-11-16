<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Real domain boundaries around historical task participants, not mocked permission checks. */
final class task_discussion_participant_regression_test extends \advanced_testcase {
    private function fixture(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $companyid = repository::save_company((object)['name' => 'Discussion ACL A', 'active' => 1]);
        $otherid = repository::save_company((object)['name' => 'Discussion ACL B', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $other = identity_registry::company_uuid($otherid);
        $assignee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $unrelated = $this->getDataGenerator()->create_user();
        foreach ([$assignee, $reviewer, $unrelated] as $user) {
            repository::add_member($companyid, (int)$user->id, 'employee');
            employee_service::ensure_membership($company, (int)$user->id, [], 'bms');
        }
        // Reviewer belongs to both companies: rejection must be object scoping, not missing company membership.
        repository::add_member($otherid, (int)$reviewer->id, 'employee');
        employee_service::ensure_membership($other, (int)$reviewer->id, [], 'bms');
        $task = task_service::create($company, $admin, [
            'title' => 'Historical task', 'description' => 'Retain role history', 'product' => 'Approved result',
            'assigneeid' => (int)$assignee->id, 'reviewerid' => (int)$reviewer->id,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        return compact('admin', 'company', 'other', 'assignee', 'reviewer', 'unrelated', 'task');
    }

    public function test_task_author_edits_and_withdraws_canonical_message(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $actor = (int)$f['reviewer']->id;
        $uuid = task_service::post_discussion_message($f['company'], $actor, $f['task'], 'Original');
        task_service::change_discussion_message($f['company'], $actor, $f['task'], $uuid,
            'edit', 'Corrected', hash('sha256', "Original\nactive"));
        $context = task_service::discussion_context($f['company'], $actor, $f['task']);
        $this->assertSame('Corrected', $context['messages'][0]['body']);
        $this->assertTrue($context['messages'][0]['edited']);
        $canonical = communication_service::context($f['company'], $actor, $context['thread_uuid'], 'all');
        $this->assertSame('Corrected', $canonical['messages'][0]['body']);
        task_service::change_discussion_message($f['company'], $actor, $f['task'], $uuid,
            'withdraw', '', hash('sha256', "Corrected\nactive"));
        $this->assertFalse(communication_service::can_view_message($f['company'], $actor, $uuid));
        $this->assertSame('withdrawn', $DB->get_field('local_kbacompany_commmsg', 'status', ['uuid'=>$uuid]));
        $this->assertEquals(2, $DB->count_records('local_kbacompany_commrev', ['messageuuid'=>$uuid]));
    }

    public function test_task_message_changes_reject_wrong_company_non_author_and_stale_revision(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $actor = (int)$f['reviewer']->id;
        $uuid = task_service::post_discussion_message($f['company'], $actor, $f['task'], 'Original');
        $hash = hash('sha256', "Original\nactive");
        foreach (['edit', 'withdraw'] as $operation) {
            // Other company, another task participant (not the author), a company user without task access, stale revision.
            foreach ([[$f['other'], $actor, $hash], [$f['company'], (int)$f['assignee']->id, $hash],
                    [$f['company'], (int)$f['unrelated']->id, $hash],
                    [$f['company'], $actor, str_repeat('0', 64)]] as $attempt) {
                try {
                    task_service::change_discussion_message($attempt[0], $attempt[1], $f['task'],
                        $uuid, $operation, 'Forbidden', $attempt[2]);
                    $this->fail('Unauthorised or stale mutation succeeded');
                } catch (\moodle_exception $e) {
                    $this->assertNotEmpty($e->errorcode);
                }
                $this->assertSame('Original', $DB->get_field('local_kbacompany_commmsg', 'body', ['uuid'=>$uuid]));
                $this->assertSame('active', $DB->get_field('local_kbacompany_commmsg', 'status', ['uuid'=>$uuid]));
                $this->assertEquals(0, $DB->count_records('local_kbacompany_commrev', ['messageuuid'=>$uuid]));
            }
        }
    }

    public function test_task_cannot_mutate_message_from_another_thread(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $actor = (int)$f['reviewer']->id;
        task_service::post_discussion_message($f['company'], $actor, $f['task'], 'Task discussion');
        $thread = communication_service::create_thread($f['company'], $actor, [
            'threadtype'=>'direct', 'title'=>'', 'participants'=>[(int)$f['assignee']->id]]);
        $foreign = communication_service::send_message($f['company'], $actor, $thread, 'Private message');
        try {
            task_service::change_discussion_message($f['company'], $actor, $f['task'], $foreign,
                'withdraw', '', hash('sha256', "Private message\nactive"));
            $this->fail('Cross-thread mutation succeeded');
        } catch (\moodle_exception $e) {
            $this->assertSame('communication_permission', $e->errorcode);
        }
        $this->assertSame('active', $DB->get_field('local_kbacompany_commmsg', 'status', ['uuid'=>$foreign]));
    }

    public function test_task_message_controls_and_failed_edit_preserve_revision(): void {
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $actor = (int)$f['reviewer']->id;
        task_service::post_discussion_message($f['company'], $actor, $f['task'], 'Original');
        $context = task_service::discussion_context($f['company'], $actor, $f['task']);
        $message = $context['messages'][0];
        $renderer = new \ReflectionMethod(\local_kbacompany\local\task_ui_service::class, 'discussion_message');
        $renderer->setAccessible(true);
        $task = ['uuid'=>$f['task'], 'revision'=>1];
        $render = function(array $msg, bool $canpost = true, array $values = []) use ($renderer, $f, $task): string {
            return $renderer->invoke(null, $msg, $f['company'], $task, $canpost, 'discussion', $values);
        };
        $html = $render($message);
        $this->assertStringContainsString('value="discussionedit"', $html);
        $this->assertStringContainsString('value="discussionwithdraw"', $html);
        $this->assertStringContainsString('name="confirmwithdraw"', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringNotContainsString('value="discussionedit"', $render($message, false));
        $other = $message;
        $other['author_id'] = (int)$f['assignee']->id;
        $this->assertStringNotContainsString('value="discussionwithdraw"', $render($other));
        $draft = ['discussion_edit'=>['uuid'=>$message['uuid'], 'body'=>'<script>draft</script>',
            'expectedhash'=>str_repeat('a', 64)]];
        $html = $render($message, true, $draft);
        $this->assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $html);
        $this->assertStringContainsString(str_repeat('a', 64), $html);
        $withdrawn = $message;
        $withdrawn['status'] = 'withdrawn';
        $withdrawn['attachments'] = [['label'=>'private-file', 'value'=>'secret', 'url'=>'https://example.invalid/secret']];
        $html = $render($withdrawn);
        $this->assertStringNotContainsString('Original', $html);
        $this->assertStringNotContainsString('private-file', $html);
        $this->assertStringNotContainsString('value="discussionedit"', $html);
    }

    private function assert_denied_without_message(callable $operation): void {
        global $DB;
        $before = $DB->count_records('local_kbacompany_commmsg');
        try {
            $operation();
            $this->fail('Unauthorised operation unexpectedly succeeded');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('local_kbacompany_commmsg'));
    }

    public function test_current_reviewer_posts_twice_without_inviting_historical_creator(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $first = task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'First');
        $second = task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'Second');
        $a = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $first], '*', MUST_EXIST);
        $b = $DB->get_record('local_kbacompany_commmsg', ['uuid' => $second], '*', MUST_EXIST);
        $this->assertSame($a->threaduuid, $b->threaduuid);
        $this->assertSame(1, $DB->count_records('local_kbacompany_commthread', [
            'companyuuid' => $f['company'], 'contextkind' => 'task', 'contextuuid' => $f['task'],
        ]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $f['company'], 'threaduuid' => $a->threaduuid, 'userid' => $f['admin'],
        ]));
        $this->assertSame($f['admin'], (int)$DB->get_field('local_kbacompany_task', 'creatorid', ['uuid' => $f['task']]));
        $this->assertSame(2, $DB->count_records('local_kbacompany_commmsg', ['threaduuid' => $a->threaduuid]));
    }

    public function test_foreign_company_task_and_same_company_unrelated_actor_cannot_post(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $this->assert_denied_without_message(function() use ($f) {
            task_service::post_discussion_message($f['other'], (int)$f['reviewer']->id, $f['task'], 'Wrong scope');
        });
        $this->setUser($f['unrelated']);
        $this->assertFalse(task_service::can_view_task($f['company'], (int)$f['unrelated']->id, $f['task']));
        $this->assert_denied_without_message(function() use ($f) {
            task_service::post_discussion_message($f['company'], (int)$f['unrelated']->id, $f['task'], 'No task access');
        });
        $this->assertSame(0, $DB->count_records('local_kbacompany_commthread'));
    }

    public function test_revoked_role_is_not_reactivated_on_existing_thread_and_cannot_post(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $first = task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'Before revoke');
        $thread = $DB->get_field('local_kbacompany_commmsg', 'threaduuid', ['uuid' => $first], MUST_EXIST);
        $this->setAdminUser();
        employee_service::close_membership($f['company'], (int)$f['assignee']->id, $f['admin']);
        // An inactive retained participant must not be resurrected by posting from another current role.
        $DB->set_field('local_kbacompany_commpart', 'active', 0, [
            'companyuuid' => $f['company'], 'threaduuid' => $thread, 'userid' => $f['assignee']->id,
        ]);
        $this->setUser($f['reviewer']);
        task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'After revoke');
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_commpart', 'active', [
            'companyuuid' => $f['company'], 'threaduuid' => $thread, 'userid' => $f['assignee']->id,
        ], MUST_EXIST));
        $this->setUser($f['assignee']);
        $this->assert_denied_without_message(function() use ($f) {
            task_service::post_discussion_message($f['company'], (int)$f['assignee']->id, $f['task'], 'Revoked task write');
        });
        $this->assert_denied_without_message(function() use ($f, $thread) {
            communication_service::send_message($f['company'], (int)$f['assignee']->id, $thread, 'Revoked direct write');
        });
        $this->assertSame((int)$f['assignee']->id, (int)$DB->get_field('local_kbacompany_task', 'assigneeid', ['uuid' => $f['task']]));
        $this->assertTrue($DB->record_exists('local_kbacompany_commmsg', ['uuid' => $first]));
    }

    public function test_authorised_admin_without_employee_row_can_explicitly_post(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'Reviewer');
        $this->setAdminUser();
        $message = task_service::post_discussion_message($f['company'], $f['admin'], $f['task'], 'Explicit authorised manager post');
        $this->assertSame($f['admin'], (int)$DB->get_field('local_kbacompany_commmsg', 'authorid', ['uuid' => $message], MUST_EXIST));
    }

    public function test_retained_active_thread_participant_without_task_access_cannot_post_or_read_files(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['reviewer']);
        $message = task_service::post_discussion_message($f['company'], (int)$f['reviewer']->id, $f['task'], 'Before reassignment');
        $thread = $DB->get_field('local_kbacompany_commmsg', 'threaduuid', ['uuid' => $message], MUST_EXIST);
        // Model a retained communication row after the task role was reassigned elsewhere.
        $DB->set_field('local_kbacompany_task', 'assigneeid', (int)$f['unrelated']->id, ['uuid' => $f['task']]);
        $this->assertTrue($DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $f['company'], 'threaduuid' => $thread, 'userid' => $f['assignee']->id, 'active' => 1,
        ]));
        $this->setUser($f['assignee']);
        $this->assertFalse(task_service::can_view_task($f['company'], (int)$f['assignee']->id, $f['task']));
        $this->assertFalse(communication_service::can_view_message($f['company'], (int)$f['assignee']->id, $message));
        $this->assert_denied_without_message(function() use ($f) {
            task_service::post_discussion_message($f['company'], (int)$f['assignee']->id, $f['task'], 'Stale task role');
        });
        $this->assert_denied_without_message(function() use ($f, $thread) {
            communication_service::send_message($f['company'], (int)$f['assignee']->id, $thread, 'Stale participant');
        });
    }
}
