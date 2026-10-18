<?php
namespace local_bitrixsync\adapters\corporate;

defined('MOODLE_INTERNAL') || die();

class company_adapter {
    /** @var api_client */
    private $api;

    public function __construct(?api_client $api = null) {
        $this->api = $api ?? new api_client();
    }

    public function sync(int $bitrixcompanyid, string $companytitle): array {
        return $this->api->sync_company($bitrixcompanyid, $companytitle);
    }

    public function snapshot(int $bitrixcompanyid): array {
        return $this->api->get_license_snapshot($bitrixcompanyid);
    }
}
