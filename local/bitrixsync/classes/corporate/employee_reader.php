<?php
namespace local_bitrixsync\corporate;
defined('MOODLE_INTERNAL') || die();
class employee_reader {
    public function get(int $companyid,int $userid): array {
        return ['companyid'=>$companyid,'userid'=>$userid,'mode'=>'read_only_shadow'];
    }
}
