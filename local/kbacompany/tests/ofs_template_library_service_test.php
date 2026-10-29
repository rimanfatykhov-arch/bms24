<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\ofs_template_library_service;
use local_kbacompany\local\ofs_visual_designer_service;

/** Regression coverage for built-in industry OFS templates. */
final class ofs_template_library_service_test extends \advanced_testcase {
    public function test_library_has_stable_industry_catalogue(): void {
        $keys = ofs_template_library_service::keys();
        $this->assertCount(8, $keys);
        $this->assertContains('tpl_distribution', $keys);
        $this->assertContains('tpl_dental', $keys);
        $this->assertContains('tpl_custombuild', $keys);
        $this->assertContains('tpl_meatretail', $keys);
        $this->assertContains('tpl_boat', $keys);
        $this->assertContains('tpl_apparel', $keys);
        $this->assertContains('tpl_electrical', $keys);
        $this->assertContains('tpl_cafe', $keys);
    }

    public function test_each_library_template_projects_into_bms_ofs_form(): void {
        foreach (ofs_template_library_service::keys() as $key) {
            $payload = ofs_template_library_service::payload($key);
            $poster = ofs_visual_designer_service::poster($payload);
            $this->assertCount(1, $poster['investment'], $key);
            $this->assertCount(1, $poster['strategic_office'], $key);
            $this->assertCount(7, $poster['strategic_functions'], $key);
            $this->assertCount(1, $poster['management'], $key);
            $this->assertCount(1, $poster['executive'], $key);
            $this->assertGreaterThanOrEqual(1, count($poster['management_units']), $key);
            $this->assertCount(6, $poster['departments'], $key);
            $this->assertNotSame('', $poster['companyproduct'], $key);
            foreach ($poster['departments'] as $department) {
                $this->assertNotSame('', $department['product'], $key);
            }
        }
    }

    public function test_template_preview_does_not_expose_drive_source_urls(): void {
        $preview = ofs_template_library_service::preview('tpl_distribution');
        $this->assertSame('tpl_distribution', $preview['key']);
        $json = json_encode($preview);
        $this->assertStringNotContainsString('drive.google.com', $json);
        $this->assertStringNotContainsString('1BOq7KnPp7hHeO-LvWHwCT5LcR_MLFLs5', $json);
    }
}
