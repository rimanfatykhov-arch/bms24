<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_schedule_service as schedule;
use local_kbacompany\local\daily_report_summary_service as summary;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/**
 * ADR-0011 block 2 (owner decisions 25.11.2026): reports are required Monday to Friday in the author's time zone; the
 * author is reminded on a working day, the reviewer is told once the last working day stays unsent; days off never
 * trigger anything; reminders are one deterministic notification per day; another company's people never mix in.
 * Fixed instants (Europe/Moscow, UTC+3): Wednesday 25.11.2026, previous working day Tuesday 24.11.2026.
 */
final class daily_report_schedule_service_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function company(string $name): array {
        global $DB;
        $cid = repository::save_company((object)['name' => $name, 'active' => 1]);
        $uuid = identity_registry::company_uuid($cid);
        if (!$DB->record_exists('local_kbacompany_company', ['uuid' => $uuid])) {
            $DB->insert_record('local_kbacompany_company', (object)['uuid' => $uuid, 'name' => $name,
                'legacycompanyid' => $cid, 'active' => 1]);
        }
        $DB->set_field('local_kbacompany_company', 'active', 1, ['uuid' => $uuid]);
        return [$cid, $uuid];
    }

    private function fixture(): array {
        $this->resetAfterTest(); $this->setAdminUser();
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        set_config('dailyreports_selftop', 1, 'local_kbacompany');
        set_config('dailyreports_remind_enabled', 1, 'local_kbacompany');
        [$cid, $company] = $this->company('Synthetic schedule A');
        [$bid, $other] = $this->company('Synthetic schedule B');
        $users = [];
        foreach (['director' => $cid, 'lead' => $cid, 'employee' => $cid, 'worker' => $cid, 'newbie' => $cid,
                'bmanager' => $bid, 'outsider' => $bid] as $name => $companyid) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'firstname' => 'Synthetic',
                'lastname' => $name]);
            $this->member($companyid, $companyid === $cid ? $company : $other, (int)$u->id,
                $name === 'newbie' ? gmmktime(8, 0, 0, 11, 25, 2026) : 1);
            $users[$name] = $u;
        }
        // A: ROOT (director, top of the chart) > TEAM (lead manages employee, worker, newbie). B: ROOT (bmanager) > outsider.
        $this->branch = \core\uuid::generate();
        $root = $this->node($company, '', 'ROOT', 1);
        $team = $this->node($company, $root, 'TEAM', 2);
        $this->assign($company, $root, (int)$users['director']->id, true);
        $this->assign($company, $team, (int)$users['lead']->id, true);
        foreach (['employee', 'worker', 'newbie'] as $name) {
            $this->assign($company, $team, (int)$users[$name]->id, false);
        }
        $broot = $this->node($other, '', 'BROOT', 1);
        $bteam = $this->node($other, $broot, 'BTEAM', 2);
        $this->assign($other, $broot, (int)$users['bmanager']->id, true);
        $this->assign($other, $bteam, (int)$users['outsider']->id, false);
        return compact('company', 'other') + $users;
    }

    private function member(int $cid, string $company, int $userid, int $since): void {
        global $DB;
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $userid,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $since, 'timemodified' => $since]);
        $DB->delete_records('local_kbacompany_member', ['companyuuid' => $company, 'userid' => $userid]);
        $DB->insert_record('local_kbacompany_member', (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $company,
            'userid' => $userid, 'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
            'isdepartmentmanager' => 0, 'ishr' => 0, 'effectivefrom' => $since, 'effectiveto' => 0, 'sourcekind' => 'bms',
            'active' => 1, 'timecreated' => $since, 'timemodified' => $since]);
    }

    private function node(string $company, string $parent, string $code, int $sort): string {
        global $DB;
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => $this->branch,
            'uuid' => $uuid, 'parentuuid' => $parent, 'code' => $code, 'nodetype' => 'department', 'name' => $code,
            'sortorder' => $sort, 'color' => '', 'sourcecomponent' => 'local_kbacompany', 'sourceentity' => 'test',
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

    /** A sent report (a current version exists); only the presence matters to the schedule. */
    private function sent(string $company, int $userid, string $day): void {
        global $DB;
        $DB->insert_record('local_kbacompany_dreport', (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $company,
            'userid' => $userid, 'reportday' => $day, 'timezone' => 'Europe/Moscow', 'status' => 'submitted',
            'currentversionuuid' => \core\uuid::generate(), 'draftversionuuid' => '', 'revision' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
    }

    /** Messages of one kind as [recipient id => count]. */
    private function sent_to(\phpunit_message_sink $sink, string $kind): array {
        $out = [];
        foreach ($sink->get_messages() as $message) {
            if ($message->component === 'local_kbacompany' && $message->eventtype === $kind) {
                $out[(int)$message->useridto] = ($out[(int)$message->useridto] ?? 0) + 1;
            }
        }
        ksort($out);
        return $out;
    }

    /** MSK wall clock on a November 2026 day, as a Unix instant. */
    private static function msk(int $day, int $hour, int $minute = 0): int {
        return gmmktime($hour - 3, $minute, 0, 11, $day, 2026);
    }

    public function test_working_days_and_empty_cell_states(): void {
        $this->resetAfterTest();
        $this->assertTrue(schedule::required('2026-11-25'));
        $this->assertTrue(schedule::required('2026-11-27'));
        $this->assertFalse(schedule::required('2026-11-28'));
        $this->assertFalse(schedule::required('2026-11-29'));
        $this->assertFalse(schedule::required('2026-02-30'));
        $this->assertFalse(schedule::required('not-a-day'));
        $this->assertSame('2026-11-24', schedule::previous_required('2026-11-25'));
        $this->assertSame('2026-11-27', schedule::previous_required('2026-11-30'));
        $this->assertSame('2026-11-27', schedule::previous_required('2026-11-29'));
        $this->assertSame('missed', summary::empty_state('2026-11-24', '2026-11-25'));
        $this->assertSame('none', summary::empty_state('2026-11-25', '2026-11-25'));
        $this->assertSame('none', summary::empty_state('2026-11-26', '2026-11-25'));
        $this->assertSame('weekend', summary::empty_state('2026-11-22', '2026-11-25'));
        set_config('dailyreports_remind_time', '25:99', 'local_kbacompany');
        $this->assertSame('18:00', schedule::time_setting('dailyreports_remind_time', schedule::DEFAULT_REMIND_TIME));
        set_config('dailyreports_remind_time', '17:45', 'local_kbacompany');
        $this->assertSame('17:45', schedule::time_setting('dailyreports_remind_time', schedule::DEFAULT_REMIND_TIME));
    }

    public function test_switches_off_send_nothing(): void {
        $f = $this->fixture();
        $sink = $this->redirectMessages();
        set_config('dailyreports_remind_enabled', 0, 'local_kbacompany');
        $this->assertSame(['scanned' => 0, 'reminders' => 0, 'overdue' => 0, 'skipped' => 0],
            schedule::run_due(self::msk(25, 18, 30)));
        set_config('dailyreports_remind_enabled', 1, 'local_kbacompany');
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->assertSame(0, schedule::run_due(self::msk(25, 18, 30))['scanned']);
        $this->assertSame([], $sink->get_messages());
        $this->assertNotEmpty($f['company']);
    }

    public function test_author_reminder_and_reviewer_overdue_once(): void {
        global $DB;
        $f = $this->fixture();
        $lead = (int)$f['lead']->id; $employee = (int)$f['employee']->id; $worker = (int)$f['worker']->id;
        $director = (int)$f['director']->id; $newbie = (int)$f['newbie']->id;
        $this->sent($f['company'], $worker, '2026-11-24');
        $this->sent($f['company'], $worker, '2026-11-25');
        $sink = $this->redirectMessages();

        // 09:30: before the overdue notice and the reminder time.
        $stats = schedule::run_due(self::msk(25, 9, 30));
        $this->assertSame([0, 0], [$stats['reminders'], $stats['overdue']]);
        $this->assertSame([], $sink->get_messages());

        // 12:00: overdue notices for Tuesday only; the author reminder waits for 18:00. The lead hears about the
        // employee (the worker sent Tuesday); the director about the lead; nobody about the director (self_top).
        $stats = schedule::run_due(self::msk(25, 12));
        $this->assertSame(0, $stats['reminders']);
        $expected = [$director => 1, $lead => 1, (int)$f['bmanager']->id => 1];
        ksort($expected);
        $this->assertSame($expected, $this->sent_to($sink, 'dailyreport_overdue'));

        // 18:30: everyone without today's report is reminded; Tuesday notices are not repeated.
        $stats = schedule::run_due(self::msk(25, 18, 30));
        $expected = [$director => 1, $lead => 1, $employee => 1, $newbie => 1, (int)$f['bmanager']->id => 1,
            (int)$f['outsider']->id => 1];
        ksort($expected);
        $this->assertSame($expected, array_intersect_key($this->sent_to($sink, 'dailyreport_reminder'),
            $expected + [$worker => 0]));
        $overdue = $this->sent_to($sink, 'dailyreport_overdue');
        $this->assertSame(1, $overdue[$lead]);
        $this->assertArrayNotHasKey($worker, $overdue);
        $this->assertArrayNotHasKey($newbie, $overdue);
        // Company B: the outsider's manager is told, nobody in A is told about B.
        $this->assertSame(1, $overdue[(int)$f['bmanager']->id]);

        // A replay of the same instant and a later run that day add nothing.
        $before = count($sink->get_messages());
        schedule::run_due(self::msk(25, 18, 30));
        schedule::run_due(self::msk(25, 23, 45));
        $this->assertCount($before, $sink->get_messages());

        // The notification rows are company-scoped: the B notices live in B only.
        $this->assertSame(2, $DB->count_records('local_kbacompany_commnotif', ['companyuuid' => $f['other'],
            'userid' => (int)$f['bmanager']->id]));
        $this->assertSame(0, $DB->count_records('local_kbacompany_commnotif', ['companyuuid' => $f['company'],
            'userid' => (int)$f['bmanager']->id]));
    }

    public function test_top_of_chart_and_new_member_raise_no_overdue(): void {
        $f = $this->fixture();
        $sink = $this->redirectMessages();
        schedule::run_due(self::msk(25, 12));
        $overdue = $this->sent_to($sink, 'dailyreport_overdue');
        // The director reviews own report at the top (self_top): nobody is told. The lead's reviewer is the director.
        $this->assertSame([(int)$f['director']->id, (int)$f['lead']->id, (int)$f['bmanager']->id], array_keys($overdue));
        // Lead hears about employee and worker, not about newbie, who joined on Wednesday morning.
        $this->assertSame(2, $overdue[(int)$f['lead']->id]);
        $this->assertSame(1, $overdue[(int)$f['director']->id]);
    }

    public function test_days_off_raise_no_reminder(): void {
        $f = $this->fixture();
        $sink = $this->redirectMessages();
        // Saturday evening: no reminder for Saturday; Friday's overdue notices are due once (newbie was a member).
        schedule::run_due(self::msk(28, 19));
        $this->assertSame([], $this->sent_to($sink, 'dailyreport_reminder'));
        $this->assertSame(3, $this->sent_to($sink, 'dailyreport_overdue')[(int)$f['lead']->id] ?? 0);
        // Sunday adds nothing: Friday is still the last working day and its notices already exist.
        $before = count($sink->get_messages());
        schedule::run_due(self::msk(29, 19));
        $this->assertCount($before, $sink->get_messages());
    }

    public function test_week_summary_marks_days_off_and_missed_days(): void {
        $f = $this->fixture();
        $this->sent($f['company'], (int)$f['worker']->id, '2026-11-03');
        $grid = summary::grid($f['company'], (int)$f['director']->id, '', 'all', '', 'week', '2026-11-04');
        $this->assertSame('week', $grid['period']);
        $this->assertSame('2026-11-02', $grid['week']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'],
            array_keys($grid['days']));
        $rows = [];
        foreach ($grid['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[$row['userid']] = $rows[$row['userid']] ?? $row;
            }
        }
        $this->assertArrayNotHasKey((int)$f['outsider']->id, $rows);
        $employee = $rows[(int)$f['employee']->id];
        $this->assertSame(['missed', 'missed', 'missed', 'missed', 'missed', 'weekend', 'weekend'],
            array_column(array_values($employee['cells']), 'state'));
        $this->assertSame([0, 5], [$employee['total'], $employee['missed']]);
    }
}
