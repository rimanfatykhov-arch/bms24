<?php
namespace local_bitrixsync\repositories;

defined('MOODLE_INTERNAL') || die();

class corporate_pairing_repository {
    private const TABLE = 'local_bitrixsync_pairing';

    public function create(string $codehash, string $environment, int $expires): int {
        global $DB;
        return (int)$DB->insert_record(self::TABLE, (object)[
            'codehash' => $codehash,
            'environment' => $environment,
            'timecreated' => time(),
            'timeexpires' => $expires,
            'timeused' => 0,
            'used' => 0,
            'attempts' => 0,
            'lastattempt' => 0,
        ]);
    }

    public function find_by_hash(string $hash): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['codehash' => $hash]);
        return $record ?: null;
    }

    public function mark_attempt(int $id): void {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $record->attempts = (int)$record->attempts + 1;
        $record->lastattempt = time();
        $DB->update_record(self::TABLE, $record);
    }

    public function mark_used(int $id): void {
        global $DB;
        $DB->set_field(self::TABLE, 'used', 1, ['id' => $id]);
        $DB->set_field(self::TABLE, 'timeused', time(), ['id' => $id]);
    }

    public function invalidate_active(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET used = 1, timeused = ? WHERE used = 0', [time()]);
    }
}
