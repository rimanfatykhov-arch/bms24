<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_template_service;

/** Pure condition formula tests for Battle Plan quotas. */
final class battle_plan_template_service_test extends \advanced_testcase {
    public function test_condition_formula_for_up_metric(): void {
        $thresholds = ['danger_below'=>70, 'emergency_below'=>90, 'normal_high'=>110, 'affluence_high'=>130, 'danger_trend'=>-20];
        $this->assertSame('nonexistence', battle_plan_template_service::automatic_condition(null, 0.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('danger', battle_plan_template_service::automatic_condition(100.0, 60.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('emergency', battle_plan_template_service::automatic_condition(80.0, 80.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('normal', battle_plan_template_service::automatic_condition(100.0, 100.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('affluence', battle_plan_template_service::automatic_condition(100.0, 120.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('affluence', battle_plan_template_service::automatic_condition(100.0, 140.0, 100.0, 'up', false, $thresholds)['condition']);
        $this->assertSame('power', battle_plan_template_service::automatic_condition(140.0, 150.0, 100.0, 'up', false, $thresholds)['condition']);
    }

    public function test_condition_formula_for_lower_is_better_metric(): void {
        $thresholds = ['danger_below'=>70, 'emergency_below'=>90, 'normal_high'=>110, 'affluence_high'=>130, 'danger_trend'=>-20];
        $result = battle_plan_template_service::automatic_condition(10.0, 8.0, 10.0, 'down', false, $thresholds);
        $this->assertGreaterThan(100.0, $result['performance']);
        $this->assertGreaterThan(0.0, $result['trend']);
    }

    public function test_power_change_is_not_automatic(): void {
        $thresholds = ['danger_below'=>70, 'emergency_below'=>90, 'normal_high'=>110, 'affluence_high'=>130, 'danger_trend'=>-20];
        $result = battle_plan_template_service::automatic_condition(200.0, 200.0, 100.0, 'up', false, $thresholds);
        $this->assertSame('power', $result['condition']);
        $this->assertNotSame('powerchange', $result['condition']);
    }
}
