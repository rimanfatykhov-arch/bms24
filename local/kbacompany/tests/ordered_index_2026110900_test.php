<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

/**
 * Real SQL regression: ordered, nonunique task indexes and the 0900 checkpoint.
 * @package local_kbacompany
 * @coversNothing
 */
final class ordered_index_2026110900_test extends \advanced_testcase {
    private const TABLE = 'local_kbacompany_task';
    private const TARGET = ['companyuuid', 'status', 'duedate'];
    private const LEGACY = ['companyuuid', 'duedate', 'status'];
    private $originalindexes = [];

    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        require_once(__DIR__ . '/../db/upgradelib.php');
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/Percona physical-index drift fixtures');
        }
        $this->originalindexes = $DB->get_indexes(self::TABLE, false);
    }

    protected function tearDown(): void {
        try {
            $this->assertDebuggingNotCalled();
        } finally {
            parent::tearDown();
        }
    }

    /** Quote only database metadata or fixed test identifiers. */
    private function identifier(string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new \coding_exception('Unsafe test index identifier');
        }
        return '`' . $name . '`';
    }

    private function drop(string $name): void {
        global $DB;
        $DB->execute('DROP INDEX ' . $this->identifier($name) . ' ON {' . self::TABLE . '}');
    }

    private function create(string $name, array $columns, bool $unique = false): void {
        global $DB;
        $fields = implode(', ', array_map([$this, 'identifier'], $columns));
        $DB->execute('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->identifier($name) .
            ' ON {' . self::TABLE . '} (' . $fields . ')');
    }

    /** Undo fixture DDL independently of the migration under test. */
    private function restore(): void {
        global $DB;
        $actual = $DB->get_indexes(self::TABLE, false);
        foreach ($actual as $name => $index) {
            if (!isset($this->originalindexes[$name]) || $index != $this->originalindexes[$name]) {
                $this->drop($name);
            }
        }
        $actual = $DB->get_indexes(self::TABLE, false);
        foreach ($this->originalindexes as $name => $index) {
            if (!isset($actual[$name])) {
                $this->create($name, $index['columns'], (bool)$index['unique']);
            }
        }
    }

    private function remove_target(): void {
        global $DB;
        foreach ($DB->get_indexes(self::TABLE, false) as $name => $index) {
            if (array_values($index['columns']) === self::TARGET) { $this->drop($name); }
        }
    }

    private function matching(array $indexes, array $columns, bool $unique): array {
        return array_filter($indexes, static function(array $index) use ($columns, $unique): bool {
            return array_values($index['columns']) === $columns && (bool)$index['unique'] === $unique;
        });
    }

    private function rows(): array {
        global $DB;
        return $DB->get_records(self::TABLE, null, 'id');
    }

    private function seed(): void {
        global $DB;
        foreach (['Alpha', 'Beta'] as $offset => $name) {
            $companyid = \local_kbacorporate\local\repository::save_company((object)['name' => $name, 'active' => 1]);
            $company = \local_kbacompany\local\identity_registry::company_uuid($companyid);
            $DB->insert_record(self::TABLE, (object)['companyuuid' => $company,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $offset + 1),
                'title' => 'Preserve ' . $name, 'product' => 'Index repair fixture',
                'status' => 'open', 'duedate' => '2026-11-10', 'revision' => 7,
                'starttime' => '09:15', 'duetime' => '17:45']);
        }
    }

    public static function starting_version_provider(): array {
        return ['already0890' => [2026110890], 'import1879' => [2026101879], 'functional0804' => [2026110804]];
    }

    /** @dataProvider starting_version_provider */
    public function test_checkpoint_adds_exact_order_preserving_legacy_index_and_rows(int $oldversion): void {
        global $DB;
        try {
            $this->remove_target();
            $this->seed();
            $before = $DB->get_indexes(self::TABLE, false);
            $rows = $this->rows();
            $this->assertNotEmpty($this->matching($before, self::LEGACY, false));
            $this->assertEmpty($this->matching($before, self::TARGET, false));
            set_config('version', $oldversion, 'local_kbacompany');
            $this->assertTrue(xmldb_local_kbacompany_upgrade($oldversion));
            $this->assertSame('2026110906', (string)get_config('local_kbacompany', 'version'));
            $after = $DB->get_indexes(self::TABLE, false);
            $this->assertCount(1, $this->matching($after, self::TARGET, false));
            $this->assertCount(count($before) + 1, $after);
            foreach ($before as $name => $index) { $this->assertEquals($index, $after[$name]); }
            $this->assertEquals($rows, $this->rows());
            local_kbacompany_upgrade_ordered_task_index_2026110900();
            $this->assertEquals($after, $DB->get_indexes(self::TABLE, false));
            $this->assertTrue(xmldb_local_kbacompany_upgrade((int)get_config('local_kbacompany', 'version')));
            $this->assertEquals($after, $DB->get_indexes(self::TABLE, false));
            $this->assertEquals($rows, $this->rows());
        } finally {
            $this->restore();
        }
    }

    public function test_unique_exact_index_does_not_satisfy_nonunique_requirement(): void {
        global $DB;
        try {
            $this->remove_target();
            $this->seed();
            $this->create('qa0900_unique_exact', self::TARGET, true);
            $before = $DB->get_indexes(self::TABLE, false);
            $rows = $this->rows();
            local_kbacompany_upgrade_ordered_task_index_2026110900();
            $after = $DB->get_indexes(self::TABLE, false);
            $this->assertCount(1, $this->matching($after, self::TARGET, false));
            $this->assertCount(1, $this->matching($after, self::TARGET, true));
            $this->assertEquals($before['qa0900_unique_exact'], $after['qa0900_unique_exact']);
            $this->assertNotEmpty($this->matching($after, self::LEGACY, false));
            $this->assertEquals($rows, $this->rows());
        } finally {
            $this->restore();
        }
    }

    public function test_existing_exact_order_under_custom_name_is_noop(): void {
        global $DB;
        try {
            $this->remove_target();
            $this->create('qa0900_custom_exact', self::TARGET);
            $this->seed();
            $before = $DB->get_indexes(self::TABLE, false);
            $rows = $this->rows();
            local_kbacompany_upgrade_ordered_task_index_2026110900();
            $this->assertEquals($before, $DB->get_indexes(self::TABLE, false));
            $this->assertEquals($rows, $this->rows());
        } finally {
            $this->restore();
        }
    }

    public static function conflict_provider(): array {
        return ['wrong-order' => [false, false, false], 'wrong-uniqueness' => [true, false, false],
            'wrong-order-with-exact-elsewhere' => [false, true, false],
            'case-insensitive-name' => [false, false, true]];
    }

    /** @dataProvider conflict_provider */
    public function test_reserved_name_conflict_fails_without_savepoint_or_changes(
            bool $unique, bool $exactelsewhere, bool $uppercase): void {
        global $DB;
        try {
            $this->remove_target();
            $this->seed();
            $name = $DB->get_prefix() . 'bms_task_status_due_0900_ix';
            if ($uppercase) { $name = strtoupper($name); }
            $this->create($name, $unique ? self::TARGET : self::LEGACY, $unique);
            if ($exactelsewhere) { $this->create('qa0900_other_exact', self::TARGET); }
            $before = $DB->get_indexes(self::TABLE, false);
            $columns = $DB->get_columns(self::TABLE, false);
            $rows = $this->rows();
            set_config('version', 2026110890, 'local_kbacompany');
            $rejected = false;
            try {
                xmldb_local_kbacompany_upgrade(2026110890);
            } catch (\upgrade_exception $e) {
                $rejected = true;
                $this->assertStringContainsString('conflicting index name', $e->debuginfo);
            }
            $this->assertTrue($rejected);
            $this->assertSame('2026110890', (string)get_config('local_kbacompany', 'version'));
            $this->assertEquals($before, $DB->get_indexes(self::TABLE, false));
            $this->assertEquals($columns, $DB->get_columns(self::TABLE, false));
            $this->assertEquals($rows, $this->rows());
        } finally {
            $this->restore();
        }
    }
}
