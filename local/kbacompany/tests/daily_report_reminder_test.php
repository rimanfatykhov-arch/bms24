<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\communication_center_ui_service as centerui;
use local_kbacompany\local\daily_report_calendar_service as calendar;
use local_kbacompany\local\daily_report_reminder_service as reminder;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R5c (owner decision K4, 24.11.2026): one morning reminder about an unsent report of the last required
 * working day, on the day its deadline falls and before it. Calendar off, day off, absence, before the calendar start,
 * a sent report, a revoked member or a non-member administrator: no reminder. Idempotent per company, employee and day.
 */
final class daily_report_reminder_test extends \advanced_testcase {
    /** Tuesday 24.11.2026 is the report day; its deadline is Wednesday 25.11.2026 12:00 Moscow time. */
    private const DAY = '2026-11-24';

    private function local_time(string $local): int {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Moscow')))->getTimestamp();
    }

    private function fixture(): array {
        global $USER, $DB;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $cid = repository::save_company((object)['name' => 'Synthetic reminder A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['employee', 'peer', 'absent', 'drafter'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'lang' => 'en']);
            $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => (int)$u->id,
                'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => time(), 'timemodified' => time()]);
            $users[$name] = $u;
        }
        calendar::save_settings($company, $admin, true, '1111100', 720, 0);
        $DB->set_field('local_kbacompany_rptcalendar', 'enabledfrom', '2026-01-01', ['companyuuid' => $company]);
        calendar::add_absence($company, $admin, (int)$users['absent']->id, 'sick', self::DAY, self::DAY);
        $values = ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
        $this->setUser($users['employee']);
        store::submit($company, (int)$users['employee']->id, self::DAY, \core\uuid::generate(), 0, $values);
        // A draft that was never sent is still a missing report.
        $this->setUser($users['drafter']);
        store::save_draft($company, (int)$users['drafter']->id, self::DAY, $values, [], 0);
        $this->setAdminUser();
        return compact('admin', 'company', 'cid') + $users;
    }

    private function reminded(int $userid): int {
        global $DB;
        return $DB->count_records('local_kbacompany_commnotif', ['userid' => $userid, 'kind' => reminder::KIND]);
    }

    public function test_window_follows_calendar_deadline_and_morning(): void {
        $f = $this->fixture();
        $cal = calendar::settings($f['company']);
        $deadline = $this->local_time('2026-11-25 12:00');
        $this->assertNull(reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-25 07:59')));
        $this->assertSame([self::DAY, $deadline],
            reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-25 08:00')));
        $this->assertSame([self::DAY, $deadline],
            reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-25 11:59')));
        $this->assertNull(reminder::window($f['company'], $cal, 'Europe/Moscow', $deadline));
        // Weekend: Friday's deadline is Monday, so Saturday has no reminder and Monday morning has one for Friday.
        $this->assertNull(reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-28 09:00')));
        $this->assertSame(['2026-11-27', $this->local_time('2026-11-30 12:00')],
            reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-30 09:00')));
        // A holiday on Wednesday moves Tuesday's deadline to Thursday.
        calendar::add_offday($f['company'], $f['admin'], '2026-11-25', 'holiday', 'Synthetic');
        $this->assertNull(reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-25 09:00')));
        $this->assertSame([self::DAY, $this->local_time('2026-11-26 12:00')],
            reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-26 09:00')));
        // An early deadline opens the window one hour before it. Saved through the calendar service, because the
        // deadline itself is always read from the stored company calendar (the same source the cron task uses).
        $cal = calendar::save_settings($f['company'], $f['admin'], true, '1111100', 420, (int)$cal->revision);
        $this->assertSame(420, (int)$cal->deadlineminutes);
        $this->assertSame([self::DAY, $this->local_time('2026-11-26 07:00')],
            reminder::window($f['company'], $cal, 'Europe/Moscow', $this->local_time('2026-11-26 06:00')));
    }

    public function test_one_reminder_only_to_members_without_a_sent_report(): void {
        $f = $this->fixture();
        $sink = $this->redirectMessages();
        $this->assertSame(0, reminder::run_due($this->local_time('2026-11-25 07:00'))['sent']);
        $stats = reminder::run_due($this->local_time('2026-11-25 09:00'));
        $this->assertSame(2, $stats['sent']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(1, $this->reminded((int)$f['peer']->id));
        $this->assertSame(1, $this->reminded((int)$f['drafter']->id));
        $this->assertSame(0, $this->reminded((int)$f['employee']->id), 'a sent report needs no reminder');
        $this->assertSame(0, $this->reminded((int)$f['absent']->id), 'absence: not required');
        $this->assertSame(0, $this->reminded($f['admin']), 'an administrator who is not a member is never reminded');
        $this->assertCount(2, $sink->get_messages());
        // Idempotent: the same morning again, and later before the deadline, sends nothing more.
        $this->assertSame(0, reminder::run_due($this->local_time('2026-11-25 09:00'))['sent']);
        $this->assertSame(0, reminder::run_due($this->local_time('2026-11-25 11:00'))['sent']);
        $this->assertSame(1, $this->reminded((int)$f['peer']->id));
        $this->assertCount(2, $sink->get_messages());
        $sink->close();
    }

    public function test_title_link_and_language(): void {
        global $DB;
        $f = $this->fixture();
        reminder::run_due($this->local_time('2026-11-25 09:00'));
        $row = $DB->get_record('local_kbacompany_commnotif', ['userid' => (int)$f['peer']->id, 'kind' => reminder::KIND],
            '*', MUST_EXIST);
        $this->assertSame($f['company'], (string)$row->companyuuid);
        $this->assertStringContainsString('2026', (string)$row->title);
        $this->assertStringContainsString('has not been sent', (string)$row->title);
        [$url, $label] = centerui::daily_report_target($f['company'], reminder::KIND);
        $this->assertSame('dailyreports_tab_my', $label);
        $this->assertStringContainsString('/local/kbacompany/daily_report.php', $url->out(false));
    }

    public function test_nothing_is_sent_when_not_required(): void {
        global $DB;
        $f = $this->fixture();
        // The calendar started after the day (K5).
        $DB->set_field('local_kbacompany_rptcalendar', 'enabledfrom', '2026-11-25', ['companyuuid' => $f['company']]);
        $this->assertSame(0, reminder::run_due($this->local_time('2026-11-25 09:00'))['sent']);
        // The calendar is off (K6).
        $DB->set_field('local_kbacompany_rptcalendar', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
        calendar::save_settings($f['company'], $f['admin'], false, '1111100', 720, 1);
        $this->assertSame(['companies' => 0, 'checked' => 0, 'sent' => 0, 'failed' => 0],
            reminder::run_due($this->local_time('2026-11-25 09:00')));
        // Daily reports switched off globally.
        calendar::save_settings($f['company'], $f['admin'], true, '1111100', 720, 2);
        $DB->set_field('local_kbacompany_rptcalendar', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->assertSame(0, reminder::run_due($this->local_time('2026-11-25 09:00'))['sent']);
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        // A revoked member is not reminded; the others are.
        membership_access_service::change($f['company'], (int)$f['peer']->id, $f['admin']);
        $this->assertSame(1, reminder::run_due($this->local_time('2026-11-25 09:00'))['sent']);
        $this->assertSame(0, $this->reminded((int)$f['peer']->id));
        $this->assertSame(1, $this->reminded((int)$f['drafter']->id));
    }

    public function test_other_company_is_isolated(): void {
        global $DB;
        $f = $this->fixture();
        $othercid = repository::save_company((object)['name' => 'Synthetic reminder B', 'active' => 1]);
        $other = identity_registry::company_uuid($othercid);
        $outsider = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $othercid, 'userid' => (int)$outsider->id,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        // Company B has no calendar: its member gets nothing, company A members are not listed for B.
        $this->assertNotContains((int)$f['peer']->id, reminder::members($other));
        reminder::run_due($this->local_time('2026-11-25 09:00'));
        $this->assertSame(0, $this->reminded((int)$outsider->id));
        $this->assertSame(0, $DB->count_records('local_kbacompany_commnotif', ['companyuuid' => $other]));
    }
}
