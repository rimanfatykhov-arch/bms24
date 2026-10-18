<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class category_mapper {
    public function get(int $categoryid) {
        global $DB;
        return $DB->get_record('local_bitrixsync_catmap', ['categoryid' => $categoryid]);
    }

    public function save(
        int $categoryid,
        int $sectionid,
        int $parentsectionid,
        int $iblockid,
        string $name,
        string $status = 'synced',
        ?string $error = null
    ): int {
        global $DB;

        $now = time();
        $record = $this->get($categoryid);

        if ($record) {
            $record->bitrix_section_id = $sectionid;
            $record->bitrix_parent_section_id = $parentsectionid ?: null;
            $record->iblockid = $iblockid ?: null;
            $record->name = $name;
            $record->status = $status;
            $record->last_sync = $now;
            $record->error = $error;
            $record->timemodified = $now;

            $this->apply_legacy_columns(
                $record,
                $sectionid,
                $parentsectionid,
                $now
            );

            $DB->update_record('local_bitrixsync_catmap', $record);
            return (int)$record->id;
        }

        $record = (object)[
            'categoryid' => $categoryid,
            'bitrix_section_id' => $sectionid,
            'bitrix_parent_section_id' => $parentsectionid ?: null,
            'iblockid' => $iblockid ?: null,
            'name' => $name,
            'status' => $status,
            'last_sync' => $now,
            'error' => $error,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        // Historical production tables still have NOT NULL legacy columns
        // (sectionid, parentcategoryid, lastsync) with no defaults. Until those
        // columns are retired, new mappings must dual-write both schemas.
        $this->apply_legacy_columns(
            $record,
            $sectionid,
            $parentsectionid,
            $now
        );

        return (int)$DB->insert_record(
            'local_bitrixsync_catmap',
            $record
        );
    }

    public function mark_error(int $categoryid, string $message): void {
        global $DB;

        $record = $this->get($categoryid);
        if (!$record) {
            return;
        }

        $record->status = 'error';
        $record->error = $message;
        $record->timemodified = time();

        // Existing legacy fields remain on the loaded record and are preserved.
        $DB->update_record('local_bitrixsync_catmap', $record);
    }

    private function apply_legacy_columns(
        \stdClass $record,
        int $sectionid,
        int $parentsectionid,
        int $syncat
    ): void {
        global $DB;

        $columns = $DB->get_columns('local_bitrixsync_catmap');

        if (isset($columns['sectionid'])) {
            $record->sectionid = $sectionid;
        }

        if (isset($columns['parentcategoryid'])) {
            // Legacy column is NOT NULL in production.
            $record->parentcategoryid = max(0, $parentsectionid);
        }

        if (isset($columns['lastsync'])) {
            $record->lastsync = $syncat;
        }
    }
}
