<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/**
 * Real installed-schema regression for field parity through the 0900 checkpoint.
 *
 * This covers a simulated missing-field state on PHPUnit's installed database.
 * Historical installation followed by the complete CLI upgrade is a separate gate.
 *
 * @package local_kbacompany
 * @coversNothing
 */
final class schema_parity_2026110890_test extends \advanced_testcase {
    private const FIELDS = [
        ['local_kbacompany_task', 'starttime', '5', '', 'startdate'],
        ['local_kbacompany_task', 'duetime', '5', '', 'duedate'],
        ['local_kbacompany_deviation', 'executionstatus', '16', 'pending', 'duedate'],
    ];

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        // MySQL/MariaDB DDL implicitly commits. Do not use transaction reset.
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        require_once(__DIR__ . '/../db/upgradelib.php');
    }

    protected function tearDown(): void {
        try {
            // Fail inside PHPUnit's lifecycle so Moodle still resets the DB.
            // Do not accept or hide debugging emitted by the actual repair.
            $this->assertDebuggingNotCalled();
        } finally {
            parent::tearDown();
        }
    }

    private function definition(array $spec): \xmldb_field {
        // XMLDB represents an empty CHAR default as null; the SQL generator
        // creates the database-specific empty default for a NOT NULL field.
        return new \xmldb_field($spec[1], XMLDB_TYPE_CHAR, $spec[2], null,
            XMLDB_NOTNULL, null, $spec[3] === '' ? null : $spec[3], $spec[4]);
    }

    /** Restore schema even when a test assertion or the repair itself fails. */
    private function restore_fields(): void {
        global $DB;
        $manager = $DB->get_manager();
        foreach (self::FIELDS as $spec) {
            $table = new \xmldb_table($spec[0]);
            $field = $this->definition($spec);
            if ($manager->field_exists($table, $field)) {
                $manager->drop_field($table, $field);
            }
            $manager->add_field($table, $field);
        }
    }

    private function remove_field(int $index): void {
        global $DB;
        $spec = self::FIELDS[$index];
        $DB->get_manager()->drop_field(new \xmldb_table($spec[0]), $this->definition($spec));
    }

    private function fixtures(int $mask): void {
        global $DB;
        foreach (['Alpha', 'Beta'] as $index => $name) {
            $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
            $company = identity_registry::company_uuid($companyid);
            $task = (object)['companyuuid' => $company,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                'title' => 'Keep task ' . $name, 'product' => 'Preserved product ' . $name,
                'startdate' => '2026-11-08', 'duedate' => '2026-11-09',
                'revision' => 7, 'planminutes' => 25 + $index];
            if ($mask & 1) { $task->starttime = $index ? '11:20' : '09:15'; }
            if ($mask & 2) { $task->duetime = $index ? '18:30' : '17:45'; }
            $DB->insert_record('local_kbacompany_task', $task);
            $deviation = (object)['companyuuid' => $company,
                'uuid' => sprintf('00000000-0000-4000-9000-%012d', $index + 1),
                'observedon' => '2026-11-08', 'title' => 'Keep deviation ' . $name,
                'evidence' => 'Preserved evidence ' . $name, 'revision' => 3];
            if ($mask & 4) { $deviation->executionstatus = $index ? 'active' : 'done'; }
            $DB->insert_record('local_kbacompany_deviation', $deviation);
        }
    }

    private function snapshot(): array {
        global $DB;
        $result = [];
        foreach (['local_kbacompany_task', 'local_kbacompany_deviation'] as $table) {
            $result[$table] = ['columns' => $DB->get_columns($table, false),
                'indexes' => $DB->get_indexes($table, false),
                'rows' => $DB->get_records($table, null, 'id')];
        }
        return $result;
    }

    public static function presence_provider(): array {
        $cases = [];
        foreach ([2026101879, 2026110804, 2026110890] as $oldversion) {
            for ($mask = 0; $mask < 8; $mask++) {
                $cases[$oldversion . '-presence-' . $mask] = [$mask, $oldversion];
            }
        }
        return $cases;
    }

    /** @dataProvider presence_provider */
    public function test_checkpoint_preserves_tenants_and_repairs_every_partial_state(int $mask, int $oldversion): void {
        global $DB;
        try {
            foreach (self::FIELDS as $index => $spec) {
                if (!($mask & (1 << $index))) { $this->remove_field($index); }
            }
            $this->fixtures($mask);
            $before = $this->snapshot();
            set_config('version', $oldversion, 'local_kbacompany');
            $this->assertTrue(xmldb_local_kbacompany_upgrade($oldversion));
            $this->assertSame('2026110902', (string)get_config('local_kbacompany', 'version'));
            $after = $this->snapshot();
            foreach (self::FIELDS as $index => [$table, $name, $length, $default]) {
                $column = $after[$table]['columns'][$name];
                $this->assertSame('C', $column->meta_type);
                $this->assertSame((int)$length, (int)$column->max_length);
                $this->assertTrue((bool)$column->not_null);
                $this->assertSame($default, $column->default_value);
                foreach ($after[$table]['rows'] as $id => $row) {
                    $expected = ($mask & (1 << $index)) ? $before[$table]['rows'][$id]->$name : $default;
                    $this->assertSame($expected, $row->$name);
                }
            }
            foreach ($before as $table => $state) {
                $this->assertCount(2, $after[$table]['rows']);
                $this->assertEquals($state['indexes'], $after[$table]['indexes']);
                $tenants = [];
                foreach ($state['rows'] as $id => $row) {
                    $tenants[$row->companyuuid] = true;
                    foreach ((array)$row as $field => $value) {
                        $this->assertSame($value, $after[$table]['rows'][$id]->$field, "$table.$field preserved");
                    }
                }
                $this->assertCount(2, $tenants);
                // A new row which omits the repaired fields receives their real DB defaults.
                $new = clone reset($after[$table]['rows']);
                unset($new->id);
                $new->uuid = '00000000-0000-4000-a000-000000000001';
                foreach (self::FIELDS as [$target, $field]) {
                    if ($target === $table) { unset($new->$field); }
                }
                $id = $DB->insert_record($table, $new);
                $inserted = $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
                foreach (self::FIELDS as [$target, $field, $length, $default]) {
                    if ($target === $table) { $this->assertSame($default, $inserted->$field); }
                }
            }
            $once = $this->snapshot();
            local_kbacompany_upgrade_schema_parity_2026110890();
            $this->assertEquals($once, $this->snapshot(), 'Helper is idempotent');
            $this->assertTrue(xmldb_local_kbacompany_upgrade((int)get_config('local_kbacompany', 'version')));
            $this->assertEquals($once, $this->snapshot(), 'Applied checkpoint leaves schema and data unchanged');
        } finally {
            $this->restore_fields();
        }
    }

    public static function incompatible_provider(): array {
        return ['length' => ['length'], 'nullable' => ['nullable'], 'default' => ['default'],
            'type' => ['type'], 'no-default' => ['no-default']];
    }

    /** @dataProvider incompatible_provider */
    public function test_bad_later_definition_prevents_ddl_and_savepoint(string $drift): void {
        global $DB;
        try {
            $this->remove_field(0);
            $this->fixtures(6);
            $table = new \xmldb_table('local_kbacompany_deviation');
            $field = $this->definition(self::FIELDS[2]);
            if ($drift === 'length') {
                $field->setLength('20');
                $DB->get_manager()->change_field_precision($table, $field);
            } else if ($drift === 'nullable') {
                $field->setNotNull(false);
                $DB->get_manager()->change_field_notnull($table, $field);
            } else if ($drift === 'default') {
                $field->setDefault('drift');
                $DB->get_manager()->change_field_default($table, $field);
            } else if ($drift === 'type') {
                $DB->get_manager()->drop_field($table, $field);
                $field = new \xmldb_field('executionstatus', XMLDB_TYPE_INTEGER, '10', null,
                    XMLDB_NOTNULL, null, '0', 'duedate');
                $DB->get_manager()->add_field($table, $field);
            } else {
                if ($DB->get_dbfamily() !== 'mysql') { $this->markTestSkipped('MySQL/MariaDB drift SQL'); }
                $DB->execute('ALTER TABLE {local_kbacompany_deviation} ALTER COLUMN executionstatus DROP DEFAULT');
            }
            $before = $this->snapshot();
            set_config('version', 2026101879, 'local_kbacompany');
            $rejected = false;
            try {
                xmldb_local_kbacompany_upgrade(2026101879);
            } catch (\upgrade_exception $e) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Incompatible definition must fail closed');
            // Earlier version-only checkpoints may complete; failed schema checkpoint must not.
            $this->assertSame('2026110804', (string)get_config('local_kbacompany', 'version'));
            $this->assertEquals($before, $this->snapshot(), 'Preflight rejects before any schema/data change');
            $this->assertFalse($DB->get_manager()->field_exists(new \xmldb_table('local_kbacompany_task'),
                new \xmldb_field('starttime')));
        } finally {
            $this->restore_fields();
        }
    }
}
