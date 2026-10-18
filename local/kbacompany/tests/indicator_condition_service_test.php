<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\indicator_condition_service;

/** Pure read-model tests for v0.5.3 management conditions. */
final class indicator_condition_service_test extends \advanced_testcase {
    private function series(array $facts): array {
        $out = [];
        $i = 1;
        foreach ($facts as $fact) {
            $out[] = ['period' => '2026-09-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'fact' => $fact, 'plan' => null];
            $i++;
        }
        return $out;
    }

    private function policy(array $extra = []): array {
        return array_merge([
            'binduuid' => 'b1', 'revision' => 1, 'status' => 'active', 'effectivefrom' => '2026-01-01',
            'effectiveto' => '', 'dangerdecline' => 20, 'dangerperiods' => 3,
            'affluencegrowth' => 15, 'powerlevel' => 150, 'powerperiods' => 3,
        ], $extra);
    }

    public function test_emergency_is_flat_or_declining(): void {
        $r = indicator_condition_service::analyze($this->series([100, 100]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('emergency', $r['condition']);
        $r = indicator_condition_service::analyze($this->series([100, 95]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('emergency', $r['condition']);
    }

    public function test_danger_uses_explicit_steepness_or_duration(): void {
        $r = indicator_condition_service::analyze($this->series([100, 70]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('danger', $r['condition']);
        $r = indicator_condition_service::analyze($this->series([100, 99, 98, 97]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('danger', $r['condition']);
        $this->assertSame('prolonged_emergency', $r['reason']);
    }

    public function test_normal_and_affluence_are_separated_by_explicit_policy(): void {
        $r = indicator_condition_service::analyze($this->series([100, 105, 110]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('normal', $r['condition']);
        $r = indicator_condition_service::analyze($this->series([100, 120]), 'up', [], [$this->policy()], 'b1');
        $this->assertSame('affluence', $r['condition']);
    }

    public function test_power_requires_high_level_held_against_target_norm(): void {
        $norms = [[
            'binduuid' => 'b1', 'revision' => 1, 'status' => 'active', 'normkind' => 'target',
            'targetvalue' => 100, 'minval' => null, 'maxval' => null, 'effectivefrom' => '2026-01-01', 'effectiveto' => '',
        ]];
        $r = indicator_condition_service::analyze($this->series([150, 155, 160]), 'up', $norms, [$this->policy()], 'b1');
        $this->assertSame('power', $r['condition']);
    }

    public function test_nonexistence_and_powerchange_are_not_inferred_from_statistic(): void {
        $r = indicator_condition_service::analyze($this->series([0, 0]), 'up', [], [$this->policy()], 'b1');
        $this->assertNotSame('nonexistence', $r['condition']);
        $this->assertNotSame('powerchange', $r['condition']);
    }

    public function test_missing_policy_does_not_invent_steepness_thresholds(): void {
        $r = indicator_condition_service::analyze($this->series([100, 130]), 'up', [], [], 'b1');
        $this->assertSame('', $r['condition']);
        $this->assertSame('policy_missing', $r['reason']);
    }
}
