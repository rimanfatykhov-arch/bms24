<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\deviation_service as deviations;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\business_date_service;
use local_kbacorporate\local\repository;

/** Stored approved-bundle fixtures isolate deviation logic from the upstream approval tests. */
final class deviation_service_test extends \advanced_testcase {
    public function test_numeric_rules_preserve_zero_negative_and_precision(): void {
        $this->assertFalse(deviations::evaluate('0', ['amount' => '1'], 'up')['inrange']);
        $this->assertTrue(deviations::evaluate('0', ['amount' => '0'], 'neutral')['inrange']);
        $this->assertTrue(deviations::evaluate('-2', ['amount' => '-1'], 'down')['inrange']);
        $this->assertFalse(deviations::evaluate('999999999999.999998', ['amount' => '999999999999.999999'], 'up')['inrange']);
        $this->assertTrue(deviations::evaluate('7', ['amount' => '10', 'lower' => '5', 'upper' => '12'], 'up')['inrange']);
        $this->expectException(\moodle_exception::class);
        deviations::evaluate('', ['amount' => '0'], 'up');
    }

    public function test_observation_must_be_within_norm_period(): void {
        deviations::observation_date('2026-09-30', '2026-09-01', 'monthly');
        $this->expectException(\moodle_exception::class);
        deviations::observation_date('2026-10-01', '2026-09-01', 'monthly');
    }

    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $company = identity_registry::company_uuid(repository::save_company((object)['name' => 'Deviations', 'active' => 1]));
        // One instant for acceptance and observation; display userdate() removes day zeroes.
        $now = time();
        $date = business_date_service::day($now);
        $period = substr($date, 0, 7) . '-01';
        $function = '10000000-0000-4000-8000-000000000001';
        $norm = '10000000-0000-4000-8000-000000000002';
        $approval = '10000000-0000-4000-8000-000000000003';
        $task = '10000000-0000-4000-8000-000000000004';
        $snapshot = ['object' => ['uuid' => $function, 'kind' => 'function', 'name' => 'Function'],
            'objects' => [['uuid' => $norm, 'kind' => 'target', 'parentuuid' => $function, 'name' => 'Norm',
                'payload' => json_encode(['indicator' => 'S1', 'periodstart' => $period, 'amount' => '10', 'lower' => null, 'upper' => null])]],
            'references' => ['stat' => ['uuid' => 'S1', 'name' => 'Count', 'unit' => 'count', 'periodtype' => 'monthly', 'direction' => 'up']]];
        $DB->insert_record('local_kbacompany_wfapprove', (object)['companyuuid' => $company,
            'uuid' => $approval, 'objectuuid' => $function, 'revision' => 1, 'fingerprint' => hash('sha256', json_encode($snapshot)),
            'snapshot' => json_encode($snapshot), 'approvedby' => $actor, 'timecreated' => $now, 'revoked' => 0]);
        $DB->insert_record('local_kbacompany_wfwork', (object)['companyuuid' => $company, 'uuid' => $task,
            'kind' => 'task', 'parentuuid' => '', 'functionuuid' => $function, 'approvaluuid' => $approval,
            'normuuid' => $norm, 'assignmentuuid' => '', 'userid' => 0, 'reviewerid' => 0,
            'name' => 'Previously accepted correction', 'description' => 'Correction', 'acceptance' => 'Reviewed',
            'deadline' => $date, 'status' => 'accepted', 'revision' => 5, 'attempt' => 1,
            'timecreated' => $now, 'timemodified' => $now]);
        $id = deviations::command($company, $actor, 'create', ['approval' => $approval, 'norm' => $norm,
            'actual' => '5', 'observedon' => $date, 'evidence' => 'Source observation']);
        deviations::command($company, $actor, 'analyze', ['object' => $id, 'revision' => 1, 'cause' => 'Reasoned hypothesis']);
        deviations::command($company, $actor, 'link', ['object' => $id, 'revision' => 2, 'task' => $task, 'reason' => 'Addresses the cause']);
        return compact('company', 'actor', 'date', 'norm', 'approval', 'task', 'id', 'snapshot');
    }

    public function test_accepted_task_does_not_close_deviation(): void {
        $f = $this->fixture();
        $context = deviations::context($f['company'], $f['actor'], $f['id']);
        $this->assertSame('correcting', $context['selected']->status);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('dv_checkrequired', 'local_kbacompany'));
        deviations::command($f['company'], $f['actor'], 'close', ['object' => $f['id'], 'revision' => 3, 'reason' => 'Task done']);
    }

    public function test_recheck_and_explicit_closure_do_not_create_statistics(): void {
        global $DB;
        $f = $this->fixture();
        $before = $DB->count_records('local_kbacompany_statval');
        deviations::command($f['company'], $f['actor'], 'check', ['object' => $f['id'], 'revision' => 3,
            'approval' => $f['approval'], 'norm' => $f['norm'], 'actual' => '10',
            'observedon' => $f['date'], 'evidence' => 'Repeated source observation']);
        $this->assertSame('verified', deviations::context($f['company'], $f['actor'], $f['id'])['selected']->status);
        deviations::command($f['company'], $f['actor'], 'close', ['object' => $f['id'], 'revision' => 4, 'reason' => 'Evidence reviewed']);
        $this->assertSame('closed', deviations::context($f['company'], $f['actor'], $f['id'])['selected']->status);
        $this->assertSame($before, $DB->count_records('local_kbacompany_statval'));
    }

    public function test_lowered_norm_cannot_be_used_as_improvement(): void {
        global $DB;
        $f = $this->fixture();
        $changed = $f['snapshot'];
        $payload = json_decode($changed['objects'][0]['payload'], true);
        $payload['amount'] = '4';
        $changed['objects'][0]['payload'] = json_encode($payload);
        $newapproval = '10000000-0000-4000-8000-000000000005';
        $DB->insert_record('local_kbacompany_wfapprove', (object)['companyuuid' => $f['company'],
            'uuid' => $newapproval, 'objectuuid' => $changed['object']['uuid'], 'revision' => 2,
            'fingerprint' => hash('sha256', json_encode($changed)), 'snapshot' => json_encode($changed),
            'approvedby' => $f['actor'], 'timecreated' => time(), 'revoked' => 0]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('dv_notcomparable', 'local_kbacompany'));
        deviations::command($f['company'], $f['actor'], 'check', ['object' => $f['id'], 'revision' => 3,
            'approval' => $newapproval, 'norm' => $f['norm'], 'actual' => '5',
            'observedon' => $f['date'], 'evidence' => 'Same value, easier norm']);
    }

    public function test_regular_user_cannot_read_deviations(): void {
        $f = $this->fixture();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        deviations::context($f['company'], (int)$user->id);
    }

    public function test_machine_observation_dates_preserve_single_digit_days(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['2026-11-01', '2026-11-09'] as $expected) {
            $instant = new \DateTimeImmutable($expected . ' 12:00:00', \core_date::get_user_timezone_object());
            $date = business_date_service::day($instant->getTimestamp());
            $this->assertSame($expected, $date);
            deviations::observation_date($date, '2026-11-01', 'monthly');
        }
        $this->assertDebuggingNotCalled();
    }

    public static function invalid_period_cases(): array {
        return ['unpadded observation' => ['2026-11-9', '2026-11-01'],
            'invalid calendar date' => ['2026-02-30', '2026-02-01'],
            'monthly start must be first' => ['2026-11-09', '2026-11-02']];
    }

    /** @dataProvider invalid_period_cases */
    public function test_invalid_observation_period_is_still_rejected(string $date, string $start): void {
        try {
            deviations::observation_date($date, $start, 'monthly');
            $this->fail('Invalid machine dates and period starts must be rejected.');
        } catch (\moodle_exception $error) {
            $this->assertSame('productinvalidperiod', $error->errorcode);
        }
    }

    public function test_recheck_before_task_acceptance_preserves_deviation_and_check_history(): void {
        global $DB;
        $f = $this->fixture();
        // Observation is within its norm, but precedes the corrective task acceptance.
        $accepted = (new \DateTimeImmutable($f['date'] . ' 12:00:00',
            \core_date::get_user_timezone_object()))->modify('+1 day')->getTimestamp();
        $DB->set_field('local_kbacompany_wfwork', 'timemodified', $accepted,
            ['companyuuid' => $f['company'], 'uuid' => $f['task']]);
        $scope = ['companyuuid' => $f['company'], 'uuid' => $f['id']];
        $before = $DB->get_record('local_kbacompany_deviation', $scope, '*', MUST_EXIST);
        try {
            deviations::command($f['company'], $f['actor'], 'check', ['object' => $f['id'], 'revision' => 3,
                'approval' => $f['approval'], 'norm' => $f['norm'], 'actual' => '10',
                'observedon' => $f['date'], 'evidence' => 'Too early to prove the correction']);
            $this->fail('Observation before task acceptance must be rejected.');
        } catch (\moodle_exception $error) {
            $this->assertSame('dv_chronology', $error->errorcode);
        }
        $this->assertEquals($before, $DB->get_record('local_kbacompany_deviation', $scope, '*', MUST_EXIST));
        $this->assertSame(0, $DB->count_records('local_kbacompany_devcheck',
            ['companyuuid' => $f['company'], 'deviationuuid' => $f['id']]));
    }
}
