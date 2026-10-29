<?php
namespace local_kbacompany;

use local_kbacompany\local\budgeting_service;
use local_kbacompany\local\financial_model_service;

defined('MOODLE_INTERNAL') || die();

/** @covers \local_kbacompany\local\budgeting_service */
class budgeting_service_test extends \advanced_testcase {

    public function test_contract_is_stable(): void {
        $contract = budgeting_service::contract();
        $this->assertSame('0.6.2', $contract['version']);
        $this->assertSame(['goal', 'program', 'project', 'orgunit'], budgeting_service::BUDGET_TYPES);
        $this->assertContains('plan_fact_uses_aggregate_management_snapshots_not_ledger_transactions', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_budget_lifecycle_and_plan_fact_are_append_only(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '62000000-0000-4000-8000-000000000001';
        $orguuid = '62000000-0000-4000-8000-000000000002';
        $this->create_company($companyuuid);
        $this->create_orgunit($companyuuid, $orguuid);
        $scenario = $this->published_scenario($companyuuid, (int)$admin->id);

        $budgetuuid = budgeting_service::save_budget($companyuuid, (int)$admin->id, [
            'code' => 'BUD-OPS-2027', 'name' => 'Operations budget', 'budgettype' => 'orgunit',
            'scenariouuid' => $scenario, 'centeruuid' => '', 'targetuuid' => $orguuid,
            'periodstart' => '2027-01-01', 'periodend' => '2027-03-31', 'currency' => 'RUB',
            'approvalrule' => 'CEO approval', 'notes' => '',
        ], [[
            'linecode' => 'MKT', 'name' => 'Marketing', 'linetype' => 'expense',
            'periodstart' => '2027-01-01', 'periodend' => '2027-03-31',
            'planamount' => 1000, 'limitamount' => 1200, 'ownerpositionuuid' => '',
            'indicatoruuid' => '', 'sourceinfo' => 'Quarter plan',
        ]]);
        $this->assertSame('draft', $DB->get_field('local_kbacompany_finbudget', 'status',
            ['uuid' => $budgetuuid, 'revision' => 1]));
        budgeting_service::submit_budget($companyuuid, (int)$admin->id, $budgetuuid, 1);
        budgeting_service::approve_budget($companyuuid, (int)$admin->id, $budgetuuid, 2, 'Approved');
        $this->assertSame('approved', $DB->get_field('local_kbacompany_finbudget', 'status',
            ['uuid' => $budgetuuid, 'revision' => 3]));
        $this->assertEquals(3, $DB->count_records('local_kbacompany_finbudget', ['uuid' => $budgetuuid]));

        budgeting_service::record_fact($companyuuid, (int)$admin->id, $budgetuuid, 3, 'MKT', [
            'asofdate' => '2027-03-31', 'actualamount' => 900, 'sourcekind' => 'manual',
            'sourceinfo' => 'Management close',
        ]);
        $detail = budgeting_service::budget($companyuuid, (int)$admin->id, $budgetuuid, 3);
        $this->assertEquals(-100.0, $detail['lines'][0]['variance']);
        $this->assertEquals(-10.0, $detail['lines'][0]['variancepct']);
    }

    public function test_spend_above_limit_cannot_submit(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '62000000-0000-4000-8000-000000000003';
        $orguuid = '62000000-0000-4000-8000-000000000004';
        $this->create_company($companyuuid);
        $this->create_orgunit($companyuuid, $orguuid);
        $scenario = $this->published_scenario($companyuuid, (int)$admin->id);
        $budgetuuid = budgeting_service::save_budget($companyuuid, (int)$admin->id, [
            'code' => 'BUD-LIMIT', 'name' => 'Limit test', 'budgettype' => 'orgunit',
            'scenariouuid' => $scenario, 'centeruuid' => '', 'targetuuid' => $orguuid,
            'periodstart' => '2027-01-01', 'periodend' => '2027-01-31', 'currency' => 'RUB',
            'approvalrule' => '', 'notes' => '',
        ], [[
            'linecode' => 'CAPEX', 'name' => 'Equipment', 'linetype' => 'investment',
            'planamount' => 1500, 'limitamount' => 1000, 'ownerpositionuuid' => '',
            'indicatoruuid' => '', 'sourceinfo' => 'Investment request',
        ]]);
        $this->expectException(\moodle_exception::class);
        budgeting_service::submit_budget($companyuuid, (int)$admin->id, $budgetuuid, 1);
    }

    private function published_scenario(string $companyuuid, int $userid): string {
        $uuid = financial_model_service::save_scenario($companyuuid, $userid, [
            'code' => 'BASE-2027', 'name' => 'Base 2027', 'scenariotype' => 'base',
            'periodstart' => '2027-01-01', 'periodend' => '2027-12-31', 'currency' => 'RUB',
            'description' => '', 'assumptions' => '', 'strategyuuid' => '', 'businessmodeluuid' => '',
        ]);
        financial_model_service::publish_scenario($companyuuid, $userid, $uuid, 1);
        return $uuid;
    }

    private function create_company(string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid, 'name' => 'Budget test', 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 1, 'sourcekind' => 'manual', 'legacycompanyid' => 0,
            'syncstate' => 'local', 'active' => 1, 'archivedat' => 0, 'createdby' => 0, 'modifiedby' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    private function create_orgunit(string $companyuuid, string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_orgnode', (object)[
            'companyuuid' => $companyuuid, 'branchuuid' => 'main', 'uuid' => $uuid, 'parentuuid' => '',
            'code' => 'OPS', 'nodetype' => 'department', 'name' => 'Operations', 'sortorder' => 1,
            'color' => '', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
}
