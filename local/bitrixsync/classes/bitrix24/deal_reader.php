<?php
namespace local_bitrixsync\bitrix24;

defined('MOODLE_INTERNAL') || die();

class deal_reader {

    public function get(int $dealid): array {
        $binding = new adapter_binding();
        $result = $binding->get_deal($dealid);

        return [
            'id' => $dealid,
            'loaded' => false,
            'company_id' => 0,
            'contact_id' => 0,
            'stage_id' => '',
            'binding' => $result,
            'mode' => 'shadow_read',
        ];
    }
}
