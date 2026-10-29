<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\administrative_scale_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\strategy_service;
use local_kbacorporate\local\repository;

/** Administrative Scale regression tests. */
final class administrative_scale_service_test extends \advanced_testcase {
    private function company(string $name): string {
        $id = repository::save_company((object)['name' => $name, 'active' => 1]);
        return identity_registry::company_uuid($id);
    }

    private function owner_requirement_set(string $company, int $actor): string {
        global $DB;
        $hex = md5($company . '|owner-requirements');
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
        $DB->insert_record('local_kbacompany_ownerframe', (object)[
            'companyuuid' => $company, 'uuid' => $uuid, 'revision' => 1, 'status' => 'published',
            'owneruserid' => $actor, 'title' => 'Approved owner requirements',
            'payload' => json_encode(['requirements' => [['id' => 'REQ-01', 'text' => 'Build an autonomous business']]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdby' => $actor, 'modifiedby' => $actor, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return $uuid;
    }

    private function strategic_goal(string $company, int $actor): string {
        $intent = strategy_service::save_ownership_intent($company, $actor, [
            'code' => 'OWN', 'name' => 'Owner intent', 'ownershippurpose' => 'Build durable value',
            'longtermgoal' => 'Operate at the target scale without owner operational dependency']);
        strategy_service::publish_ownership_intent($company, $actor, $intent, 1);
        $strategy = strategy_service::save_strategy($company, $actor, [
            'ownerintentuuid' => $intent, 'code' => 'STR', 'name' => 'Strategy', 'thesis' => 'Scale the system']);
        strategy_service::publish_strategy($company, $actor, $strategy, 1);
        $goal = strategy_service::save_goal($company, $actor, [
            'strategyuuid' => $strategy, 'code' => 'SG', 'name' => 'Strategic growth',
            'perspective' => 'financial', 'outcome' => 'Profitable growth']);
        strategy_service::publish_goal($company, $actor, $goal, 1);
        return $goal;
    }

    private function governance(string $company, int $actor, string $kind, string $code): string {
        global $DB;
        $hex = md5($company . '|' . $kind . '|' . $code);
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
        $DB->insert_record('local_kbacompany_policy', (object)[
            'companyuuid' => $company, 'uuid' => $uuid, 'revision' => 1, 'kind' => $kind,
            'code' => $code, 'title' => $code . ' title', 'purpose' => 'Purpose', 'scopetype' => 'company',
            'scopeuuid' => '', 'ownertype' => 'position', 'owneruuid' => 'test-owner',
            'effectivefrom' => '2026-01-01', 'effectiveto' => '', 'documenturl' => 'https://example.test/' . $code,
            'basis' => '', 'status' => 'published', 'reason' => 'Test source', 'actorid' => $actor,
            'timecreated' => time(),
        ]);
        return $uuid;
    }

    public function test_contract_declares_scale_and_result_rule(): void {
        $contract = administrative_scale_service::contract();
        $this->assertSame('0.5.1', $contract['version']);
        $this->assertContains('longtermgoal', $contract['kinds']);
        $this->assertContains('idealstate', $contract['kinds']);
        $this->assertContains('statistic', $contract['kinds']);
        $this->assertContains('ckp', $contract['kinds']);
        $this->assertContains('programstep', $contract['execution_support']);
        $this->assertContains('plan_records_situation_goal_and_plan_body', $contract['rules']);
        $this->assertContains('stable_code_is_server_generated_and_immutable', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_full_scale_publishes_in_dependency_order_and_has_trace(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Admin Scale');
        $actor = (int)$USER->id;
        $ownerreq = $this->owner_requirement_set($company, $actor);
        $strategicgoal = $this->strategic_goal($company, $actor);
        $policyref = $this->governance($company, $actor, 'policy', 'POLICY');
        $directiveref = $this->governance($company, $actor, 'directive', 'DIR');

        $longterm = administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'code' => 'LT1', 'name' => 'Autonomous business', 'sourceuuid' => $ownerreq,
            'expectedresult' => 'Durable autonomous company']);
        administrative_scale_service::publish($company, $actor, $longterm, 1);
        $goal = administrative_scale_service::save($company, $actor, [
            'kind' => 'goal', 'code' => 'G1', 'name' => 'Execution goal', 'sourceuuid' => $strategicgoal,
            'parentuuid' => $longterm, 'expectedresult' => 'Strategic target translated into executable management work']);
        administrative_scale_service::publish($company, $actor, $goal, 1);
        $policy = administrative_scale_service::save($company, $actor, [
            'kind' => 'policy', 'code' => 'P1', 'name' => 'Growth policy', 'parentuuid' => $goal,
            'sourceuuid' => $policyref, 'description' => 'Rules for the plan']);
        administrative_scale_service::publish($company, $actor, $policy, 1);
        $plan = administrative_scale_service::save($company, $actor, [
            'kind' => 'plan', 'code' => 'PL1', 'name' => 'Growth plan', 'parentuuid' => $policy,
            'situation' => 'The company has growth potential but no coordinated expansion system',
            'plangoal' => 'Create an approved executable growth plan',
            'description' => 'Use the available market and management potential to expand in a controlled way']);
        administrative_scale_service::publish($company, $actor, $plan, 1);
        $program = administrative_scale_service::save($company, $actor, [
            'kind' => 'program', 'code' => 'PRG1', 'name' => 'Expansion program', 'parentuuid' => $plan,
            'expectedresult' => 'Coordinated set of expansion projects']);
        administrative_scale_service::publish($company, $actor, $program, 1);
        $step = administrative_scale_service::save($company, $actor, [
            'kind' => 'programstep', 'code' => 'S1', 'name' => 'Prepare branch launch', 'parentuuid' => $program,
            'expectedresult' => 'Branch launch package prepared']);
        administrative_scale_service::publish($company, $actor, $step, 1);
        $project = administrative_scale_service::save($company, $actor, [
            'kind' => 'project', 'code' => 'PJ1', 'name' => 'Open branch', 'parentuuid' => $step,
            'expectedresult' => 'Operating branch']);
        administrative_scale_service::publish($company, $actor, $project, 1);
        $decision = administrative_scale_service::save($company, $actor, [
            'kind' => 'decision', 'code' => 'D1', 'name' => 'Launch order', 'parentuuid' => $project,
            'sourceuuid' => $directiveref, 'expectedresult' => 'Authorized launch']);
        administrative_scale_service::publish($company, $actor, $decision, 1);
        $task = administrative_scale_service::save($company, $actor, [
            'kind' => 'task', 'code' => 'T1', 'name' => 'Sign lease', 'parentuuid' => $decision,
            'expectedresult' => 'Signed lease agreement']);
        administrative_scale_service::publish($company, $actor, $task, 1);

        $context = administrative_scale_service::context($company, $actor);
        $this->assertCount(9, $context['items']);
        $plans = $context['by_kind']['plan'];
        $this->assertCount(1, $plans);
        $this->assertSame('The company has growth potential but no coordinated expansion system', $plans[0]['situation']);
        $this->assertSame('Create an approved executable growth plan', $plans[0]['plangoal']);
        $this->assertSame('Use the available market and management potential to expand in a controlled way', $plans[0]['description']);
        $tasks = $context['by_kind']['task'];
        $this->assertCount(1, $tasks);
        $this->assertSame(['LTG-0001', 'GOAL-0001', 'POL-0001', 'PLN-0001', 'PRG-0001', 'STEP-0001',
            'PRJ-0001', 'ORD-0001', 'TSK-0001'], array_column($tasks[0]['path'], 'code'));
        $this->assertFalse($tasks[0]['stale']);
    }

    public function test_code_is_generated_and_preserved_across_revisions(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Stable code');
        $actor = (int)$USER->id;
        $ownerreq = $this->owner_requirement_set($company, $actor);

        $uuid = administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'code' => 'MANUAL-SHOULD-BE-IGNORED', 'name' => 'Long-term goal',
            'sourceuuid' => $ownerreq, 'expectedresult' => 'Result']);
        $row = $DB->get_record('local_kbacompany_adminitem',
            ['companyuuid' => $company, 'uuid' => $uuid, 'revision' => 1], '*', MUST_EXIST);
        $this->assertSame('LTG-0001', $row->code);

        administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'code' => 'HACK-9999', 'name' => 'Renamed long-term goal',
            'sourceuuid' => $ownerreq, 'expectedresult' => 'Updated result'], $uuid, 1);
        $row2 = $DB->get_record('local_kbacompany_adminitem',
            ['companyuuid' => $company, 'uuid' => $uuid, 'revision' => 2], '*', MUST_EXIST);
        $this->assertSame('LTG-0001', $row2->code);

        administrative_scale_service::archive($company, $actor, $uuid, 2);
        $uuid2 = administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'name' => 'Replacement goal', 'sourceuuid' => $ownerreq,
            'expectedresult' => 'Replacement result']);
        $replacement = $DB->get_record('local_kbacompany_adminitem',
            ['companyuuid' => $company, 'uuid' => $uuid2, 'revision' => 1], '*', MUST_EXIST);
        $this->assertSame('LTG-0002', $replacement->code);
    }

    public function test_wrong_parent_kind_is_rejected(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Wrong parent');
        $actor = (int)$USER->id;
        $ownerreq = $this->owner_requirement_set($company, $actor);
        $strategicgoal = $this->strategic_goal($company, $actor);
        $longterm = administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'code' => 'LT', 'name' => 'Long-term goal', 'sourceuuid' => $ownerreq,
            'expectedresult' => 'Result']);
        administrative_scale_service::publish($company, $actor, $longterm, 1);
        $goal = administrative_scale_service::save($company, $actor, [
            'kind' => 'goal', 'code' => 'G', 'name' => 'Goal', 'sourceuuid' => $strategicgoal,
            'parentuuid' => $longterm, 'expectedresult' => 'Result']);
        administrative_scale_service::publish($company, $actor, $goal, 1);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('adminscale_parentref', 'local_kbacompany'));
        administrative_scale_service::save($company, $actor, [
            'kind' => 'project', 'code' => 'PJ', 'name' => 'Project', 'parentuuid' => $goal,
            'expectedresult' => 'Result']);
    }

    public function test_changed_parent_revision_blocks_child_publication(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = $this->company('Pinned parent');
        $actor = (int)$USER->id;
        $ownerreq = $this->owner_requirement_set($company, $actor);
        $strategicgoal = $this->strategic_goal($company, $actor);
        $policyref = $this->governance($company, $actor, 'policy', 'POL');
        $longterm = administrative_scale_service::save($company, $actor, [
            'kind' => 'longtermgoal', 'code' => 'LT', 'name' => 'Long-term goal', 'sourceuuid' => $ownerreq,
            'expectedresult' => 'Result']);
        administrative_scale_service::publish($company, $actor, $longterm, 1);
        $goal = administrative_scale_service::save($company, $actor, [
            'kind' => 'goal', 'code' => 'G', 'name' => 'Goal', 'sourceuuid' => $strategicgoal,
            'parentuuid' => $longterm, 'expectedresult' => 'Result']);
        administrative_scale_service::publish($company, $actor, $goal, 1);
        $policy = administrative_scale_service::save($company, $actor, [
            'kind' => 'policy', 'code' => 'P', 'name' => 'Policy', 'parentuuid' => $goal,
            'sourceuuid' => $policyref]);
        administrative_scale_service::publish($company, $actor, $policy, 1);
        $plan = administrative_scale_service::save($company, $actor, [
            'kind' => 'plan', 'code' => 'PL', 'name' => 'Plan', 'parentuuid' => $policy,
            'situation' => 'Current situation', 'plangoal' => 'Plan result', 'description' => 'Plan body']);

        // Publish a new Policy revision; the Plan draft remains pinned to the previous published revision.
        administrative_scale_service::save($company, $actor, [
            'kind' => 'policy', 'code' => 'P', 'name' => 'Policy revised', 'parentuuid' => $goal,
            'sourceuuid' => $policyref], $policy, 2);
        administrative_scale_service::publish($company, $actor, $policy, 3);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('adminscale_stale', 'local_kbacompany'));
        administrative_scale_service::publish($company, $actor, $plan, 1);
    }
}
