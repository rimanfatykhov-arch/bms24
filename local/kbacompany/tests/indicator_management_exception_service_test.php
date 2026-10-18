<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\indicator_management_exception_service;

/** Pure rule tests for v0.5.5 management exceptions. */
final class indicator_management_exception_service_test extends \advanced_testcase {
    public function test_danger_and_overdue_task_are_critical_without_hidden_thresholds(): void {
        $reasons = indicator_management_exception_service::build_reasons([
            'critical' => true, 'has_fact' => true, 'has_norm' => true, 'has_policy' => true,
            'condition' => 'danger', 'trend_quality' => 'worsening', 'direction' => 'up',
            'outside_norm' => true, 'event_types' => ['reversal_down'], 'confirmed' => true,
            'task_count' => 1, 'overdue_task_count' => 1,
        ]);
        $this->assertSame('critical', indicator_management_exception_service::highest_severity($reasons));
        $codes = array_column($reasons, 'code');
        $this->assertContains('condition_danger', $codes);
        $this->assertContains('overdue_task', $codes);
        $this->assertContains('outside_norm', $codes);
    }

    public function test_confirmed_attention_condition_without_task_is_high(): void {
        $reasons = indicator_management_exception_service::build_reasons([
            'critical' => false, 'has_fact' => true, 'has_norm' => true, 'has_policy' => true,
            'condition' => 'emergency', 'trend_quality' => 'worsening', 'direction' => 'up',
            'outside_norm' => false, 'event_types' => [], 'confirmed' => true,
            'task_count' => 0, 'overdue_task_count' => 0,
        ]);
        $codes = array_column($reasons, 'code');
        $this->assertContains('confirmed_without_task', $codes);
        $this->assertSame('high', indicator_management_exception_service::highest_severity($reasons));
    }

    public function test_critical_configuration_gaps_are_explicit(): void {
        $reasons = indicator_management_exception_service::build_reasons([
            'critical' => true, 'has_fact' => false, 'has_norm' => false, 'has_policy' => false,
            'condition' => '', 'trend_quality' => 'insufficient', 'direction' => 'up',
            'outside_norm' => false, 'event_types' => [], 'confirmed' => false,
            'task_count' => 0, 'overdue_task_count' => 0,
        ]);
        $codes = array_column($reasons, 'code');
        $this->assertContains('critical_no_data', $codes);
        $this->assertContains('critical_no_norm', $codes);
        $this->assertSame('critical', indicator_management_exception_service::highest_severity($reasons));
    }

    public function test_adverse_events_are_direction_aware(): void {
        $this->assertTrue(indicator_management_exception_service::adverse_event('new_min', 'up'));
        $this->assertTrue(indicator_management_exception_service::adverse_event('new_max', 'down'));
        $this->assertFalse(indicator_management_exception_service::adverse_event('new_max', 'up'));
        $this->assertFalse(indicator_management_exception_service::adverse_event('reversal_down', 'down'));
    }

    public function test_worsening_followup_requires_explicit_new_decision_reason(): void {
        $reasons = indicator_management_exception_service::build_reasons([
            'critical' => false, 'has_fact' => true, 'has_norm' => true, 'has_policy' => true,
            'condition' => 'normal', 'trend_quality' => 'neutral', 'direction' => 'up',
            'outside_norm' => false, 'event_types' => [], 'confirmed' => true,
            'task_count' => 1, 'overdue_task_count' => 0,
            'followup_quality' => 'worsening', 'requires_new_decision' => true,
        ]);
        $codes = array_column($reasons, 'code');
        $this->assertContains('action_followup_worsened', $codes);
        $this->assertSame('high', indicator_management_exception_service::highest_severity($reasons));
    }

    public function test_improving_followup_does_not_create_an_exception_by_itself(): void {
        $reasons = indicator_management_exception_service::build_reasons([
            'critical' => false, 'has_fact' => true, 'has_norm' => true, 'has_policy' => true,
            'condition' => 'normal', 'trend_quality' => 'neutral', 'direction' => 'up',
            'outside_norm' => false, 'event_types' => [], 'confirmed' => true,
            'task_count' => 1, 'overdue_task_count' => 0,
            'followup_quality' => 'improving', 'requires_new_decision' => false,
        ]);
        $this->assertSame([], $reasons);
    }
}
