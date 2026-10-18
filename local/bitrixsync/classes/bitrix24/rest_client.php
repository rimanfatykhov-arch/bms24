<?php
namespace local_bitrixsync\bitrix24;
defined('MOODLE_INTERNAL') || die();
class rest_client {
    public function call(string $method, array $params = []): array {
        return ['method'=>$method,'params'=>$params,'mode'=>'shadow_read'];
    }
}
