<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\design_service;
use local_kbacompany\local\ofs_export_service;
use local_kbacompany\local\ofs_visual_designer_service;

/** Regression coverage for OFS vector/export projection. */
final class ofs_export_service_test extends \advanced_testcase {
    public function test_svg_and_drawio_use_same_visual_nodes(): void {
        $payload = design_service::template('bms_ofs');
        $payload['companyproduct'] = 'Ценный конечный продукт компании';
        $poster = ofs_visual_designer_service::poster($payload);
        $layout = ofs_export_service::layout($poster, 7);

        $this->assertGreaterThan(0, count($layout['nodes']));
        $svg = ofs_export_service::svg($layout, 'Test OFS');
        $drawio = ofs_export_service::drawio($layout, 'Test OFS');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Ценный конечный продукт компании', $svg);
        $this->assertStringContainsString('<mxGraphModel', $drawio);
        $this->assertStringContainsString('bmsUuid=', $drawio);
        $this->assertStringContainsString('bmsRevision="7"', $drawio);
    }

    public function test_tcpdf_svg_forces_unicode_dejavusans_font(): void {
        $payload = design_service::template('bms_ofs');
        $payload['companyproduct'] = 'Ценный конечный продукт компании';
        $poster = ofs_visual_designer_service::poster($payload);
        $layout = ofs_export_service::layout($poster, 9);

        $svg = ofs_export_service::tcpdf_svg($layout, 'ОФС АЛТРЕЙД');
        $this->assertStringContainsString('font-family="dejavusans"', $svg);
        $this->assertStringContainsString('Ценный конечный продукт компании', $svg);
        $this->assertStringNotContainsString('font-family:DejaVu Sans,Arial,sans-serif', $svg);
    }

    public function test_pdf_papers_include_auto_and_standard_sizes(): void {
        $layout = ['width' => 1600, 'height' => 1000];
        $a3 = ofs_export_service::pdf_page('A3', $layout);
        $auto = ofs_export_service::pdf_page('AUTO', $layout);
        $this->assertSame(420.0, $a3['width']);
        $this->assertSame(297.0, $a3['height']);
        $this->assertTrue($auto['custom']);
        $this->assertGreaterThan(0, $auto['width']);
        $this->assertGreaterThan(0, $auto['height']);
    }
}
