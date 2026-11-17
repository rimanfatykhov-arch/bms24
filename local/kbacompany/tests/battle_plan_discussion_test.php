<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_discussion_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_object_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Communication Center stage E2 (owner decision Q9, 2026-11-16): one Battle Plan discussion, readable by everyone who
 * may open the plan, written only by explicit participants with the plan write-to-discussion right; a message never
 * changes the plan. Real domain services, no mocked permission checks.
 */
final class battle_plan_discussion_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $companyid = repository::save_company((object)['name' => 'Plan discussion A', 'active' => 1]);
        $otherid = repository::save_company((object)['name' => 'Plan discussion B', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $other = identity_registry::company_uuid($otherid);
        $gen = $this->getDataGenerator();
        $owner = $gen->create_user(['firstname' => 'Olga', 'lastname' => 'Owner']);
        $reviewer = $gen->create_user(['firstname' => 'Roman', 'lastname' => 'Reviewer']);
        $unrelated = $gen->create_user(['firstname' => 'Uma', 'lastname' => 'Unrelated']);
        $manager = $gen->create_user(['firstname' => 'Mark', 'lastname' => 'Manager']);
        $outsider = $gen->create_user(['firstname' => 'Otto', 'lastname' => 'Outsider']);
        $now = time();
        foreach ([[$owner, 0, $companyid], [$reviewer, 1, $companyid], [$unrelated, 0, $companyid],
                [$manager, 0, $companyid], [$outsider, 0, $otherid]] as [$user, $ismanager, $cid]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $cid, 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => $ismanager, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        // A company-wide plan reader who is not one of the plan people (site administrator manages plans).
        set_config('siteadmins', $CFG->siteadmins . ',' . (int)$manager->id);
        $positionuuid = '20000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $company, 'branchuuid' => '20000000-0000-4000-8000-000000000002',
            'orgnodeuuid' => '20000000-0000-4000-8000-000000000003', 'uuid' => $positionuuid,
            'code' => 'P-DISC', 'name' => 'Discussion position', 'purpose' => 'Produce results', 'ismanager' => 0,
            'plannedcount' => 1, 'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid' => $company, 'positionuuid' => $positionuuid, 'slotuuid' => '',
            'userid' => (int)$owner->id, 'assignmenttype' => 'primary', 'effectivefrom' => $now - 60,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $plan = battle_plan_service::create_plan($company, $admin, (int)$owner->id, $positionuuid,
            '2026-11-16', (int)$reviewer->id);
        return compact('admin', 'company', 'other', 'owner', 'reviewer', 'unrelated', 'manager', 'outsider', 'plan');
    }

    private function planrow(array $f): array {
        global $DB;
        $row = (array)$DB->get_record('local_kbacompany_bplan', ['companyuuid' => $f['company'], 'uuid' => $f['plan']]);
        unset($row['id']);
        return $row;
    }

    public function test_plan_people_discuss_and_a_message_never_changes_the_plan(): void {
        global $DB;
        $f = $this->fixture();
        $before = $this->planrow($f);
        $this->setUser($f['owner']);
        $message = battle_plan_discussion_service::post($f['company'], (int)$f['owner']->id, $f['plan'], 'Plan question');
        $thread = battle_plan_discussion_service::thread($f['company'], $f['plan']);
        $this->assertNotNull($thread);
        $this->assertSame('battleplan', (string)$thread->contextkind);
        // The reviewer is a participant from the start and reads the same canonical message.
        $this->setUser($f['reviewer']);
        $ctx = battle_plan_discussion_service::context($f['company'], (int)$f['reviewer']->id, $f['plan']);
        $this->assertTrue($ctx['is_participant']);
        $this->assertTrue($ctx['can_post']);
        $this->assertSame([$message], array_column($ctx['messages'], 'uuid'));
        $this->assertTrue($DB->record_exists('local_kbacompany_commnotif',
            ['messageuuid' => $message, 'userid' => (int)$f['reviewer']->id]));
        $reply = battle_plan_discussion_service::post($f['company'], (int)$f['reviewer']->id, $f['plan'], 'Answer', null, $message);
        $messages = array_column(battle_plan_discussion_service::context($f['company'], (int)$f['reviewer']->id,
            $f['plan'])['messages'], null, 'uuid');
        $this->assertSame($message, $messages[$reply]['reply_to']['uuid']);
        // Discussion never changes the plan.
        $this->assertSame($before, $this->planrow($f));
        $this->assertSame(1, $DB->count_records('local_kbacompany_commthread',
            ['companyuuid' => $f['company'], 'contextkind' => 'battleplan', 'contextuuid' => $f['plan']]));
    }

    public function test_plan_reader_reads_without_joining_and_joins_only_by_posting(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['owner']);
        $message = battle_plan_discussion_service::post($f['company'], (int)$f['owner']->id, $f['plan'], 'Visible to readers');
        $this->setUser($f['manager']);
        $manager = (int)$f['manager']->id;
        $ctx = battle_plan_discussion_service::context($f['company'], $manager, $f['plan']);
        $this->assertFalse($ctx['is_participant']);
        $this->assertSame([$message], array_column($ctx['messages'], 'uuid'));
        $this->assertFalse($DB->record_exists('local_kbacompany_commpart', ['threaduuid' => $ctx['thread_uuid'], 'userid' => $manager]));
        $this->assertFalse($DB->record_exists('local_kbacompany_commread', ['threaduuid' => $ctx['thread_uuid'], 'userid' => $manager]));
        $this->assertTrue(communication_service::can_view_message($f['company'], $manager, $message));
        // The Center shows the same thread read-only with an explicit join, and the way back to the plan.
        $center = communication_center_service::workspace_context($f['company'], $manager, 'discussions', $ctx['thread_uuid']);
        $this->assertFalse($center['selected']['is_participant']);
        $this->assertTrue($center['selected']['can_join']);
        $this->assertStringContainsString('battle_plan.php', $center['selected']['context']['url']);
        $this->assertStringContainsString('tab=discussion', $center['selected']['context']['url']);
        $this->assertSame([$message], array_column($center['messages'], 'uuid'));
        battle_plan_discussion_service::post($f['company'], $manager, $f['plan'], 'Manager joins');
        $this->assertTrue(battle_plan_discussion_service::context($f['company'], $manager, $f['plan'])['is_participant']);
    }

    public function test_plan_discussion_is_closed_without_plan_access_in_other_company_and_after_revocation(): void {
        $f = $this->fixture();
        $this->setUser($f['owner']);
        $message = battle_plan_discussion_service::post($f['company'], (int)$f['owner']->id, $f['plan'], 'Closed circle');
        $thread = battle_plan_discussion_service::thread($f['company'], $f['plan']);
        foreach ([[$f['unrelated'], $f['company']], [$f['outsider'], $f['company']], [$f['outsider'], $f['other']]] as [$user, $company]) {
            $this->setUser($user);
            $uid = (int)$user->id;
            foreach ([
                static function() use ($company, $uid, $f): void {
                    battle_plan_discussion_service::context($company, $uid, $f['plan']);
                },
                static function() use ($company, $uid, $thread): void {
                    communication_service::context($company, $uid, (string)$thread->uuid, 'all');
                },
                static function() use ($company, $uid, $f): void {
                    battle_plan_discussion_service::post($company, $uid, $f['plan'], 'Forbidden');
                },
                static function() use ($company, $uid, $f): void {
                    communication_object_service::describe($company, $uid, 'battleplan', $f['plan']);
                },
            ] as $call) {
                try {
                    $call();
                    $this->fail('Plan discussion leaked outside plan access');
                } catch (\moodle_exception $e) {
                    $this->assertNotEmpty($e->errorcode);
                }
            }
            $this->assertFalse(battle_plan_discussion_service::can_post_for($company, $uid, $f['plan']));
            $this->assertFalse(communication_service::can_view_message($company, $uid, $message));
        }
        // Revoked membership closes the thread and its files for a former participant.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['reviewer']->id, $f['admin']);
        $this->setUser($f['reviewer']);
        $this->assertFalse(communication_service::can_view_message($f['company'], (int)$f['reviewer']->id, $message));
        $this->expectException(\moodle_exception::class);
        battle_plan_discussion_service::context($f['company'], (int)$f['reviewer']->id, $f['plan']);
    }

    public function test_contract_and_kinds_declare_the_plan_discussion(): void {
        $this->assertContains('battleplan', communication_service::CONTEXT_KINDS);
        $this->assertContains('battleplan', communication_service::VIEWER_READ_KINDS);
        $this->assertContains('battle_plan_discussion_is_readable_by_plan_viewers_and_never_changes_the_plan',
            communication_service::contract()['rules']);
    }
}
