<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\economic_indicators;

/** Regression tests for the economic indicator registry. */
final class economic_indicators_test extends \advanced_testcase {
    public function test_default_catalogue_uses_stable_codes(): void {
        global $DB;
        $this->resetAfterTest();
        economic_indicators::seed_defaults(0);

        $this->assertTrue($DB->record_exists('local_kbacorp_econ_ind', [
            'companyid' => 0,
            'code' => 'REV',
            'systemdefined' => 1,
        ]));
        $this->assertTrue($DB->record_exists('local_kbacorp_econ_ind', [
            'companyid' => 0,
            'code' => 'PROFIT',
            'systemdefined' => 1,
        ]));
        $this->assertTrue($DB->record_exists('local_kbacorp_econ_ind', [
            'companyid' => 0,
            'code' => 'AVG_CHECK',
            'systemdefined' => 1,
        ]));
    }

    public function test_company_indicator_value_and_history_are_created(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Economic registry company');

        $indicatorid = economic_indicators::save_indicator((object)[
            'companyid' => $companyid,
            'code' => 'CUSTOM_KPI',
            'name' => 'Custom KPI',
            'description' => 'A test KPI',
            'category' => 'operations',
            'valuetype' => 'ratio',
            'unit' => 'x',
            'periodtype' => 'quarter',
            'defaultsource' => 'manual',
            'direction' => 'up',
            'sortorder' => 999,
            'active' => 1,
        ], (int)get_admin()->id);

        $valueid = economic_indicators::save_value((object)[
            'companyid' => $companyid,
            'indicatorid' => $indicatorid,
            'auditid' => 0,
            'periodtype' => 'quarter',
            'periodstart' => make_timestamp(2030, 2, 10),
            'periodend' => make_timestamp(2030, 3, 25),
            'scenario' => 'actual',
            'value' => '1,75',
            'currency' => 'RUB',
            'source' => 'manual',
            'comment' => 'Initial value',
            'verified' => 1,
        ], (int)get_admin()->id);

        $value = $DB->get_record('local_kbacorp_econ_val', ['id' => $valueid], '*', MUST_EXIST);
        $this->assertSame('2030-Q1', (string)$value->periodkey);
        $this->assertEquals(1.75, (float)$value->value);
        $this->assertSame('actual', (string)$value->scenario);
        $this->assertSame(1, (int)$value->verified);
        $this->assertSame(1, $DB->count_records('local_kbacorp_econ_log', [
            'entitytype' => 'indicator',
            'entityid' => $indicatorid,
            'action' => 'created',
        ]));
        $this->assertSame(1, $DB->count_records('local_kbacorp_econ_log', [
            'entitytype' => 'value',
            'entityid' => $valueid,
            'action' => 'created',
        ]));
    }

    public function test_one_value_per_indicator_and_period_is_enforced(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Unique period company');
        $indicator = $DB->get_record('local_kbacorp_econ_ind', ['companyid' => 0, 'code' => 'REV'], '*', MUST_EXIST);
        $data = (object)[
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => 0,
            'periodtype' => 'month',
            'periodstart' => make_timestamp(2031, 5, 15),
            'periodend' => make_timestamp(2031, 5, 31),
            'scenario' => 'actual',
            'value' => '1000000',
            'currency' => 'RUB',
            'source' => 'erp',
            'comment' => '',
            'verified' => 0,
        ];
        economic_indicators::save_value($data, (int)get_admin()->id);

        $this->expectException(\moodle_exception::class);
        economic_indicators::save_value($data, (int)get_admin()->id);
    }

    public function test_actual_and_plan_values_can_share_a_period(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Scenario company');
        $indicator = $DB->get_record('local_kbacorp_econ_ind',
            ['companyid' => 0, 'code' => 'REV'], '*', MUST_EXIST);
        $base = (object)[
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => 0,
            'periodtype' => 'month',
            'periodstart' => make_timestamp(2031, 6, 1),
            'periodend' => make_timestamp(2031, 6, 30),
            'currency' => 'RUB',
            'source' => 'manual',
            'comment' => '',
            'verified' => 0,
        ];
        $actual = clone $base;
        $actual->scenario = 'actual';
        $actual->value = '100';
        $plan = clone $base;
        $plan->scenario = 'plan';
        $plan->value = '120';
        economic_indicators::save_value($actual, (int)get_admin()->id);
        economic_indicators::save_value($plan, (int)get_admin()->id);

        $this->assertSame(2, $DB->count_records('local_kbacorp_econ_val', [
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'periodkey' => '2031-06',
        ]));
    }

    public function test_legacy_snapshot_migration_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        economic_indicators::seed_defaults(0);
        $companyid = $this->create_company('Legacy snapshot company');
        $toolid = (int)$DB->insert_record('local_kbacorp_bms_tool', (object)[
            'companyid' => $companyid,
            'code' => 'ECON-TEST',
            'name' => 'Economic test tool',
            'description' => '',
            'area' => 'finance',
            'scalemax' => 9,
            'attentionthreshold' => 3,
            'indexmethod' => 'area_average',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $auditid = (int)$DB->insert_record('local_kbacorp_impl_audit', (object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Legacy economic audit',
            'audittype' => 'baseline',
            'auditdate' => make_timestamp(2032, 12, 31),
            'status' => 'confirmed',
            'scalemax' => 9,
            'indexmethod' => 'area_average',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_kbacorp_impl_econ', (object)[
            'auditid' => $auditid,
            'companyid' => $companyid,
            'periodtype' => 'year',
            'periodstart' => make_timestamp(2032, 1, 1),
            'periodend' => make_timestamp(2032, 12, 31),
            'currency' => 'RUB',
            'revenue' => 25000000,
            'netprofit' => 3500000,
            'source' => 'odoo',
            'verified' => 1,
            'verifiedby' => (int)get_admin()->id,
            'verifiedat' => time(),
            'createdby' => (int)get_admin()->id,
            'modifiedby' => (int)get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $first = economic_indicators::migrate_legacy_snapshots(0);
        $second = economic_indicators::migrate_legacy_snapshots(0);
        $this->assertSame(2, (int)$first->created);
        $this->assertSame(0, (int)$second->created);
        $this->assertGreaterThanOrEqual(2, (int)$second->skipped);
        $this->assertSame(2, $DB->count_records('local_kbacorp_econ_val', ['companyid' => $companyid]));
        $this->assertSame(2, $DB->count_records('local_kbacorp_econ_val', [
            'companyid' => $companyid,
            'source' => 'erp',
        ]));
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
