<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Frozen, upgrade-only helpers for local_kbacompany.
 *
 * @package local_kbacompany
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Add the three fresh/upgrade parity fields missed by historical upgrade paths.
 *
 * Validate existing definitions before any DDL; never convert an incompatible
 * column or overwrite existing values. Re-read metadata after DDL so an incomplete
 * attempt cannot be followed by the caller's savepoint. Partial DDL is retryable.
 */
function local_kbacompany_upgrade_schema_parity_2026110890(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $definitions = [
        ['local_kbacompany_task', 'starttime', '5', '', 'startdate'],
        ['local_kbacompany_task', 'duetime', '5', '', 'duedate'],
        ['local_kbacompany_deviation', 'executionstatus', '16', 'pending', 'duedate'],
    ];
    $validate = static function(string $table, string $name, string $length,
            string $default, array $columns): void {
        $column = $columns[$name];
        if ($column->meta_type !== 'C' || (int)$column->max_length !== (int)$length ||
                !$column->not_null || $column->default_value === null ||
                (string)$column->default_value !== $default) {
            throw new upgrade_exception('local_kbacompany', 2026110890,
                'Schema parity repair stopped: incompatible ' . $table . '.' . $name .
                '. Inspect its type, length, nullability and default; no automatic conversion is allowed.');
        }
    };

    // Check every existing target definition before adding even the first field.
    $pending = [];
    foreach ($definitions as [$tablename, $name, $length, $default, $previous]) {
        $table = new xmldb_table($tablename);
        if (!$dbman->table_exists($table)) {
            throw new ddl_table_missing_exception($tablename);
        }
        $columns = $DB->get_columns($tablename, false);
        if (isset($columns[$name])) {
            $validate($tablename, $name, $length, $default, $columns);
        } else {
            // XMLDB represents an empty CHAR default as null. The MySQL/MariaDB
            // generator produces SQL DEFAULT ''; passing '' emits a developer warning.
            $xmldbdefault = $default === '' ? null : $default;
            $pending[] = [$table, new xmldb_field($name, XMLDB_TYPE_CHAR, $length,
                null, XMLDB_NOTNULL, null, $xmldbdefault, $previous)];
        }
    }
    foreach ($pending as [$table, $field]) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
    foreach ($definitions as [$tablename, $name, $length, $default]) {
        $columns = $DB->get_columns($tablename, false);
        if (!isset($columns[$name])) {
            throw new ddl_field_missing_exception($name, $tablename);
        }
        $validate($tablename, $name, $length, $default, $columns);
    }
}

/**
 * Add the ordered status/due index without treating the reversed due_ix as equal.
 *
 * Moodle 4.1 index_exists/add_index compare sets, not order or uniqueness. Keep
 * both indexes: create only the missing ordered non-unique index through the
 * public driver generator and DDL execution API. No index is renamed or dropped.
 * This repair targets the accepted MySQL-family test profiles (Percona/MariaDB).
 */
function local_kbacompany_upgrade_ordered_task_index_2026110900(): void {
    global $DB;

    $tablename = 'local_kbacompany_task';
    $fields = ['companyuuid', 'status', 'duedate'];
    $table = new xmldb_table($tablename);
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists($table)) {
        throw new ddl_table_missing_exception($tablename);
    }
    $columns = $DB->get_columns($tablename, false);
    foreach ($fields as $field) {
        if (!isset($columns[$field])) {
            throw new ddl_field_missing_exception($field, $tablename);
        }
    }
    // Explicit physical name prevents generator auto-suffixing from concealing a
    // pre-existing conflicting definition. It is not used to locate other indexes.
    $physicalname = $DB->get_prefix() . 'bms_task_status_due_0900_ix';
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $physicalname) || strlen($physicalname) > 63) {
        throw new upgrade_exception('local_kbacompany', 2026110900, 'Unsafe ordered-index identifier');
    }
    $matches = static function(array $index) use ($fields): bool {
        return array_key_exists('unique', $index) && $index['unique'] === false &&
            isset($index['columns']) && array_values($index['columns']) === $fields;
    };
    $before = $DB->get_indexes($tablename, false);
    foreach ($before as $name => $index) {
        if (strcasecmp($name, $physicalname) === 0 && !$matches($index)) {
            throw new upgrade_exception('local_kbacompany', 2026110900,
                'Ordered-index repair stopped: conflicting index name ' . $physicalname);
        }
    }
    foreach ($before as $index) {
        if ($matches($index)) { return; }
    }
    if ($DB->get_dbfamily() !== 'mysql') {
        throw new upgrade_exception('local_kbacompany', 2026110900,
            'Ordered-index repair requires a separately verified DDL path for this database family');
    }
    $generator = $dbman->generator;
    $sql = $generator->getAddIndexSQL($table, new xmldb_index('status_due_ix', XMLDB_INDEX_NOTUNIQUE, $fields));
    // Preserve the driver's table/column quoting. Replace only its index name;
    // reject unexpected output rather than executing a guessed DDL statement.
    if (count($sql) !== 1 || !preg_match('/\ACREATE INDEX (?:`[^`]+`|[a-zA-Z0-9_]+) ON /D', $sql[0])) {
        throw new upgrade_exception('local_kbacompany', 2026110900, 'Unexpected ordered-index DDL generation');
    }
    $sql[0] = preg_replace('/\ACREATE INDEX (?:`[^`]+`|[a-zA-Z0-9_]+) ON /D',
        'CREATE INDEX ' . $generator->getEncQuoted($physicalname) . ' ON ', $sql[0], 1);
    $DB->change_database_structure($sql, [$tablename]);

    $after = $DB->get_indexes($tablename, false);
    if (!isset($after[$physicalname]) || !$matches($after[$physicalname])) {
        throw new upgrade_exception('local_kbacompany', 2026110900,
            'Ordered-index repair incomplete: expected exact non-unique column order');
    }
    foreach ($before as $name => $index) {
        if (!isset($after[$name]) || array_values($after[$name]['columns']) !== array_values($index['columns']) ||
                (bool)$after[$name]['unique'] !== (bool)$index['unique']) {
            throw new upgrade_exception('local_kbacompany', 2026110900,
                'Ordered-index repair stopped: a pre-existing index changed: ' . $name);
        }
    }
}

