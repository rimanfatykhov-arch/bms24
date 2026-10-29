<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\workflow_service as workflow;
use local_kbacompany\local\cascade_service as cascade;
use local_kbacompany\local\product_service as products;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacorporate\local\repository;

/** Requires Moodle PHPUnit and local_kbacorporate. Not a standalone PHP test. */
final class workflow_service_test extends \advanced_testcase {
    public function test_design_versions_and_forks_preserve_live_board(): void {
        global $DB;
        $f = $this->fixture();
        $service = \local_kbacompany\local\design_service::class;
        $before = $DB->get_records('local_kbacompany_orgnode');
        $id = $service::command($f['company'], $f['admin'], 'create', ['source' => 'directors', 'name' => 'Design']);
        $v1 = $service::version($f['company'], $f['admin'], $id, 1);
        $service::command($f['company'], $f['admin'], 'save', ['object' => $id, 'revision' => 1,
            'name' => 'New design', 'mission' => 'Mission', 'intent' => 'Intent']);
        $this->assertSame($v1->payload, $service::version($f['company'], $f['admin'], $id, 1)->payload);
        $fork = $service::command($f['company'], $f['admin'], 'create',
            ['source' => 'version', 'sourceuuid' => $id, 'sourcerevision' => 1, 'name' => 'Fork']);
        $a = json_decode($v1->payload, true);
        $b = json_decode($service::version($f['company'], $f['admin'], $fork, 1)->payload, true);
        $this->assertCount(11, $a['nodes']);
        $this->assertNotSame($a['nodes'][0]['uuid'], $b['nodes'][0]['uuid']);
        $this->assertSame($b['nodes'][0]['uuid'], $b['nodes'][1]['layout_parent']);
        $this->assertSame('', $b['nodes'][1]['reporting_parent']);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_orgnode'));
    }

    public function test_design_rejects_stale_version(): void {
        $f = $this->fixture();
        $service = \local_kbacompany\local\design_service::class;
        $id = $service::command($f['company'], $f['admin'], 'create', ['source' => 'seven', 'name' => 'Design']);
        $this->expectException(\moodle_exception::class);
        $service::command($f['company'], $f['admin'], 'save', ['object' => $id, 'revision' => 0, 'name' => 'Stale']);
    }

    public function test_design_rejects_employee(): void {
        $f = $this->fixture();
        $this->expectException(\moodle_exception::class);
        \local_kbacompany\local\design_service::context($f['company'], (int)$f['executor']->id);
    }

    public function test_design_visual_editor_versions_nodes_and_rejects_cycles(): void {
        global $DB;
        $f = $this->fixture();
        $service = \local_kbacompany\local\design_service::class;
        $before = $DB->get_records('local_kbacompany_orgnode');
        $id = $service::command($f['company'], $f['admin'], 'create',
            ['source' => 'directors', 'name' => 'Visual design']);
        $v1 = json_decode($service::version($f['company'], $f['admin'], $id, 1)->payload, true);
        $nodes = array_column($v1['nodes'], null, 'code');
        $service::command($f['company'], $f['admin'], 'node', ['object' => $id, 'revision' => 1,
            'node' => $nodes['1.0.0']['uuid'], 'code' => '1.0.0', 'name' => 'People division',
            'type' => 'division', 'layout_parent' => $nodes['HR']['uuid'],
            'reporting_parent' => $nodes['EXEC']['uuid'], 'sortorder' => 20, 'color' => '#123456']);
        $v2 = json_decode($service::version($f['company'], $f['admin'], $id, 2)->payload, true);
        $edited = array_column($v2['nodes'], null, 'code')['1.0.0'];
        $this->assertSame('People division', $edited['name']);
        $this->assertSame($nodes['HR']['uuid'], $edited['layout_parent']);
        $this->assertSame($nodes['EXEC']['uuid'], $edited['reporting_parent']);
        $service::command($f['company'], $f['admin'], 'insert', ['object' => $id, 'revision' => 2,
            'code' => '1.1.0', 'name' => 'Recruitment department', 'type' => 'department',
            'layout_parent' => $nodes['1.0.0']['uuid'], 'reporting_parent' => $nodes['HR']['uuid'],
            'sortorder' => 10, 'color' => '#00843D']);
        $this->assertCount(12, json_decode($service::version($f['company'], $f['admin'], $id, 3)->payload, true)['nodes']);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_orgnode'));
        $this->expectException(\moodle_exception::class);
        $service::command($f['company'], $f['admin'], 'node', ['object' => $id, 'revision' => 3,
            'node' => $nodes['7.0.0']['uuid'], 'code' => '7.0.0', 'name' => 'Administration',
            'type' => 'division', 'layout_parent' => $nodes['1.0.0']['uuid'],
            'reporting_parent' => '', 'sortorder' => 10, 'color' => '#00843D']);
    }

