<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/** ADR-0009 R2: version-bound decisions, ratings, return and rework, reassignment, no self review, isolation. */
final class daily_report_review_service_test extends \advanced_testcase {
    private function fixture(bool $withplan = true): array {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        // Reviewer notifications use Moodle messages; capture them instead of sending.
        $this->redirectMessages();
        $cid = repository::save_company((object)['name' => 'Synthetic review A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['employee', 'lead', 'peer'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
            $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $u->id,
                'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => time(), 'timemodified' => time()]);
            $users[$name] = $u;
        }
        $this->setUser($users['employee']);
        $day = business_date_service::day();
        $this->setAdminUser();
        if ($withplan) {
            $DB->insert_record('local_kbacompany_bplan', (object)['companyuuid' => $company, 'uuid' => \core\uuid::generate(),
                'userid' => $users['employee']->id, 'positionuuid' => '', 'periodstart' => $day, 'periodend' => $day,
                'status' => 'approved', 'reviewerid' => $users['lead']->id, 'observerid' => 0, 'templateuuid' => '',
                'templaterevision' => 0, 'normuuid' => '', 'normrevision' => 0, 'statepayload' => '{}',
                'createdby' => $admin, 'approvedby' => 0, 'submittedat' => 0, 'approvedat' => 0, 'closedat' => 0,
                'timecreated' => time(), 'timemodified' => time()]);
        }
        return compact('admin', 'company', 'cid', 'day') + $users;
    }

    private function key(): string {
        return \core\uuid::generate();
    }

    private function values(string $done = 'Synthetic result'): array {
        return ['planned' => 'Plan', 'done' => $done, 'notdone' => '', 'blockers' => '', 'nextplan' => ''];
    }

    private function submitted(array $f, string $done = 'Synthetic result'): array {
        $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $view = store::view($f['company'], $u, $f['day']);
        $revision = $view['draft'] ? (int)$view['draft']->revision : 0;
        return store::submit($f['company'], $u, $f['day'], $this->key(), $revision, $this->values($done));
    }

    private function error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_accept_with_rating_is_idempotent_and_notifies_once(): void {
        global $DB;
        $f = $this->fixture(); $lead = (int)$f['lead']->id;
        $sink = $this->redirectMessages();
        $sent = $this->submitted($f);
        $this->setUser($f['lead']);
        $queue = review::queue($f['company'], $lead, 'pending');
        $this->assertSame([$sent['reportuuid']], array_column($queue['items'], 'uuid'));
        $card = review::card($f['company'], $lead, $sent['reportuuid']);
        $this->assertTrue($card['can_decide']);
        $key = $this->key();
        $one = review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '', $key);
        $two = review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'negative', 'x', $key);
        $this->assertSame($one['uuid'], $two['uuid']); $this->assertTrue($two['replayed']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportrev', ['versionuuid' => $sent['versionuuid']]));
        $this->assertSame('accepted', $DB->get_field('local_kbacompany_dreport', 'status', ['uuid' => $sent['reportuuid']]));
        $this->assertSame('positive', $DB->get_field('local_kbacompany_dreportrev', 'rating', ['uuid' => $one['uuid']]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_commnotif', ['userid' => $lead, 'kind' => 'dailyreport_submitted']));
        $this->assertSame(1, $DB->count_records('local_kbacompany_commnotif',
            ['userid' => $f['employee']->id, 'kind' => 'dailyreport_decision']));
        $this->assertSame(2, $sink->count());
        $this->assertSame([], review::queue($f['company'], $lead, 'pending')['items']);
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $lead, 'accepted')['items'], 'uuid'));
    }

    public function test_reason_comment_and_rating_rules(): void {
        $f = $this->fixture(); $lead = (int)$f['lead']->id; $sent = $this->submitted($f);
        $this->setUser($f['lead']);
        $this->error('dailyreports_commentrequired', function() use ($f, $lead, $sent) {
            review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'negative', ' ', $this->key());
        });
        $this->error('dailyreports_ratingrequired', function() use ($f, $lead, $sent) {
            review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'stars5', '', $this->key());
        });
        $this->error('dailyreports_reasonrequired', function() use ($f, $lead, $sent) {
            review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'returned', '', '', $this->key());
        });
    }

    public function test_only_the_stored_reviewer_decides_and_never_the_author(): void {
        global $DB;
        $f = $this->fixture(); $sent = $this->submitted($f);
        $this->setUser($f['peer']);
        $this->error('dailyreports_notreviewer', function() use ($f, $sent) {
            review::decide($f['company'], (int)$f['peer']->id, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'none', '',
                $this->key());
        });
        $this->error('dailyreports_notavailable', function() use ($f, $sent) {
            review::card($f['company'], (int)$f['peer']->id, $sent['reportuuid']);
        });
        // Even a corrupted assignment to the author is refused on the server.
        $DB->set_field('local_kbacompany_dreportver', 'reviewerid', $f['employee']->id, ['uuid' => $sent['versionuuid']]);
        $this->setUser($f['employee']);
        $this->error('dailyreports_notreviewer', function() use ($f, $sent) {
            review::decide($f['company'], (int)$f['employee']->id, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive',
                '', $this->key());
        });
        $this->error('dailyreports_notavailable', function() use ($f, $sent) {
            review::card($f['company'], (int)$f['employee']->id, $sent['reportuuid']);
        });
        $this->assertSame(0, $DB->count_records('local_kbacompany_dreportrev', ['reportuuid' => $sent['reportuuid']]));
    }

    public function test_return_rework_new_version_starts_without_rating_and_old_version_is_stale(): void {
        global $DB;
        $f = $this->fixture(); $lead = (int)$f['lead']->id; $u = (int)$f['employee']->id;
        $v1 = $this->submitted($f, 'First attempt');
        $this->setUser($f['lead']);
        review::decide($f['company'], $lead, $v1['reportuuid'], $v1['versionuuid'], 'returned', '', 'Add the result link', $this->key());
        $this->assertSame([$v1['reportuuid']], array_column(review::queue($f['company'], $lead, 'returned')['items'], 'uuid'));
        $this->setUser($f['employee']);
        $draft = store::start_revision($f['company'], $u, $f['day']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame('First attempt', $view['draft']->done);
        $this->assertSame(2, (int)$view['draft']->versionno);
        $v2 = store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision'], $this->values('Second attempt'));
        $this->assertNotSame($v1['versionuuid'], $v2['versionuuid']);
        $this->setUser($f['lead']);
        $this->error('dailyreports_staleversion', function() use ($f, $lead, $v1) {
            review::decide($f['company'], $lead, $v1['reportuuid'], $v1['versionuuid'], 'accepted', 'positive', '', $this->key());
        });
        $this->assertSame(0, $DB->count_records('local_kbacompany_dreportrev', ['versionuuid' => $v2['versionuuid']]));
        review::decide($f['company'], $lead, $v2['reportuuid'], $v2['versionuuid'], 'accepted', 'none', '', $this->key());
        $this->assertSame('returned', $DB->get_field('local_kbacompany_dreportrev', 'decision', ['versionuuid' => $v1['versionuuid']]));
        $this->assertSame('none', $DB->get_field('local_kbacompany_dreportrev', 'rating', ['versionuuid' => $v2['versionuuid']]));
        $this->assertSame('First attempt', $DB->get_field('local_kbacompany_dreportver', 'done', ['uuid' => $v1['versionuuid']]));
    }

    public function test_rating_change_supersedes_with_reason_and_correction_keeps_old_rating(): void {
        global $DB;
        $f = $this->fixture(); $lead = (int)$f['lead']->id; $u = (int)$f['employee']->id;
        $v1 = $this->submitted($f);
        $this->setUser($f['lead']);
        $first = review::decide($f['company'], $lead, $v1['reportuuid'], $v1['versionuuid'], 'accepted', 'positive', '', $this->key());
        $this->error('dailyreports_reasonrequired', function() use ($f, $lead, $v1) {
            review::decide($f['company'], $lead, $v1['reportuuid'], $v1['versionuuid'], 'accepted', 'none', '', $this->key());
        });
        $second = review::decide($f['company'], $lead, $v1['reportuuid'], $v1['versionuuid'], 'accepted', 'negative',
            'Result was incomplete', $this->key());
        $this->assertSame($first['uuid'], $DB->get_field('local_kbacompany_dreportrev', 'supersedesuuid', ['uuid' => $second['uuid']]));
        $this->assertSame('positive', $DB->get_field('local_kbacompany_dreportrev', 'rating', ['uuid' => $first['uuid']]));
        $this->setUser($f['employee']);
        $this->error('dailyreports_correctionrequired', function() use ($f, $u) {
            store::start_revision($f['company'], $u, $f['day'], '');
        });
        store::start_revision($f['company'], $u, $f['day'], 'Fixed the figure in the result');
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame('draft', $view['report']->status);
        $this->assertSame('Fixed the figure in the result', $view['draft']->correctionnote);
        $this->assertSame(2, $DB->count_records('local_kbacompany_dreportrev', ['versionuuid' => $v1['versionuuid']]));
    }

    public function test_revoked_reviewer_and_other_company_are_denied(): void {
        $f = $this->fixture(); $lead = (int)$f['lead']->id; $sent = $this->submitted($f);
        $this->setAdminUser();
        $other = repository::save_company((object)['name' => 'Synthetic review B', 'active' => 1]);
        $outsider = $this->getDataGenerator()->create_user();
        repository::add_member($other, (int)$outsider->id, 'employee');
        $this->setUser($outsider);
        try {
            review::card($f['company'], (int)$outsider->id, $sent['reportuuid']);
            $this->fail('Foreign company must be denied');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
        $this->setAdminUser();
        membership_access_service::change($f['company'], $lead, $f['admin']);
        $this->setUser($f['lead']);
        try {
            review::decide($f['company'], $lead, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '', $this->key());
            $this->fail('Revoked reviewer must be denied');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
    }

    public function test_administrator_sees_unassigned_and_reassigns_with_journal(): void {
        global $DB;
        $f = $this->fixture(false); $peer = (int)$f['peer']->id;
        $sent = $this->submitted($f);
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_dreportver', 'reviewerid', ['uuid' => $sent['versionuuid']]));
        $this->setAdminUser();
        $this->assertSame([$sent['reportuuid']], array_column(review::queue($f['company'], $f['admin'], 'unassigned')['items'], 'uuid'));
        $this->error('dailyreports_reviewerinvalid', function() use ($f, $sent) {
            review::reassign($f['company'], $f['admin'], $sent['reportuuid'], (int)$f['employee']->id);
        });
        review::reassign($f['company'], $f['admin'], $sent['reportuuid'], $peer);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportlog',
            ['reportuuid' => $sent['reportuuid'], 'action' => 'reviewer_reassigned']));
        $this->setUser($f['peer']);
        $this->assertFalse(review::is_admin($f['company'], $peer));
        $this->error('dailyreports_notavailable', function() use ($f, $peer) {
            review::queue($f['company'], $peer, 'unassigned');
        });
        review::decide($f['company'], $peer, $sent['reportuuid'], $sent['versionuuid'], 'accepted', 'positive', '', $this->key());
        $this->assertSame('accepted', $DB->get_field('local_kbacompany_dreport', 'status', ['uuid' => $sent['reportuuid']]));
    }
}
