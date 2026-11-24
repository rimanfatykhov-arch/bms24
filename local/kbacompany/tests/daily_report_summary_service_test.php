<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_summary_service as summary;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/**
 * ADR-0011 block 1: monthly summary of daily reports. Readers are administrators and managers (ADR-0010 D4); states
 * and ratios come from stored versions and decisions; drafts stay hidden; "only my subordinates" follows the
 * organisation chart chain; another company's reports never appear.
 */
final class daily_report_summary_service_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic summary A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['director', 'head', 'lead', 'employee', 'worker', 'peer', 'other'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'firstname' => 'Synthetic',
                'lastname' => $name === 'peer' ? '<b>peer</b>' : $name]);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        // ROOT > DEPT > TEAM > SUB, OTHER beside DEPT; managers director ROOT, head DEPT, lead TEAM, other OTHER.
        $nodes = [];
        $nodes['root'] = $this->node($company, '', 'ROOT', 1);
        $nodes['dept'] = $this->node($company, $nodes['root'], 'DEPT', 2);
        $nodes['team'] = $this->node($company, $nodes['dept'], 'TEAM', 3);
        $nodes['sub'] = $this->node($company, $nodes['team'], 'SUB', 4);
        $nodes['other'] = $this->node($company, $nodes['root'], 'OTHER', 5);
        $this->assign($company, $nodes['root'], (int)$users['director']->id, true);
        $this->assign($company, $nodes['dept'], (int)$users['head']->id, true);
        $this->assign($company, $nodes['team'], (int)$users['lead']->id, true);
        $this->assign($company, $nodes['other'], (int)$users['other']->id, true);
        $this->assign($company, $nodes['team'], (int)$users['employee']->id, false);
        $this->assign($company, $nodes['sub'], (int)$users['worker']->id, false);
        $this->assign($company, $nodes['team'], (int)$users['peer']->id, false);
        $day = business_date_service::day();
        return compact('admin', 'company', 'cid', 'nodes', 'day') + ['month' => substr($day, 0, 7)] + $users;
    }

    private function member(int $cid, int $userid): void {
        global $DB;
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $userid,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
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

    private function values(): array {
        return ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
    }

    private function submit(array $f, \stdClass $user): array {
        $this->setUser($user);
        return store::submit($f['company'], (int)$user->id, $f['day'], \core\uuid::generate(), 0, $this->values(), [], []);
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    /** Rows of the grid by user id (first occurrence) and the user ids of each unit group. */
    private function rows(array $grid): array {
        $rows = []; $groups = [];
        foreach ($grid['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[$row['userid']] = $rows[$row['userid']] ?? $row;
                $groups[$group['unit']][] = $row['userid'];
            }
        }
        return [$rows, $groups];
    }

    public function test_month_grid_states_ratios_and_hidden_drafts(): void {
        $f = $this->fixture(); $lead = (int)$f['lead']->id;
        $accepted = $this->submit($f, $f['employee']);
        $pending = $this->submit($f, $f['worker']);
        $this->setUser($f['peer']);
        store::save_draft($f['company'], (int)$f['peer']->id, $f['day'], $this->values(), [], 0, []);
        $returned = $this->submit($f, $f['head']);
        $this->setUser($f['lead']);
        review::decide($f['company'], $lead, $accepted['reportuuid'], $accepted['versionuuid'], 'accepted', 'positive', '',
            \core\uuid::generate());
        $this->setUser($f['director']);
        review::decide($f['company'], (int)$f['director']->id, $returned['reportuuid'], $returned['versionuuid'], 'returned',
            '', 'Synthetic reason', \core\uuid::generate());

        $grid = summary::grid($f['company'], (int)$f['director']->id, $f['month']);
        $this->assertSame($f['month'], $grid['month']);
        $this->assertSame((int)date('t', strtotime($f['month'] . '-15 12:00:00 UTC')), count($grid['days']));
        [$rows, $groups] = $this->rows($grid);
        $employee = $rows[(int)$f['employee']->id];
        $this->assertSame(['state' => 'accepted', 'rating' => 'positive', 'report' => $accepted['reportuuid']],
            $employee['cells'][$f['day']]);
        $this->assertSame([1, 1, 1, 100, 100], [$employee['total'], $employee['rated'], $employee['positive'],
            $employee['ratedpct'], $employee['positivepct']]);
        $worker = $rows[(int)$f['worker']->id];
        $this->assertSame('submitted', $worker['cells'][$f['day']]['state']);
        $this->assertSame([1, 0, 0, null], [$worker['total'], $worker['ratedpct'], $worker['positive'], $worker['positivepct']]);
        $this->assertSame('returned', $rows[(int)$f['head']->id]['cells'][$f['day']]['state']);
        // A draft is visible only to its author: the summary shows "no report" and counts nothing.
        $peer = $rows[(int)$f['peer']->id];
        $this->assertSame(['state' => 'none', 'rating' => 'none', 'report' => ''], $peer['cells'][$f['day']]);
        $this->assertSame([0, null], [$peer['total'], $peer['ratedpct']]);
        // Units in chart order; every employee under the unit they work in.
        $this->assertSame([$f['nodes']['root'], $f['nodes']['dept'], $f['nodes']['team'], $f['nodes']['sub'],
            $f['nodes']['other']], array_keys($groups));
        $this->assertSame([(int)$f['worker']->id], $groups[$f['nodes']['sub']]);
        $this->assertContains((int)$f['peer']->id, $groups[$f['nodes']['team']]);
        // A report reworked after the return is a draft with a current version: still shown as returned.
        $this->setUser($f['head']);
        store::start_revision($f['company'], (int)$f['head']->id, $f['day'], '');
        [$rows] = $this->rows(summary::grid($f['company'], (int)$f['director']->id, $f['month']));
        $this->assertSame('returned', $rows[(int)$f['head']->id]['cells'][$f['day']]['state']);

        // Rendering: escaped names, cell states as data, links to the read-only card, text next to every icon.
        $html = reportui::summary($f['company'], summary::grid($f['company'], (int)$f['director']->id, $f['month']));
        $this->assertStringNotContainsString('<b>peer</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;peer&lt;/b&gt;', $html);
        $this->assertStringContainsString('data-dailyreport-summary-cell="accepted"', $html);
        $this->assertStringContainsString('data-dailyreport-summary-rating="positive"', $html);
        $this->assertStringContainsString('report=' . $accepted['reportuuid'], $html);
        $this->assertStringContainsString('report=' . $pending['reportuuid'], $html);
        $this->assertStringContainsString(get_string('dailyreports_summary_rating_positive', 'local_kbacompany'), $html);
        $this->assertStringContainsString('100%', $html);
        // The linked card is readable by the summary reader (D4) and read-only.
        $card = review::card($f['company'], (int)$f['director']->id, $accepted['reportuuid']);
        $this->assertFalse($card['can_decide']);
    }

    public function test_only_my_subordinates_and_unit_filter(): void {
        $f = $this->fixture();
        $this->submit($f, $f['employee']);
        $ids = static function(array $grid): array {
            $out = [];
            foreach ($grid['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $out[$row['userid']] = true;
                }
            }
            $out = array_keys($out); sort($out);
            return $out;
        };
        $sorted = static function(array $users): array {
            $out = array_map(static function($u): int { return (int)$u->id; }, $users); sort($out);
            return $out;
        };
        $this->setUser($f['head']);
        $mine = summary::grid($f['company'], (int)$f['head']->id, $f['month'], 'mine');
        $this->assertSame('mine', $mine['scope']);
        $this->assertSame($sorted([$f['lead'], $f['employee'], $f['worker'], $f['peer']]), $ids($mine));
        $this->setUser($f['other']);
        $this->assertSame([], summary::grid($f['company'], (int)$f['other']->id, $f['month'], 'mine')['groups']);
        $this->assertStringContainsString('data-dailyreport-summary-empty="1"',
            reportui::summary($f['company'], summary::grid($f['company'], (int)$f['other']->id, $f['month'], 'mine')));
        $team = summary::grid($f['company'], (int)$f['other']->id, $f['month'], 'all', $f['nodes']['team']);
        $this->assertSame($sorted([$f['lead'], $f['employee'], $f['peer']]), $ids($team));
        $this->assertSame([$f['nodes']['team']], array_column($team['groups'], 'unit'));
        // Unknown scope or unit falls back to everything, never to an error or another company's unit.
        $all = summary::grid($f['company'], (int)$f['other']->id, $f['month'], 'bogus', \core\uuid::generate());
        $this->assertSame(['all', ''], [$all['scope'], $all['unit']]);
    }

    public function test_access_switch_company_scope_and_month_parameters(): void {
        global $DB;
        $f = $this->fixture();
        $sent = $this->submit($f, $f['employee']);
        // Ordinary employees do not see the summary.
        $this->setUser($f['peer']);
        $this->assertFalse(summary::can_view($f['company'], (int)$f['peer']->id));
        $this->error('dailyreports_notavailable', function() use ($f) {
            summary::grid($f['company'], (int)$f['peer']->id, $f['month']);
        });
        // Another company's report on the same day never appears, even with a member of both companies.
        $this->setAdminUser();
        $cidb = repository::save_company((object)['name' => 'Synthetic summary B', 'active' => 1]);
        $companyb = identity_registry::company_uuid($cidb);
        $this->member($cidb, (int)$f['employee']->id);
        $this->setUser($f['employee']);
        $foreign = store::submit($companyb, (int)$f['employee']->id, $f['day'], \core\uuid::generate(), 0, $this->values(),
            [], []);
        $this->setUser($f['director']);
        [$rows] = $this->rows(summary::grid($f['company'], (int)$f['director']->id, $f['month']));
        $this->assertSame($sent['reportuuid'], $rows[(int)$f['employee']->id]['cells'][$f['day']]['report']);
        $this->assertStringNotContainsString($foreign['reportuuid'],
            reportui::summary($f['company'], summary::grid($f['company'], (int)$f['director']->id, $f['month'])));
        $this->assertNotEmpty($DB->get_record('local_kbacompany_dreport', ['uuid' => $foreign['reportuuid']]));
        // Behind the switch.
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->error('dailyreports_notavailable', function() use ($f) {
            summary::grid($f['company'], (int)$f['director']->id, $f['month']);
        });
        // Month parameter: malformed values fall back to the current month; months have their real length.
        $this->assertSame($f['month'], summary::month('2026-13'));
        $this->assertSame($f['month'], summary::month('../2026'));
        $this->assertCount(29, summary::days('2024-02'));
        $this->assertSame(7, summary::days('2026-11')['2026-11-01']);
        $this->assertSame('2025-12', summary::shift('2026-01', -1));
        $this->assertSame('2027-01', summary::shift('2026-12', 1));
    }
}
