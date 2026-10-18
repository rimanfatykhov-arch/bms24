<?php
namespace local_bitrixsync\bitrix24;

defined('MOODLE_INTERNAL') || die();

class product_rows_reader {
    public function get(int $dealid): array {
        $rest = new legacy_rest_bridge();

        return [
            'dealid' => $dealid,
            'rows' => [],
            'rest' => $rest->call('crm.deal.productrows.get', [
                'ID' => $dealid,
            ]),
            'mode' => 'shadow_read',
        ];
    }
}