    public function test_design_functions_staffing_and_custom_template_remain_isolated(): void {
        global $DB;
        $f = $this->fixture();
        $service = \local_kbacompany\local\design_service::class;
        $before = $DB->get_records('local_kbacompany_orgnode');
        $id = $service::command($f['company'], $f['admin'], 'create',
            ['source' => 'directors', 'name' => 'Operating model']);
        $payload = json_decode($service::version($f['company'], $f['admin'], $id, 1)->payload, true);
        $node = array_column($payload['nodes'], null, 'code')['1.0.0'];
        $service::command($f['company'], $f['admin'], 'position', ['object' => $id, 'revision' => 1,
            'targetnode' => $node['uuid'], 'code' => 'P-HR', 'name' => 'HR director',
            'plannedfte' => '1.00', 'managerial' => 1]);
        $v2 = json_decode($service::version($f['company'], $f['admin'], $id, 2)->payload, true);
        $position = $v2['positions'][0];
        $service::command($f['company'], $f['admin'], 'function', ['object' => $id, 'revision' => 2,
            'targetnode' => $node['uuid'], 'code' => 'F-HIRE', 'name' => 'Recruit people',
            'result' => 'Productive employee hired on time', 'position' => $position['uuid']]);
        $context = $service::context($f['company'], $f['admin'], $id);
        $nodeissues = array_filter($context['issues'], static function(array $issue): bool {
            return strpos($issue['label'], '1.0.0') === 0;
        });
        $this->assertSame([], array_values($nodeissues));
        $service::command($f['company'], $f['admin'], 'maketemplate', ['object' => $id,
            'revision' => 3, 'reason' => 'Approved company pattern']);
        $copy = $service::command($f['company'], $f['admin'], 'create',
            ['source' => 'custom', 'sourceuuid' => $id, 'name' => 'Template copy']);
        $copypayload = json_decode($service::version($f['company'], $f['admin'], $copy, 1)->payload, true);
        $this->assertCount(1, $copypayload['positions']);
        $this->assertCount(1, $copypayload['functions']);
        $this->assertNotSame($position['uuid'], $copypayload['positions'][0]['uuid']);
        $this->assertSame($copypayload['positions'][0]['uuid'], $copypayload['functions'][0]['positionuuid']);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_orgnode'));
    }

