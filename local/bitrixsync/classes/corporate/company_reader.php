<?php
namespace local_bitrixsync\corporate;
defined('MOODLE_INTERNAL') || die();
class company_reader {
    public function get_by_bitrix_id(int $id): array {
        return ['bitrix_company_id'=>$id,'corporate_company_id'=>0,'mode'=>'read_only_shadow'];
    }
}
