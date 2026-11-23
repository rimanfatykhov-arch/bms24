<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_reviewer_service as reviewers;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/**
 * ADR-0010: reviewer by organisation chart (nearest manager up the reporting line), self review only at the top,
 * company-wide read-only visibility for managers, administrator-author access, and read-only report copies.
 */
final class daily_report_reviewer_service_test extends \advanced_testcase {
    /** @var string */
    private $branch;

    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic org chart A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['director', 'head', 'lead', 'employee', 'worker', 'peer', 'other', 'comanager'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
            $this->member($cid, (int)$u->id);
            $users[$name] = $u;
        }
        $this->branch = \core\uuid::generate();
        // Layout: ROOT > DEPT > TEAM > SUB, and OTHER beside DEPT. Managers: director ROOT, head DEPT, lead TEAM,
        // other OTHER. SUB has no manager (depth 4 for the worker).
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
        // The runner's administrator and the report authors can be on different civil dates.
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

    private function assign(string $company, string $node, int $userid, bool $manager, string $type = 'primary'): void {
        global $DB;
        $position = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => $this->branch,
            'orgnodeuuid' => $node, 'uuid' => $position, 'code' => substr($position, 0, 8), 'name' => 'P',
            'purpose' => '', 'ismanager' => $manager ? 1 : 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $company, 'positionuuid' => $position,
            'slotuuid' => '', 'userid' => $userid, 'assignmenttype' => $type, 'assignmentfte' => 1, 'effectivefrom' => 0,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
    }

    private function key(): string {
        return \core\uuid::generate();
    }