    public function test_design_compare_review_approve_and_additive_apply(): void {
        global $DB;
        $f = $this->fixture();
        $service = \local_kbacompany\local\design_service::class;
        $beforepositions = $DB->get_records('local_kbacompany_position');
        $beforenodecount = $DB->count_records('local_kbacompany_orgnode', ['companyuuid' => $f['company']]);
        $id = $service::command($f['company'], $f['admin'], 'create', ['source' => 'live', 'name' => 'Approved model']);
        $payload = json_decode($service::version($f['company'], $f['admin'], $id, 1)->payload, true);
        $first = $payload['nodes'][0];
        $service::command($f['company'], $f['admin'], 'node', ['object' => $id, 'revision' => 1,
            'node' => $first['uuid'], 'code' => $first['code'], 'name' => 'Applied administration',
            'type' => $first['type'], 'layout_parent' => $first['layout_parent'],
            'reporting_parent' => $first['reporting_parent'], 'sortorder' => $first['sortorder'],
            'color' => $first['color']]);
        $comparison = $service::compare_versions($f['company'], $f['admin'], $id, 1, 2);
        $this->assertSame('changed', $comparison['nodes'][0]['status']);
        $this->assertSame($first['code'], $comparison['nodes'][0]['code']);
        $revision = 2;
        foreach ($payload['nodes'] as $index => $node) {
            $positioncode = 'P-' . ($index + 1);
            $service::command($f['company'], $f['admin'], 'position', ['object' => $id, 'revision' => $revision,
                'targetnode' => $node['uuid'], 'code' => $positioncode, 'name' => 'Manager ' . ($index + 1),
                'plannedfte' => '1', 'managerial' => 1]);
            $revision++;
            $current = json_decode($service::version($f['company'], $f['admin'], $id, $revision)->payload, true);
            $position = array_column($current['positions'], null, 'code')[$positioncode];
            $service::command($f['company'], $f['admin'], 'function', ['object' => $id, 'revision' => $revision,
                'targetnode' => $node['uuid'], 'code' => 'F-' . ($index + 1), 'name' => 'Function ' . ($index + 1),
                'result' => 'Accepted result ' . ($index + 1), 'position' => $position['uuid']]);
            $revision++;
        }
        $this->assertSame([], $service::context($f['company'], $f['admin'], $id)['issues']);
        $service::command($f['company'], $f['admin'], 'submit', ['object' => $id,
            'revision' => $revision, 'reason' => 'Ready for review']);
        $revision++;
        $service::command($f['company'], $f['admin'], 'return', ['object' => $id,
            'revision' => $revision, 'reason' => 'Check structure']);
        $revision++;
        $service::command($f['company'], $f['admin'], 'submit', ['object' => $id,
            'revision' => $revision, 'reason' => 'Checked']);
        $revision++;
        $service::command($f['company'], $f['admin'], 'approve', ['object' => $id,
            'revision' => $revision, 'reason' => 'Approved']);
        $revision++;
        $branch = $DB->get_record('local_kbacompany_orgbranch', ['companyuuid' => $f['company'], 'isdefault' => 1]);
        $service::command($f['company'], $f['admin'], 'apply', ['object' => $id,
            'revision' => $revision, 'branch' => $branch->uuid, 'reason' => 'Put into operation']);
        $this->assertSame('applied', $DB->get_field('local_kbacompany_design', 'status', ['uuid' => $id]));
        $this->assertSame('Applied administration', $DB->get_field('local_kbacompany_orgnode', 'name',
            ['companyuuid' => $f['company'], 'uuid' => $first['sourceuuid']]));
        $this->assertSame($beforenodecount, $DB->count_records('local_kbacompany_orgnode', ['companyuuid' => $f['company']]));
        $this->assertEquals($beforepositions, $DB->get_records('local_kbacompany_position'));
        $this->assertCount(count($payload['nodes']), $DB->get_records('local_kbacompany_orgreport',
            ['companyuuid' => $f['company'], 'designuuid' => $id]));
    }

    private function flow_fixture(): array {
        $f = $this->fixture();
        $receiver = cascade::save($f['company'], $f['admin'], 'function',
            ['code' => 'RECEIVER', 'name' => 'Receiving function', 'node' => $f['node']]);
        cascade::save($f['company'], $f['admin'], 'result', ['code' => 'RR', 'name' => 'Recipient result',
            'parentuuid' => $receiver, 'acceptance' => 'Ready', 'product' => $f['p']]);
        cascade::save($f['company'], $f['admin'], 'function', ['code' => 'RECEIVER',
            'name' => 'Receiving function', 'node' => $f['node'], 'mainstat' => $f['s']], $receiver, 1);
        cascade::contribute($f['company'], $f['admin'], $f['strategy'], $receiver);
        $this->approve($f['company'], $receiver, $f['admin']);
        $f['flowdata'] = ['code' => 'FLOW', 'name' => 'Handoff', 'fromuuid' => $f['function'],
            'touuid' => $receiver, 'productuuid' => $f['p'], 'criteria' => 'Signature and complete report',
            'handoff' => 'Weekly report in shared workspace'];
        return $f;
    }

