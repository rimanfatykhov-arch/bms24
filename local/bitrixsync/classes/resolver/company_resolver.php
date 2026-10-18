<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class company_resolver {
    public function resolve(int $bitrixcompanyid): array {
        return [
            'bitrix_company_id' => $bitrixcompanyid,
            'corporate_company_id' => 0,
            'status' => 'shadow_lookup',
        ];
    }
}
