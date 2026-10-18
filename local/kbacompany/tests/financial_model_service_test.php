<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\financial_model_service;

class financial_model_service_test extends \advanced_testcase {
    public function test_contract_defines_foundation_boundaries(): void {
        $contract = financial_model_service::contract();
        $this->assertSame('0.6.0', $contract['version']);
        $this->assertContains('FinancialScenario', $contract['objects']);
        $this->assertContains('FinancialResponsibilityCenter', $contract['objects']);
        $this->assertContains('centres_reference_canonical_ofs_targets_instead_of_copying_org_structure',
            $contract['rules']);
        $this->assertContains('no_financial_statement_or_budget_fact_storage_in_v0_6_0', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_enumerations_are_stable(): void {
        $this->assertSame(['base', 'stretch', 'crisis', 'custom'], financial_model_service::SCENARIO_TYPES);
        $this->assertSame(['revenue', 'cost', 'profit', 'investment'], financial_model_service::CENTER_TYPES);
        $this->assertSame(['company', 'orgunit', 'position', 'product'], financial_model_service::TARGET_TYPES);
    }

    public function test_scenario_lifecycle_is_append_only_and_company_scoped(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '60000000-0000-4000-8000-000000000001';
        $this->create_canonical_company($companyuuid, 'Finance test');

        $uuid = financial_model_service::save_scenario($companyuuid, (int)$admin->id, [
            'code' => 'BASE-2027', 'name' => 'Base 2027', 'scenariotype' => 'base',
            'periodstart' => '2027-01-01', 'periodend' => '2027-12-31', 'currency' => 'rub',
            'description' => 'Base scenario', 'assumptions' => 'Current capacity',
            'strategyuuid' => '', 'businessmodeluuid' => '',
        ]);
        $draft = $DB->get_record('local_kbacompany_finscenario', ['companyuuid' => $companyuuid, 'uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('draft', (string)$draft->status);
        $this->assertSame('FIN-0001', (string)$draft->code);
        $this->assertSame('RUB', (string)$draft->currency);
        $this->assertEquals(1, $draft->revision);

        financial_model_service::publish_scenario($companyuuid, (int)$admin->id, $uuid, 1);
        $published = $DB->get_record('local_kbacompany_finscenario',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 2], '*', MUST_EXIST);
        $this->assertSame('published', (string)$published->status);
        $this->assertSame(2, $DB->count_records('local_kbacompany_finscenario', ['uuid' => $uuid]));

        financial_model_service::save_scenario($companyuuid, (int)$admin->id, [
            'code' => 'BASE-2027', 'name' => 'Base 2027 revised', 'scenariotype' => 'base',
            'periodstart' => '2027-01-01', 'periodend' => '2027-12-31', 'currency' => 'RUB',
            'description' => '', 'assumptions' => 'Revised assumptions',
            'strategyuuid' => '', 'businessmodeluuid' => '',
        ], $uuid, 2);
        $revision3 = $DB->get_record('local_kbacompany_finscenario',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 3], '*', MUST_EXIST);
        $this->assertSame('draft', (string)$revision3->status);
        $this->assertSame('FIN-0001', (string)$revision3->code);
        $this->assertSame('published', (string)$published->status);
    }

    public function test_financial_center_reuses_canonical_company_target_and_rejects_duplicate_target(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '60000000-0000-4000-8000-000000000002';
        $this->create_canonical_company($companyuuid, 'Centre test');

        $uuid = financial_model_service::save_center($companyuuid, (int)$admin->id, [
            'code' => 'CFO-COMPANY', 'name' => 'Company profit centre', 'centertype' => 'profit',
            'targettype' => 'company', 'targetuuid' => '', 'ownerpositionuuid' => '', 'mainstatuuid' => '',
            'resultdescription' => 'Company financial result', 'authorityrule' => 'Owner policy',
            'reviewperiod' => 'monthly',
        ]);
        $row = $DB->get_record('local_kbacompany_fincenter', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame($companyuuid, (string)$row->targetuuid);
        $this->assertSame('CFO-0001', (string)$row->code);

        $this->expectException(\moodle_exception::class);
        financial_model_service::save_center($companyuuid, (int)$admin->id, [
            'code' => 'CFO-DUP', 'name' => 'Duplicate target', 'centertype' => 'cost',
            'targettype' => 'company', 'targetuuid' => '', 'ownerpositionuuid' => '', 'mainstatuuid' => '',
            'resultdescription' => '', 'authorityrule' => '', 'reviewperiod' => 'weekly',
        ]);
    }

    private function create_canonical_company(string $uuid, string $name): void {
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
