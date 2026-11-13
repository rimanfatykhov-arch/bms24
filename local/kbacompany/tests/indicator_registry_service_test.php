<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\indicator_event_service;
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\indicator_trend_service;
use local_kbacompany\local\product_service;

final class indicator_registry_service_test extends \advanced_testcase {
    public function test_contract_keeps_one_canonical_indicator_registry(): void {
        $contract = indicator_registry_service::contract();
        $this->assertSame('0.5.5.2', $contract['version']);
        $this->assertSame('local_kbacompany_stat', $contract['canonical_indicator']);
        $this->assertSame('local_kbacompany_statval', $contract['history']);
        $this->assertContains('observations_remain_append_only', $contract['rules']);
        $this->assertSame(['strategy', 'organization'], $contract['tree_projections']);
        $this->assertContains('one_indicator_many_bindings', $contract['rules']);
        $this->assertContains('battle_plan_does_not_own_indicator_definitions', $contract['rules']);
        $this->assertContains('trends_are_derived_from_canonical_fact_history_not_stored_as_parallel_facts', $contract['rules']);
    }

    public function test_trend_shape_uses_exact_comparisons_and_three_facts(): void {
        $series = [
            ['period'=>'2026-08-31', 'fact'=>'100', 'plan'=>null],
            ['period'=>'2026-09-07', 'fact'=>'101', 'plan'=>null],
            ['period'=>'2026-09-14', 'fact'=>'102', 'plan'=>null],
        ];
        $analysis = indicator_trend_service::analyze($series, 'up');
        $this->assertSame('sustained_up', $analysis['movement']);
        $this->assertSame('improving', $analysis['quality']);

        $analysis = indicator_trend_service::analyze(array_slice($series, -2), 'up');
        $this->assertSame('insufficient', $analysis['movement']);
    }

    public function test_trend_shape_detects_unchanged_and_no_data(): void {
        $unchanged = [
            ['period'=>'2026-08-31', 'fact'=>'100'],
            ['period'=>'2026-09-07', 'fact'=>'100'],
            ['period'=>'2026-09-14', 'fact'=>'100'],
        ];
        $analysis = indicator_trend_service::analyze($unchanged, 'up');
        $this->assertSame('unchanged', $analysis['movement']);
        $this->assertSame('stable', $analysis['quality']);
        $this->assertSame('insufficient', indicator_trend_service::analyze([], 'up')['movement']);
    }

    public function test_trend_shape_detects_mixed_and_direction_aware_worsening(): void {
        $mixed = [
            ['period'=>'2026-08-31', 'fact'=>'100'],
            ['period'=>'2026-09-07', 'fact'=>'110'],
            ['period'=>'2026-09-14', 'fact'=>'105'],
        ];
        $this->assertSame('mixed', indicator_trend_service::analyze($mixed, 'up')['movement']);

        $falling = [
            ['period'=>'2026-08-31', 'fact'=>'100'],
            ['period'=>'2026-09-07', 'fact'=>'90'],
            ['period'=>'2026-09-14', 'fact'=>'80'],
        ];
        $analysis = indicator_trend_service::analyze($falling, 'up');
        $this->assertSame('sustained_down', $analysis['movement']);
        $this->assertSame('worsening', $analysis['quality']);
        $this->assertSame('improving', indicator_trend_service::analyze($falling, 'down')['quality']);
    }

    public function test_derived_events_detect_reversal_and_new_extrema(): void {
        $series = [
            ['period'=>'2026-08-24', 'fact'=>'100'],
            ['period'=>'2026-08-31', 'fact'=>'90'],
            ['period'=>'2026-09-07', 'fact'=>'80'],
            ['period'=>'2026-09-14', 'fact'=>'110'],
        ];
        $types = array_column(indicator_event_service::latest($series, 'up'), 'type');
        $this->assertContains('reversal_up', $types);
        $this->assertContains('new_max', $types);
    }

    public function test_derived_events_detect_norm_exit_and_return(): void {
        $norms = [[
            'status'=>'active', 'binduuid'=>'bind-1', 'revision'=>1, 'normkind'=>'target',
            'targetvalue'=>'100', 'minval'=>null, 'maxval'=>null,
            'effectivefrom'=>'2026-01-01', 'effectiveto'=>'',
        ]];
        $outside = [
            ['period'=>'2026-09-07', 'fact'=>'110'],
            ['period'=>'2026-09-14', 'fact'=>'90'],
        ];
        $types = array_column(indicator_event_service::latest($outside, 'up', $norms, 'bind-1'), 'type');
        $this->assertContains('out_of_norm', $types);

        $returned = [
            ['period'=>'2026-09-07', 'fact'=>'90'],
            ['period'=>'2026-09-14', 'fact'=>'110'],
        ];
        $types = array_column(indicator_event_service::latest($returned, 'up', $norms, 'bind-1'), 'type');
        $this->assertContains('back_in_norm', $types);
    }

    public function test_norm_events_resolve_revision_by_effective_period(): void {
        $norms = [
            [
                'status'=>'active', 'binduuid'=>'bind-1', 'revision'=>1, 'normkind'=>'target',
                'targetvalue'=>'100', 'effectivefrom'=>'2026-01-01', 'effectiveto'=>'',
            ],
            [
                'status'=>'active', 'binduuid'=>'bind-1', 'revision'=>2, 'normkind'=>'target',
                'targetvalue'=>'120', 'effectivefrom'=>'2026-09-14', 'effectiveto'=>'',
            ],
        ];
        $this->assertSame('100', (string)indicator_event_service::norm_for_period($norms, 'bind-1', '2026-09-07')['targetvalue']);
        $this->assertSame('120', (string)indicator_event_service::norm_for_period($norms, 'bind-1', '2026-09-14')['targetvalue']);
    }

    public function test_extended_period_validation_accepts_quarter_and_year_boundaries(): void {
        product_service::validate_period('2026-01-01', 'quarterly');
        product_service::validate_period('2026-04-01', 'quarterly');
        product_service::validate_period('2026-01-01', 'annual');
        $this->assertTrue(true);
    }

    public function test_extended_period_validation_rejects_wrong_quarter_start(): void {
        $this->expectException(\moodle_exception::class);
        product_service::validate_period('2026-02-01', 'quarterly');
    }
}
