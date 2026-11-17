<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_service as comm;
use local_kbacompany\local\communication_center_service as center;
use local_kbacompany\local\communication_center_ui_service as ui;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** UX-1 behavior tests use real company membership and canonical notification storage. */
final class communication_center_ux_test extends \advanced_testcase {
    private function fixture(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = repository::save_company((object)['name' => 'UX A', 'active' => 1]);
        $b = repository::save_company((object)['name' => 'UX B', 'active' => 1]);
        $company = identity_registry::company_uuid($a);
        $other = identity_registry::company_uuid($b);
        $employee = $this->getDataGenerator()->create_user();
        $colleague = $this->getDataGenerator()->create_user();
        foreach ([$employee, $colleague] as $u) {
            repository::add_member($a, (int)$u->id, 'employee');
            employee_service::ensure_membership($company, (int)$u->id, [], 'bms');
        }
        return [$company, $other, (int)$employee->id, (int)$colleague->id];
    }

    private function notification(string $company, int $user, string $thread = ''): int {
        global $DB;
        return (int)$DB->insert_record('local_kbacompany_commnotif', (object)[
            'companyuuid' => $company, 'uuid' => '12345678-1234-4abc-8abc-' . bin2hex(random_bytes(6)), 'userid' => $user,
            'kind' => 'calendar_day', 'threaduuid' => $thread, 'messageuuid' => '', 'taskuuid' => '',
            'title' => 'UX notification', 'isread' => 0, 'timecreated' => time(), 'timeread' => 0,
        ]);
    }

    public function test_default_shows_conversations_all_and_badge_excludes_notifications(): void {
        [$c, $other, $a, $b] = $this->fixture();
        $thread = comm::create_thread($c, $b, ['threadtype' => 'direct', 'title' => '', 'participants' => [$a]]);
        comm::send_message($c, $b, $thread, 'Unread conversation');
        $this->notification($c, $a);
        $data = center::workspace_context($c, $a);
        $this->assertSame('messages', $data['tab']);
        $this->assertSame('all', $data['filter']);
        $this->assertContains($thread, array_column($data['threads'], 'uuid'));
        $counts = center::shell_counters($c, $a);
        $this->assertSame(1, $counts['incoming']);
        $this->assertSame(3, $counts['unread_notifications']);
    }

    public function test_notifications_screen_never_opens_or_marks_a_supplied_thread(): void {
        global $DB, $USER;
        [$c, $other, $a, $b] = $this->fixture();
        $this->setUser($a);
        $thread = comm::create_thread($c, $b, ['threadtype' => 'direct', 'title' => '', 'participants' => [$a]]);
        comm::send_message($c, $b, $thread, 'Remain unread');
        $before = $DB->get_records('local_kbacompany_commread', ['companyuuid' => $c, 'userid' => $a]);
        $data = center::workspace_context($c, $a, 'notifications', $thread);
        $this->assertNull($data['selected']);
        $html = ui::workspace($c, $data);
        $this->assertStringContainsString('bms-comm-ux-notifications', $html);
        $this->assertStringNotContainsString('bms-comm2-welcome', $html);
        $this->assertStringNotContainsString('bms-comm2-compose', $html);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_commread', ['companyuuid' => $c, 'userid' => $a]));
    }
}
