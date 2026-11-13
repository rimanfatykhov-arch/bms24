<?php
/** Reconstruct observed metadata ONLY in a disposable CI database; not a backup restore. */
declare(strict_types=1);
$core = realpath($argv[1] ?? ''); $candidate = realpath($argv[2] ?? ''); $out = $argv[3] ?? '';
$root = realpath(getenv('TEST_ROOT') ?: '');
if (!$root || !$core || strpos($core, $root . '/') !== 0 || strpos($out, $root . '/') !== 0 ||
        getenv('DBHOST') !== '127.0.0.1' || !getenv('GITHUB_ACTIONS')) { throw new RuntimeException('Disposable CI only'); }
define('CLI_SCRIPT', true);
require $core . '/config.php';
require_once($core . '/lib/ddllib.php');
if ($CFG->dbhost !== '127.0.0.1' || !preg_match('/^bmsci_[a-f0-9]{10}_fromprod1878$/D', $CFG->dbname) ||
        (string)get_config('local_kbacompany', 'version') !== '2026101878' ||
        (string)get_config('local_kbacorporate', 'version') !== '2026102613') { throw new RuntimeException('Wrong baseline'); }
$fixture = json_decode(file_get_contents($candidate . '/docs/deploy/RC30_PRODUCTION_SCHEMA_DIFF.json'), true, 512, JSON_THROW_ON_ERROR);
$dbman = $DB->get_manager();
foreach ($fixture['decimal_type_differences'] as $spec) {
    if (!preg_match('/^local_kbacompany_[a-z]+$/D', $spec['table']) ||
            !preg_match('/^[a-z]+$/D', $spec['column']) ||
            !preg_match('/^decimal\((18|8),0\)$/D', $spec['actual_type'], $m)) { throw new RuntimeException('Unsafe fixture'); }
    $column = $DB->get_columns($spec['table'], false)[$spec['column']];
    $dbman->change_field_type(new xmldb_table($spec['table']), new xmldb_field($spec['column'],
        XMLDB_TYPE_NUMBER, $m[1] . ',0', null, $column->not_null, null, $column->default_value));
}
foreach (['starttime','duetime'] as $name) {
    $table = new xmldb_table('local_kbacompany_adminitem'); $field = new xmldb_field($name);
    if ($dbman->field_exists($table, $field)) { $dbman->drop_field($table, $field); }
}
$table = new xmldb_table('local_kbacompany_deviation');
$field = new xmldb_field('executionstatus');
if ($dbman->field_exists($table, $field)) { $dbman->drop_field($table, $field); }
// Observed production has the legacy due/status index, not the repaired ordered index.
$task = 'local_kbacompany_task';
$legacy = false;
foreach ($DB->get_indexes($task, false) as $name => $index) {
    if (array_values($index['columns']) === ['companyuuid','duedate','status'] && !$index['unique']) { $legacy = true; }
    if (array_values($index['columns']) === ['companyuuid','status','duedate'] && !$index['unique']) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Unsafe index name'); }
        $DB->change_database_structure(['DROP INDEX `' . $name . '` ON `' . $CFG->prefix . $task . '`'], [$task]);
    }
}
if (!$legacy) {
    $dbman->add_index(new xmldb_table($task), new xmldb_index('company_due_status_ix', XMLDB_INDEX_NOTUNIQUE,
        ['companyuuid','duedate','status']));
}
// Preserve known historical Corporate defaults; never silently migrate them to0901 defaults.
// Fixture-only SET DEFAULT avoids redefining the indexed column's existing type.
$DB->change_database_structure(["ALTER TABLE `{$CFG->prefix}local_kbacorp_devplan` ALTER COLUMN `status` SET DEFAULT 'active'"],
    ['local_kbacorp_devplan']);
$DB->change_database_structure(["ALTER TABLE `{$CFG->prefix}local_kbacorp_member` ALTER COLUMN `isemployee` SET DEFAULT 0"],
    ['local_kbacorp_member']);
