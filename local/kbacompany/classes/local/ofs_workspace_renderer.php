<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Shared visual renderer for current and design OFS states. */
class ofs_workspace_renderer {
    public static function poster(array $poster, array $options = []): string {
        $state = (string)($options['state'] ?? 'current');
        $editable = !empty($options['editable']);
        $editurl = $options['editurl'] ?? null;
        $html = \html_writer::start_div('kbacompany-ofs-poster kbacompany-ofs-workspace-poster state-' . $state);
        $html .= \html_writer::start_div('kbacompany-ofs-poster-canvas');
        $html .= \html_writer::start_div('kbacompany-ofs-poster-brand');
        $html .= \html_writer::tag('strong', 'BMS', ['class' => 'kbacompany-ofs-logo']);
        $html .= \html_writer::span(get_string('ofsworkspace_unifiedstructure', 'local_kbacompany'), 'kbacompany-ofs-poster-name');
        $html .= \html_writer::end_div();

        $groups = [
            'strategic_office' => get_string('ofsdesigner_tier_strategic_office', 'local_kbacompany'),
            'management' => get_string('ofsdesigner_tier_management', 'local_kbacompany'),
            'executive' => get_string('ofsdesigner_tier_executive', 'local_kbacompany'),
            'departments' => get_string('ofsdesigner_departments', 'local_kbacompany'),
        ];
        foreach ($groups as $key => $label) {
            $items = $key === 'departments' ? ($poster['departments'] ?? []) : ($poster[$key] ?? []);
            if (!$items) { continue; }
            $html .= \html_writer::start_div('kbacompany-ofs-department-section');
            $html .= \html_writer::div($label, 'kbacompany-ofs-band-title');
            $html .= \html_writer::start_div($key === 'departments' ? 'kbacompany-ofs-departments' : 'kbacompany-ofs-grid');
            foreach ($items as $node) {
                $html .= self::card($node, $editable, $editurl);
            }
            $html .= \html_writer::end_div() . \html_writer::end_div();
        }
        $html .= \html_writer::start_div('kbacompany-ofs-company-product');
        $html .= \html_writer::div(get_string('ofsdesigner_companyproduct', 'local_kbacompany'), 'kbacompany-ofs-company-product-title');
        $companyproduct = !empty($poster['has_companyproduct']) ? s($poster['companyproduct']) :
            get_string('ofsdesigner_companyproduct_missing', 'local_kbacompany');
        if (!empty($poster['companyproduct_code'])) {
            $companyproduct = \html_writer::span(s($poster['companyproduct_code']), 'kbacompany-ofs-product-code') . ' ' . $companyproduct;
        }
        $html .= \html_writer::div($companyproduct, 'kbacompany-ofs-company-product-text');
        $html .= \html_writer::span(!empty($poster['companyproduct_canonical']) ?
            get_string('ofsworkspace_canonical', 'local_kbacompany') : get_string('ofsworkspace_legacyproduct', 'local_kbacompany'),
            'kbacompany-ofs-binding ' . (!empty($poster['companyproduct_canonical']) ? 'is-canonical' : 'is-warning'));
        $html .= \html_writer::end_div();
        $html .= \html_writer::end_div() . \html_writer::end_div();
        return $html;
    }

    private static function card(array $node, bool $editable, $editurl): string {
        $html = \html_writer::start_tag('article', ['class' => 'kbacompany-ofs-card']);
        $html .= \html_writer::div(s($node['code']), 'kbacompany-ofs-code');
        $html .= \html_writer::tag('h4', format_string($node['name']), ['class' => 'kbacompany-ofs-card-title']);
        $product = !empty($node['has_product']) ? s($node['product']) : get_string('ofsdesigner_product_missing', 'local_kbacompany');
        if (!empty($node['product_code'])) {
            $product = \html_writer::span(s($node['product_code']), 'kbacompany-ofs-product-code') . ' ' . $product;
        }
        $html .= \html_writer::div(get_string('ofsdesigner_result', 'local_kbacompany') . ' ' . $product, 'kbacompany-ofs-node-product');
        $html .= \html_writer::span(!empty($node['product_canonical']) ?
            get_string('ofsworkspace_canonical', 'local_kbacompany') : get_string('ofsworkspace_legacyproduct', 'local_kbacompany'),
            'kbacompany-ofs-binding ' . (!empty($node['product_canonical']) ? 'is-canonical' : 'is-warning'));
        if (!empty($node['positions'])) {
            $html .= \html_writer::div(get_string('design_positions', 'local_kbacompany') . ': ' . count($node['positions']), 'kbacompany-ofs-meta');
        }
        if (!empty($node['functions'])) {
            $html .= \html_writer::div(get_string('design_functions', 'local_kbacompany') . ': ' . count($node['functions']), 'kbacompany-ofs-meta');
        }
        if ($editable && $editurl) {
            $url = new \moodle_url($editurl, ['node' => $node['uuid']]);
            $html .= \html_writer::link($url, get_string('ofsdesigner_edit', 'local_kbacompany'),
                ['class' => 'btn btn-sm btn-link kbacompany-ofs-card-edit']);
        }
        $html .= \html_writer::end_tag('article');
        return $html;
    }
}
