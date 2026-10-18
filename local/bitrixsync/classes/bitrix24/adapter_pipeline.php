<?php
namespace local_bitrixsync\bitrix24;

defined('MOODLE_INTERNAL') || die();

/**
 * Alpha.6 adapter selection point.
 *
 * Keeps workflow independent from concrete REST implementation.
 */
class adapter_pipeline {
    public function candidates(): array {
        return [
            'deal_client' => class_exists('\\local_bitrixsync\\adapters\\bitrix24\\deal_client'),
            'client' => class_exists('\\local_bitrixsync\\adapters\\bitrix24\\client'),
            'corporate_client' => class_exists('\\local_bitrixsync\\adapters\\corporate\\api_client'),
        ];
    }
}