    private function values(): array {
        return ['planned' => 'Plan', 'done' => 'Synthetic result', 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
    }

    private function submit(array $f, \stdClass $user, array $cc = []): array {
        $this->setUser($user);
        return store::submit($f['company'], (int)$user->id, $f['day'], $this->key(), 0, $this->values(), [], $cc);
    }

    private function reviewer(array $f, string $name): array {
        return store::resolve_reviewer($f['company'], (int)$f[$name]->id, $f['day']);
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_nearest_manager_up_the_chart_for_depth_one_to_four(): void {
        $f = $this->fixture();
        $this->assertSame([(int)$f['lead']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
        $this->assertSame([(int)$f['head']->id, 'orgstructure'], $this->reviewer($f, 'lead'));
        $this->assertSame([(int)$f['director']->id, 'orgstructure'], $this->reviewer($f, 'head'));
        $this->assertSame([(int)$f['lead']->id, 'orgstructure'], $this->reviewer($f, 'worker'));
        $chain = array_column(reviewers::chain($f['company'], (int)$f['worker']->id), 'userid');
        $this->assertSame([(int)$f['lead']->id, (int)$f['head']->id, (int)$f['director']->id], $chain);
    }

    public function test_two_units_two_managers_and_ineligible_managers(): void {
        global $DB;
        $f = $this->fixture();
        // Employee also works in OTHER (secondary): the primary unit's manager stays the reviewer.
        $this->assign($f['company'], $f['nodes']['other'], (int)$f['employee']->id, false, 'secondary');
        $this->assertSame([(int)$f['lead']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
        // A second TEAM manager on a secondary assignment: the primary manager still wins, whatever the ids are.
        $this->assign($f['company'], $f['nodes']['team'], (int)$f['comanager']->id, true, 'secondary');
        $this->assertSame([(int)$f['lead']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
        // Without an explicit primary manager, the lowest user id is taken.
        $DB->set_field('local_kbacompany_posassign', 'assignmenttype', 'secondary', ['userid' => $f['lead']->id]);
        $expected = min((int)$f['lead']->id, (int)$f['comanager']->id);
        $this->assertSame([$expected, 'orgstructure'], $this->reviewer($f, 'employee'));
        // Co-managers are peers: the lead's reviewer is the DEPT head, never the other TEAM manager.
        $this->assertSame([(int)$f['head']->id, 'orgstructure'], $this->reviewer($f, 'lead'));
        // Both TEAM managers suspended or deleted: the next eligible manager at the same level (OTHER), then up.
        $DB->set_field('user', 'suspended', 1, ['id' => $f['lead']->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $f['comanager']->id]);
        $this->assertSame([(int)$f['other']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
        $DB->delete_records('local_kbacompany_posassign', ['userid' => $f['employee']->id, 'assignmenttype' => 'secondary']);
        $this->assertSame([(int)$f['head']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
        // A manager who is not a member of this company (another company's person) is never chosen.
        $foreign = $this->getDataGenerator()->create_user();
        $othercid = repository::save_company((object)['name' => 'Synthetic org chart B', 'active' => 1]);
        $this->member($othercid, (int)$foreign->id);
        $this->assign($f['company'], $f['nodes']['dept'], (int)$foreign->id, true, 'primary');
        $DB->set_field('local_kbacompany_posassign', 'assignmenttype', 'secondary', ['userid' => $f['head']->id]);
        $this->assertSame([(int)$f['head']->id, 'orgstructure'], $this->reviewer($f, 'employee'));
    }

    public function test_applied_reporting_parent_is_a_unit_and_never_a_person(): void {
        global $DB;
        $f = $this->fixture();
        // orgreport.manageruuid holds the reporting parent UNIT (design_service); TEAM reports directly to ROOT.
        $DB->insert_record('local_kbacompany_orgreport', (object)['companyuuid' => $f['company'],
            'nodeuuid' => $f['nodes']['team'], 'manageruuid' => $f['nodes']['root'], 'designuuid' => \core\uuid::generate(),
            'designrevision' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        $this->assertSame([(int)$f['director']->id, 'orgstructure'], $this->reviewer($f, 'lead'));
        // A value that is not a unit of this company (a person's id, another company's node) ends the walk.
        $DB->set_field('local_kbacompany_orgreport', 'manageruuid', (string)$f['head']->id, ['nodeuuid' => $f['nodes']['team']]);
        $this->assertSame('', reviewers::reporting_parent($f['company'], $f['nodes']['team']));
        $this->assertSame([(int)$f['lead']->id, 'self_top'], $this->reviewer($f, 'lead'));
        // An applied empty reporting parent means "top", even with a layout parent.
        $DB->set_field('local_kbacompany_orgreport', 'manageruuid', '', ['nodeuuid' => $f['nodes']['team']]);
        $this->assertSame('', reviewers::reporting_parent($f['company'], $f['nodes']['team']));
    }

    public function test_top_of_chart_self_review_is_marked_in_card_history_and_audit(): void {
        global $DB;
        $f = $this->fixture(); $director = (int)$f['director']->id;
        $this->assertSame([$director, 'self_top'], $this->reviewer($f, 'director'));
        $sink = $this->redirectMessages();
        $sent = $this->submit($f, $f['director']);
        $version = $DB->get_record('local_kbacompany_dreportver', ['uuid' => $sent['versionuuid']]);
        $this->assertSame($director, (int)$version->reviewerid);
        $this->assertSame('self_top', $version->reviewersource);
        $log = json_decode($DB->get_field('local_kbacompany_dreportlog', 'details',
            ['reportuuid' => $sent['reportuuid'], 'action' => 'submitted']), true);
        $this->assertTrue($log['selfreview']); $this->assertSame('self_top', $log['reviewersource']);
        $this->assertSame(0, $sink->count(), 'nobody is notified about own self review');
        $this->assertTrue(review::has_review_access($f['company'], $director));
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $director, 'pending')['items'], 'uuid'));
        $card = review::card($f['company'], $director, $sent['reportuuid']);
        $this->assertTrue($card['selfreview']); $this->assertTrue($card['can_decide']);
        $html = reportui::card($f['company'], $director, $card, []);
        $this->assertStringContainsString('data-dailyreport-selfreview="1"', $html);
        $this->assertStringContainsString(get_string('dailyreports_selfreview_note', 'local_kbacompany'), $html);
        review::decide($f['company'], $director, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '',
            $this->key());
        $decided = json_decode($DB->get_field('local_kbacompany_dreportlog', 'details',
            ['reportuuid' => $sent['reportuuid'], 'action' => 'decided']), true);
        $this->assertTrue($decided['selfreview']);
        $history = store::history($f['company'], $director);
        $this->assertStringContainsString('data-dailyreport-selfreview="1"', reportui::history($f['company'], $history));
        // Ordinary self decisions stay refused: a non-top version stored on the author is not a self review.
        $sentemployee = $this->submit($f, $f['employee']);
        $DB->set_field('local_kbacompany_dreportver', 'reviewerid', $f['employee']->id, ['uuid' => $sentemployee['versionuuid']]);
        $this->error('dailyreports_notreviewer', function() use ($f, $sentemployee) {
            review::decide($f['company'], (int)$f['employee']->id, $sentemployee['reportuuid'], $sentemployee['versionuuid'],
                'accepted', 'positive', '', $this->key());
        });
    }

    public function test_company_top_reviewer_replaces_self_review_and_unplaced_staff_do_not_self_review(): void {
        $f = $this->fixture();
        $this->setAdminUser();
        $this->error('dailyreports_settingsinvalid', function() use ($f) {
            reviewers::save_settings($f['company'], $f['admin'], 'reviewer', 0, 'none', 0);
        });
        reviewers::save_settings($f['company'], $f['admin'], 'reviewer', (int)$f['peer']->id, 'none', 0);
        $this->error('dailyreports_conflict', function() use ($f) {
            reviewers::save_settings($f['company'], $f['admin'], 'self', 0, 'none', 0);
        });
        $this->assertSame([(int)$f['peer']->id, 'top_reviewer'], $this->reviewer($f, 'director'));
        // An employee without any unit is not "at the top": without a top reviewer the report waits for an admin.
        reviewers::save_settings($f['company'], $f['admin'], 'self', 0, 'none', 1);
        $loner = $this->getDataGenerator()->create_user();
        $this->member((int)$f['cid'], (int)$loner->id);
        $this->assertSame([0, 'none'], store::resolve_reviewer($f['company'], (int)$loner->id, $f['day']));
        // The site switch turns self review off: the top author goes to "without reviewer".
        set_config('dailyreports_selftop', 0, 'local_kbacompany');
        $this->assertSame([0, 'none'], $this->reviewer($f, 'director'));
        $this->setUser($f['peer']);
        $this->error('dailyreports_notavailable', function() use ($f) {
            reviewers::save_settings($f['company'], (int)$f['peer']->id, 'self', 0, 'none', 2);
        });
    }

    public function test_manager_reads_whole_company_read_only(): void {
        $f = $this->fixture(); $head = (int)$f['head']->id; $other = (int)$f['other']->id;
        $sent = $this->submit($f, $f['employee']);
        // OTHER's manager is not in the employee's line but is a manager: reads, never decides.
        $this->setUser($f['other']);
        $this->assertTrue(review::has_review_access($f['company'], $other));
        $this->assertContains('company', review::queues($f['company'], $other));
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $other, 'company')['items'], 'uuid'));
        $card = review::card($f['company'], $other, $sent['reportuuid']);
        $this->assertFalse($card['can_decide']); $this->assertTrue($card['manager']);
        $this->error('dailyreports_notreviewer', function() use ($f, $other, $sent) {
            review::decide($f['company'], $other, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '',
                $this->key());
        });
        $this->setUser($f['head']);
        $this->assertFalse(review::card($f['company'], $head, $sent['reportuuid'])['can_decide']);
        // A non-manager colleague sees nothing.
        $this->setUser($f['peer']);
        $this->assertFalse(review::has_review_access($f['company'], (int)$f['peer']->id));
        $this->error('dailyreports_notavailable', function() use ($f, $sent) {
            review::card($f['company'], (int)$f['peer']->id, $sent['reportuuid']);
        });
        $this->error('dailyreports_notavailable', function() use ($f) {
            review::queue($f['company'], (int)$f['peer']->id, 'company');
        });
    }

    public function test_administrator_author_opens_own_unassigned_report_and_reassigns(): void {
        global $DB;
        $f = $this->fixture();
        $this->member((int)$f['cid'], $f['admin']);
        $this->setAdminUser();
        $sent = store::submit($f['company'], $f['admin'], $f['day'], $this->key(), 0, $this->values());
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_dreportver', 'reviewerid', ['uuid' => $sent['versionuuid']]));
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $f['admin'], 'unassigned')['items'], 'uuid'));
        $card = review::card($f['company'], $f['admin'], $sent['reportuuid']);
        $this->assertTrue($card['can_reassign']); $this->assertFalse($card['can_decide']);
        $this->error('dailyreports_reviewerinvalid', function() use ($f, $sent) {
            review::reassign($f['company'], $f['admin'], $sent['reportuuid'], $f['admin']);
        });
        review::reassign($f['company'], $f['admin'], $sent['reportuuid'], (int)$f['head']->id);
        $this->assertSame((int)$f['head']->id,
            (int)$DB->get_field('local_kbacompany_dreportver', 'reviewerid', ['uuid' => $sent['versionuuid']]));
    }

    public function test_copy_recipient_reads_is_notified_once_and_cannot_decide(): void {
        global $DB;
        $f = $this->fixture(); $peer = (int)$f['peer']->id;
        $this->setUser($f['employee']);
        $this->error('dailyreports_ccinvalid', function() use ($f) {
            store::save_draft($f['company'], (int)$f['employee']->id, $f['day'], $this->values(), [], 0,
                [(int)$f['employee']->id]);
        });
        $foreign = $this->getDataGenerator()->create_user();
        $this->error('dailyreports_ccinvalid', function() use ($f, $foreign) {
            store::save_draft($f['company'], (int)$f['employee']->id, $f['day'], $this->values(), [], 0, [(int)$foreign->id]);
        });
        $sink = $this->redirectMessages();
        $sent = $this->submit($f, $f['employee'], [$peer, (int)$f['lead']->id]);
        // The reviewer never also receives a copy.
        $this->assertSame([$peer], array_map('intval', $DB->get_fieldset_select('local_kbacompany_dreportcc', 'userid',
            'versionuuid = :v', ['v' => $sent['versionuuid']])));
        $this->assertSame(1, $DB->count_records('local_kbacompany_commnotif', ['userid' => $peer, 'kind' => 'dailyreport_copy']));
        $this->assertSame(2, $sink->count());
        $this->setUser($f['peer']);
        $this->assertTrue(review::has_review_access($f['company'], $peer));
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $peer, 'copies')['items'], 'uuid'));
        $card = review::card($f['company'], $peer, $sent['reportuuid']);
        $this->assertTrue($card['copy']); $this->assertFalse($card['can_decide']);
        $this->error('dailyreports_notreviewer', function() use ($f, $peer, $sent) {
            review::decide($f['company'], $peer, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '',
                $this->key());
        });
        // Rework keeps the manual copy on the new version; the returned version's copy rows never change.
        $this->setUser($f['lead']);
        review::decide($f['company'], (int)$f['lead']->id, $sent['reportuuid'], $sent['versionuuid'], 'returned', '', 'Why',
            $this->key());
        $this->setUser($f['employee']);
        $draft = store::start_revision($f['company'], (int)$f['employee']->id, $f['day']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportcc', ['versionuuid' => $draft['versionuuid'],
            'userid' => $peer]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportcc', ['versionuuid' => $sent['versionuuid']]));
    }

    public function test_automatic_copy_goes_to_the_manager_above_the_reviewer(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        reviewers::save_settings($f['company'], $f['admin'], 'self', 0, 'upper', 0);
        $resolved = store::resolve($f['company'], (int)$f['employee']->id, $f['day']);
        $this->assertSame((int)$f['lead']->id, $resolved['reviewerid']);
        $this->assertSame([(int)$f['head']->id], $resolved['cc']);
        $sent = $this->submit($f, $f['employee']);
        $this->assertSame('orgrule', $DB->get_field('local_kbacompany_dreportcc', 'source',
            ['versionuuid' => $sent['versionuuid'], 'userid' => $f['head']->id]));
        // At the top there is nobody above: no automatic copy.
        $this->assertSame([], store::resolve($f['company'], (int)$f['director']->id, $f['day'])['cc']);
    }
}