$expected = json_decode(file_get_contents($candidate . '/docs/release/production1878-schema.json'), true, 512, JSON_THROW_ON_ERROR);
$quoteid = static function(string $name): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Unsafe fixture identifier'); }
    return '`' . $name . '`';
};
// Historical upgrades changed physical column order. Reproduce that order only
// in this empty disposable baseline, preserving each native type/default/attribute.
$previous = [];
foreach ($expected['columns'] as $spec) {
    $table = substr($spec['TABLE_NAME'], 4); $field = $spec['COLUMN_NAME'];
    $native = $DB->get_record_sql('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS coltype,
        ORDINAL_POSITION AS position, EXTRA AS extra, CHARACTER_SET_NAME AS charsetname,
        COLLATION_NAME AS collationname FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :field',
        ['table'=>$CFG->prefix . $table, 'field'=>$field]);
    if (!$native) { throw new RuntimeException('Missing baseline column ' . $table . '.' . $field); }
    if ((int)$native->position !== (int)$spec['ORDINAL_POSITION']) {
        if (!preg_match('/^(?:bigint|int|tinyint|smallint|mediumint)(?:\([0-9]+\))?$|^(?:varchar|char)\([0-9]+\)$|^decimal\([0-9]+,[0-9]+\)$|^(?:longtext|mediumtext|text)$/D',
                strtolower($native->coltype)) || !in_array($native->extra, ['', 'auto_increment'], true)) {
            throw new RuntimeException('Unsupported fixture column attributes');
        }
        $col = $DB->get_columns($table, false)[$field];
        $definition = $native->coltype;
        if ($native->charsetname !== null) {
            $definition .= ' CHARACTER SET ' . $quoteid($native->charsetname) . ' COLLATE ' . $quoteid($native->collationname);
        }
        $definition .= $col->not_null ? ' NOT NULL' : ' NULL';
        if ($col->default_value !== null) {
            $default = (string)$col->default_value;
            if (!preg_match('/^[a-zA-Z0-9_. -]*$/D', $default)) { throw new RuntimeException('Unsupported fixture default'); }
            $definition .= " DEFAULT '" . $default . "'";
        } else if (!$col->not_null) { $definition .= ' DEFAULT NULL'; }
        if ($native->extra === 'auto_increment') { $definition .= ' AUTO_INCREMENT'; }
        $definition .= isset($previous[$table]) ? ' AFTER ' . $quoteid($previous[$table]) : ' FIRST';
        $DB->change_database_structure(['ALTER TABLE ' . $quoteid($CFG->prefix . $table) .
            ' MODIFY COLUMN ' . $quoteid($field) . ' ' . $definition], [$table]);
    }
    $previous[$table] = $field;
}
// XMLDB's globally generated suffixes depend on installation history. Retain
// production names and duplicate historical index structures, not only semantics.
$expectedgroups = [];
foreach ($expected['indexes'] as $spec) {
    $expectedgroups[$spec['TABLE_NAME']][$spec['INDEX_NAME']][] = $spec;
}
foreach ($expectedgroups as $observedtable => $groups) {
    $table = substr($observedtable, 4);
    $nativegroups = [];
    $rs = $DB->get_recordset_sql('SELECT INDEX_NAME AS name, NON_UNIQUE AS nonunique,
        SEQ_IN_INDEX AS position, COLUMN_NAME AS columnname, SUB_PART AS subpart, INDEX_TYPE AS indextype
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        ['table'=>$CFG->prefix . $table]);
    foreach ($rs as $row) { $nativegroups[$row->name][] = [(string)$row->nonunique, (string)$row->position,
        $row->columnname, $row->subpart === null ? null : (string)$row->subpart, $row->indextype]; }
    $rs->close();
    foreach ($groups as $name => $rows) {
        $signature = array_map(static function(array $row): array {
            return [(string)$row['NON_UNIQUE'], (string)$row['SEQ_IN_INDEX'], $row['COLUMN_NAME'],
                $row['SUB_PART'] === null ? null : (string)$row['SUB_PART'], $row['INDEX_TYPE']];
        }, $rows);
        if (isset($nativegroups[$name])) {
            if ($nativegroups[$name] !== $signature) { throw new RuntimeException('Index name collision in fixture'); }
            continue;
        }
        if ($name === 'PRIMARY') { throw new RuntimeException('Missing baseline primary key'); }
        $rename = null;
        foreach ($nativegroups as $actualname => $actualsignature) {
            if (!isset($groups[$actualname]) && $actualsignature === $signature) { $rename = $actualname; break; }
        }
        if ($rename !== null) {
            $DB->change_database_structure(['ALTER TABLE ' . $quoteid($CFG->prefix . $table) .
                ' RENAME INDEX ' . $quoteid($rename) . ' TO ' . $quoteid($name)], [$table]);
            unset($nativegroups[$rename]);
        } else {
            $columns = [];
            foreach ($signature as [$nonunique, $position, $column, $subpart, $type]) {
                if ($type !== 'BTREE' || ($subpart !== null && !ctype_digit($subpart))) {
                    throw new RuntimeException('Unsupported fixture index');
                }
                $columns[] = $quoteid($column) . ($subpart === null ? '' : '(' . $subpart . ')');
            }
            $DB->change_database_structure(['CREATE ' . ($signature[0][0] === '0' ? 'UNIQUE ' : '') .
                'INDEX ' . $quoteid($name) . ' ON ' . $quoteid($CFG->prefix . $table) .
                ' (' . implode(',', $columns) . ') USING BTREE'], [$table]);
        }
        $nativegroups[$name] = $signature;
    }
}
$differences = []; $checked = 0;
$normaltype = static function(string $type): string { return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower($type)); };
foreach ($expected['columns'] as $spec) {
    $name = substr($spec['TABLE_NAME'], 4);
    $rows = $DB->get_records_sql('SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
        IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra, ORDINAL_POSITION AS ordinal_position,
        CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tablename AND COLUMN_NAME = :columnname',
        ['tablename'=>$CFG->prefix . $name, 'columnname'=>$spec['COLUMN_NAME']]);
    $actual = reset($rows);
    if (!$actual) { $differences[] = $name . '.' . $spec['COLUMN_NAME'] . ':missing'; continue; }
    // MariaDB reports quoted character defaults; normalize the SQL literal only.
    $default = $actual->column_default;
    $quoted = $default !== null && strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'";
    if ($quoted) {
        $default = str_replace("''", "'", substr($default, 1, -1));
    }
    if (!$quoted && $default === 'NULL' && $actual->is_nullable === 'YES') { $default = null; }
    if ($normaltype($actual->column_type) !== $normaltype($spec['COLUMN_TYPE']) ||
            $actual->is_nullable !== $spec['IS_NULLABLE'] ||
            ($default === null ? null : hash('sha256', (string)$default)) !== $spec['default_sha256'] ||
            (int)$actual->ordinal_position !== (int)$spec['ORDINAL_POSITION'] ||
            $actual->extra !== $spec['EXTRA'] || $actual->charset_name !== $spec['CHARACTER_SET_NAME'] ||
            $actual->collation_name !== $spec['COLLATION_NAME']) { $differences[] = $name . '.' . $spec['COLUMN_NAME']; }
    $checked++;
}
$expectedcolumns = [];
foreach ($expected['columns'] as $spec) { $expectedcolumns[] = $spec['TABLE_NAME'] . '.' . $spec['COLUMN_NAME']; }
$actualcolumns = [];
$allcolumns = $DB->get_recordset_sql('SELECT TABLE_NAME AS tablename, COLUMN_NAME AS columnname
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
foreach ($allcolumns as $column) {
    $name = substr($column->tablename, strlen($CFG->prefix));
    if (strpos($name, 'local_kbacompany_') === 0 || strpos($name, 'local_kbacorp_') === 0) {
        $actualcolumns[] = 'mdl_' . $name . '.' . $column->columnname;
    }
}
$allcolumns->close();
foreach (array_diff($actualcolumns, $expectedcolumns) as $extra) { $differences[] = $extra . ':unexpected'; }
$expectedindexes = []; $actualindexes = [];
$indexkey = static function(array $row): string {
    return implode('|', array_map(static function($v): string { return $v === null ? '<NULL>' : (string)$v; }, $row));
};
foreach ($expected['indexes'] as $spec) {
    $expectedindexes[] = $indexkey([$spec['TABLE_NAME'], $spec['INDEX_NAME'], $spec['NON_UNIQUE'],
        $spec['SEQ_IN_INDEX'], $spec['COLUMN_NAME'], $spec['SUB_PART'], $spec['INDEX_TYPE']]);
}
$allindexes = $DB->get_recordset_sql('SELECT TABLE_NAME AS tablename, INDEX_NAME AS indexname,
    NON_UNIQUE AS nonunique, SEQ_IN_INDEX AS sequence, COLUMN_NAME AS columnname, SUB_PART AS subpart,
    INDEX_TYPE AS indextype FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()');
foreach ($allindexes as $index) {
    $name = substr($index->tablename, strlen($CFG->prefix));
    if (strpos($name, 'local_kbacompany_') === 0 || strpos($name, 'local_kbacorp_') === 0) {
        $actualindexes[] = $indexkey(['mdl_' . $name, $index->indexname, $index->nonunique, $index->sequence,
            $index->columnname, $index->subpart, $index->indextype]);
    }
}
$allindexes->close();
foreach (array_diff($expectedindexes, $actualindexes) as $missing) { $differences[] = 'index-missing:' . $missing; }
foreach (array_diff($actualindexes, $expectedindexes) as $extra) { $differences[] = 'index-unexpected:' . $extra; }
$report = ['scope'=>'Observed production metadata reconstruction with synthetic data; NOT backup restore',
    'bms'=>2026101878,'corporate'=>2026102613,'checked_columns'=>$checked,
    'checked_index_rows'=>count($expectedindexes),'differences'=>$differences,
    'status'=>$differences ? 'FAIL' : 'PASS'];
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report) . "\n";
exit($differences ? 1 : 0);
