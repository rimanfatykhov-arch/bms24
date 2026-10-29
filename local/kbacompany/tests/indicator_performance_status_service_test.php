<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\indicator_performance_status_service;

/** Pure read-model status tests for Metrics 2.2. */
final class indicator_performance_status_service_test extends \advanced_testcase {
    public function test_plan_has_priority_over_norm(): void {
        $result = indicator_performance_status_service::assess(95.0, 90.0,
            ['normkind'=>'target','targetvalue'=>'100'], 'up');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('plan', $result['source']);
    }

    public function test_outside_target_is_risk_without_hidden_threshold(): void {
        $result = indicator_performance_status_service::assess(99.0, null,
            ['normkind'=>'target','targetvalue'=>'100'], 'up');
        $this->assertSame('risk', $result['status']);
        $this->assertSame('outside_target', $result['reason']);
    }

    public function test_explicit_critical_or_worsening_trend_makes_deviation_critical(): void {
        $critical = indicator_performance_status_service::assess(99.0, null,
            ['normkind'=>'target','targetvalue'=>'100'], 'up', true, 'neutral');
        $worsening = indicator_performance_status_service::assess(99.0, null,
            ['normkind'=>'target','targetvalue'=>'100'], 'up', false, 'worsening');
        $this->assertSame('critical', $critical['status']);
        $this->assertSame('critical', $worsening['status']);
    }

    public function test_missing_fact_or_target_is_nodata(): void {
        $this->assertSame('nodata', indicator_performance_status_service::assess(null, 100.0, null, 'up')['status']);
        $this->assertSame('nodata', indicator_performance_status_service::assess(100.0, null, null, 'up')['status']);
    }

    public function test_range_norm_uses_nearest_boundary_for_variance(): void {
        $result = indicator_performance_status_service::assess(8.0, null,
            ['normkind'=>'range','minval'=>'10','maxval'=>'20'], 'neutral');
        $this->assertSame('risk', $result['status']);
        $this->assertEquals(-2.0, $result['variance']);
    }
}
