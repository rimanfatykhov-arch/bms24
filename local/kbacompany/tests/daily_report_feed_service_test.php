<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_feed_service as feed;
use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/**
 * Day feed -> report draft (first scenario, task results only): the feed shows own submit events of the day, the result
 * is copied from the server event into "Done" of the own draft, existing text is kept, a repeated click adds nothing,
 * and foreign companies, other authors, sent reports and the switch are refused.
 */
final class daily_report_feed_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin = (int)$USER->id;
        set_config('dailyreports_enabled', 1, 'local_kbacompany');
        $this->redirectMessages();
        $users = [];
        $companies = [];
        foreach (['A', 'B'] as $label) {
            $cid = repository::save_company((object)['name' => 'Synthetic day feed ' . $label, 'active' => 1]);
            $companies[$label] = identity_registry::company_uuid($cid);
            foreach ($label === 'A' ? ['employee', 'peer'] : ['foreign'] as $name) {
                $u = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Moscow']);
                $DB->insert_record('local_kbacorp_member', (object)['companyid' => $cid, 'userid' => $u->id,
                    'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0,
                    'ishr' => 0, 'timecreated' => time(), 'timemodified' => time()]);
                $users[$name] = $u;
            }
        }
        $this->setUser($users['employee']);
        $day = business_date_service::yesterday();
        $this->setAdminUser();
        return ['admin' => $admin, 'company' => $companies['A'], 'other' => $companies['B'], 'day' => $day] + $users;
    }

    /** A task of $assignee submitted by $assignee with $result; the submit event is placed at noon of $day. */
    private function submitted(array $f, \stdClass $assignee, string $title, string $result, string $day,
            int $reviewer = 0): array {
        global $DB;
        $this->setAdminUser();
        $task = task_service::create($f['company'], $f['admin'], ['title' => $title, 'product' => 'Verified result',
            'assigneeid' => (int)$assignee->id, 'reviewerid' => $reviewer, 'observerid' => 0, 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => $day, 'duedate' => $day, 'planminutes' => 30]);
        $this->setUser($assignee);
        task_service::transition($f['company'], (int)$assignee->id, $task, 1, 'start');
        task_service::transition($f['company'], (int)$assignee->id, $task, 2, 'submit', ['result' => $result, 'factminutes' => 20]);
        [$from] = daily_report_service::bounds($day);
        $log = $DB->get_record('local_kbacompany_tasklog', ['taskuuid' => $task, 'action' => 'submit'], '*', MUST_EXIST);
        $DB->set_field('local_kbacompany_tasklog', 'timecreated', $from + 12 * 3600, ['id' => $log->id]);
        return ['task' => $task, 'event' => (int)$log->id];
    }

    private function values(string $done): array {
        return ['planned' => 'Plan', 'done' => $done, 'notdone' => '', 'blockers' => '', 'nextplan' => 'Next'];
    }

    private function expect_error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_feed_shows_own_results_of_the_selected_day_only(): void {
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $own = $this->submitted($f, $f['employee'], 'Own task', 'Own result', $f['day'], (int)$f['peer']->id);
        $this->submitted($f, $f['peer'], 'Peer task', 'Peer result', $f['day']);
        $earlier = date('Y-m-d', strtotime($f['day'] . ' -3 days'));
        $this->submitted($f, $f['employee'], 'Earlier task', 'Earlier result', $earlier);
        $this->setUser($f['employee']);
        $day = feed::day($f['company'], $u, $f['day']);
        $this->assertSame(1, $day['total']);
        $this->assertSame($own['event'], (int)$day['results'][0]->id);
        $this->assertSame('Own result', $day['results'][0]->result);
        // "Result submitted" keeps the current task status: submitted is awaiting acceptance, not accepted.
        $this->assertSame('review', $day['results'][0]->currentstatus);
        $this->assertSame(1, feed::day($f['company'], $u, $earlier)['total']);
        // An empty day is an empty feed, not an error.
        $empty = date('Y-m-d', strtotime($f['day'] . ' -10 days'));
        $this->assertSame(0, feed::day($f['company'], $u, $empty)['total']);
        $this->assertSame([], feed::day($f['company'], $u, $empty)['results']);
    }

    public function test_add_keeps_existing_text_links_task_and_repeated_click_adds_nothing(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $r = $this->submitted($f, $f['employee'], 'Prepare offer', 'Offer agreed with sales', $f['day']);
        // The current task result changes later; the report gets the submitted text.
        $DB->set_field('local_kbacompany_task', 'result', 'Later mutable value', ['uuid' => $r['task']]);
        $this->setUser($f['employee']);
        store::save_draft($f['company'], $u, $f['day'], $this->values('Written by hand'), [], 0);
        $first = feed::add($f['company'], $u, $f['day'], $r['event']);
        $this->assertTrue($first['added']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame("Written by hand\n«Prepare offer»: Offer agreed with sales", $view['draft']->done);
        $this->assertSame('Plan', $view['draft']->planned);
        $this->assertSame([$r['task']], array_map(static function($l) { return (string)$l->taskuuid; }, $view['draftlinks']));
        $revision = (int)$view['draft']->revision;
        $logs = $DB->count_records('local_kbacompany_dreportlog');
        // Repeated click in the feed: nothing written.
        $again = feed::add($f['company'], $u, $f['day'], $r['event']);
        $this->assertFalse($again['added']);
        $this->assertSame($revision, (int)store::view($f['company'], $u, $f['day'])['draft']->revision);
        $this->assertSame($logs, $DB->count_records('local_kbacompany_dreportlog'));
        // Repeated click inside the open form: the unsaved edit is kept, the result is not duplicated.
        $edited = $view['draft']->done . "\nUnsaved edit";
        $form = ['values' => $this->values($edited), 'links' => [$r['task']], 'cc' => [], 'revision' => $revision];
        $inform = feed::add($f['company'], $u, $f['day'], $r['event'], $form);
        $this->assertFalse($inform['added']);
        $done = store::view($f['company'], $u, $f['day'])['draft']->done;
        $this->assertSame($edited, $done);
        $this->assertSame(1, substr_count($done, 'Offer agreed with sales'));
    }

    public function test_add_creates_draft_when_none_and_keeps_unsaved_form_text(): void {
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $one = $this->submitted($f, $f['employee'], 'Task one', 'Result one', $f['day']);
        $two = $this->submitted($f, $f['employee'], 'Task two', 'Result two', $f['day']);
        $this->setUser($f['employee']);
        $this->assertTrue(feed::add($f['company'], $u, $f['day'], $one['event'])['added']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame('«Task one»: Result one', $view['draft']->done);
        $form = ['values' => $this->values("«Task one»: Result one\nTyped, not saved"), 'links' => [], 'cc' => [],
            'revision' => (int)$view['draft']->revision];
        $this->assertTrue(feed::add($f['company'], $u, $f['day'], $two['event'], $form)['added']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame("«Task one»: Result one\nTyped, not saved\n«Task two»: Result two", $view['draft']->done);
        // A stale form revision is the store's conflict and changes nothing.
        $stale = ['values' => $this->values('Stale'), 'links' => [], 'cc' => [], 'revision' => 1];
        $this->expect_error('dailyreports_conflict', function() use ($f, $u, $one, $stale) {
            feed::add($f['company'], $u, $f['day'], $one['event'], $stale);
        });
        $this->assertSame($view['draft']->done, store::view($f['company'], $u, $f['day'])['draft']->done);
    }

    public function test_other_author_other_company_and_wrong_day_are_refused_alike(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $r = $this->submitted($f, $f['employee'], 'Own task', 'Own result', $f['day']);
        $peer = $this->submitted($f, $f['peer'], 'Peer task', 'Peer result', $f['day']);
        $this->setUser($f['employee']);
        // Another author's event in the same company.
        $this->expect_error('dailyreports_taskunavailable', function() use ($f, $u, $peer) {
            feed::add($f['company'], $u, $f['day'], $peer['event']);
        });
        // The own event under another day.
        $other = date('Y-m-d', strtotime($f['day'] . ' -1 day'));
        $this->expect_error('dailyreports_taskunavailable', function() use ($f, $u, $r, $other) {
            feed::add($f['company'], $u, $other, $r['event']);
        });
        $this->assertNull(daily_report_service::result($f['company'], $u, $f['day'], 0));
        // An employee of another company: not a member of A, and A's event is unknown under B.
        $this->setUser($f['foreign']);
        $foreign = (int)$f['foreign']->id;
        try {
            feed::add($f['company'], $foreign, $f['day'], $r['event']);
            $this->fail('Foreign member added to company A');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('', $e->errorcode);
        }
        $this->expect_error('dailyreports_taskunavailable', function() use ($f, $foreign, $r) {
            feed::add($f['other'], $foreign, $f['day'], $r['event']);
        });
        $this->assertSame(0, $DB->count_records('local_kbacompany_dreport'));
    }

    public function test_sent_report_and_switch_off_are_refused(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        $r = $this->submitted($f, $f['employee'], 'Own task', 'Own result', $f['day']);
        $this->setUser($f['employee']);
        $saved = store::save_draft($f['company'], $u, $f['day'], $this->values('Sent text'), [], 0);
        // Fixture: the report is no longer a draft (as after submission).
        $DB->set_field('local_kbacompany_dreport', 'draftversionuuid', '', ['uuid' => $saved['reportuuid']]);
        $DB->set_field('local_kbacompany_dreport', 'status', 'submitted', ['uuid' => $saved['reportuuid']]);
        $this->expect_error('dailyreports_notdraft', function() use ($f, $u, $r) {
            feed::add($f['company'], $u, $f['day'], $r['event']);
        });
        set_config('dailyreports_enabled', 0, 'local_kbacompany');
        $this->expect_error('dailyreports_disabled', function() use ($f, $u, $r) {
            feed::add($f['company'], $u, $f['day'], $r['event']);
        });
    }

    public function test_repeat_is_decided_by_submit_event_id_not_by_text(): void {
        global $DB;
        $f = $this->fixture(); $u = (int)$f['employee']->id;
        // Two different submit events whose blocks look exactly alike: both are added.
        $one = $this->submitted($f, $f['employee'], 'Call client', 'Done', $f['day']);
        $two = $this->submitted($f, $f['employee'], 'Call client', 'Done', $f['day']);
        $this->setUser($f['employee']);
        $this->assertTrue(feed::add($f['company'], $u, $f['day'], $one['event'])['added']);
        $this->assertTrue(feed::add($f['company'], $u, $f['day'], $two['event'])['added']);
        $view = store::view($f['company'], $u, $f['day']);
        $this->assertSame("«Call client»: Done\n«Call client»: Done", $view['draft']->done);
        $this->assertTrue(store::result_added($f['company'], (string)$view['report']->uuid, $one['event']));
        $this->assertTrue(store::result_added($f['company'], (string)$view['report']->uuid, $two['event']));
        // The task is renamed afterwards: the same event is still recognised in the feed and in the open form.
        $DB->set_field('local_kbacompany_task', 'title', 'Call the key client', ['uuid' => $one['task']]);
        $revision = (int)$view['draft']->revision;
        $logs = $DB->count_records('local_kbacompany_dreportlog');
        $again = feed::add($f['company'], $u, $f['day'], $one['event']);
        $this->assertSame(['added' => false, 'revision' => $revision], $again);
        $this->assertSame($logs, $DB->count_records('local_kbacompany_dreportlog'));
        $form = ['values' => $this->values($view['draft']->done . "\nTyped"), 'links' => [], 'cc' => [], 'revision' => $revision];
        $this->assertFalse(feed::add($f['company'], $u, $f['day'], $one['event'], $form)['added']);
        $done = store::view($f['company'], $u, $f['day'])['draft']->done;
        $this->assertSame("«Call client»: Done\n«Call client»: Done\nTyped", $done);
        $this->assertStringNotContainsString('Call the key client', $done);
        // Text removed by hand does not make the event new again; the journal row is per report, not per text.
        $view = store::view($f['company'], $u, $f['day']);
        $form = ['values' => $this->values('Rewritten by hand'), 'links' => [], 'cc' => [], 'revision' => (int)$view['draft']->revision];
        $this->assertFalse(feed::add($f['company'], $u, $f['day'], $two['event'], $form)['added']);
        $this->assertSame('Rewritten by hand', store::view($f['company'], $u, $f['day'])['draft']->done);
        // The journal is company-scoped: the same event id is unknown for another report.
        $this->assertFalse(store::result_added($f['other'], (string)$view['report']->uuid, $one['event']));
    }

    public function test_block(): void {
        $this->resetAfterTest();
        $event = (object)['title' => ' Title ', 'result' => "Line 1\r\nLine 2 "];
        $this->assertSame("«Title»: Line 1\nLine 2", feed::block($event));
        $this->assertSame('«Title»', feed::block((object)['title' => 'Title', 'result' => '']));
    }
}
