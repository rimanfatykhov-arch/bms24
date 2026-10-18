<?php
namespace local_bitrixsync\repositories;

defined('MOODLE_INTERNAL') || die();

class corporate_connection_repository {
    private const TABLE = 'local_bitrixsync_corpconn';

    public function create(array $data): int {
        global $DB;
        $record = (object)[
            'connectionid' => (string)$data['connectionid'],
            'component' => (string)$data['component'],
            'environment' => (string)$data['environment'],
            'tokenhash' => (string)$data['tokenhash'],
            'tokenfingerprint' => (string)$data['tokenfingerprint'],
            'clientversion' => (string)$data['clientversion'],
            'apiversion' => (string)$data['apiversion'],
            'timecreated' => time(),
            'lastseen' => time(),
            'revoked' => 0,
            'timerevoked' => 0,
        ];
        return (int)$DB->insert_record(self::TABLE, $record);
    }


    public function active_all(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return [];
        }
        return array_values($DB->get_records(self::TABLE, ['revoked' => 0], 'timecreated DESC'));
    }

    public function find_active_by_tokenhash(string $hash): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['tokenhash' => $hash, 'revoked' => 0]);
        return $record ?: null;
    }

    public function latest_active(string $environment = ''): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return null;
        }
        $conditions = ['revoked' => 0];
        if ($environment !== '') {
            $conditions['environment'] = $environment;
        }
        $records = $DB->get_records(self::TABLE, $conditions, 'timecreated DESC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        return reset($records) ?: null;
    }

    public function touch(int $id): void {
        global $DB;
        $DB->set_field(self::TABLE, 'lastseen', time(), ['id' => $id]);
    }

    public function revoke(int $id): void {
        global $DB;
        $DB->set_field(self::TABLE, 'revoked', 1, ['id' => $id]);
        $DB->set_field(self::TABLE, 'timerevoked', time(), ['id' => $id]);
    }

    public function count_active(): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::TABLE))) {
            return 0;
        }
        return (int)$DB->count_records(self::TABLE, ['revoked' => 0]);
    }
}
