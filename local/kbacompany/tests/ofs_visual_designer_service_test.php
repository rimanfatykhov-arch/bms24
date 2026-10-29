<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\design_service;
use local_kbacompany\local\ofs_visual_designer_service;

/** Regression coverage for the universal BMS OFS visual form. */
final class ofs_visual_designer_service_test extends \advanced_testcase {
    public function test_universal_template_projects_into_expected_visual_tiers(): void {
        $payload = design_service::template('bms_ofs');
        $poster = ofs_visual_designer_service::poster($payload);

        $this->assertCount(1, $poster['investment']);
        $this->assertCount(1, $poster['strategic_office']);
        $this->assertCount(7, $poster['strategic_functions']);
        $this->assertCount(1, $poster['management']);
        $this->assertCount(5, $poster['management_units']);
        $this->assertCount(1, $poster['executive']);
        $this->assertCount(6, $poster['departments']);
        $this->assertSame('', $poster['companyproduct']);
    }

    public function test_company_and_department_products_are_visual_results(): void {
        $payload = design_service::template('bms_ofs');
        $payload['companyproduct'] = 'Оплаченный и принятый клиентом результат бизнеса';
        foreach ($payload['nodes'] as &$node) {
            if (($node['visualtier'] ?? '') === 'department') {
                $node['product'] = 'ЦКП департамента';
                break;
            }
        }
        unset($node);

        $poster = ofs_visual_designer_service::poster($payload);
        $this->assertSame('Оплаченный и принятый клиентом результат бизнеса', $poster['companyproduct']);
        $this->assertTrue($poster['has_companyproduct']);
        $this->assertSame('ЦКП департамента', $poster['departments'][0]['product']);
        $this->assertTrue($poster['departments'][0]['has_product']);
    }
}
