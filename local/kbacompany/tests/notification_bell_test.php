<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_center_ui_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\notification_bell_service;
use local_kbacorporate\local\repository;

/**
 * Top bar bell: the feed reuses the canonical notification registry and its ACL, never marks anything read,
 * and the opt-in sound is a per-account preference.
 */
final class notification_bell_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $now = time();
        $companies = [];
        foreach (['A' => 'Bell Company A', 'B' => 'Bell Company B'] as $key => $name) {
            $id = repository::save_company((object)['name' => $name, 'active' => 1]);
            $companies[$key] = ['id' => $id, 'uuid' => identity_registry::company_uuid($id)];
        }
        $employee = $this->getDataGenerator()->create_user();
        $peer = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([[$employee, 'A'], [$peer, 'A'], [$outsider, 'B']] as [$user, $company]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companies[$company]['id'], 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return ['admin' => $admin, 'a' => $companies['A']['uuid'], 'b' => $companies['B']['uuid'], 'bid' => $companies['B']['id'],
            'employee' => (int)$employee->id, 'peer' => (int)$peer->id, 'outsider' => (int)$outsider->id];
    }

    public function test_feed_matches_center_counts_and_marks_nothing_read(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['a'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Bell thread', 'participants' => [$f['employee'], $f['peer']]]);
        communication_service::send_message($f['a'], $f['admin'], $thread, 'First');
        communication_service::send_message($f['a'], $f['admin'], $thread, 'Second');
        communication_service::ensure_system_notification($f['a'], $f['employee'], 'calendar_day',
            hash('sha256', 'bell-day'), 'Day plan');
        $before = $DB->get_records_menu('local_kbacompany_commnotif', ['userid' => $f['employee']], '', 'id,isread');

        $this->setUser($f['employee']);
        $feed = communication_service::notification_feed($f['a'], $f['employee'], 8);
        $this->assertGreaterThanOrEqual(3, count($feed['items']));
        $context = communication_service::context($f['a'], $f['employee'], '', 'inbox');
        $this->assertSame($context['counts']['unread_notifications'], $feed['unread']);
        $this->assertSame(communication_center_service::shell_counters($f['a'], $f['employee'])['unread_notifications'],
            $feed['unread']);
        $ids = array_column($feed['items'], 'uuid');
        $this->assertSame(array_values(array_unique($ids)), $ids, 'stable unique ids');
        $this->assertSame($before, $DB->get_records_menu('local_kbacompany_commnotif', ['userid' => $f['employee']], '', 'id,isread'),
            'reading the feed must not mark notifications read');

        $payload = communication_center_ui_service::bell_feed($f['a'], $f['employee'], $feed);
        $this->assertTrue($payload['ok']);
        $this->assertSame($f['employee'], $payload['user']);
        $this->assertSame($f['a'], $payload['company']);
        $this->assertFalse($payload['sound']);
        foreach ($payload['items'] as $item) {
            $this->assertStringContainsString('uuid=' . $f['a'], $item['url']);
            $this->assertNotSame('', $item['id']);
        }
    }

    public function test_revoked_participation_hides_rows_and_count(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['a'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Private plan', 'participants' => [$f['employee']]]);
        $message = communication_service::send_message($f['a'], $f['admin'], $thread, 'Secret');
        // Exact fixture: create_thread() invites the employee (thread_invite) and send_message() notifies it
        // (message); both rows are bound to this thread. The employee has no other notification in company A.
        $rows = $DB->get_records('local_kbacompany_commnotif', ['companyuuid' => $f['a'], 'userid' => $f['employee']], 'id ASC');
        $this->assertSame(['thread_invite', 'message'], array_values(array_map(function($r) { return (string)$r->kind; }, $rows)));
        foreach ($rows as $row) {
            $this->assertSame($thread, (string)$row->threaduuid);
            $this->assertSame(0, (int)$row->isread);
        }
        $this->assertSame($message, (string)end($rows)->messageuuid);
        $this->setUser($f['employee']);
        $before = communication_service::notification_feed($f['a'], $f['employee']);
        $this->assertSame(2, $before['unread']);
        $this->assertSame(array_values(array_reverse(array_map(function($r) { return (string)$r->uuid; }, $rows))),
            array_column($before['items'], 'uuid'));
        $history = [
            'notif' => $DB->get_records('local_kbacompany_commnotif', ['threaduuid' => $thread], 'id ASC'),
            'msg' => $DB->get_records('local_kbacompany_commmsg', ['threaduuid' => $thread], 'id ASC'),
        ];

        // Participation revoked through the canonical owner action.
        $this->setAdminUser();
        communication_service::remove_participant($f['a'], $f['admin'], $thread, $f['employee']);
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_commpart', 'active',
            ['companyuuid' => $f['a'], 'threaduuid' => $thread, 'userid' => $f['employee']]));
        $this->setUser($f['employee']);
        $feed = communication_service::notification_feed($f['a'], $f['employee']);
        $this->assertSame(0, $feed['unread'], 'no unread visible notification after revocation');
        $this->assertSame(0, communication_service::visible_unread_notification_count($f['a'], $f['employee']));
        $this->assertSame([], $feed['items'], 'the closed conversation is absent from the feed');
        $payload = json_encode(communication_center_ui_service::bell_feed($f['a'], $f['employee'], $feed));
        $this->assertStringNotContainsString('Private plan', $payload);
        $this->assertStringNotContainsString($thread, $payload);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString((string)$row->uuid, $payload);
        }
        // History is preserved: the notification and message rows are untouched (still unread, same content).
        $this->assertEquals($history['notif'], $DB->get_records('local_kbacompany_commnotif', ['threaduuid' => $thread], 'id ASC'));
        $this->assertEquals($history['msg'], $DB->get_records('local_kbacompany_commmsg', ['threaduuid' => $thread], 'id ASC'));
    }

    public function test_foreign_company_feed_is_refused(): void {
        $f = $this->fixture();
        communication_service::ensure_system_notification($f['a'], $f['employee'], 'calendar_day',
            hash('sha256', 'bell-foreign'), 'Company A day');
        $this->setUser($f['outsider']);
        $this->assertSame(0, communication_service::notification_feed($f['b'], $f['outsider'])['unread']);
        $this->expectException(\moodle_exception::class);
        communication_service::notification_feed($f['a'], $f['outsider']);
    }

    public function test_signal_claim_is_granted_once_per_new_batch(): void {
        global $DB;
        $f = $this->fixture();
        $n1 = $this->system_notification($f['a'], $f['employee'], 'claim-1');
        $this->setUser($f['employee']);
        // Sound off: never granted.
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n1]));
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        // First tab wins, a second tab (or a repeated poll) with the same batch does not.
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n1]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n1]));
        // A new notification is a new batch; claiming it together with the old one still plays once.
        $n2 = $this->system_notification($f['a'], $f['employee'], 'claim-2');
        $n3 = $this->system_notification($f['a'], $f['employee'], 'claim-3');
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n1, $n2, $n3]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n3]));
        // Read, stale, malformed, another user's or another company's rows are never granted.
        $n4 = $this->system_notification($f['a'], $f['employee'], 'claim-4');
        $DB->set_field('local_kbacompany_commnotif', 'isread', 1, ['uuid' => $n4]);
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n4]));
        $n5 = $this->system_notification($f['a'], $f['employee'], 'claim-5');
        $DB->set_field('local_kbacompany_commnotif', 'timecreated', time() - 3600, ['uuid' => $n5]);
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$n5]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], ['not-a-uuid', '']));
        $peer = $this->system_notification($f['a'], $f['peer'], 'claim-peer');
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$peer]));
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_commnotif', 'isread', ['uuid' => $n1]),
            'claiming never marks read');
        $this->setUser($f['outsider']);
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['outsider']);
        $this->expectException(\moodle_exception::class);
        notification_bell_service::claim_signal($f['a'], $f['outsider'], [$n1]);
    }

    public function test_signal_claim_never_suppresses_an_older_unsignalled_id(): void {
        global $DB;
        $f = $this->fixture();
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        $this->setUser($f['employee']);
        // Same company, claimed out of creation order: id(c1) < id(c2), c2 sounds first, c1 must still sound once.
        $c1 = $this->system_notification($f['a'], $f['employee'], 'order-1');
        $c2 = $this->system_notification($f['a'], $f['employee'], 'order-2');
        $this->assertLessThan($this->notification_id($c2), $this->notification_id($c1));
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$c2]));
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$c1]), 'older id suppressed');
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$c1]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$c2, $c1]));

        // Two companies of the same account: the newer company B row sounds first, the older company A row still sounds.
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $f['bid'],
            'userid' => $f['employee'], 'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
            'isdepartmentmanager' => 0, 'ishr' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->assertTrue($this->member_of($f['b'], $f['employee']), 'fixture: employee also belongs to company B');
        $a1 = $this->system_notification($f['a'], $f['employee'], 'cross-a');
        $b2 = $this->system_notification($f['b'], $f['employee'], 'cross-b');
        $this->assertLessThan($this->notification_id($b2), $this->notification_id($a1));
        $this->assertTrue(notification_bell_service::claim_signal($f['b'], $f['employee'], [$b2]));
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$a1]), 'company A row suppressed by company B');
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$a1]));
        $this->assertFalse(notification_bell_service::claim_signal($f['b'], $f['employee'], [$b2]));
        // A row is only claimable in its own company context.
        $a3 = $this->system_notification($f['a'], $f['employee'], 'cross-a3');
        $this->assertFalse(notification_bell_service::claim_signal($f['b'], $f['employee'], [$a3]));
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$a3]));
    }

    public function test_signal_set_is_pruned_to_claimable_rows(): void {
        global $DB;
        $f = $this->fixture();
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        $this->setUser($f['employee']);
        $old = $this->system_notification($f['a'], $f['employee'], 'prune-old');
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$old]));
        $DB->set_field('local_kbacompany_commnotif', 'timecreated', time() - 3600, ['uuid' => $old]);
        $new = $this->system_notification($f['a'], $f['employee'], 'prune-new');
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$new]));
        $stored = explode(',', (string)$DB->get_field('user_preferences', 'value',
            ['userid' => $f['employee'], 'name' => notification_bell_service::SIGNALLED_PREFERENCE]));
        $this->assertSame([(string)$this->notification_id($new)], $stored, 'stale ids are pruned, new id kept');
        // An unreadable stored value is treated as an empty set (the row may sound once more), never as an error.
        set_user_preference(notification_bell_service::SIGNALLED_PREFERENCE, 'x,,-5,abc', $f['employee']);
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$new]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$new]));
    }

    private function notification_id(string $uuid): int {
        global $DB;
        return (int)$DB->get_field('local_kbacompany_commnotif', 'id', ['uuid' => $uuid], MUST_EXIST);
    }

    public function test_large_signal_set_preserves_unclaimed_ids_and_prunes_expired_chunks(): void {
        global $DB;
        $f = $this->fixture();
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        $this->setUser($f['employee']);
        $unclaimed = $this->system_notification($f['a'], $f['employee'], 'large-unclaimed');
        $claimed = [];
        for ($i = 0; $i < 400; $i++) {
            $claimed[] = $this->system_notification($f['a'], $f['employee'], 'large-' . $i);
        }
        foreach (array_chunk($claimed, notification_bell_service::MAX_CLAIM) as $chunk) {
            $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], $chunk));
        }
        $stored = notification_bell_service::signal_preferences($f['employee']);
        $this->assertGreaterThan(1300, strlen(implode(',', $stored)));
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$unclaimed]));
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], [$unclaimed, end($claimed)]));
        $DB->set_field('local_kbacompany_commnotif', 'timecreated', time() - 3600, ['userid' => $f['employee']]);
        $fresh = $this->system_notification($f['a'], $f['employee'], 'large-fresh');
        $this->assertTrue(notification_bell_service::claim_signal($f['a'], $f['employee'], [$fresh]));
        $this->assertSame([notification_bell_service::SIGNALLED_PREFERENCE => (string)$this->notification_id($fresh)],
            notification_bell_service::signal_preferences($f['employee']));
    }

    private function member_of(string $company, int $userid): bool {
        try {
            \local_kbacompany\local\company_context_service::context($company, $userid);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    public function test_signal_claim_ignores_revoked_thread(): void {
        global $DB;
        $f = $this->fixture();
        $thread = communication_service::create_thread($f['a'], $f['admin'], [
            'threadtype' => 'group', 'title' => 'Claim thread', 'participants' => [$f['employee']]]);
        communication_service::send_message($f['a'], $f['admin'], $thread, 'Hi');
        $ids = $DB->get_fieldset_select('local_kbacompany_commnotif', 'uuid', 'threaduuid = ? AND userid = ?',
            [$thread, $f['employee']]);
        $this->assertCount(2, $ids);
        communication_service::remove_participant($f['a'], $f['admin'], $thread, $f['employee']);
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        $this->setUser($f['employee']);
        $this->assertFalse(notification_bell_service::claim_signal($f['a'], $f['employee'], $ids));
    }

    private function system_notification(string $company, int $userid, string $tag): string {
        global $DB;
        communication_service::ensure_system_notification($company, $userid, 'calendar_day', hash('sha256', $tag), 'Bell ' . $tag);
        return (string)$DB->get_field('local_kbacompany_commnotif', 'uuid',
            ['companyuuid' => $company, 'userid' => $userid, 'title' => 'Bell ' . $tag], MUST_EXIST);
    }

    public function test_sound_preference_is_per_account_and_off_by_default(): void {
        $f = $this->fixture();
        $this->assertFalse(communication_center_ui_service::sound_enabled($f['employee']));
        set_user_preference(communication_center_ui_service::SOUND_PREFERENCE, '1', $f['employee']);
        $this->assertTrue(communication_center_ui_service::sound_enabled($f['employee']));
        $this->assertFalse(communication_center_ui_service::sound_enabled($f['peer']));
        $model = communication_center_ui_service::bell($f['a'], $f['peer']);
        $this->assertFalse($model['sound']);
        $this->assertSame($f['peer'], $model['userid']);
    }
}
