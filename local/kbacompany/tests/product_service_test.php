<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\product_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organizing_board_service;
use local_kbacorporate\local\repository;

/** Product and statistics regression tests, to run within a Moodle PHPUnit installation. */
final class product_service_test extends \advanced_testcase {
    public function test_series_preserves_missing_zero_negative_and_revisions(): void {
        $series = product_service::series([
            (object)['periodstart' => '2026-09-01', 'kind' => 'fact', 'amount' => '0', 'revision' => 1],
            (object)['periodstart' => '2026-09-02', 'kind' => 'fact', 'amount' => '5', 'revision' => 1],
            (object)['periodstart' => '2026-09-02', 'kind' => 'fact', 'amount' => '-2', 'revision' => 2],
            (object)['periodstart' => '2026-09-02', 'kind' => 'plan', 'amount' => '10', 'revision' => 1],
        ]);
        $this->assertSame('0', $series[0]['fact']);
        $this->assertNull($series[0]['plan']);
        $this->assertSame('-2', $series[1]['fact']);
        $this->assertSame('10', $series[1]['plan']);
        $this->assertSame(2, $series[1]['fact_revision']);
    }

    public function test_calendar_periods(): void {
        product_service::validate_period('2026-09-07', 'weekly');
        product_service::validate_period('2026-09-01', 'monthly');
        product_service::validate_period('2024-02-29', 'daily');
        $this->expectException(\moodle_exception::class);
        product_service::validate_period('2026-02-29', 'daily');
    }

    public function test_week_must_start_monday(): void {
        $this->expectException(\moodle_exception::class);
        product_service::validate_period('2026-09-08', 'weekly');
    }

    public function test_month_must_start_on_first_day(): void {
        $this->expectException(\moodle_exception::class);
        product_service::validate_period('2026-09-02', 'monthly');
    }

    public function test_company_scoped_creation_and_append_only_correction(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name' => 'Products test', 'active' => 1]);
        $uuid = identity_registry::company_uuid($companyid);
        $board = organizing_board_service::install_seven_division_template($uuid, (int)$USER->id);
        $product = product_service::create_product($uuid, (int)$USER->id, [
            'code' => 'VFP-1', 'name' => 'Accepted service', 'acceptance' => 'Acceptance signed',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
        $stat = product_service::create_statistic($uuid, (int)$USER->id, [
            'productuuid' => $product, 'code' => 'STAT-1', 'name' => 'Accepted services',
            'unit' => 'count', 'periodtype' => 'monthly', 'direction' => 'up',
        ]);
        product_service::record_value($uuid, (int)$USER->id, $stat, '2026-09-01', 'fact', '0');
        product_service::record_value($uuid, (int)$USER->id, $stat, '2026-09-01', 'fact', '2', 'Corrected source');
        $context = product_service::context($uuid, (int)$USER->id, $stat);
        $this->assertCount(2, $context['history']);
        $this->assertEquals(2, $context['series'][0]['fact']);
        $this->assertNull($context['series'][0]['plan']);
        $this->assertSame(2, $DB->count_records('local_kbacompany_statval'));
        $this->expectException(\moodle_exception::class);
        product_service::record_value($uuid, (int)$USER->id, $stat, '2026-09-01', 'fact', '3');
    }

    public function test_company_level_ckp_is_supported_and_company_scoped(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $company = identity_registry::company_uuid(repository::save_company((object)[
            'name' => 'Company CKP', 'active' => 1,
        ]));
        $productuuid = product_service::create_product($company, (int)$USER->id, [
            'code' => 'CKP-COMPANY', 'name' => 'Satisfied customers and sustainable profit',
            'acceptance' => 'Result is verified by customer and financial statistics',
            'targettype' => 'company', 'targetuuid' => $company,
        ]);
        $row = $DB->get_record('local_kbacompany_product', ['uuid' => $productuuid], '*', MUST_EXIST);
        $this->assertSame('company', $row->targettype);
        $this->assertSame($company, $row->targetuuid);
    }

    public function test_outside_company_target_rejected(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = identity_registry::company_uuid(repository::save_company((object)['name' => 'First', 'active' => 1]));
        $second = identity_registry::company_uuid(repository::save_company((object)['name' => 'Second', 'active' => 1]));
        $board = organizing_board_service::install_seven_division_template($first, (int)$USER->id);
        $this->expectException(\dml_missing_record_exception::class);
        product_service::create_product($second, (int)$USER->id, [
            'code' => 'BAD', 'name' => 'Bad target', 'acceptance' => 'Test',
            'targettype' => 'node', 'targetuuid' => $board['nodes'][0]['uuid'],
        ]);
    }
}
