<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation schema for BMS Financial Model CRUD. */
class financial_model_ui_service {
    public const CONTRACT_VERSION = '0.6.9.9.3';

    public static function scenario_form_schema(): array {
        return [
            [
                'key' => 'main',
                'fields' => ['stablecode', 'name', 'scenariotype', 'periodstart', 'periodend', 'currency'],
            ],
            [
                'key' => 'sources',
                'fields' => ['strategyuuid', 'businessmodeluuid'],
            ],
            [
                'key' => 'meaning',
                'fields' => ['description', 'assumptions'],
            ],
        ];
    }

    public static function center_form_schema(): array {
        return [
            [
                'key' => 'main',
                'fields' => ['stablecode', 'name', 'centertype', 'targetcompound'],
            ],
            [
                'key' => 'responsibility',
                'fields' => ['ownerpositionuuid', 'mainstatuuid', 'reviewperiod'],
            ],
            [
                'key' => 'governance',
                'fields' => ['resultdescription', 'authorityrule'],
            ],
        ];
    }

    public static function section_label_key(string $section): string {
        $map = [
            'main' => 'financialcrud_section_main',
            'sources' => 'financialcrud_section_sources',
            'meaning' => 'financialcrud_section_meaning',
            'responsibility' => 'financialcrud_section_responsibility',
            'governance' => 'financialcrud_section_governance',
            'history' => 'financialcrud_section_history',
            'advanced' => 'financialcrud_section_advanced',
        ];
        return $map[$section] ?? 'financialmodel_title';
    }

    public static function scenario_status_badge_class(string $status): string {
        if ($status === 'published') { return 'badge badge-success'; }
        if ($status === 'draft') { return 'badge badge-warning'; }
        return 'badge badge-secondary';
    }

    public static function center_status_badge_class(string $status): string {
        return self::scenario_status_badge_class($status);
    }
}
