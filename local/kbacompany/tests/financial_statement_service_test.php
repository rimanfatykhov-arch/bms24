<?php
namespace local_kbacompany;

use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_statement_service;

defined('MOODLE_INTERNAL') || die();

/** @covers \local_kbacompany\local\financial_statement_service */
class financial_statement_service_test extends \advanced_testcase {

    public function test_contract_and_templates_are_stable(): void {
        $contract = financial_statement_service::contract();
        $this->assertSame('0.6.1', $contract['version']);
        $this->assertSame(['bdr', 'bdds', 'balance'], financial_statement_service::TYPES);
        $this->assertContains('forecast_balance_must_balance_before_publish', $contract['rules']);
        $this->assertContains('no_actual_accounting_or_bank_facts_are_created', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
        $this->assertCount(11, financial_statement_service::template('bdr'));
        $this->assertCount(11, financial_statement_service::template('bdds'));
        $this->assertCount(13, financial_statement_service::template('balance'));
    }

    public function test_bdr_and_bdds_calculations_match_bms_finance_rules(): void {
        $bdr = financial_statement_service::calculate('bdr', [
            'REV' => [1 => 1000], 'VAR' => [1 => 300], 'FIX' => [1 => 200],
            'OTH_INC' => [1 => 50], 'OTH_EXP' => [1 => 20], 'TAX' => [1 => 30],
        ]);
        $this->assertEquals(700.0, $bdr['values']['CM'][1]);
        $this->assertEquals(70.0, $bdr['values']['CMR'][1]);
        $this->assertEquals(500.0, $bdr['values']['OP'][1]);
        //1000-300-200+50-20-30=500; include other income, expense and tax.
        $this->assertEquals(530.0, $bdr['values']['PBT'][1]);
        $this->assertEquals(500.0, $bdr['values']['NP'][1]);
        $this->assertEquals(500.0, $bdr['summary']['netprofit']);

        $bdds = financial_statement_service::calculate('bdds', [
            'OPENING' => [1 => 100],
            'CLIENT_IN' => [1 => 50, 2 => 40, 3 => 30, 4 => 20],
            'OTHER_OP_IN' => [1 => 0, 2 => 0, 3 => 0, 4 => 0],
            'FIN_IN' => [1 => 0, 2 => 0, 3 => 0, 4 => 0],
            'OP_OUT' => [1 => 70, 2 => 60, 3 => 50, 4 => 40],
            'PAYROLL_TAX' => [1 => 0, 2 => 0, 3 => 0, 4 => 0],
            'INVEST_OUT' => [1 => 0, 2 => 0, 3 => 0, 4 => 0],
            'FIN_OUT' => [1 => 0, 2 => 0, 3 => 0, 4 => 0],
        ]);
        $this->assertEquals(80.0, $bdds['values']['CLOSING'][1]);
        $this->assertEquals(80.0, $bdds['values']['OPENING'][2]);
        $this->assertEquals(20.0, $bdds['summary']['closingcash']);
        $this->assertSame(0, $bdds['summary']['negativebuckets']);
    }

    public function test_statement_lifecycle_is_append_only_and_scenario_pinned(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '61000000-0000-4000-8000-000000000001';
        $this->create_company($companyuuid, 'Statement test');
        $scenario = $this->published_scenario($companyuuid, (int)$admin->id);

        $uuid = financial_statement_service::save_statement($companyuuid, (int)$admin->id, [
            'code' => 'BDR-2027-01', 'name' => 'January P&L plan', 'statementtype' => 'bdr',
            'scenariouuid' => $scenario, 'centeruuid' => '',
            'periodstart' => '2027-01-01', 'periodend' => '2027-01-31', 'currency' => 'RUB',
            'sourceinfo' => 'Sales plan', 'notes' => '',
        ], [
            'REV' => [1 => 1000], 'VAR' => [1 => 300], 'FIX' => [1 => 200],
            'OTH_INC' => [1 => 0], 'OTH_EXP' => [1 => 0], 'TAX' => [1 => 50],
        ], []);
        $draft = $DB->get_record('local_kbacompany_finstmt', ['uuid' => $uuid, 'revision' => 1], '*', MUST_EXIST);
        $this->assertSame('draft', (string)$draft->status);
        $this->assertSame($scenario, (string)$draft->scenariouuid);
        $this->assertEquals(2, $draft->scenariorevision);
        $this->assertEquals(count(financial_statement_service::input_cells('bdr')),
            $DB->count_records('local_kbacompany_finline', ['statementuuid' => $uuid, 'statementrevision' => 1]));

        financial_statement_service::publish_statement($companyuuid, (int)$admin->id, $uuid, 1);
        $published = $DB->get_record('local_kbacompany_finstmt', ['uuid' => $uuid, 'revision' => 2], '*', MUST_EXIST);
        $this->assertSame('published', (string)$published->status);
        $this->assertEquals(2, $DB->count_records('local_kbacompany_finstmt', ['uuid' => $uuid]));
    }

    public function test_unbalanced_forecast_balance_cannot_publish(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '61000000-0000-4000-8000-000000000002';
        $this->create_company($companyuuid, 'Balance test');
        $scenario = $this->published_scenario($companyuuid, (int)$admin->id);

        $uuid = financial_statement_service::save_statement($companyuuid, (int)$admin->id, [
            'code' => 'BAL-2027-01', 'name' => 'Forecast balance', 'statementtype' => 'balance',
            'scenariouuid' => $scenario, 'centeruuid' => '',
            'periodstart' => '2027-01-01', 'periodend' => '2027-01-31', 'currency' => 'RUB',
            'sourceinfo' => '', 'notes' => '',
        ], [
            'CASH' => [1 => 100], 'AR' => [1 => 100], 'INVENTORY' => [1 => 0],
            'OTHER_CURRENT' => [1 => 0], 'FIXED_ASSETS' => [1 => 0], 'OTHER_ASSETS' => [1 => 0],
            'AP' => [1 => 50], 'DEBT' => [1 => 0], 'OTHER_LIAB' => [1 => 0], 'EQUITY' => [1 => 100],
        ], []);
        $this->expectException(\moodle_exception::class);
        financial_statement_service::publish_statement($companyuuid, (int)$admin->id, $uuid, 1);
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

    private function create_company(string $uuid, string $name): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid, 'name' => $name, 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 1, 'sourcekind' => 'manual', 'legacycompanyid' => 0,
            'syncstate' => 'local', 'active' => 1, 'archivedat' => 0, 'createdby' => 0, 'modifiedby' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
}
