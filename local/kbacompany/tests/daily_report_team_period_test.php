<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_team_service as team;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R6 (#75 RC34 "reports of my team"): the team list for a period of up to 31 days, filtered by one of the
 * listed employees, and the ordered sequence of sent reports for reading them one after another. The employee filter
 * never reaches people outside the list (other units, other companies, revoked members); drafts stay out of the sequence.
 */
final class daily_report_team_period_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic period A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['head', 'lead', 'anna', 'boris'] as $name) {
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
        $this->assign($company, $team, (int)$users['anna']->id, false);
        $this->assign($company, $team, (int)$users['boris']->id, false);
        $days = [];
        foreach ([3, 2, 1] as $back) {
            $days[] = (new \DateTimeImmutable('-' . $back . ' days', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        }
        return compact('admin', 'company', 'cid', 'days') + $users;
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

    private function values(): array {
        return ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
    }

    private function submit(array $f, string $who, string $day): string {
        $this->setUser($f[$who]);
        return store::submit($f['company'], (int)$f[$who]->id, $day, \core\uuid::generate(), 0, $this->values())['reportuuid'];
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_period_rows_order_and_sequence(): void {
        $f = $this->fixture();
        [$d1, $d2, $d3] = $f['days'];
        $a1 = $this->submit($f, 'anna', $d1);
        $a3 = $this->submit($f, 'anna', $d3);
        $b2 = $this->submit($f, 'boris', $d2);
        // A draft is never listed for reading.
        $this->setUser($f['boris']);
        store::save_draft($f['company'], (int)$f['boris']->id, $d3, $this->values(), [], 0);

        $lead = (int)$f['lead']->id;
        $this->setUser($f['lead']);
        $one = team::day($f['company'], $lead, $d3);
        $this->assertSame([1, $d3, 2], [$one['days'], $one['dayto'], $one['total']]);
        $this->assertSame([$a3], $one['sequence']);

        $list = team::day($f['company'], $lead, $d1, '', '', '', 0, '', 0, $d3);
        $this->assertSame(3, $list['days']);
        $this->assertSame(6, $list['total']);
        $this->assertSame([$d3, $d3, $d2, $d2, $d1, $d1], array_column($list['items'], 'day'));
        $this->assertSame('Anna', explode(' ', $list['items'][0]['name'])[0]);
        $this->assertSame([$a3, $b2, $a1], $list['sequence'], 'newest day first, then by name; drafts excluded');
        $this->assertSame(['none' => 2, 'notsent' => 1, 'submitted' => 3, 'returned' => 0, 'accepted' => 0], $list['counts']);
        $this->assertSame(['Anna Synthetic', 'Boris Synthetic'], array_values($list['people']));

        // Filters keep working over the period.
        $sent = team::day($f['company'], $lead, $d1, '', '', 'submitted', 0, '', 0, $d3);
        $this->assertSame(3, $sent['total']);
        $this->assertSame([$a3, $b2, $a1], $sent['sequence']);
    }

    public function test_employee_filter_is_limited_to_the_listed_people(): void {
        $f = $this->fixture();
        [$d1, , $d3] = $f['days'];
        $a1 = $this->submit($f, 'anna', $d1);
        $this->submit($f, 'boris', $d1);
        $lead = (int)$f['lead']->id;
        $this->setUser($f['lead']);
        $anna = team::day($f['company'], $lead, $d1, '', '', '', 0, '', (int)$f['anna']->id, $d3);
        $this->assertSame([(int)$f['anna']->id], array_values(array_unique(array_column($anna['items'], 'userid'))));
        $this->assertSame(3, $anna['total']);
        $this->assertSame([$a1], $anna['sequence']);
        $this->assertCount(2, $anna['people'], 'the select still offers the whole list');

        // The lead's own manager, the lead, another company's member and an unknown id are refused.
        $othercid = repository::save_company((object)['name' => 'Synthetic period B', 'active' => 1]);
        $stranger = $this->getDataGenerator()->create_user();
        $this->member($othercid, (int)$stranger->id);
        foreach ([(int)$f['head']->id, $lead, (int)$stranger->id, 999999] as $userid) {
            $this->error('invalidparameter', function() use ($f, $lead, $d1, $userid) {
                team::day($f['company'], $lead, $d1, '', '', '', 0, '', $userid);
            });
        }
        // A revoked member is neither listed nor selectable.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['boris']->id, $f['admin']);
        $this->setUser($f['lead']);
        $this->assertArrayNotHasKey((int)$f['boris']->id, team::day($f['company'], $lead, $d1)['people']);
        $this->error('invalidparameter', function() use ($f, $lead, $d1) {
            team::day($f['company'], $lead, $d1, '', '', '', 0, '', (int)$f['boris']->id);
        });
    }

    public function test_period_bounds(): void {
        $f = $this->fixture();
        [$d1, , $d3] = $f['days'];
        $lead = (int)$f['lead']->id;
        $this->setUser($f['lead']);
        $this->error('dailyreports_teamperiod', function() use ($f, $lead, $d1, $d3) {
            team::day($f['company'], $lead, $d3, '', '', '', 0, '', 0, $d1);
        });
        $long = (new \DateTimeImmutable($d3))->modify('-' . team::MAX_DAYS . ' days')->format('Y-m-d');
        $this->error('dailyreports_teamperiod', function() use ($f, $lead, $long, $d3) {
            team::day($f['company'], $lead, $long, '', '', '', 0, '', 0, $d3);
        });
        $ok = (new \DateTimeImmutable($d3))->modify('-' . (team::MAX_DAYS - 1) . ' days')->format('Y-m-d');
        $this->assertSame(team::MAX_DAYS, team::day($f['company'], $lead, $ok, '', '', '', 0, '', 0, $d3)['days']);
        $future = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $this->error('dailyreports_futureday', function() use ($f, $lead, $d1, $future) {
            team::day($f['company'], $lead, $d1, '', '', '', 0, '', 0, $future);
        });
        $this->error('dailyreport_invaliddate', function() use ($f, $lead, $d1) {
            team::day($f['company'], $lead, $d1, '', '', '', 0, '', 0, '2026-02-30');
        });
    }

    public function test_screens_keep_the_list_context(): void {
        $f = $this->fixture();
        [$d1, $d2, $d3] = $f['days'];
        $a1 = $this->submit($f, 'anna', $d1);
        $a3 = $this->submit($f, 'anna', $d3);
        $b2 = $this->submit($f, 'boris', $d2);
        $lead = (int)$f['lead']->id;
        $this->setUser($f['lead']);
        $list = team::day($f['company'], $lead, $d1, '', '', '', 0, '', 0, $d3);
        $html = reportui::team($f['company'], $list, ['pending', 'team']);
        $this->assertStringContainsString('name="dayto"', $html);
        $this->assertStringContainsString('name="employee"', $html);
        $this->assertStringNotContainsString('name="userid"', $html);
        $this->assertStringContainsString('dayto=' . $d3, $html, 'card links keep the period');

        $nav = reportui::team_nav($f['company'], $list, $b2);
        $this->assertStringContainsString('data-dailyreport-team-prev="' . $a3 . '"', $nav);
        $this->assertStringContainsString('data-dailyreport-team-next="' . $a1 . '"', $nav);
        $this->assertStringContainsString('data-dailyreport-team-position="2"', $nav);
        $first = reportui::team_nav($f['company'], $list, $a3);
        $this->assertStringNotContainsString('data-dailyreport-team-prev', $first);
        $last = reportui::team_nav($f['company'], $list, $a1);
        $this->assertStringNotContainsString('data-dailyreport-team-next', $last);
        // A report outside the list gets only the way back.
        $outside = reportui::team_nav($f['company'], $list, \core\uuid::generate());
        $this->assertStringContainsString('data-dailyreport-team-nav="0"', $outside);
        $this->assertStringNotContainsString('data-dailyreport-team-next', $outside);
        $this->assertSame(['tab' => 'review', 'queue' => 'team', 'day' => $d1, 'scope' => 'team', 'dayto' => $d3],
            reportui::team_params($list));
    }
}