/**
 * Make the six legacy quantitative fields nullable without losing the norm index.
 *
 * Moodle checks index dependencies even for change_field_notnull(). Only the
 * known non-unique norm_ix may be temporarily dropped. Unknown dependencies or
 * an altered/ambiguous definition stop the upgrade, never discard custom indexes.
 *
 * Every call inspects fresh metadata. The norm index is restored even when a
 * previous process stopped after ALTER and the field is already nullable. No
 * business rows are read or written. Do not call from a web page or cron task.
 *
 * @param int $targetversion Upgrade savepoint used only in diagnostics.
 * @return void
 */
function local_kbacompany_upgrade_deviation_nullable_2026092505(int $targetversion): void {
    global $DB;

    $dbman = $DB->get_manager();
    $normtable = 'local_kbacompany_deviation';
    $normfields = ['companyuuid', 'normuuid'];
    $normindex = new xmldb_index('norm_ix', XMLDB_INDEX_NOTUNIQUE, $normfields);
    $pending = [];

    // Validate both tables and all pending dependencies BEFORE dropping anything.
    foreach ([$normtable, 'local_kbacompany_devcheck'] as $name) {
        $table = new xmldb_table($name);
        if (!$dbman->table_exists($table)) {
            throw new ddl_table_missing_exception($name);
        }
        $columns = $DB->get_columns($name, false);
        $pending[$name] = [];
        foreach (['approvaluuid' => '36', 'normuuid' => '36', 'actual' => '32'] as $column => $length) {
            if (!isset($columns[$column])) {
                throw new ddl_field_missing_exception($column, $name);
            }
            if ($columns[$column]->not_null) {
                $pending[$name][$column] = $length;
            }
        }
        $normmatches = 0;
        foreach ($DB->get_indexes($name, false) as $indexname => $index) {
            $fields = array_values($index['columns']);
            // XMLDB index_exists/drop_index match sets of columns, ignoring order
            // and uniqueness. Reject ambiguity before delegating to those methods.
            $normmatch = $name === $normtable && count($fields) === count($normfields) &&
                !array_diff($fields, $normfields) && !array_diff($normfields, $fields);
            if ($normmatch) {
                $normmatches++;
                if ($fields !== $normfields || (bool)$index['unique'] || $normmatches > 1) {
                    throw new upgrade_exception('local_kbacompany', $targetversion,
                        'Deviation upgrade stopped: unexpected or ambiguous norm index definition. ' .
                        'No norm index was dropped. Inspect ' . $name . '.' . $indexname);
                }
            }
            foreach (array_keys($pending[$name]) as $column) {
                if (in_array($column, $fields, true) && !$normmatch) {
                    throw new ddl_dependency_exception('column', $name . '->' . $column,
                        'index', $indexname . ' (' . implode(', ', $fields) . ')');
                }
            }
        }
    }

    foreach ($pending as $name => $fields) {
        $table = new xmldb_table($name);
        foreach ($fields as $column => $length) {
            if ($name === $normtable && $column === 'normuuid' && $dbman->index_exists($table, $normindex)) {
                $dbman->drop_index($table, $normindex);
            }
            $field = new xmldb_field($column, XMLDB_TYPE_CHAR, $length, null, null, null, null);
            $dbman->change_field_notnull($table, $field);
            // Minimise the interval without the index. A crash here is also safe
            // to retry: the unconditional reconciliation below knows its definition.
            if ($name === $normtable && $column === 'normuuid' && !$dbman->index_exists($table, $normindex)) {
                $dbman->add_index($table, $normindex);
            }
        }
    }
    $table = new xmldb_table($normtable);
    if (!$dbman->index_exists($table, $normindex)) {
        $dbman->add_index($table, $normindex);
    }

    // A successful API return is insufficient: verify the persisted metadata.
    foreach (array_keys($pending) as $name) {
        $columns = $DB->get_columns($name, false);
        foreach (['approvaluuid', 'normuuid', 'actual'] as $column) {
            if (!isset($columns[$column]) || $columns[$column]->not_null) {
                throw new upgrade_exception('local_kbacompany', $targetversion,
                    'Deviation upgrade incomplete: expected nullable ' . $name . '.' . $column);
            }
        }
    }
    $found = false;
    foreach ($DB->get_indexes($normtable, false) as $index) {
        if (array_values($index['columns']) === $normfields && !(bool)$index['unique']) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new upgrade_exception('local_kbacompany', $targetversion,
            'Deviation upgrade incomplete: missing non-unique norm_ix (companyuuid, normuuid)');
    }
}

