<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_week_readiness_service;

/** Regression source for schema-neutral management-week readiness. */
final class battle_plan_week_readiness_service_test extends \advanced_testcase {
    public function test_contract_uses_existing_lifecycle_without_status_registry(): void {
        $contract = battle_plan_week_readiness_service::contract();
        $this->assertSame('0.5.13', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertContains('readiness_is_read_model_not_status_registry', $contract['rules']);
        $this->assertContains('hard_blockers_mirror_existing_battle_plan_transition_contract', $contract['rules']);
        $this->assertContains('critical_indicator_and_norm_gaps_are_advisory_not_transition_blockers', $contract['rules']);
    }

    public function test_classification_mirrors_existing_transition_requirements(): void {
        $blocked = battle_plan_week_readiness_service::classify('draft', 10, 0, false, 0);
        $this->assertSame('blocked', $blocked['readiness']);
        $this->assertContains('missing_reviewer', $blocked['blockers']);
        $this->assertContains('no_tasks', $blocked['blockers']);

        $submit = battle_plan_week_readiness_service::classify('draft', 10, 20, true, 2);
        $this->assertSame('ready_submit', $submit['readiness']);
        $this->assertSame('submit', $submit['action']);

        $self = battle_plan_week_readiness_service::classify('draft', 10, 10, true, 1);
        $this->assertSame('ready_selfapprove', $self['readiness']);
        $this->assertSame('selfapprove', $self['action']);

        $activate = battle_plan_week_readiness_service::classify('submitted', 10, 20, true, 1);
        $this->assertSame('ready_activate', $activate['readiness']);
        $this->assertSame('approve', $activate['action']);
    }
}
