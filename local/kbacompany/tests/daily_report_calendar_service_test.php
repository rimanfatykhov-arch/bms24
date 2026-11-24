<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_calendar_service as calendar;
use local_kbacompany\local\daily_report_calendar_ui_service as calendarui;
use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R5a "reporting calendar" (owner decisions K1–K6, 24.11.2026): administrator-only calendar with its own
 * switch and start day, weekday pattern and exceptions, deadline on the next working day, absence periods kept by the
 * administrator or the nearest manager only, no overlap, optimistic revisions, journal, company isolation.
 */
final class daily_report_calendar_service_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $cid = repository::save_company((object)['name' => 'Synthetic calendar A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['head', 'lead', 'employee', 'peer'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        // DEPT > TEAM. head manages DEPT, lead manages TEAM; employee and peer work in TEAM.
        $dept = $this->node($company, '', 'DEPT');
        $team = $this->node($company, $dept, 'TEAM');
        $this->assign($company, $dept, (int)$users['head']->id, true);
        $this->assign($company, $team, (int)$users['lead']->id, true);
        $this->assign($company, $team, (int)$users['employee']->id, false);
        $this->assign($company, $team, (int)$users['peer']->id, false);
        return compact('admin', 'company', 'cid') + $users;
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

    public function test_defaults_are_off_and_neutral(): void {
        $f = $this->fixture();
        $cal = calendar::settings($f['company']);
        $this->assertSame(0, (int)$cal->enabled);
        $this->assertSame('1111100', $cal->workdays);
        $this->assertSame(720, (int)$cal->deadlineminutes);
        $this->assertSame(['required' => false, 'reason' => 'disabled'],
            calendar::requirement($f['company'], (int)$f['employee']->id, '2026-11-25'));
    }

    public function test_admin_only_settings_start_day_revision_and_journal(): void {
        global $DB;
        $f = $this->fixture();
        foreach (['lead', 'employee'] as $who) {
            $this->setUser($f[$who]);
            $this->error('dailyreports_notavailable', function() use ($f, $who) {
                calendar::save_settings($f['company'], (int)$f[$who]->id, true, '1111100', 720, 0);
            });
        }
        $this->setAdminUser();
        $this->error('dailyreports_calendar_invalid', function() use ($f) {
            calendar::save_settings($f['company'], $f['admin'], true, '0000000', 720, 0);
        });
        $this->error('dailyreports_calendar_invalid', function() use ($f) {
            calendar::save_settings($f['company'], $f['admin'], true, '1111100', 1440, 0);
        });
        $cal = calendar::save_settings($f['company'], $f['admin'], true, '1111110', 600, 0);
        $today = \local_kbacompany\local\business_date_service::day();
        $this->assertSame([1, $today, '1111110', 600, 1],
            [(int)$cal->enabled, (string)$cal->enabledfrom, (string)$cal->workdays, (int)$cal->deadlineminutes, (int)$cal->revision]);
        $this->error('dailyreports_conflict', function() use ($f) {
            calendar::save_settings($f['company'], $f['admin'], false, '1111100', 720, 0);
        });
        // K5: a day before the start is never required; staying on keeps the start day.
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $this->assertSame('beforestart', calendar::requirement($f['company'], (int)$f['employee']->id, $yesterday)['reason']);
        $cal = calendar::save_settings($f['company'], $f['admin'], true, '1111100', 720, 1);
        $this->assertSame($today, (string)$cal->enabledfrom);
        $this->assertSame(2, $DB->count_records('local_kbacompany_dreportlog',
            ['companyuuid' => $f['company'], 'action' => 'calendar_changed', 'actorid' => $f['admin']]));
    }

    public function test_weekdays_exceptions_and_deadline(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        calendar::save_settings($f['company'], $f['admin'], true, '1111100', 720, 0);
        // Start the calendar in the past so that fixed test dates count.
        $DB->set_field('local_kbacompany_dreportcal', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
        $employee = (int)$f['employee']->id;
        // 2026-11-27 is a Friday, 2026-11-28 a Saturday, 2026-11-30 a Monday.
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-11-27')['reason']);
        $this->assertSame('dayoff', calendar::requirement($f['company'], $employee, '2026-11-28')['reason']);
        $holiday = calendar::add_offday($f['company'], $f['admin'], '2026-11-30', 'holiday', 'Synthetic holiday');
        calendar::add_offday($f['company'], $f['admin'], '2026-11-28', 'workday', 'Transfer');
        $this->error('dailyreports_calendar_dayexists', function() use ($f) {
            calendar::add_offday($f['company'], $f['admin'], '2026-11-30', 'holiday', '');
        });
        $this->error('dailyreports_calendar_invalid', function() use ($f) {
            calendar::add_offday($f['company'], $f['admin'], '2026-02-30', 'holiday', '');
        });
        $this->assertSame('dayoff', calendar::requirement($f['company'], $employee, '2026-11-30')['reason']);
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-11-28')['reason']);
        // Deadline of Friday's report: the transferred working Saturday at 12:00 Moscow time.
        $this->assertSame((new \DateTimeImmutable('2026-11-28 12:00', new \DateTimeZone('Europe/Moscow')))->getTimestamp(),
            calendar::deadline($f['company'], '2026-11-27', 'Europe/Moscow'));
        // Saturday's report: Sunday off, Monday holiday -> Tuesday 12:00 in the employee's time zone.
        $this->assertSame((new \DateTimeImmutable('2026-12-01 12:00', new \DateTimeZone('Asia/Yekaterinburg')))->getTimestamp(),
            calendar::deadline($f['company'], '2026-11-28', 'Asia/Yekaterinburg'));
        calendar::remove_offday($f['company'], $f['admin'], $holiday);
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-11-30')['reason']);
        $this->assertCount(1, calendar::offdays($f['company'], '2026-11-01', '2026-11-30'));
        $this->assertTrue($DB->record_exists('local_kbacompany_dreportlog', ['companyuuid' => $f['company'],
            'action' => 'offday_removed']));
    }

    public function test_absences_by_admin_and_nearest_manager_only(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        calendar::save_settings($f['company'], $f['admin'], true, '1111111', 720, 0);
        $DB->set_field('local_kbacompany_dreportcal', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
        $employee = (int)$f['employee']->id;
        // The nearest manager (lead) manages the team; the upper manager (head) and a peer do not.
        $this->setUser($f['lead']);
        $people = calendar::manageable_people($f['company'], (int)$f['lead']->id);
        $this->assertArrayHasKey($employee, $people);
        $this->assertArrayHasKey((int)$f['peer']->id, $people);
        $this->assertArrayNotHasKey((int)$f['lead']->id, $people);
        $this->assertArrayNotHasKey((int)$f['head']->id, $people);
        $vacation = calendar::add_absence($f['company'], (int)$f['lead']->id, $employee, 'vacation', '2026-12-01', '2026-12-05');
        $this->assertSame('absence', calendar::requirement($f['company'], $employee, '2026-12-03')['reason']);
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-12-07')['reason']);
        $this->error('dailyreports_absence_overlap', function() use ($f, $employee) {
            calendar::add_absence($f['company'], (int)$f['lead']->id, $employee, 'sick', '2026-12-05', '2026-12-06');
        });
        $this->error('dailyreports_absence_invalid', function() use ($f, $employee) {
            calendar::add_absence($f['company'], (int)$f['lead']->id, $employee, 'sick', '2026-12-09', '2026-12-08');
        });
        $this->error('dailyreports_absence_invalid', function() use ($f, $employee) {
            calendar::add_absence($f['company'], (int)$f['lead']->id, $employee, 'diagnosis', '2026-12-09', '2026-12-10');
        });
        $this->error('dailyreports_notavailable', function() use ($f) {
            calendar::add_absence($f['company'], (int)$f['lead']->id, (int)$f['lead']->id, 'vacation', '2027-01-01', '2027-01-02');
        });
        foreach (['head', 'peer'] as $who) {
            $this->setUser($f[$who]);
            $this->error('dailyreports_notavailable', function() use ($f, $who, $employee) {
                calendar::add_absence($f['company'], (int)$f[$who]->id, $employee, 'other', '2027-02-01', '2027-02-02');
            });
            $this->error('dailyreports_notavailable', function() use ($f, $who, $vacation) {
                calendar::change_absence($f['company'], (int)$f[$who]->id, $vacation, 1, 'vacation', '2026-12-01',
                    '2026-12-02');
            });
        }
        // The head manages the lead (nearest manager of the lead).
        $this->setUser($f['head']);
        calendar::add_absence($f['company'], (int)$f['head']->id, (int)$f['lead']->id, 'other', '2027-03-01', '2027-03-01');
        // Changes are revision-checked and journaled; a cancelled period no longer counts.
        $this->setUser($f['lead']);
        calendar::change_absence($f['company'], (int)$f['lead']->id, $vacation, 1, 'vacation', '2026-12-01', '2026-12-02');
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-12-03')['reason']);
        $this->error('dailyreports_conflict', function() use ($f, $vacation) {
            calendar::change_absence($f['company'], (int)$f['lead']->id, $vacation, 1, 'vacation', '2026-12-01', '2026-12-03');
        });
        calendar::change_absence($f['company'], (int)$f['lead']->id, $vacation, 2, '', '', '', true);
        $this->assertSame('workday', calendar::requirement($f['company'], $employee, '2026-12-01')['reason']);
        $actions = $DB->get_fieldset_select('local_kbacompany_dreportlog', 'action',
            "companyuuid = :c AND reportuuid = '' AND " . $DB->sql_like('action', ':a'), ['c' => $f['company'], 'a' => 'absence_%']);
        sort($actions);
        $this->assertSame(['absence_added', 'absence_added', 'absence_cancelled', 'absence_changed'], $actions);
        // The employee sees no absence screen data and cannot add own absences.
        $this->setUser($f['employee']);
        $this->assertSame([], calendar::absences($f['company'], $employee)['items']);
        $this->assertNotContains('absences', review::queues($f['company'], $employee));
        $this->assertNotContains('calendar', review::queues($f['company'], (int)$f['lead']->id));
    }

    public function test_company_isolation_and_revocation(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        $othercid = repository::save_company((object)['name' => 'Synthetic calendar B', 'active' => 1]);
        $other = identity_registry::company_uuid($othercid);
        $stranger = $this->getDataGenerator()->create_user();
        $this->member($othercid, (int)$stranger->id);
        calendar::save_settings($f['company'], $f['admin'], true, '1111100', 720, 0);
        $DB->set_field('local_kbacompany_dreportcal', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
        $absence = calendar::add_absence($f['company'], $f['admin'], (int)$f['employee']->id, 'sick', '2026-12-01', '2026-12-01');
        // A member of company B cannot touch company A, nor manage A's employee from B.
        $this->setUser($stranger);
        foreach ([function() use ($f, $stranger) {
                    calendar::save_settings($f['company'], (int)$stranger->id, false, '1111100', 720, 1);
                }, function() use ($f, $stranger, $absence) {
                    calendar::change_absence($f['company'], (int)$stranger->id, $absence, 1, '', '', '', true);
                }, function() use ($f, $stranger, $other) {
                    calendar::add_absence($other, (int)$stranger->id, (int)$f['employee']->id, 'vacation', '2026-12-10', '2026-12-11');
                }] as $call) {
            try {
                $call();
                $this->fail('cross-company calendar write accepted');
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
        $this->assertSame('active', $DB->get_field('local_kbacompany_dreportabs', 'status', ['uuid' => $absence]));
        $this->assertSame(0, $DB->count_records('local_kbacompany_dreportabs', ['companyuuid' => $other]));
        // A revoked manager loses the screen; a revoked employee is no longer manageable.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['employee']->id, $f['admin']);
        $this->assertFalse(calendar::can_manage_absence($f['company'], $f['admin'], (int)$f['employee']->id));
        membership_access_service::change($f['company'], (int)$f['lead']->id, $f['admin']);
        $this->assertFalse(calendar::can_open_absences($f['company'], (int)$f['lead']->id));
    }

    public function test_screens_render_escaped_and_post_only(): void {
        $f = $this->fixture();
        $this->setAdminUser();
        calendar::add_offday($f['company'], $f['admin'], '2026-12-31', 'holiday', '<script>alert(1)</script>');
        $queues = review::queues($f['company'], $f['admin']);
        $this->assertContains('calendar', $queues);
        $this->assertContains('absences', $queues);
        $html = calendarui::calendar($f['company'], calendar::settings($f['company']),
            calendar::offdays($f['company'], '2026-01-01', '2026-12-31'), $queues);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('data-dailyreport-calendar-form', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $html = calendarui::absences($f['company'], calendar::absences($f['company'], $f['admin']), $queues);
        $this->assertStringContainsString('data-dailyreport-absence-form', $html);
        $this->assertStringNotContainsString('name="userid"', $html);
    }

    public function test_privacy_forgets_absences_and_anonymises_authors(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        calendar::save_settings($f['company'], $f['admin'], true, '1111100', 720, 0);
        $this->setUser($f['lead']);
        $own = calendar::add_absence($f['company'], (int)$f['lead']->id, (int)$f['employee']->id, 'sick', '2026-12-01',
            '2026-12-02');
        $kept = calendar::add_absence($f['company'], (int)$f['lead']->id, (int)$f['peer']->id, 'vacation', '2026-12-01',
            '2026-12-02');
        $system = \context_system::instance();
        foreach ([(int)$f['employee']->id, (int)$f['lead']->id] as $userid) {
            $this->assertContains((int)$system->id, array_map('intval',
                \local_kbacompany\privacy\provider::get_contexts_for_userid($userid)->get_contextids()));
        }
        $this->setAdminUser();
        \local_kbacompany\privacy\provider::delete_data_for_user(
            new \core_privacy\local\request\approved_contextlist($f['employee'], 'local_kbacompany', [$system->id]));
        $this->assertFalse($DB->record_exists('local_kbacompany_dreportabs', ['uuid' => $own]));
        $this->assertFalse($DB->record_exists_select('local_kbacompany_dreportlog',
            $DB->sql_like('details', ':n'), ['n' => '%' . $own . '%']));
        \local_kbacompany\privacy\provider::delete_data_for_user(
            new \core_privacy\local\request\approved_contextlist($f['lead'], 'local_kbacompany', [$system->id]));
        $row = $DB->get_record('local_kbacompany_dreportabs', ['uuid' => $kept], '*', MUST_EXIST);
        $this->assertSame([0, 0, 'vacation'], [(int)$row->createdby, (int)$row->modifiedby, (string)$row->kind]);
        $this->assertSame((int)$f['admin'], (int)$DB->get_field('local_kbacompany_dreportcal', 'modifiedby',
            ['companyuuid' => $f['company']]));
    }
}
