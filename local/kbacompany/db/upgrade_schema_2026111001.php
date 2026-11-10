<?php
/** Frozen ADR-0004 repair. Requires quiesced writers; MySQL DDL is not rollback-atomic. */
defined('MOODLE_INTERNAL') || die();

/** Frozen definitions, independent of future install.xml edits. */
function local_kbacompany_decimal_definitions_2026111001(): array {
    return [
        ['local_kbacompany_finbudfact', 'actualamount', 18, 2, true, '0'],
        ['local_kbacompany_finbudline', 'planamount', 18, 2, true, '0'],
        ['local_kbacompany_finbudline', 'limitamount', 18, 2, true, '0'],
        ['local_kbacompany_finline', 'amount', 18, 2, true, '0'],
        ['local_kbacompany_statbind', 'weight', 8, 3, true, '1'],
        ['local_kbacompany_statnorm', 'targetvalue', 18, 6, false, null],
        ['local_kbacompany_statnorm', 'minval', 18, 6, false, null],
        ['local_kbacompany_statnorm', 'maxval', 18, 6, false, null],
        ['local_kbacompany_statpolicy', 'dangerdecline', 8, 3, false, null],
        ['local_kbacompany_statpolicy', 'affluencegrowth', 8, 3, false, null],
        ['local_kbacompany_statpolicy', 'powerlevel', 8, 3, false, null],
    ];
}

/** Preflight the entire group before any DDL; validate all postconditions before caller savepoint. */
function local_kbacompany_upgrade_schema_2026111001(): void {
    global $DB;
    $stop = static function(string $reason): void {
        // Only schema identifiers/reasons; never disclose tenant data or numeric values.
        throw new upgrade_exception('local_kbacompany', 2026111001, 'ADR-0004 repair stopped: ' . $reason);
    };
    if ($DB->get_dbfamily() !== 'mysql') { $stop('requires a verified MySQL-family driver'); }
    $modes = explode(',', strtoupper((string)$DB->get_field_sql('SELECT @@SESSION.sql_mode')));
    if (!array_intersect(['STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES'], $modes)) {
        $stop('strict SQL mode required on the migration connection');
    }
    $dbman = $DB->get_manager();
    $definitions = local_kbacompany_decimal_definitions_2026111001();
    $admin = 'local_kbacompany_adminitem';
    $tables = array_unique(array_merge([$admin], array_column($definitions, 0)));
    foreach ($tables as $table) {
        if (!$dbman->table_exists(new xmldb_table($table))) { $stop('missing table ' . $table); }
        $engine = $DB->get_field_sql('SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tablename', ['tablename' => $DB->get_prefix() . $table]);
        if (strcasecmp((string)$engine, 'InnoDB') !== 0) { $stop('InnoDB required: ' . $table); }
    }
    $native = static function(string $table, string $field) use ($DB) {
        return $DB->get_record_sql('SELECT COLUMN_TYPE AS coltype, EXTRA AS extra
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tablename AND COLUMN_NAME = :fieldname',
            ['tablename' => $DB->get_prefix() . $table, 'fieldname' => $field]);
    };
    $checkadmin = static function(string $field, bool $required) use ($DB, $admin, $native, $stop): bool {
        $columns = $DB->get_columns($admin, false);
        if (!isset($columns[$field])) {
            if ($required) { $stop('missing postcondition ' . $admin . '.' . $field); }
            return false;
        }
        $column = $columns[$field];
        $actual = $native($admin, $field);
        if (!$actual || strtolower($actual->coltype) !== 'varchar(5)' || $actual->extra !== '' ||
                $column->meta_type !== 'C' || (int)$column->max_length !== 5 || !$column->not_null ||
                $column->auto_increment || $column->default_value !== '') {
            $stop('incompatible definition ' . $admin . '.' . $field);
        }
        return true;
    };
    $checkdecimal = static function(array $definition, bool $final) use ($DB, $native, $stop): int {
        [$table, $field, $precision, $scale, $notnull, $default] = $definition;
        $column = $DB->get_columns($table, false)[$field] ?? null;
        $actual = $native($table, $field);
        $actualscale = $column ? (int)$column->scale : -1;
        $defaultok = $column && ($default === null ? $column->default_value === null :
            preg_match('/\A' . $default . '(?:\.0+)?\z/D', (string)$column->default_value) === 1);
        if (!$column || !$actual || $column->meta_type !== 'N' || (int)$column->max_length !== $precision ||
                ($final ? $actualscale !== $scale : !in_array($actualscale, [0, $scale], true)) ||
                strtolower($actual->coltype) !== 'decimal(' . $precision . ',' . $actualscale . ')' ||
                $actual->extra !== '' || $column->auto_increment || (bool)$column->not_null !== $notnull || !$defaultok) {
            $stop('incompatible definition ' . $table . '.' . $field);
        }
        return $actualscale;
    };

    $add = [];
    foreach (['starttime', 'duetime'] as $field) {
        if (!$checkadmin($field, false)) { $add[] = $field; }
    }
    $alter = [];
    foreach ($definitions as $definition) {
        [$table, $field, $precision, $scale] = $definition;
        if ($checkdecimal($definition, false) !== $scale) { $alter[] = $definition; }
        // Exact DB DECIMAL comparison: parameters are strings, explicitly cast, never PHP floats.
        $maximum = str_repeat('9', $precision - $scale) . '.' . str_repeat('9', $scale);
        if ($DB->record_exists_sql('SELECT 1 FROM {' . $table . '} WHERE `' . $field . '` IS NOT NULL AND
            (`' . $field . '` > CAST(:maximum AS DECIMAL(' . $precision . ',' . $scale . ')) OR
             `' . $field . '` < CAST(:minimum AS DECIMAL(' . $precision . ',' . $scale . ')))',
                ['maximum' => $maximum, 'minimum' => '-' . $maximum])) {
            $stop('out-of-range values in ' . $table . '.' . $field . '; no group DDL performed');
        }
    }
    // Every target definition and every non-NULL value has passed before the first group DDL.
    foreach ($add as $field) {
        $dbman->add_field(new xmldb_table($admin), new xmldb_field($field, XMLDB_TYPE_CHAR, '5',
            null, XMLDB_NOTNULL, null, null));
    }
    foreach ($alter as [$table, $field, $precision, $scale, $notnull, $default]) {
        $dbman->change_field_precision(new xmldb_table($table), new xmldb_field($field, XMLDB_TYPE_NUMBER,
            $precision . ',' . $scale, null, $notnull ? XMLDB_NOTNULL : null, null, $default));
    }
    foreach (['starttime', 'duetime'] as $field) { $checkadmin($field, true); }
    foreach ($definitions as $definition) { $checkdecimal($definition, true); }
}
