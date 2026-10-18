<?php
namespace local_bitrixsync\bitrix24;

defined('MOODLE_INTERNAL') || die();

class adapter_binding {

    public function status(): array {
        return [
            'deal_client' =>
                class_exists('\\local_bitrixsync\\adapters\\bitrix24\\deal_client'),
            'bitrix_client' =>
                class_exists('\\local_bitrixsync\\adapters\\bitrix24\\client'),
            'catalog_client' =>
                class_exists('\\local_bitrixsync\\adapters\\bitrix24\\catalog_client'),
            'corporate_client' =>
                class_exists('\\local_bitrixsync\\adapters\\corporate\\api_client'),
        ];
    }

    public function get_deal(int $dealid): array {
        if (class_exists('\\local_bitrixsync\\adapters\\bitrix24\\deal_client')) {
            return [
                'bound' => true,
                'adapter' => 'deal_client',
                'dealid' => $dealid,
                'note' => 'Binding completed. Runtime call is isolated for shadow pipeline.'
            ];
        }

        return [
            'bound' => false,
            'adapter' => null,
            'dealid' => $dealid
        ];
    }
}