    public function test_flow_publication_staleness_and_archive_do_not_execute_work(): void {
        global $DB;
        $f = $this->flow_fixture();
        $flow = \local_kbacompany\local\flow_service::class;
        $before = $DB->get_records('local_kbacompany_wfwork');
        $id = $flow::command($f['company'], $f['admin'], 'save', $f['flowdata']);
        $this->assertSame([], $flow::context($f['company'], (int)$f['executor']->id)['flows']);
        $flow::command($f['company'], $f['admin'], 'publish', ['object' => $id, 'revision' => 1]);
        $r = $flow::context($f['company'], (int)$f['executor']->id, $id)['selected'];
        $this->assertTrue($r->basiscurrent);
        $snapshot = json_decode($r->snapshot, true);
        workflow::command($f['company'], $f['admin'], 'revoke',
            ['object' => $snapshot['fromuuid']['approval'], 'reason' => 'Review basis']);
        $this->assertFalse($flow::context($f['company'], $f['admin'], $id)['selected']->basiscurrent);
        $flow::command($f['company'], $f['admin'], 'archive', ['object' => $id, 'revision' => 2, 'reason' => 'Replace definition']);
        $this->assertSame([], $flow::context($f['company'], (int)$f['executor']->id)['flows']);
        $this->assertEquals($before, $DB->get_records('local_kbacompany_wfwork'));
    }

    public function test_flow_rejects_self_handoff(): void {
        $f = $this->flow_fixture();
        $f['flowdata']['touuid'] = $f['function'];
        $this->expectException(\moodle_exception::class);
        \local_kbacompany\local\flow_service::command($f['company'], $f['admin'], 'save', $f['flowdata']);
    }

    public function test_flow_rejects_foreign_reference(): void {
        $f = $this->flow_fixture();
        $f['flowdata']['productuuid'] = '00000000-0000-4000-8000-000000000000';
        $this->expectException(\moodle_exception::class);
        \local_kbacompany\local\flow_service::command($f['company'], $f['admin'], 'save', $f['flowdata']);
    }

    public function test_flow_employee_cannot_write(): void {
        $f = $this->flow_fixture();
        $this->expectException(\moodle_exception::class);
        \local_kbacompany\local\flow_service::command($f['company'], (int)$f['executor']->id, 'save', $f['flowdata']);
    }

    public function test_calendar_clamps_short_month_and_handles_week_year_boundary(): void {
        $this->assertSame('2027-02-28', workflow::cycle_deadline('2026-12-31', '2026-12-01', '2027-02-01', 'monthly'));
        $this->assertSame('2028-02-29', workflow::cycle_deadline('2026-12-31', '2026-12-01', '2028-02-01', 'monthly'));
        $this->assertSame('2026-12-28', workflow::cycle_start('2027-01-03', 'weekly'));
        $this->assertSame('2027-01-10', workflow::cycle_deadline('2027-01-03', '2026-12-28', '2027-01-04', 'weekly'));
    }

    public function test_repeat_is_draft_idempotent_and_checkpoint_does_not_close_plan(): void {
        global $DB;
        $f = $this->fixture();
        workflow::command($f['company'], $f['admin'], 'activate', ['object' => $f['plan'], 'revision' => 1]);
        $input = ['object' => $f['plan'], 'period' => 'monthly', 'periodstart' => '2027-02-01'];
        $p = workflow::command($f['company'], $f['admin'], 'repeat', $input);
        $this->assertSame($p, workflow::command($f['company'], $f['admin'], 'repeat', $input));
        $this->assertSame(4, $DB->count_records('local_kbacompany_wfwork', ['companyuuid' => $f['company']]));
        $plan = $DB->get_record('local_kbacompany_wfwork', ['uuid' => $p]);
        $this->assertSame('draft', $plan->status);
        $this->assertSame('2027-02-28', $plan->deadline);
        $task = $DB->get_record('local_kbacompany_wfwork', ['parentuuid' => $p]);
        $this->assertSame('draft', $task->status);
        $this->assertSame('2027-02-01', $task->deadline);
        $this->assertEquals(0, $task->attempt);
        $this->assertSame(0, $DB->count_records('local_kbacompany_wfevidence', ['taskuuid' => $task->uuid]));
        $cycle = $DB->get_record('local_kbacompany_wfrepeat', ['planuuid' => $p]);
        workflow::command($f['company'], $f['admin'], 'checkpoint', ['object' => $cycle->uuid, 'reason' => 'Review draft next week']);
        $this->assertSame('draft', $DB->get_field('local_kbacompany_wfwork', 'status', ['uuid' => $p]));
        $this->assertGreaterThan(0, $DB->get_field('local_kbacompany_wfrepeat', 'checkedat', ['uuid' => $cycle->uuid]));
        workflow::command($f['company'], $f['admin'], 'activate', ['object' => $p, 'revision' => 1]);
        $march = workflow::command($f['company'], $f['admin'], 'repeat',
            ['object' => $p, 'period' => 'monthly', 'periodstart' => '2027-03-01']);
        $this->assertSame('2027-03-31', $DB->get_field('local_kbacompany_wfwork', 'deadline', ['uuid' => $march]));
    }

