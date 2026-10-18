<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\deviation_service as deviations;
use local_kbacompany\local\identity_registry;
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
        $date = userdate(time(), '%Y-%m-%d');
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
            'snapshot' => json_encode($snapshot), 'approvedby' => $actor, 'timecreated' => time(), 'revoked' => 0]);
        $DB->insert_record('local_kbacompany_wfwork', (object)['companyuuid' => $company, 'uuid' => $task,
            'kind' => 'task', 'parentuuid' => '', 'functionuuid' => $function, 'approvaluuid' => $approval,
            'normuuid' => $norm, 'assignmentuuid' => '', 'userid' => 0, 'reviewerid' => 0,
            'name' => 'Previously accepted correction', 'description' => 'Correction', 'acceptance' => 'Reviewed',
            'deadline' => $date, 'status' => 'accepted', 'revision' => 5, 'attempt' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
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
}
