<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\communication_center_ui_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\identity_registry;
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
        return ['admin' => $admin, 'a' => $companies['A']['uuid'], 'b' => $companies['B']['uuid'],
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
        communication_service::send_message($f['a'], $f['admin'], $thread, 'Secret');
        $this->setUser($f['employee']);
        $this->assertSame(1, communication_service::notification_feed($f['a'], $f['employee'])['unread']);

        $DB->set_field('local_kbacompany_commpart', 'active', 0,
            ['companyuuid' => $f['a'], 'threaduuid' => $thread, 'userid' => $f['employee']]);
        $feed = communication_service::notification_feed($f['a'], $f['employee']);
        $this->assertSame(0, $feed['unread']);
        $this->assertSame([], array_filter($feed['items'], function($item) use ($thread) {
            return $item['thread_uuid'] === $thread;
        }));
        $payload = json_encode(communication_center_ui_service::bell_feed($f['a'], $f['employee'], $feed));
        $this->assertStringNotContainsString('Private plan', $payload);
        $this->assertStringNotContainsString($thread, $payload);
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
