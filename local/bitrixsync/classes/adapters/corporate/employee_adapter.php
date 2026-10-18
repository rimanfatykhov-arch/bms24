<?php
namespace local_bitrixsync\adapters\corporate;

defined('MOODLE_INTERNAL') || die();

class employee_adapter {
    /** @var api_client */
    private $api;

    public function __construct(?api_client $api = null) {
        $this->api = $api ?? new api_client();
    }

    public function sync(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $bitrixcontactid = 0
    ): array {
        return $this->api->sync_employee($userid, $bitrixcompanyid, $companytitle, $bitrixcontactid);
    }
}
