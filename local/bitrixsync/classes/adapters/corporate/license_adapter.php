<?php
namespace local_bitrixsync\adapters\corporate;

defined('MOODLE_INTERNAL') || die();

class license_adapter {
    /** @var api_client */
    private $api;

    public function __construct(?api_client $api = null) {
        $this->api = $api ?? new api_client();
    }

    public function sync_package(
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid,
        int $seats,
        int $datefrom,
        int $dateto,
        string $dealid,
        string $productrowid
    ): array {
        return $this->api->sync_course_license(
            $bitrixcompanyid,
            $companytitle,
            $courseid,
            $groupid,
            $seats,
            $datefrom,
            $dateto,
            $dealid,
            $productrowid
        );
    }

    public function allocate(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid,
        string $dealid,
        string $productrowid
    ): array {
        return $this->api->assign_license_user(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            $dealid,
            $productrowid
        );
    }

    public function release(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid,
        string $dealid,
        string $productrowid
    ): array {
        return $this->api->release_license_user(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            $dealid,
            $productrowid
        );
    }
}
