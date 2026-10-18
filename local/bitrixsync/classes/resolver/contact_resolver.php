<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class contact_resolver {
    public function resolve(int $bitrixcontactid): array {
        return [
            'bitrix_contact_id' => $bitrixcontactid,
            'userid' => 0,
            'status' => 'shadow_lookup',
        ];
    }
}
