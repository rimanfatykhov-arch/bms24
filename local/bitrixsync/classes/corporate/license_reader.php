<?php
namespace local_bitrixsync\corporate;
defined('MOODLE_INTERNAL') || die();
class license_reader {
    public function snapshot(int $id): array {
        return ['companyid'=>$id,'licenses'=>[],'mode'=>'read_only_shadow'];
    }
}
