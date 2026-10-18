<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class product_mapper {
    public function get_course_by_product(int $productid, ?string $productname = null) {
        global $DB;

        $mapping = $DB->get_record(
            'local_bitrixsync_productmap',
            ['bitrix_product_id' => $productid, 'active' => 1],
            '*',
            IGNORE_MULTIPLE
        );

        if ($mapping) {
            return $mapping;
        }

        // Compatibility only. Moodle Master Catalog does not rely on this.
        if (!get_config('local_bitrixsync', 'auto_map_by_name') ||
                $productname === null || trim($productname) === '') {
            return false;
        }

        $course = $DB->get_record(
            'course',
            ['fullname' => trim($productname)],
            'id,shortname,category',
            IGNORE_MULTIPLE
        );

        if (!$course) {
            return false;
        }

        $this->bind_course(
            (int)$course->id,
            $productid,
            0,
            0,
            'compatibility'
        );

        return $DB->get_record(
            'local_bitrixsync_productmap',
            ['bitrix_product_id' => $productid, 'courseid' => $course->id],
            '*',
            IGNORE_MULTIPLE
        );
    }

    public function get_by_course(int $courseid) {
        global $DB;

        $records = $DB->get_records(
            'local_bitrixsync_productmap',
            ['courseid' => $courseid],
            'id DESC',
            '*',
            0,
            1
        );

        return $records ? reset($records) : false;
    }

    public function bind_course(
        int $courseid,
        int $productid,
        int $iblockid,
        int $sectionid,
        string $status = 'synced',
        ?string $error = null
    ): int {
        global $DB;

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,category',
            MUST_EXIST
        );

        $now = time();
        $existing = $this->get_by_course($courseid);

        if ($existing) {
            $existing->bitrix_product_id = $productid;
            $existing->coursecode = $course->shortname;
            $existing->categoryid = $course->category;
            $existing->bitrix_section_id = $sectionid ?: null;
            $existing->iblockid = $iblockid ?: null;
            $existing->source = 'moodle';
            $existing->status = $status;
            $existing->active = 1;
            $existing->last_sync = $now;
            $existing->error = $error;
            $existing->timemodified = $now;
            $DB->update_record('local_bitrixsync_productmap', $existing);
            return (int)$existing->id;
        }

        return (int)$DB->insert_record('local_bitrixsync_productmap', (object)[
            'bitrix_product_id' => $productid,
            'courseid' => $courseid,
            'coursecode' => $course->shortname,
            'categoryid' => $course->category,
            'bitrix_section_id' => $sectionid ?: null,
            'iblockid' => $iblockid ?: null,
            'source' => 'moodle',
            'status' => $status,
            'active' => 1,
            'last_sync' => $now,
            'error' => $error,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public function mark_error(int $courseid, string $message): void {
        global $DB;

        $existing = $this->get_by_course($courseid);
        if (!$existing) {
            return;
        }

        $existing->status = 'error';
        $existing->error = $message;
        $existing->timemodified = time();
        $DB->update_record('local_bitrixsync_productmap', $existing);
    }

    public function map_product(int $productid, int $courseid): int {
        return $this->bind_course($courseid, $productid, 0, 0, 'manual');
    }

    public function remove_by_product(int $productid): void {
        global $DB;
        $DB->delete_records('local_bitrixsync_productmap', ['bitrix_product_id' => $productid]);
    }

    public function delete_mapping(int $id): void {
        global $DB;
        $DB->delete_records('local_bitrixsync_productmap', ['id' => $id]);
    }
}
