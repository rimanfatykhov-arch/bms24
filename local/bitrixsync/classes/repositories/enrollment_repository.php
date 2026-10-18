<?php
namespace local_bitrixsync\repositories;

defined('MOODLE_INTERNAL') || die();

class enrollment_repository {
    public function find_existing(
        int $dealid,
        int $userid,
        int $courseid,
        ?int $productrowid = null
    ) {
        global $DB;

        $conditions = [
            'dealid' => $dealid,
            'userid' => $userid,
            'courseid' => $courseid,
        ];

        if ($productrowid !== null && $productrowid > 0) {
            $conditions['productrowid'] = $productrowid;
        }

        return $DB->get_record(
            'local_bitrixsync_enrollment',
            $conditions,
            '*',
            IGNORE_MULTIPLE
        );
    }

    public function create(array $data): int {
        global $DB;

        $now = time();

        $record = (object)array_merge([
            'dealid' => null,
            'companyid' => null,
            'contactid' => null,
            'userid' => null,
            'productid' => null,
            'productrowid' => null,
            'courseid' => null,
            'groupid' => null,
            'corp_license_id' => null,
            'corp_allocation_id' => null,
            'corp_status' => null,
            'status' => 'pending',
            'error' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $data);

        return (int)$DB->insert_record(
            'local_bitrixsync_enrollment',
            $record
        );
    }

    public function update_status(
        int $id,
        string $status,
        ?string $error = null
    ): void {
        global $DB;

        $DB->update_record(
            'local_bitrixsync_enrollment',
            (object)[
                'id' => $id,
                'status' => $status,
                'error' => $error,
                'timemodified' => time(),
            ]
        );
    }

    public function update_corporate(
        int $id,
        ?int $licenseid,
        ?int $allocationid,
        string $status
    ): void {
        global $DB;

        $DB->update_record(
            'local_bitrixsync_enrollment',
            (object)[
                'id' => $id,
                'corp_license_id' => $licenseid ?: null,
                'corp_allocation_id' => $allocationid ?: null,
                'corp_status' => $status,
                'timemodified' => time(),
            ]
        );
    }
}