    public function test_repeat_rejects_employee_and_hides_cycles(): void {
        $f = $this->fixture();
        $this->setUser($f['executor']);
        $this->assertSame([], workflow::context($f['company'], (int)$f['executor']->id)['cycles']);
        $this->expectException(\moodle_exception::class);
        workflow::command($f['company'], (int)$f['executor']->id, 'repeat',
            ['object' => $f['plan'], 'period' => 'monthly', 'periodstart' => '2027-01-01']);
    }

    public function test_repeat_rejects_tasks_spanning_multiple_source_periods(): void {
        $f = $this->fixture();
        workflow::command($f['company'], $f['admin'], 'activate', ['object' => $f['plan'], 'revision' => 1]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('wf_cycleperiod', 'local_kbacompany'));
        workflow::command($f['company'], $f['admin'], 'repeat',
            ['object' => $f['plan'], 'period' => 'weekly', 'periodstart' => '2027-01-04']);
    }

    private function approve(string $company, string $object, int $actor): void {
        $preview = workflow::preview($company, $object, $actor);
        workflow::command($company, $actor, 'approve', ['object' => $object,
            'revision' => $preview['bundle']['object']->revision, 'fingerprint' => $preview['fingerprint']]);
    }

    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Workflow test', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $executor = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$executor, $reviewer, $outsider] as $user) {
            $DB->insert_record('local_kbacorp_member', (object)['companyid' => $companyid,
                'userid' => $user->id, 'membertype' => 'employee', 'isemployee' => 1,
                'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        }
        $board = organizing_board_service::install_seven_division_template($company, $admin);
        $node = $board['nodes'][0]['uuid'];
        $p = products::create_product($company, $admin, ['code' => 'P', 'name' => 'Product',
            'acceptance' => 'Accepted', 'targettype' => 'node', 'targetuuid' => $node]);
        $s = products::create_statistic($company, $admin, ['productuuid' => $p, 'code' => 'S',
            'name' => 'Count', 'unit' => 'count', 'periodtype' => 'monthly', 'direction' => 'up']);
        $i = cascade::save($company, $admin, 'intent', ['code' => 'I', 'name' => 'Intent', 'purpose' => 'Value']);
        $g = cascade::save($company, $admin, 'goal', ['code' => 'G', 'name' => 'Goal',
            'parentuuid' => $i, 'current' => 'A', 'desired' => 'B']);
        $strategydata = ['code' => 'SP', 'name' => 'Strategy', 'parentuuid' => $g,
            'idea' => 'Idea', 'problem' => 'Problem', 'solution' => 'Solution', 'expected' => 'Result'];
        $strategy = cascade::save($company, $admin, 'strategy', $strategydata);
        $functiondata = ['code' => 'F', 'name' => 'Function', 'node' => $node];
        $function = cascade::save($company, $admin, 'function', $functiondata);
        cascade::save($company, $admin, 'result', ['code' => 'R', 'name' => 'Result',
            'parentuuid' => $function, 'acceptance' => 'Ready', 'product' => $p]);
        cascade::save($company, $admin, 'function', $functiondata + ['mainstat' => $s], $function, 1);
        cascade::contribute($company, $admin, $strategy, $function);
        $this->approve($company, $strategy, $admin);
        $this->approve($company, $function, $admin);
        workflow::command($company, $admin, 'assign', ['object' => $function, 'userid' => $executor->id]);
        $plan = workflow::command($company, $admin, 'plan', ['strategy' => $strategy, 'name' => 'Plan',
            'description' => 'Work', 'acceptance' => 'All results accepted', 'deadline' => '2026-12-31']);
        $taskdata = ['plan' => $plan, 'function' => $function, 'reviewerid' => $reviewer->id,
            'name' => 'Task', 'description' => 'Deliver', 'acceptance' => 'Signed result', 'deadline' => '2026-12-01'];
        $task = workflow::command($company, $admin, 'task', $taskdata);
        return compact('company', 'admin', 'executor', 'reviewer', 'outsider', 'plan', 'task', 'taskdata', 'strategy', 'strategydata', 'function', 'node', 'p', 's');
    }

    public function test_submit_return_resubmit_accept_and_close_preserve_measurements(): void {
        global $DB;
        $f = $this->fixture();
        $before = $DB->get_records('local_kbacompany_statval');
        workflow::command($f['company'], $f['admin'], 'activate', ['object' => $f['plan'], 'revision' => 1]);
        $this->setUser($f['executor']);
        workflow::command($f['company'], (int)$f['executor']->id, 'submit',
            ['object' => $f['task'], 'revision' => 2, 'body' => 'First result']);
        $this->setUser($f['reviewer']);
        workflow::command($f['company'], (int)$f['reviewer']->id, 'return',
            ['object' => $f['task'], 'revision' => 3, 'reason' => 'Missing signature']);
        $this->setUser($f['executor']);
        workflow::command($f['company'], (int)$f['executor']->id, 'submit',
            ['object' => $f['task'], 'revision' => 4, 'body' => 'Signed result', 'url' => 'https://example.org/result']);
        $this->setUser($f['reviewer']);
        workflow::command($f['company'], (int)$f['reviewer']->id, 'accept',
            ['object' => $f['task'], 'revision' => 5, 'reason' => 'Signature checked']);
        $this->setAdminUser();
        workflow::command($f['company'], $f['admin'], 'close', ['object' => $f['plan'], 'revision' => 2]);
        $this->assertSame('accepted', $DB->get_field('local_kbacompany_wfwork', 'status', ['uuid' => $f['task']]));
        $this->assertSame('closed', $DB->get_field('local_kbacompany_wfwork', 'status', ['uuid' => $f['plan']]));
        $this->assertSame(2, $DB->count_records('local_kbacompany_wfevidence', ['taskuuid' => $f['task']]));
        $this->assertEquals($before, $DB->get_records('local_kbacompany_statval'));
    }

    public function test_unrelated_employee_cannot_see_task_or_evidence(): void {
        $f = $this->fixture();
        $this->setUser($f['outsider']);
        $context = workflow::context($f['company'], (int)$f['outsider']->id);
        $this->assertCount(1, $context['work']);
        $this->assertSame('plan', $context['work'][0]->kind);
        $this->expectException(\moodle_exception::class);
        workflow::context($f['company'], (int)$f['outsider']->id, $f['task']);
    }

    public function test_executor_cannot_be_reviewer(): void {
        $f = $this->fixture();
        $data = $f['taskdata'];
        $data['reviewerid'] = $f['executor']->id;
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('wf_selfreview', 'local_kbacompany'));
        workflow::command($f['company'], $f['admin'], 'task', $data);
    }

    public function test_modified_strategy_requires_new_approval(): void {
        $f = $this->fixture();
        $changed = $f['strategydata'];
        $changed['name'] = 'Changed strategy';
        cascade::save($f['company'], $f['admin'], 'strategy', $changed, $f['strategy'], 1);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('wf_approvalrequired', 'local_kbacompany'));
        workflow::command($f['company'], $f['admin'], 'activate', ['object' => $f['plan'], 'revision' => 1]);
    }

    public function test_employee_cannot_activate_plan(): void {
        $f = $this->fixture();
        $this->setUser($f['executor']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('wf_permission', 'local_kbacompany'));
        workflow::command($f['company'], (int)$f['executor']->id, 'activate', ['object' => $f['plan'], 'revision' => 1]);
    }
}
