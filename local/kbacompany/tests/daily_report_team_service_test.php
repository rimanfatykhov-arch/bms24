<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_team_service as team;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/**
 * Daily reports R4 "team for the day" (owner decisions T1–T5, 24.11.2026): every current employee for one day,
 * "my team" by the nearest manager of ADR-0010 D2, drafts without content, neutral "no report", no deleted, suspended,
 * revoked or foreign people, and no access for ordinary employees or another company.
 */
final class daily_report_team_service_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic team day A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['director', 'head', 'lead', 'employee', 'worker', 'peer', 'other', 'unplaced'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow', 'firstname' => ucfirst($name),
                'lastname' => 'Synthetic']);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        // ROOT > DEPT > TEAM > SUB, OTHER beside DEPT. Managers: director ROOT, head DEPT, lead TEAM, other OTHER.
        // employee and peer work in TEAM, worker in SUB (no manager there), unplaced has no unit.
        $nodes = [];
        $nodes['root'] = $this->node($company, '', 'ROOT');
        $nodes['dept'] = $this->node($company, $nodes['root'], 'DEPT');
        $nodes['team'] = $this->node($company, $nodes['dept'], 'TEAM');
        $nodes['sub'] = $this->node($company, $nodes['team'], 'SUB');
        $nodes['other'] = $this->node($company, $nodes['root'], 'OTHER');
        $this->assign($company, $nodes['root'], (int)$users['director']->id, true);
        $this->assign($company, $nodes['dept'], (int)$users['head']->id, true);
        $this->assign($company, $nodes['team'], (int)$users['lead']->id, true);
        $this->assign($company, $nodes['other'], (int)$users['other']->id, true);
        $this->assign($company, $nodes['team'], (int)$users['employee']->id, false);
        $this->assign($company, $nodes['sub'], (int)$users['worker']->id, false);
        $this->assign($company, $nodes['team'], (int)$users['peer']->id, false);
        $day = (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        return compact('admin', 'company', 'cid', 'nodes', 'day') + $users;
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

    private function values(string $done = 'Synthetic result'): array {
        return ['planned' => 'Plan', 'done' => $done, 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
    }

    private function submit(array $f, \stdClass $user): array {
        $this->setUser($user);
        return store::submit($f['company'], (int)$user->id, $f['day'], \core\uuid::generate(), 0, $this->values());
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    /** States of the day: accepted with rating, returned, not sent (draft), none; prepared by the team lead. */
    private function day_states(array $f): array {
        $lead = (int)$f['lead']->id;
        $accepted = $this->submit($f, $f['employee']);
        $returned = $this->submit($f, $f['peer']);
        $this->setUser($f['worker']);
        store::save_draft($f['company'], (int)$f['worker']->id, $f['day'], $this->values('Private draft text'), [], 0);
        $this->setUser($f['lead']);
        review::decide($f['company'], $lead, $accepted['reportuuid'], $accepted['versionuuid'], 'accepted', 'positive', '',
            \core\uuid::generate());
        review::decide($f['company'], $lead, $returned['reportuuid'], $returned['versionuuid'], 'returned', '',
            'Synthetic reason', \core\uuid::generate());
        return compact('accepted', 'returned');
    }

    private function by_user(array $list): array {
        $out = [];
        foreach ($list['items'] as $row) {
            $out[$row['userid']] = $row;
        }
        return $out;
    }

    public function test_my_team_is_the_people_whose_nearest_manager_i_am(): void {
        $f = $this->fixture(); $lead = (int)$f['lead']->id;
        $states = $this->day_states($f);
        $this->setUser($f['lead']);
        $this->assertContains('team', review::queues($f['company'], $lead));
        $list = team::day($f['company'], $lead, $f['day']);
        $this->assertSame('team', $list['scope'], 'T1: a manager starts on "my team"');
        $this->assertSame(['team', 'company'], $list['scopes']);
        $rows = $this->by_user($list);
        $this->assertEqualsCanonicalizing([(int)$f['employee']->id, (int)$f['peer']->id, (int)$f['worker']->id],
            array_keys($rows), 'TEAM members and the SUB worker without a SUB manager; never the lead or other units');
        $employee = $rows[(int)$f['employee']->id];
        $this->assertSame('accepted', $employee['state']);
        $this->assertSame('positive', $employee['rating']);
        $this->assertSame($states['accepted']['reportuuid'], $employee['reportuuid']);
        $this->assertSame($lead, $employee['reviewerid']);
        $this->assertSame('returned', $rows[(int)$f['peer']->id]['state']);
        // T2: a draft is "not sent" and exposes nothing: no card link, no reviewer, no time.
        $draft = $rows[(int)$f['worker']->id];
        $this->assertSame('notsent', $draft['state']);
        $this->assertSame('', $draft['reportuuid']);
        $this->assertSame(0, $draft['reviewerid']);
        $this->assertSame(0, $draft['submittedat']);
        $this->assertSame(['none' => 0, 'notsent' => 1, 'submitted' => 0, 'returned' => 1, 'accepted' => 1], $list['counts']);
        $html = reportui::team($f['company'], $list, review::queues($f['company'], $lead));
        $this->assertStringNotContainsString('Private draft text', $html);
        $this->assertStringContainsString('data-dailyreport-team-filters', $html);
    }

    public function test_whole_company_lists_current_members_with_neutral_no_report(): void {
        $f = $this->fixture(); $lead = (int)$f['lead']->id;
        $this->day_states($f);
        $this->setUser($f['lead']);
        $list = team::day($f['company'], $lead, $f['day'], 'company');
        $this->assertCount(8, $list['items']);
        $this->assertSame(['none' => 5, 'notsent' => 1, 'submitted' => 0, 'returned' => 1, 'accepted' => 1], $list['counts']);
        $rows = $this->by_user($list);
        // T3: "no report" is the neutral state, with no other marker.
        $this->assertSame('none', $rows[(int)$f['director']->id]['state']);
        $this->assertSame('', $rows[(int)$f['unplaced']->id]['unit']);
        // Filters: unit, "without unit", status; an unknown status or unit is refused.
        $team = team::day($f['company'], $lead, $f['day'], 'company', $f['nodes']['team']);
        $this->assertEqualsCanonicalizing([(int)$f['lead']->id, (int)$f['employee']->id, (int)$f['peer']->id],
            array_column($team['items'], 'userid'));
        $this->assertSame('TEAM', $team['units'][$f['nodes']['team']]);
        $this->assertSame([(int)$f['unplaced']->id], array_column(team::day($f['company'], $lead, $f['day'], 'company',
            'none')['items'], 'userid'));
        $none = team::day($f['company'], $lead, $f['day'], 'company', '', 'none');
        $this->assertCount(5, $none['items']);
        $this->assertSame(5, $none['counts']['none'], 'counts stay the totals of the day before the status filter');
        $this->error('invalidparameter', function() use ($f, $lead) {
            team::day($f['company'], $lead, $f['day'], 'company', '', 'missing');
        });
        $this->error('invalidparameter', function() use ($f, $lead) {
            team::day($f['company'], $lead, $f['day'], 'company', \core\uuid::generate());
        });
        $this->error('dailyreports_futureday', function() use ($f, $lead) {
            team::day($f['company'], $lead, (new \DateTimeImmutable('+3 days'))->format('Y-m-d'), 'company');
        });
    }

    public function test_deleted_suspended_and_revoked_people_are_not_listed(): void {
        global $DB;
        $f = $this->fixture(); $lead = (int)$f['lead']->id;
        $this->submit($f, $f['peer']);
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['peer']->id, $f['admin']);
        $DB->set_field('user', 'suspended', 1, ['id' => $f['worker']->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $f['director']->id]);
        $this->setUser($f['lead']);
        $ids = array_column(team::day($f['company'], $lead, $f['day'], 'company')['items'], 'userid');
        $this->assertNotContains((int)$f['peer']->id, $ids, 'revoked member (the report itself is kept)');
        $this->assertNotContains((int)$f['worker']->id, $ids);
        $this->assertNotContains((int)$f['director']->id, $ids);
        $this->assertTrue($DB->record_exists('local_kbacompany_dreport', ['companyuuid' => $f['company'],
            'userid' => $f['peer']->id]));
    }

    public function test_access_is_for_managers_and_administrators_of_this_company_only(): void {
        $f = $this->fixture();
        // An ordinary employee: no team list, not even through a crafted request.
        $this->setUser($f['worker']);
        $this->assertNotContains('team', review::queues($f['company'], (int)$f['worker']->id));
        $this->error('dailyreports_notavailable', function() use ($f) {
            team::day($f['company'], (int)$f['worker']->id, $f['day']);
        });
        // An administrator who manages nobody sees the company; "my team" does not exist for them.
        $this->setAdminUser();
        $list = team::day($f['company'], $f['admin'], $f['day']);
        $this->assertSame(['company'], $list['scopes']);
        $this->assertSame('company', $list['scope']);
        $this->error('invalidparameter', function() use ($f) {
            team::day($f['company'], $f['admin'], $f['day'], 'team');
        });
        // Another company's manager is refused at the company boundary.
        $othercid = repository::save_company((object)['name' => 'Synthetic team day B', 'active' => 1]);
        $outsider = $this->getDataGenerator()->create_user();
        repository::add_member($othercid, (int)$outsider->id, 'employee');
        $this->setUser($outsider);
        try {
            team::day($f['company'], (int)$outsider->id, $f['day'], 'company');
            $this->fail('Foreign company must be denied');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        // A revoked manager loses the list.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['lead']->id, $f['admin']);
        $this->setUser($f['lead']);
        $this->error('companynotavailable', function() use ($f) {
            team::day($f['company'], (int)$f['lead']->id, $f['day']);
        });
    }

    public function test_switch_off_hides_the_list(): void {
        $f = $this->fixture();
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->setUser($f['lead']);
        $this->assertFalse(team::available($f['company'], (int)$f['lead']->id));
        $this->error('dailyreports_disabled', function() use ($f) {
            team::day($f['company'], (int)$f['lead']->id, $f['day']);
        });
    }
}
