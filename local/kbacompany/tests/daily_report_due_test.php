<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_calendar_service as calendar;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_team_service as team;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R5b (owner decisions K3, K5, 24.11.2026): due states from the company reporting calendar. While the
 * calendar is off, or before its start day, everything stays "neutral" (the R4 view). Otherwise: day off / absence ->
 * not required; required day -> expected until the deadline (12:00 of the next working day in the employee's time zone),
 * not submitted after it, submitted on time or late by the first submitted version. Computed on read; nothing stored.
 */
final class daily_report_due_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic due A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['head', 'lead', 'employee', 'peer', 'absent'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'firstname' => ucfirst($name),
                'lastname' => 'Synthetic']);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        $dept = $this->node($company, '', 'DEPT');
        $team = $this->node($company, $dept, 'TEAM');
        $this->assign($company, $dept, (int)$users['head']->id, true);
        $this->assign($company, $team, (int)$users['lead']->id, true);
        foreach (['employee', 'peer', 'absent'] as $name) {
            $this->assign($company, $team, (int)$users[$name]->id, false);
        }
        $old = (new \DateTimeImmutable('-3 days', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        return compact('admin', 'company', 'cid', 'old') + $users;
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

    /** Calendar on, every weekday working, counted from long ago (the start day itself is covered in R5a tests). */
    private function calendar_on(array $f, string $workdays = '1111111'): void {
        global $DB;
        $this->setAdminUser();
        calendar::save_settings($f['company'], $f['admin'], true, $workdays, 720, 0);
        $DB->set_field('local_kbacompany_rptcalendar', 'enabledfrom', '2026-01-01', ['companyuuid' => $f['company']]);
    }

    private function submit(array $f, string $who, string $day): array {
        $this->setUser($f[$who]);
        return store::submit($f['company'], (int)$f[$who]->id, $day, \core\uuid::generate(), 0,
            ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => '']);
    }

    private function rows(array $list): array {
        $out = [];
        foreach ($list['items'] as $row) {
            $out[(int)$row['userid']] = $row;
        }
        return $out;
    }

    public function test_due_matrix(): void {
        $this->resetAfterTest();
        $deadline = 1000;
        $this->assertSame('neutral', calendar::due('disabled', 0, 0, 5000));
        $this->assertSame('neutral', calendar::due('beforestart', 900, 0, 5000));
        $this->assertSame('notrequired', calendar::due('dayoff', 0, 0, 5000));
        $this->assertSame('notrequired', calendar::due('absence', 2000, 0, 5000));
        $this->assertSame('expected', calendar::due('workday', 0, $deadline, 999));
        $this->assertSame('missed', calendar::due('workday', 0, $deadline, 1000));
        $this->assertSame('ontime', calendar::due('workday', 1000, $deadline, 5000));
        $this->assertSame('late', calendar::due('workday', 1001, $deadline, 5000));
    }

    public function test_requirements_in_one_pass(): void {
        $f = $this->fixture();
        $ids = [(int)$f['employee']->id, (int)$f['absent']->id];
        $this->assertSame(array_fill_keys($ids, 'disabled'), calendar::requirements($f['company'], $ids, $f['old']));
        $this->calendar_on($f);
        calendar::add_absence($f['company'], $f['admin'], (int)$f['absent']->id, 'sick', $f['old'], $f['old']);
        $this->assertSame([$ids[0] => 'workday', $ids[1] => 'absence'], calendar::requirements($f['company'], $ids, $f['old']));
        $this->assertSame('beforestart', calendar::requirements($f['company'], $ids, '2025-12-31')[$ids[0]]);
        calendar::add_offday($f['company'], $f['admin'], $f['old'], 'holiday', 'Synthetic');
        $this->assertSame(array_fill_keys($ids, 'dayoff'), calendar::requirements($f['company'], $ids, $f['old']));
        // The single-user read model gives the same answer.
        $this->assertSame(['required' => false, 'reason' => 'dayoff'],
            calendar::requirement($f['company'], $ids[0], $f['old']));
    }

    public function test_team_day_states_counts_and_filter(): void {
        global $DB;
        $f = $this->fixture();
        $lead = (int)$f['lead']->id;
        $sent = $this->submit($f, 'employee', $f['old']);
        // Calendar off: the R4 view is unchanged, nobody is "not submitted".
        $this->setUser($f['lead']);
        $list = team::day($f['company'], $lead, $f['old']);
        $this->assertFalse($list['calendar']);
        $this->assertSame(3, $list['duecounts']['neutral']);
        foreach ($list['items'] as $row) {
            $this->assertSame('neutral', $row['due']);
            $this->assertSame(0, $row['deadline']);
        }
        $this->assertTrue($this->rows($list)[(int)$f['employee']->id]['late']);

        $this->calendar_on($f);
        calendar::add_absence($f['company'], $f['admin'], (int)$f['absent']->id, 'vacation', $f['old'], $f['old']);
        $this->setUser($f['lead']);
        $list = team::day($f['company'], $lead, $f['old']);
        $rows = $this->rows($list);
        $deadline = (new \DateTimeImmutable($f['old'] . ' 12:00', new \DateTimeZone('Europe/Moscow')))
            ->modify('+1 day')->getTimestamp();
        $this->assertTrue($list['calendar']);
        $this->assertSame('late', $rows[(int)$f['employee']->id]['due']);
        $this->assertTrue($rows[(int)$f['employee']->id]['late']);
        $this->assertSame('missed', $rows[(int)$f['peer']->id]['due']);
        $this->assertSame($deadline, $rows[(int)$f['peer']->id]['deadline']);
        $this->assertSame('notrequired', $rows[(int)$f['absent']->id]['due']);
        $this->assertSame(['neutral' => 0, 'notrequired' => 1, 'expected' => 0, 'missed' => 1, 'ontime' => 0, 'late' => 1],
            $list['duecounts']);

        // Lateness counts from the first submitted version: sent before the deadline means on time.
        $DB->set_field('local_kbacompany_dreportver', 'submittedat', $deadline - 60, ['reportuuid' => $sent['reportuuid']]);
        $rows = $this->rows(team::day($f['company'], $lead, $f['old']));
        $this->assertSame('ontime', $rows[(int)$f['employee']->id]['due']);
        $this->assertFalse($rows[(int)$f['employee']->id]['late']);

        // Filter "not submitted" lists only the peer, counts still cover the whole team.
        $missed = team::day($f['company'], $lead, $f['old'], '', '', '', 0, 'missed');
        $this->assertSame([(int)$f['peer']->id], array_keys($this->rows($missed)));
        $this->assertSame(1, $missed['duecounts']['notrequired']);
        try {
            team::day($f['company'], $lead, $f['old'], '', '', '', 0, 'overdue');
            $this->fail('unknown due filter accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidparameter', $e->errorcode);
        }

        // Today: the deadline is tomorrow, so a missing report is only "expected".
        $today = business_date_service::day();
        $rows = $this->rows(team::day($f['company'], $lead, $today));
        $this->assertSame('expected', $rows[(int)$f['peer']->id]['due']);
        $this->assertGreaterThan(time(), $rows[(int)$f['peer']->id]['deadline']);

        // A changed calendar re-evaluates the past day (K5): a holiday makes it not required for everyone.
        calendar::add_offday($f['company'], $f['admin'], $f['old'], 'holiday', 'Synthetic');
        $this->setUser($f['lead']);
        $list = team::day($f['company'], $lead, $f['old']);
        $this->assertSame(3, $list['duecounts']['notrequired']);
    }

    public function test_revoked_and_other_company_people_are_not_counted(): void {
        $f = $this->fixture();
        $this->calendar_on($f);
        membership_access_service::change($f['company'], (int)$f['peer']->id, $f['admin']);
        $this->setUser($f['lead']);
        $list = team::day($f['company'], (int)$f['lead']->id, $f['old']);
        $this->assertArrayNotHasKey((int)$f['peer']->id, $this->rows($list));
        $this->assertSame(0, array_sum($list['duecounts']) - count($this->rows($list)));
        $othercid = repository::save_company((object)['name' => 'Synthetic due B', 'active' => 1]);
        $other = identity_registry::company_uuid($othercid);
        $this->assertSame([(int)$f['employee']->id => 'disabled'],
            calendar::requirements($other, [(int)$f['employee']->id], $f['old']));
    }

    public function test_screens_show_due_state_and_own_deadline(): void {
        $f = $this->fixture();
        $this->calendar_on($f);
        $this->setUser($f['lead']);
        $list = team::day($f['company'], (int)$f['lead']->id, $f['old']);
        $html = reportui::team($f['company'], $list, ['pending', 'team']);
        $this->assertStringContainsString('data-dailyreport-team-due="missed"', $html);
        $this->assertStringContainsString('name="due"', $html);
        $this->assertStringContainsString('data-dailyreport-team-duecount="missed"', $html);
        $this->assertStringContainsString(get_string('dailyreports_due_missed', 'local_kbacompany'), $html);
        $deadline = time() + 3600;
        $this->assertStringContainsString('data-dailyreport-owndue="expected"', reportui::own_due('expected', $deadline));
        $this->assertStringContainsString('data-dailyreport-owndue="missed"', reportui::own_due('missed', time() - 60));
        $this->assertStringContainsString('data-dailyreport-owndue="notrequired"', reportui::own_due('notrequired', 0));
        foreach (['neutral', 'ontime', 'late'] as $due) {
            $this->assertSame('', reportui::own_due($due, 0));
        }
        // Calendar off: no schedule filter and no schedule totals.
        $this->setAdminUser();
        calendar::save_settings($f['company'], $f['admin'], false, '1111111', 720, 1);
        $this->setUser($f['lead']);
        $html = reportui::team($f['company'], team::day($f['company'], (int)$f['lead']->id, $f['old']), ['pending', 'team']);
        $this->assertStringNotContainsString('name="due"', $html);
        $this->assertStringNotContainsString('data-dailyreport-team-duecount', $html);
    }
}