/**
 * Rebind active staffing assignments from archived-plan slots to compatible slots
 * of the current approved version. This repairs the rc10/rc11 behaviour where a
 * new staffing version generated fresh slot UUIDs and factual assignments appeared
 * to disappear from the staffing placement UI.
 *
 * Identity/history policy: keep the PositionAssignment row and dates unchanged;
 * only move its slot pointer when canonical position and FTE are identical.
 * Incompatible assignments are deliberately left untouched for explicit manager resolution.
 */
function local_kbacompany_upgrade_carry_staffing_assignments_2026101230(): void {
    global $DB;

    $plansql = "SELECT p.*
                  FROM {local_kbacompany_staffplan} p
                 WHERE p.status = :status
              ORDER BY p.companyuuid, p.branchuuid, p.timeapproved DESC, p.id DESC";
    $seen = [];
    foreach ($DB->get_records_sql($plansql, ['status' => 'approved']) as $plan) {
        $branchkey = (string)$plan->companyuuid . ':' . (string)$plan->branchuuid;
        if (isset($seen[$branchkey])) {
            continue;
        }
        $seen[$branchkey] = true;

        $positions = [];
        foreach ($DB->get_records('local_kbacompany_staffline', [
                'companyuuid' => (string)$plan->companyuuid,
                'planuuid' => (string)$plan->uuid,
            ], 'id ASC', 'positionuuid') as $line) {
            $positions[(string)$line->positionuuid] = true;
        }
        if (!$positions) {
            continue;
        }

        $available = [];
        foreach ($DB->get_records('local_kbacompany_staffslot', [
                'companyuuid' => (string)$plan->companyuuid,
                'planuuid' => (string)$plan->uuid,
                'active' => 1,
            ], 'positionuuid ASC, code ASC') as $slot) {
            if ($DB->record_exists('local_kbacompany_posassign', [
                    'companyuuid' => (string)$plan->companyuuid,
                    'slotuuid' => (string)$slot->uuid,
                    'active' => 1,
                ])) {
                continue;
            }
            $ftekey = number_format((float)$slot->fte, 2, '.', '');
            $available[(string)$slot->positionuuid][$ftekey][] = $slot;
        }
        if (!$available) {
            continue;
        }

        list($positionsql, $positionparams) = $DB->get_in_or_equal(array_keys($positions),
            SQL_PARAMS_NAMED, 'upgradeposition');
        $sql = "SELECT a.id AS assignmentid, a.slotuuid, a.positionuuid, s.fte AS slotfte
                  FROM {local_kbacompany_posassign} a
                  JOIN {local_kbacompany_staffslot} s
                    ON s.companyuuid = a.companyuuid
                   AND s.uuid = a.slotuuid
                 WHERE a.companyuuid = :companyuuid
                   AND a.active = 1
                   AND a.slotuuid <> ''
                   AND a.positionuuid $positionsql
                   AND s.planuuid <> :currentplan
              ORDER BY a.positionuuid ASC, s.code ASC, a.id ASC";
        $params = array_merge([
            'companyuuid' => (string)$plan->companyuuid,
            'currentplan' => (string)$plan->uuid,
        ], $positionparams);
        foreach ($DB->get_records_sql($sql, $params) as $assignment) {
            $positionuuid = (string)$assignment->positionuuid;
            $ftekey = number_format((float)$assignment->slotfte, 2, '.', '');
            if (empty($available[$positionuuid][$ftekey])) {
                continue;
            }
            $slot = array_shift($available[$positionuuid][$ftekey]);
            $DB->set_field('local_kbacompany_posassign', 'slotuuid', (string)$slot->uuid, [
                'id' => (int)$assignment->assignmentid,
                'companyuuid' => (string)$plan->companyuuid,
                'active' => 1,
            ]);
            $DB->set_field('local_kbacompany_posassign', 'timemodified', time(), [
                'id' => (int)$assignment->assignmentid,
                'companyuuid' => (string)$plan->companyuuid,
            ]);
            $DB->set_field('local_kbacompany_staffslot', 'status', 'occupied', [
                'companyuuid' => (string)$plan->companyuuid,
                'uuid' => (string)$slot->uuid,
            ]);
            $DB->set_field('local_kbacompany_staffslot', 'timemodified', time(), [
                'companyuuid' => (string)$plan->companyuuid,
                'uuid' => (string)$slot->uuid,
            ]);
        }
    }
}
