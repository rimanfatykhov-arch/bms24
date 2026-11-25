<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_object_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\daily_report_discussion_service as discussion;
use local_kbacompany\local\daily_report_discussion_ui_service as discussionui;
use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\organization_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R3 "report discussion" (owner decision 24.11.2026): one canonical thread per report, read by everyone who
 * can open the report (author, reviewers, copy recipients, managers and administrators), written only by the author and
 * the reviewer of the current version; never by a copy recipient, a manager, a former reviewer, a revoked member or
 * another company. A message never changes the report.
 */
final class daily_report_discussion_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic discussion A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['head', 'lead', 'employee', 'peer', 'outsider'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'firstname' => ucfirst($name),
                'lastname' => 'Synthetic']);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        // DEPT > TEAM. head manages DEPT, lead manages TEAM; employee and peer work in TEAM; outsider has no unit.
        $dept = $this->node($company, '', 'DEPT');
        $team = $this->node($company, $dept, 'TEAM');
        $this->assign($company, $dept, (int)$users['head']->id, true);
        $this->assign($company, $team, (int)$users['lead']->id, true);
        $this->assign($company, $team, (int)$users['employee']->id, false);
        $this->assign($company, $team, (int)$users['peer']->id, false);
        $day = (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $this->setUser($users['employee']);
        // The employee copies the report to a peer (ADR-0010 D5); the nearest manager (lead) reviews it.
        $sent = store::submit($company, (int)$users['employee']->id, $day, \core\uuid::generate(), 0,
            ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => ''],
            [], [(int)$users['peer']->id]);
        return compact('admin', 'company', 'cid', 'day', 'sent') + $users;
    }

    private function member(int $cid, int $userid): void {
        global $DB;
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $userid,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
    }

    private function node(string $company, string $parent, string $code): string {
        global $DB;
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => $this->branch,
            'uuid' => $uuid, 'parentuuid' => $parent, 'code' => $code, 'nodetype' => 'department', 'name' => $code,
            'sortorder' => 0, 'color' => '', 'sourcecomponent' => 'local_kbacompany', 'sourceentity' => 'test',
            'sourceid' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        return $uuid;
    }

    private function assign(string $company, string $node, int $userid, bool $manager): void {
        global $DB;
        $position = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => $this->branch,
            'orgnodeuuid' => $node, 'uuid' => $position, 'code' => substr($position, 0, 8), 'name' => 'P',
            'purpose' => '', 'ismanager' => $manager ? 1 : 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $company, 'positionuuid' => $position,
            'slotuuid' => '', 'userid' => $userid, 'assignmenttype' => 'primary', 'assignmentfte' => 1, 'effectivefrom' => 0,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_kind_is_registered_and_viewer_readable(): void {
        $this->assertContains(discussion::KIND, communication_service::CONTEXT_KINDS);
        $this->assertContains(discussion::KIND, communication_service::VIEWER_READ_KINDS);
        $this->assertContains('daily_report_discussion_is_readable_by_report_readers_and_writable_by_author_and_current_reviewer',
            communication_service::contract()['rules']);
        // The commthread context kind column must hold the identifier.
        $this->assertLessThanOrEqual(16, strlen(discussion::KIND));
    }

    public function test_author_and_reviewer_write_one_thread_without_changing_the_report(): void {
        global $DB;
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $this->assertSame((int)$f['lead']->id, discussion::target($f['company'], (int)$f['employee']->id, $report)['reviewer_id']);
        $before = $DB->get_record('local_kbacompany_dreport', ['uuid' => $report]);

        $this->setUser($f['employee']);
        $first = discussion::post($f['company'], (int)$f['employee']->id, $report, 'Question about the result');
        $this->setUser($f['lead']);
        $this->assertTrue(discussion::can_post_for($f['company'], (int)$f['lead']->id, $report));
        discussion::post($f['company'], (int)$f['lead']->id, $report, 'Answer', $first);

        $threads = $DB->get_records('local_kbacompany_commthread', ['companyuuid' => $f['company'],
            'contextkind' => discussion::KIND, 'contextuuid' => $report]);
        $this->assertCount(1, $threads);
        $thread = reset($threads);
        $this->assertSame('object', (string)$thread->threadtype);
        $parts = $DB->get_fieldset_select('local_kbacompany_commpart', 'userid',
            'companyuuid = :c AND threaduuid = :t AND active = 1', ['c' => $f['company'], 't' => $thread->uuid]);
        sort($parts);
        $expected = [(int)$f['lead']->id, (int)$f['employee']->id];
        sort($expected);
        $this->assertEquals($expected, array_map('intval', $parts));
        $ctx = discussion::context($f['company'], (int)$f['lead']->id, $report);
        $this->assertCount(2, $ctx['messages']);
        $replies = array_values(array_filter($ctx['messages'], static function(array $m): bool {
            return !empty($m['reply_to']);
        }));
        $this->assertCount(1, $replies);
        $this->assertSame($first, $replies[0]['reply_to']['uuid']);

        // The report itself is untouched: no decision, no status or version change.
        $after = $DB->get_record('local_kbacompany_dreport', ['uuid' => $report]);
        $this->assertSame((string)$before->status, (string)$after->status);
        $this->assertSame((string)$before->currentversionuuid, (string)$after->currentversionuuid);
        $this->assertFalse($DB->record_exists('local_kbacompany_dreportrev', ['reportuuid' => $report]));
        // The object link points the author to the own day view and the reviewer to the review card.
        $this->assertStringContainsString('day=' . $f['day'],
            communication_object_service::describe($f['company'], (int)$f['employee']->id, discussion::KIND, $report)['url']);
        $this->assertStringContainsString('tab=review',
            communication_object_service::describe($f['company'], (int)$f['lead']->id, discussion::KIND, $report)['url']);
    }

    public function test_copy_recipient_manager_and_admin_read_but_do_not_write(): void {
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $this->setUser($f['employee']);
        $thread = communication_service::create_thread($f['company'], (int)$f['employee']->id, [
            'threadtype' => 'object', 'title' => '', 'contextkind' => discussion::KIND, 'contextuuid' => $report]);
        discussion::post($f['company'], (int)$f['employee']->id, $report, 'Visible to readers');

        foreach (['peer', 'head'] as $reader) {
            $this->setUser($f[$reader]);
            $ctx = discussion::context($f['company'], (int)$f[$reader]->id, $report);
            $this->assertCount(1, $ctx['messages'], $reader);
            $this->assertFalse($ctx['can_post'], $reader);
            $this->assertFalse($ctx['is_participant'], $reader);
            $this->error('dailyreports_discussion_readonly', function() use ($f, $reader, $report) {
                discussion::post($f['company'], (int)$f[$reader]->id, $report, 'Not allowed');
            });
        }
        $this->setAdminUser();
        $this->assertFalse(discussion::context($f['company'], $f['admin'], $report)['can_post']);

        // Even added as a participant by the thread owner, a copy recipient stays a reader (rule re-checked on send).
        $this->setUser($f['employee']);
        communication_service::add_participant($f['company'], (int)$f['employee']->id, $thread, (int)$f['peer']->id);
        $this->setUser($f['peer']);
        $this->error('communication_permission', function() use ($f, $thread) {
            communication_service::send_message($f['company'], (int)$f['peer']->id, $thread, 'Bypass');
        });
    }

    public function test_unrelated_revoked_foreign_and_unsent_are_indistinguishable(): void {
        global $DB;
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $this->setUser($f['outsider']);
        foreach ([$report, \core\uuid::generate()] as $uuid) {
            $this->error('dailyreports_notavailable', function() use ($f, $uuid) {
                discussion::target($f['company'], (int)$f['outsider']->id, $uuid);
            });
            $this->error('communication_contextunavailable', function() use ($f, $uuid) {
                communication_object_service::describe($f['company'], (int)$f['outsider']->id, discussion::KIND, $uuid);
            });
        }
        // A draft that was never sent has no discussion, even for its author.
        store::save_draft($f['company'], (int)$f['outsider']->id, $f['day'],
            ['planned' => 'P', 'done' => 'Private', 'notdone' => '', 'blockers' => '', 'nextplan' => ''], [], 0);
        $draft = $DB->get_field('local_kbacompany_dreport', 'uuid',
            ['companyuuid' => $f['company'], 'userid' => (int)$f['outsider']->id]);
        $this->error('dailyreports_notavailable', function() use ($f, $draft) {
            discussion::post($f['company'], (int)$f['outsider']->id, $draft, 'Hello');
        });

        // Another company: its member does not see the report even with the right UUID.
        $this->setAdminUser();
        $othercid = repository::save_company((object)['name' => 'Synthetic discussion B', 'active' => 1]);
        $other = identity_registry::company_uuid($othercid);
        $stranger = $this->getDataGenerator()->create_user();
        $this->member($othercid, (int)$stranger->id);
        $this->setUser($stranger);
        $this->assertFalse(discussion::can_post_for($f['company'], (int)$stranger->id, $report));
        $this->assertFalse(discussion::can_post_for($other, (int)$stranger->id, $report));

        // A revoked reviewer neither reads nor writes.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['lead']->id, $f['admin']);
        $this->setUser($f['lead']);
        $this->assertFalse(discussion::can_post_for($f['company'], (int)$f['lead']->id, $report));
        $this->expectException(\moodle_exception::class);
        discussion::context($f['company'], (int)$f['lead']->id, $report);
    }

    public function test_reassigned_reviewer_stops_writing_and_new_reviewer_writes(): void {
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $this->setUser($f['lead']);
        discussion::post($f['company'], (int)$f['lead']->id, $report, 'Before reassignment');
        $thread = discussion::thread($f['company'], $report);
        $this->setAdminUser();
        review::reassign($f['company'], $f['admin'], $report, (int)$f['head']->id);

        $this->setUser($f['lead']);
        $this->assertFalse(discussion::can_post_for($f['company'], (int)$f['lead']->id, $report));
        $this->error('communication_permission', function() use ($f, $thread) {
            communication_service::send_message($f['company'], (int)$f['lead']->id, (string)$thread->uuid, 'Too late');
        });
        $this->setUser($f['head']);
        $this->assertTrue(discussion::can_post_for($f['company'], (int)$f['head']->id, $report));
        discussion::post($f['company'], (int)$f['head']->id, $report, 'New reviewer');
        $this->assertCount(2, discussion::context($f['company'], (int)$f['head']->id, $report)['messages']);
    }

    public function test_section_escapes_messages_and_shows_composer_only_to_writers(): void {
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $this->setUser($f['employee']);
        discussion::post($f['company'], (int)$f['employee']->id, $report, '<script>alert(1)</script> done');
        $html = discussionui::render($f['company'], discussion::context($f['company'], (int)$f['employee']->id, $report));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('data-dailyreport-discussion-composer', $html);
        $this->assertStringContainsString('name="action" value="discuss"', $html);
        $this->setUser($f['peer']);
        $html = discussionui::render($f['company'], discussion::context($f['company'], (int)$f['peer']->id, $report));
        $this->assertStringNotContainsString('data-dailyreport-discussion-composer', $html);
        $this->assertStringContainsString('data-dailyreport-discussion-readonly', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
    public function test_author_writes_first_when_the_reviewer_is_a_manager_outside_the_staff_roster(): void {
        global $DB;
        $f = $this->fixture();
        $report = $f['sent']['reportuuid'];
        $lead = (int)$f['lead']->id; $employee = (int)$f['employee']->id;
        // The reviewer is a company manager without a staff row (as company owners/managers often are).
        $DB->set_field('local_kbacorp_member', 'membertype', 'manager', ['companyid' => $f['cid'], 'userid' => $lead]);
        $DB->set_field('local_kbacorp_member', 'isemployee', 0, ['companyid' => $f['cid'], 'userid' => $lead]);
        $DB->set_field('local_kbacorp_member', 'ismanager', 1, ['companyid' => $f['cid'], 'userid' => $lead]);
        $this->setUser($f['employee']);
        $roster = array_map('intval', array_column(
            organization_service::organization_context($f['company'], $employee)['employees'], 'user_id'));
        $this->assertNotContains($lead, $roster, 'precondition: the reviewer is not on the staff roster');
        $this->assertSame($lead, discussion::target($f['company'], $employee, $report)['reviewer_id']);
        $participants = function(string $thread) use ($DB, $f): array {
            $ids = array_map('intval', $DB->get_fieldset_select('local_kbacompany_commpart', 'userid',
                'companyuuid = :c AND threaduuid = :t AND active = 1', ['c' => $f['company'], 't' => $thread]));
            sort($ids);
            return $ids;
        };
        $bodies = function(int $actor) use ($f, $report): array {
            return array_values(array_map(static function(array $m): string {
                return (string)$m['body'];
            }, discussion::context($f['company'], $actor, $report)['messages']));
        };

        // The author's first message is stored; the reviewer is not force-added but reads it by the report read rule.
        discussion::post($f['company'], $employee, $report, 'Author first');
        $thread = (string)discussion::thread($f['company'], $report)->uuid;
        $this->assertSame([$employee], $participants($thread));
        $this->setUser($f['lead']);
        $this->assertTrue(discussion::can_post_for($f['company'], $lead, $report));
        $this->assertSame(['Author first'], $bodies($lead));
        // The reviewer answers in the same thread (posting joins), and the author reads both in order.
        discussion::post($f['company'], $lead, $report, 'Reviewer answer');
        $this->assertSame($thread, (string)discussion::thread($f['company'], $report)->uuid);
        $expected = [$employee, $lead];
        sort($expected);
        $this->assertSame($expected, $participants($thread));
        $this->setUser($f['employee']);
        $this->assertSame(['Author first', 'Reviewer answer'], $bodies($employee));
    }
}
