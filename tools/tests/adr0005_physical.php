<?php
/** Test-only physical inventory. Called after the disposable harness guard. */
function adr0005_physical_snapshot(): array {
    global $DB, $CFG;
    $out = ['db_prefix'=>$CFG->prefix, 'tables'=>[], 'columns'=>[], 'indexes'=>[]];
    $plugin = static function(string $physical) use ($CFG): ?string {
        if (strpos($physical, $CFG->prefix) !== 0) { return null; }
        $table = substr($physical, strlen($CFG->prefix));
        return strpos($table, 'local_kbacorp_') === 0 || strpos($table, 'local_kbacompany_') === 0 ? $table : null;
    };
    $rows = $DB->get_recordset_sql('SELECT TABLE_NAME AS tablename, ENGINE AS engine,
        TABLE_COLLATION AS collationname FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    foreach ($rows as $row) {
        $table = $plugin($row->tablename);
        if ($table !== null) { $out['tables'][$table] = ['engine'=>$row->engine, 'collation'=>$row->collationname]; }
    }
    $rows->close();
    $rows = $DB->get_recordset_sql('SELECT TABLE_NAME AS tablename, COLUMN_NAME AS columnname,
        COLUMN_TYPE AS columntype, IS_NULLABLE AS isnullable, COLUMN_DEFAULT AS defaultvalue,
        ORDINAL_POSITION AS position, EXTRA AS extra, CHARACTER_SET_NAME AS charsetname,
        COLLATION_NAME AS collationname FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    foreach ($rows as $row) {
        $table = $plugin($row->tablename);
        if ($table === null) { continue; }
        $default = $row->defaultvalue;
        if ($default !== null && strlen($default) >= 2 && $default[0] === "'" && substr($default,-1) === "'") {
            $default = str_replace("''", "'", substr($default,1,-1));
        } else if ($default === 'NULL' && $row->isnullable === 'YES') { $default = null; }
        $out['columns'][$table . '.' . $row->columnname] = ['type'=>$row->columntype,
            'nullable'=>$row->isnullable, 'default'=>$default, 'position'=>(int)$row->position,
            'extra'=>$row->extra, 'charset'=>$row->charsetname, 'collation'=>$row->collationname];
    }
    $rows->close();
    $rows = $DB->get_recordset_sql('SELECT TABLE_NAME AS tablename, INDEX_NAME AS indexname,
        NON_UNIQUE AS nonunique, SEQ_IN_INDEX AS sequence, COLUMN_NAME AS columnname,
        SUB_PART AS subpart, INDEX_TYPE AS indextype, COLLATION AS collationname,
        NULLABLE AS nullable FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
    foreach ($rows as $row) {
        $table = $plugin($row->tablename);
        if ($table === null) { continue; }
        $out['indexes'][$table][$row->indexname][] = ['nonunique'=>(int)$row->nonunique,
            'sequence'=>(int)$row->sequence, 'column'=>$row->columnname,
            'subpart'=>$row->subpart === null ? null : (int)$row->subpart, 'type'=>$row->indextype,
            'collation'=>$row->collationname, 'nullable'=>$row->nullable];
    }
    $rows->close();
    foreach (['tables','columns','indexes'] as $key) { ksort($out[$key]); }
    return $out;
}
