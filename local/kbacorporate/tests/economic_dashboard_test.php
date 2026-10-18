<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\economic_dashboard;
use local_kbacorporate\local\economic_indicators;

/** Regression tests for the economic indicator dashboard model. */
final class economic_dashboard_test extends \advanced_testcase {
    public function test_direction_aware_change_states(): void {
        $up = economic_dashboard::calculate_change(120.0, 100.0, economic_indicators::DIRECTION_UP);
        $this->assertTrue($up->available);
        $this->assertEquals(20.0, $up->delta);
        $this->assertEquals(20.0, $up->percent);
        $this->assertSame('positive', $up->state);

        $down = economic_dashboard::calculate_change(80.0, 100.0, economic_indicators::DIRECTION_DOWN);
        $this->assertSame('positive', $down->state);
        $this->assertEquals(-20.0, $down->delta);

        $target = economic_dashboard::calculate_change(110.0, 100.0, economic_indicators::DIRECTION_TARGET);
        $this->assertSame('neutral', $target->state);

        $unknown = economic_dashboard::calculate_change(null, 100.0, economic_indicators::DIRECTION_UP);
        $this->assertFalse($unknown->available);
        $this->assertSame('unknown', $unknown->state);
    }

    public function test_dashboard_builds_actual_plan_and_forecast_panel(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Dashboard company');
        $indicator = $DB->get_record('local_kbacorp_econ_ind', [
            'companyid' => 0,
            'code' => 'REV',
        ], '*', MUST_EXIST);

        $this->save_value($companyid, (int)$indicator->id, 2033, 1, 'actual', 100.0);
        $this->save_value($companyid, (int)$indicator->id, 2033, 2, 'actual', 120.0);
        $this->save_value($companyid, (int)$indicator->id, 2033, 2, 'plan', 110.0);
        $this->save_value($companyid, (int)$indicator->id, 2033, 2, 'forecast', 125.0);

        $model = economic_dashboard::build($companyid, [
            'from' => make_timestamp(2033, 1, 1),
            'to' => make_timestamp(2033, 2, 28, 23, 59, 59),
            'datesexplicit' => true,
            'indicatorids' => [(int)$indicator->id],
        ]);

        $this->assertSame([(int)$indicator->id], $model->selectedids);
        $this->assertCount(1, $model->panels);
        $panel = $model->panels[0];
        $this->assertEquals(120.0, (float)$panel->latestactual->value);
        $this->assertEquals(100.0, (float)$panel->previousactual->value);
        $this->assertEquals(110.0, (float)$panel->latestplan->value);
        $this->assertEquals(125.0, (float)$panel->latestforecast->value);
        $this->assertEquals(20.0, (float)$panel->trend->delta);
        $this->assertSame('positive', $panel->trend->state);
        $this->assertEquals(10.0, (float)$panel->planvariance->delta);
        $this->assertSame(['2033-01', '2033-02'], array_values($panel->chartlabels));
        $this->assertSame([100.0, 120.0], array_values($panel->chartvalues));
        $this->assertSame(4, $panel->pointcount);
        $this->assertSame(2, $panel->actualcount);
        $this->assertEquals(100.0, (float)$panel->verifiedshare);
        $this->assertSame(4, $model->summary->totalpoints);
        $this->assertSame(2, $model->summary->actualpoints);
    }

    public function test_indicator_selection_is_limited_and_deterministic(): void {
        $catalog = [];
        for ($i = 1; $i <= 10; $i++) {
            $catalog[] = (object)[
                'id' => $i,
                'code' => 'KPI_' . $i,
            ];
        }

        $selected = economic_dashboard::select_indicator_ids(
            $catalog,
            [],
            [10, 3, 3, 99, 1, 2, 4, 5, 6, 7, 8, 9]
        );

        $this->assertSame([10, 3, 1, 2, 4, 5, 6, 7], $selected);
        $this->assertCount(economic_dashboard::MAX_INDICATORS, $selected);
    }

    public function test_opening_old_audit_includes_its_linked_value_without_explicit_dates(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Old audit dashboard company');
        $indicator = $DB->get_record('local_kbacorp_econ_ind', [
            'companyid' => 0,
            'code' => 'REV',
        ], '*', MUST_EXIST);
        $toolid = (int)$DB->insert_record('local_kbacorp_bms_tool', (object)[
            'companyid' => $companyid,
            'code' => 'DASHBOARD-TEST',
            'name' => 'Dashboard test tool',
            'description' => '',
            'area' => 'management',
            'scalemax' => 9,
            'attentionthreshold' => 3,
            'indexmethod' => 'area_average',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $auditdate = make_timestamp(2020, 12, 31);
        $auditid = (int)$DB->insert_record('local_kbacorp_impl_audit', (object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Old confirmed audit',
            'audittype' => 'baseline',
            'status' => 'confirmed',
            'auditdate' => $auditdate,
            'scalemax' => 9,
            'attentionthreshold' => 3,
            'indexmethod' => 'area_average',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        economic_indicators::save_value((object)[
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => $auditid,
            'periodtype' => 'year',
            'periodstart' => make_timestamp(2020, 1, 1),
            'periodend' => $auditdate,
            'scenario' => 'actual',
            'value' => '9000000',
            'currency' => 'RUB',
            'source' => 'manual',
            'comment' => '',
            'verified' => 1,
        ], (int)get_admin()->id);

        $model = economic_dashboard::build($companyid, [
            'auditid' => $auditid,
            'datesexplicit' => false,
        ]);

        $this->assertContains((int)$indicator->id, $model->selectedids);
        $panel = null;
        foreach ($model->panels as $candidate) {
            if ((int)$candidate->indicator->id === (int)$indicator->id) {
                $panel = $candidate;
                break;
            }
        }
        $this->assertNotNull($panel);
        $this->assertNotNull($panel->latestactual);
        $this->assertEquals(9000000.0, (float)$panel->latestactual->value);
        $this->assertNotNull($model->auditcontext);
        $this->assertSame($auditid, (int)$model->auditcontext->audit->id);
        $this->assertLessThanOrEqual(make_timestamp(2020, 1, 1), (int)$model->from);
    }

    private function save_value(int $companyid, int $indicatorid, int $year, int $month,
            string $scenario, float $value): int {
        $start = make_timestamp($year, $month, 1);
        $nextmonth = $month === 12 ? 1 : $month + 1;
        $nextyear = $month === 12 ? $year + 1 : $year;
        $end = make_timestamp($nextyear, $nextmonth, 1) - 1;
        return economic_indicators::save_value((object)[
            'companyid' => $companyid,
            'indicatorid' => $indicatorid,
            'auditid' => 0,
            'periodtype' => 'month',
            'periodstart' => $start,
            'periodend' => $end,
            'scenario' => $scenario,
            'value' => (string)$value,
            'currency' => 'RUB',
            'source' => 'manual',
            'comment' => '',
            'verified' => 1,
        ], (int)get_admin()->id);
    }

    private function create_company(string $name): int {
        global $DB;
        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => $name,
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
