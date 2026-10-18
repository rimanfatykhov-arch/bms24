<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\legacy_drawio_import_service;

/** Regression coverage for safe legacy Draw.io analysis. */
final class legacy_drawio_import_service_test extends \advanced_testcase {
    private function graph_xml(): string {
        return '<mxGraphModel><root>' .
            '<mxCell id="0"/><mxCell id="1" parent="0"/>' .
            '<mxCell id="title" value="ОРГАНИЗУЮЩАЯ СХЕМА. Тестовая компания" vertex="1" parent="1" style="fillColor=#ffffff;fontSize=18"><mxGeometry x="100" y="20" width="1200" height="70" as="geometry"/></mxCell>' .
            '<mxCell id="a" value="УЧРЕДИТЕЛИ" vertex="1" parent="1" style="fillColor=#e8b632;strokeColor=#111111"><mxGeometry x="100" y="120" width="500" height="100" as="geometry"/></mxCell>' .
            '<mxCell id="b" value="ИСПОЛНИТЕЛЬНЫЙ ДИРЕКТОР" vertex="1" parent="1"><mxGeometry x="800" y="300" width="500" height="100" as="geometry"/></mxCell>' .
            '<mxCell id="c" value="1 ОТДЕЛЕНИЕ ПРОДАЖ РУКОВОДИТЕЛЬ" vertex="1" parent="1"><mxGeometry x="800" y="700" width="500" height="120" as="geometry"/></mxCell>' .
            '<mxCell id="p1" value="ЦКП ОТДЕЛЕНИЯ: Оплаченные заказы целевых клиентов" vertex="1" parent="1"><mxGeometry x="800" y="1200" width="500" height="100" as="geometry"/></mxCell>' .
            '<mxCell id="p2" value="ЦКП: Ценный конечный продукт компании" vertex="1" parent="1"><mxGeometry x="100" y="1400" width="1200" height="100" as="geometry"/></mxCell>' .
            '<mxCell id="d" value="Обычная заметка" vertex="1" parent="1"><mxGeometry x="20" y="900" width="80" height="20" as="geometry"/></mxCell>' .
            '<mxCell id="e1" edge="1" parent="1" source="b" target="c"><mxGeometry relative="1" as="geometry"/></mxCell>' .
            '</root></mxGraphModel>';
    }

    public function test_plain_drawio_extracts_full_analysis_graph(): void {
        $xml = '<?xml version="1.0"?><mxfile version="15.8.8"><diagram name="Page 1">' .
            $this->graph_xml() . '</diagram></mxfile>';
        $parsed = legacy_drawio_import_service::parse($xml);
        $this->assertSame(7, $parsed['vertexcount']);
        $this->assertSame(1, $parsed['edgecount']);
        $this->assertSame(10, $parsed['cellcount']);
        $this->assertCount(7, $parsed['candidates']);
        $this->assertArrayHasKey('stylemeta', $parsed['cells']['a']);
        $this->assertSame('#e8b632', $parsed['cells']['a']['stylemeta']['fillColor']);
        $this->assertSame('b', $parsed['cells']['e1']['source']);
        $this->assertSame('c', $parsed['cells']['e1']['target']);
        $this->assertContains('e1', $parsed['cells']['b']['outgoing']);
        $this->assertContains('e1', $parsed['cells']['c']['incoming']);

        $types = array_column($parsed['candidates'], 'suggestedtype', 'cellid');
        $this->assertSame('company', $types['title']);
        $this->assertSame('office', $types['a']);
        $this->assertSame('office', $types['b']);
        $this->assertSame('department', $types['c']);
        $this->assertSame('ckp', $types['p1']);
        $this->assertGreaterThan(0.9, $parsed['candidates']['c']['confidence']);
        $this->assertSame('Ценный конечный продукт компании', $parsed['companyproduct']);
        $this->assertSame('Оплаченные заказы целевых клиентов', $parsed['candidates']['c']['suggestedproduct']);
    }

