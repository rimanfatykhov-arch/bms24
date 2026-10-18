<?php
namespace local_bitrixsync\repositories;

defined('MOODLE_INTERNAL') || die();

class corporate_pair_attempt_repository {
    private const TABLE = 'local_bitrixsync_pairattempt';

    public function state(string $iphash): \stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['iphash' => $iphash]);
        if ($record) {
            return $record;
        }
        $record = (object)[
            'iphash' => $iphash,
            'windowstart' => time(),
            'attempts' => 0,
            'blockeduntil' => 0,
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    public function fail(string $iphash, int $maxattempts = 5, int $window = 600, int $block = 600): \stdClass {
        global $DB;
        $record = $this->state($iphash);
        $now = time();
        if (($now - (int)$record->windowstart) > $window) {
            $record->windowstart = $now;
            $record->attempts = 0;
        }
        $record->attempts = (int)$record->attempts + 1;
        if ((int)$record->attempts >= $maxattempts) {
            $record->blockeduntil = $now + $block;
        }
        $record->timemodified = $now;
        $DB->update_record(self::TABLE, $record);
        return $record;
    }

    public function clear(string $iphash): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['iphash' => $iphash]);
    }
}
