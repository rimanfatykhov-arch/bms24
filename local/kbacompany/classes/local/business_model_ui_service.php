<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation schema and methodology hints for BMS Business Model Canvas 2.0. */
class business_model_ui_service {
    public const CONTRACT_VERSION = '0.1.0';

    public static function block_schema(): array {
        $out = [];
        foreach (business_model_crud_service::BLOCKS as $block) {
            $out[$block] = [
                'key' => $block,
                'label' => get_string('marketmodel_block_' . $block, 'local_kbacompany'),
                'purpose' => get_string('bmc_hint_' . $block . '_purpose', 'local_kbacompany'),
                'questions' => get_string('bmc_hint_' . $block . '_questions', 'local_kbacompany'),
                'mistake' => get_string('bmc_hint_' . $block . '_mistake', 'local_kbacompany'),
            ];
        }
        return $out;
    }

    public static function state_help(string $state): string {
        return get_string($state === 'target' ? 'bmc_help_target' : 'bmc_help_asis', 'local_kbacompany');
    }

    public static function marker_help(string $marker): string {
        return get_string('bmc_help_marker_' . $marker, 'local_kbacompany');
    }

    /** Render the canonical management trace As-Is → Target → Gap → Change → Indicator. */
    public static function gap_trace(array $gap, array $schemas, array $indicatorlabels): string {
        $block = $schemas[$gap['block']]['label'] ?? (string)$gap['block'];
        $indicator = $gap['indicatoruuid'] !== '' ? ($indicatorlabels[$gap['indicatoruuid']] ?? $gap['indicatoruuid']) : '—';
        $steps = [
            [
                'label' => get_string('bmcgap_trace_asis', 'local_kbacompany'),
                'code' => (string)($gap['asisitem']['stablecode'] ?? ''),
                'title' => (string)($gap['asisitem']['title'] ?? ''),
                'text' => (string)($gap['asisitem']['formulation'] ?? ''),
            ],
            [
                'label' => get_string('bmcgap_trace_target', 'local_kbacompany'),
                'code' => (string)($gap['targetitem']['stablecode'] ?? ''),
                'title' => (string)($gap['targetitem']['title'] ?? ''),
                'text' => (string)($gap['targetitem']['formulation'] ?? ''),
            ],
            [
                'label' => get_string('bmcgap_trace_gap', 'local_kbacompany'),
                'code' => (string)($gap['stablecode'] ?? ''),
                'title' => $block,
                'text' => (string)($gap['significance'] ?? ''),
            ],
            [
                'label' => get_string('bmcgap_trace_change', 'local_kbacompany'),
                'code' => '',
                'title' => get_string('bmcgap_trace_change_title', 'local_kbacompany'),
                'text' => get_string('bmcgap_trace_change_hint', 'local_kbacompany'),
            ],
            [
                'label' => get_string('bmcgap_trace_indicator', 'local_kbacompany'),
                'code' => '',
                'title' => $indicator,
                'text' => get_string('bmcgap_trace_indicator_hint', 'local_kbacompany'),
            ],
        ];
        $html = \html_writer::start_div('bms-gap-trace');
        foreach ($steps as $index => $step) {
            $meta = trim($step['code'] . ($step['code'] !== '' && $step['title'] !== '' ? ' — ' : '') . $step['title']);
            $html .= \html_writer::div(
                \html_writer::div($step['label'], 'bms-gap-trace-label') .
                ($meta !== '' ? \html_writer::div(s($meta), 'bms-gap-trace-title') : '') .
                ($step['text'] !== '' ? \html_writer::div(nl2br(s($step['text'])), 'bms-gap-trace-text') : ''),
                'bms-gap-trace-step');
            if ($index < count($steps) - 1) {
                $html .= \html_writer::div('→', 'bms-gap-trace-arrow', ['aria-hidden' => 'true']);
            }
        }
        $html .= \html_writer::end_div();
        return $html;
    }

}