    public function test_compressed_diagram_is_supported(): void {
        $encoded = base64_encode(gzdeflate(rawurlencode($this->graph_xml())));
        $xml = '<?xml version="1.0"?><mxfile version="15.8.8"><diagram name="Compressed">' .
            $encoded . '</diagram></mxfile>';
        $parsed = legacy_drawio_import_service::parse($xml);
        $this->assertSame('Compressed', $parsed['page']);
        $this->assertSame(7, $parsed['vertexcount']);
        $this->assertSame(1, $parsed['edgecount']);
    }

    public function test_doctype_and_entities_are_rejected(): void {
        $this->expectException(\moodle_exception::class);
        legacy_drawio_import_service::parse('<?xml version="1.0"?><!DOCTYPE mxfile [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><mxfile><diagram>&xxe;</diagram></mxfile>');
    }

    public function test_contract_requires_reviewed_normalized_template(): void {
        $contract = legacy_drawio_import_service::contract();
        $this->assertContains('source_library_is_never_modified', $contract['rules']);
        $this->assertContains('human_confirmation_required_before_draft_creation', $contract['rules']);
        $this->assertContains('import_creates_normalized_template_draft_not_company_project', $contract['rules']);
        $this->assertContains('template_must_be_reviewed_before_available', $contract['rules']);
        $this->assertContains('drawio_does_not_become_source_of_truth', $contract['rules']);
        $this->assertContains('compound_legacy_blocks_are_guidance_not_silent_objects', $contract['rules']);
        $this->assertContains('ckp_parenting_prefers_semantic_band_and_column_alignment', $contract['rules']);
    }

    public function test_real_world_column_normalization_hints_and_ckp_parenting(): void {
        $graph = '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>' .
            '<mxCell id="company" value="ОРГАНИЗУЮЩАЯ СХЕМА. Тест" vertex="1" parent="1"><mxGeometry x="0" y="20" width="1200" height="80" as="geometry"/></mxCell>' .
            '<mxCell id="dept" value="1 ОТДЕЛЕНИЕ РАСПРОСТРАНЕНИЯ РУКОВОДИТЕЛЬ" vertex="1" parent="1"><mxGeometry x="100" y="200" width="500" height="100" as="geometry"/></mxCell>' .
            '<mxCell id="unit" value="1 ОТДЕЛ МАРКЕТИНГА" vertex="1" parent="1"><mxGeometry x="120" y="400" width="175" height="120" as="geometry"/></mxCell>' .
            '<mxCell id="section" value="СЕКЦИЯ РЕКЛАМЫ&lt;br&gt;Администратор секции&lt;br&gt;Отв. за рекламные кампании" vertex="1" parent="1"><mxGeometry x="120" y="600" width="175" height="180" as="geometry"/></mxCell>' .
            '<mxCell id="unitckp" value="ЦКП: Завершенные кампании" vertex="1" parent="1"><mxGeometry x="120" y="1000" width="175" height="180" as="geometry"/></mxCell>' .
            '<mxCell id="deptckp" value="ЦКП ОТДЕЛЕНИЯ: Доход компании" vertex="1" parent="1"><mxGeometry x="100" y="1220" width="500" height="120" as="geometry"/></mxCell>' .
            '<mxCell id="companyckp" value="ЦКП: Ценный конечный продукт компании" vertex="1" parent="1"><mxGeometry x="0" y="1420" width="1200" height="120" as="geometry"/></mxCell>' .
            '</root></mxGraphModel>';
        $xml = '<?xml version="1.0"?><mxfile version="15.8.8"><diagram name="Legacy">' . $graph . '</diagram></mxfile>';
        $parsed = legacy_drawio_import_service::parse($xml);

        $this->assertSame('unit', $parsed['candidates']['unitckp']['suggestedparent']);
        $this->assertSame('dept', $parsed['candidates']['deptckp']['suggestedparent']);
        $this->assertSame('company', $parsed['candidates']['companyckp']['suggestedparent']);
        $this->assertSame(1, $parsed['candidates']['section']['normalizationhints']['compound']);
        $this->assertContains('embedded_roles', $parsed['candidates']['section']['normalizationhints']['codes']);
        $this->assertContains('embedded_responsibilities', $parsed['candidates']['section']['normalizationhints']['codes']);
        $this->assertGreaterThan(0, $parsed['normalization']['needsreview']);
        $this->assertGreaterThan(0, $parsed['normalization']['productcoverage']);
    }
}
