<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** ADR-0009 R1: saved drafts, idempotent immutable submissions, snapshots, reviewer resolution and isolation. */
final class daily_report_store_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $cid = repository::save_company((object)['name' => 'Synthetic daily reports A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $users = [];
        foreach (['employee', 'peer', 'lead'] as $name) {
            $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
            $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $u->id,
                'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => time(), 'timemodified' => time()]);
            $users[$name] = $u;
        }
        $this->setUser($users['employee']);
        $day = business_date_service::day();
        $this->setAdminUser();
        return compact('admin', 'company', 'cid', 'day') + $users;
    }

    private function task(array $f, int $assignee, string $title): string {
        $this->setAdminUser();
        return task_service::create($f['company'], $f['admin'], ['title' => $title, 'product' => 'Verified result',
            'assigneeid' => $assignee, 'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => $f['day'], 'duedate' => $f['day'], 'planminutes' => 30]);
    }

    private function values(string $done = 'Finished the synthetic result'): array {
        return ['planned' => 'Plan', 'done' => $done, 'notdone' => '', 'blockers' => 'None', 'nextplan' => 'Next'];
    }

    private function key(): string {
        return \core\uuid::generate();
    }

    private function expect_error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_server_draft_survives_relogin_and_one_report_per_day(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $this->setUser($f['employee']);
        $saved = store::save_draft($f['company'], $u, $f['day'], $this->values('First text'), [], 0);
        $this->setUser($f['peer']); $this->setUser($f['employee']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame('First text', $view['draft']->done);
        $this->assertSame('draft', $view['report']->status);
        store::save_draft($f['company'], $u, $f['day'], $this->values('Second text'), [], $saved['revision']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreport', ['userid' => $u]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportver', ['reportuuid' => $saved['reportuuid']]));
        $this->assertSame('Second text', store::view($f['company'], $u, $f['day'])['draft']->done);
        $this->assertSame(\core_date::get_user_timezone(), $view['report']->timezone);
    }

    public function test_stale_revision_is_a_conflict_without_losing_the_saved_text(): void {
        $f = $this->fixture(); $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $first = store::save_draft($f['company'], $u, $f['day'], $this->values('Tab A'), [], 0);
        store::save_draft($f['company'], $u, $f['day'], $this->values('Tab A again'), [], $first['revision']);
        $this->expect_error('dailyreports_conflict', function() use ($f, $u, $first) {
            store::save_draft($f['company'], $u, $f['day'], $this->values('Tab B stale'), [], $first['revision']);
        });
        $this->assertSame('Tab A again', store::view($f['company'], $u, $f['day'])['draft']->done);
        $this->expect_error('dailyreports_conflict', function() use ($f, $u) {
            store::submit($f['company'], $u, $f['day'], $this->key(), 1, $this->values('Stale submit'));
        });
        $this->assertSame('draft', store::view($f['company'], $u, $f['day'])['report']->status);
    }

    public function test_repeated_submission_key_creates_one_frozen_version(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(), [], 0);
        $key = $this->key();
        $one = store::submit($f['company'], $u, $f['day'], $key, $draft['revision'], $this->values('Final text'));
        $two = store::submit($f['company'], $u, $f['day'], $key, $draft['revision'], $this->values('Replay must not apply'));
        $this->assertFalse($one['replayed']); $this->assertTrue($two['replayed']);
        $this->assertSame($one['versionuuid'], $two['versionuuid']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportver', ['reportuuid' => $one['reportuuid']]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportlog',
            ['reportuuid' => $one['reportuuid'], 'action' => 'submitted']));
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame('submitted', $view['report']->status);
        $this->assertNull($view['draft']);
        $this->assertSame('Final text', $view['versions'][0]->done);
        $this->assertSame(64, strlen($view['versions'][0]->contenthash));
        $this->assertNotEmpty(json_decode($view['versions'][0]->autosnapshot, true)['day']);
        $this->expect_error('dailyreports_notdraft', function() use ($f, $u) {
            store::save_draft($f['company'], $u, $f['day'], $this->values('Edit after submit'), [], 2);
        });
        $this->assertSame('Final text', $DB->get_field('local_kbacompany_dreportver', 'done', ['uuid' => $one['versionuuid']]));
    }

    public function test_done_is_required_future_day_and_disabled_switch_are_refused(): void {
        $f = $this->fixture(); $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(''), [], 0);
        $this->expect_error('dailyreports_donerequired', function() use ($f, $u, $draft) {
            store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision']);
        });
        $tomorrow = (new \DateTimeImmutable($f['day']))->modify('+1 day')->format('Y-m-d');
        $this->expect_error('dailyreports_futureday', function() use ($f, $u, $tomorrow) {
            store::save_draft($f['company'], $u, $tomorrow, $this->values(), [], 0);
        });
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->expect_error('dailyreports_disabled', function() use ($f, $u, $draft) {
            store::save_draft($f['company'], $u, $f['day'], $this->values(), [], $draft['revision']);
        });
        // Read access keeps working while the switch is off (rollback path keeps data visible to its author).
        $this->assertSame('draft', store::view($f['company'], $u, $f['day'])['report']->status);
    }

    public function test_submitted_snapshot_is_not_rewritten_by_later_task_changes(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $task = $this->task($f, $u, 'Original synthetic title');
        $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(), [$task], 0);
        $sent = store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision']);
        // Explicit fixture change of the canonical task after submission.
        $DB->set_field('local_kbacompany_task', 'title', 'Renamed later', ['uuid' => $task]);
        $DB->set_field('local_kbacompany_task', 'status', 'done', ['uuid' => $task]);
        $link = store::view($f['company'], $u, $f['day'])['links'][$sent['versionuuid']][0];
        $this->assertSame('Original synthetic title', $link->title);
        $this->assertNotSame('done', $link->status);
        $this->assertSame('due', $link->origin);
        $this->assertSame($f['day'], $link->duedate);
        $this->assertSame('Renamed later', $DB->get_field('local_kbacompany_task', 'title', ['uuid' => $task]));
    }

    public function test_tasks_the_author_cannot_open_are_refused(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $peer = $this->task($f, (int)$f['peer']->id, 'Peer only');
        $this->setUser($f['employee']);
        foreach ([$peer, '00000000-0000-4000-8000-000000000000', 'not-a-uuid'] as $taskuuid) {
            $this->expect_error('dailyreports_taskunavailable', function() use ($f, $u, $taskuuid) {
                store::save_draft($f['company'], $u, $f['day'], $this->values(), [$taskuuid], 0);
            });
        }
        $this->assertSame(0, $DB->count_records('local_kbacompany_dreport', ['userid' => $u]));
    }

    public function test_other_company_and_revoked_member_see_nothing_and_data_is_kept(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(), [], 0);
        store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision']);
        $this->setAdminUser();
        $other = repository::save_company((object)['name' => 'Synthetic B', 'active' => 1]);
        $outsider = $this->getDataGenerator()->create_user();
        repository::add_member($other, (int)$outsider->id, 'employee');
        $this->setUser($outsider);
        $this->expectExceptionObjectSafe(function() use ($f, $outsider) {
            store::view($f['company'], (int)$outsider->id, $f['day']);
        });
        $this->expectExceptionObjectSafe(function() use ($f, $outsider) {
            store::history($f['company'], (int)$outsider->id);
        });
        $this->setAdminUser();
        membership_access_service::change($f['company'], $u, $f['admin']);
        $this->setUser($f['employee']);
        $this->expectExceptionObjectSafe(function() use ($f, $u) { store::view($f['company'], $u, $f['day']); });
        $this->expectExceptionObjectSafe(function() use ($f, $u) { store::history($f['company'], $u); });
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreport', ['userid' => $u]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreportver', ['state' => 'submitted']));
    }

    private function expectExceptionObjectSafe(callable $call): void {
        try {
            $call();
            $this->fail('Access must be denied');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }
    }

    public function test_reviewer_comes_from_battle_plan_and_never_the_author(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $plan = ['companyuuid' => $f['company'], 'uuid' => \core\uuid::generate(), 'userid' => $u, 'positionuuid' => '',
            'periodstart' => $f['day'], 'periodend' => $f['day'], 'status' => 'approved', 'reviewerid' => $u,
            'observerid' => 0, 'templateuuid' => '', 'templaterevision' => 0, 'normuuid' => '', 'normrevision' => 0,
            'statepayload' => '{}', 'createdby' => $f['admin'], 'approvedby' => 0, 'submittedat' => 0, 'approvedat' => 0,
            'closedat' => 0, 'timecreated' => time(), 'timemodified' => time()];
        $selfplan = $DB->insert_record('local_kbacompany_bplan', (object)$plan);
        $this->assertSame([0, 'none'], store::resolve_reviewer($f['company'], $u, $f['day']));
        $DB->set_field('local_kbacompany_bplan', 'reviewerid', (int)$f['lead']->id, ['id' => $selfplan]);
        $this->assertSame([(int)$f['lead']->id, 'battleplan'], store::resolve_reviewer($f['company'], $u, $f['day']));
        $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(), [], 0);
        $sent = store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision']);
        $version = $DB->get_record('local_kbacompany_dreportver', ['uuid' => $sent['versionuuid']]);
        $this->assertSame((int)$f['lead']->id, (int)$version->reviewerid);
        $this->assertSame('battleplan', $version->reviewersource);
        // A revoked reviewer is skipped; nobody eligible means "without reviewer", never the author.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['lead']->id, $f['admin']);
        $this->assertSame([0, 'none'], store::resolve_reviewer($f['company'], $u, $f['day']));
    }

    public function test_history_is_own_paged_and_newest_first(): void {
        $f = $this->fixture(); $u = (int)$f['employee']->id; $this->setUser($f['employee']);
        $yesterday = business_date_service::yesterday();
        store::save_draft($f['company'], $u, $yesterday, $this->values('Yesterday'), [], 0);
        store::save_draft($f['company'], $u, $f['day'], $this->values('Today'), [], 0);
        $this->setUser($f['peer']);
        store::save_draft($f['company'], (int)$f['peer']->id, $f['day'], $this->values('Peer'), [], 0);
        $this->setUser($f['employee']);
        $history = store::history($f['company'], $u);
        $this->assertSame(2, $history['total']);
        $this->assertSame([$f['day'], $yesterday], array_map(static function(array $row): string {
            return $row['report']->reportday;
        }, $history['items']));
    }

    public function test_privacy_lists_exports_and_deletes_daily_reports(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $plan = ['companyuuid' => $f['company'], 'uuid' => \core\uuid::generate(), 'userid' => $u, 'positionuuid' => '',
            'periodstart' => $f['day'], 'periodend' => $f['day'], 'status' => 'approved', 'reviewerid' => (int)$f['lead']->id,
            'observerid' => 0, 'templateuuid' => '', 'templaterevision' => 0, 'normuuid' => '', 'normrevision' => 0,
            'statepayload' => '{}', 'createdby' => $f['admin'], 'approvedby' => 0, 'submittedat' => 0, 'approvedat' => 0,
            'closedat' => 0, 'timecreated' => time(), 'timemodified' => time()];
        $DB->insert_record('local_kbacompany_bplan', (object)$plan);
        $this->setUser($f['employee']);
        $draft = store::save_draft($f['company'], $u, $f['day'], $this->values(), [], 0);
        $sent = store::submit($f['company'], $u, $f['day'], $this->key(), $draft['revision']);
        // A decision row as written by the reviewer (R2), to prove reviewer anonymisation.
        $DB->insert_record('local_kbacompany_dreportrev', (object)['uuid' => \core\uuid::generate(),
            'companyuuid' => $f['company'], 'reportuuid' => $sent['reportuuid'], 'versionuuid' => $sent['versionuuid'],
            'reviewerid' => (int)$f['lead']->id, 'decision' => 'accepted', 'rating' => 'positive',
            'comment' => 'Reviewer comment', 'supersedesuuid' => '', 'requestkey' => $this->key(), 'timecreated' => time()]);
        $system = \context_system::instance();
        foreach ([$u, (int)$f['lead']->id] as $userid) {
            $this->assertContains((int)$system->id, array_map('intval',
                \local_kbacompany\privacy\provider::get_contexts_for_userid($userid)->get_contextids()));
        }
        $this->setAdminUser();
        $lead = new \core_privacy\local\request\approved_contextlist($f['lead'], 'local_kbacompany', [$system->id]);
        \local_kbacompany\privacy\provider::delete_data_for_user($lead);
        $decision = $DB->get_record('local_kbacompany_dreportrev', ['versionuuid' => $sent['versionuuid']]);
        $this->assertSame(0, (int)$decision->reviewerid);
        $this->assertNull($decision->comment);
        $this->assertSame('accepted', $decision->decision);
        $this->assertSame(1, $DB->count_records('local_kbacompany_dreport', ['userid' => $u]));
        $author = new \core_privacy\local\request\approved_contextlist($f['employee'], 'local_kbacompany', [$system->id]);
        \local_kbacompany\privacy\provider::delete_data_for_user($author);
        foreach (['local_kbacompany_dreport' => ['userid' => $u],
                'local_kbacompany_dreportver' => ['reportuuid' => $sent['reportuuid']],
                'local_kbacompany_dreportrev' => ['reportuuid' => $sent['reportuuid']],
                'local_kbacompany_dreportlog' => ['reportuuid' => $sent['reportuuid']]] as $table => $where) {
            $this->assertSame(0, $DB->count_records($table, $where), $table);
        }
    }
}
