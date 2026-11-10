<?php
/** Synthetic Corporate preservation proof, exclusively in guarded disposable Moodle. */
function adr0005_data_digest(): array {
    global $DB;
    $out = [];
    foreach ($DB->get_tables(false) as $table) {
        if (strpos($table, 'local_kbacorp_') !== 0) { continue; }
        $rows = [];
        $records = $DB->get_recordset($table);
        foreach ($records as $record) {
            $row = (array)$record;
            ksort($row);
            foreach ($row as &$value) { if ($value !== null) { $value = (string)$value; } }
            unset($value);
            $rows[] = json_encode($row, JSON_THROW_ON_ERROR);
        }
        $records->close();
        sort($rows, SORT_STRING);
        $out[$table] = ['count'=>count($rows), 'sha256'=>hash('sha256', implode("\n", $rows))];
    }
    ksort($out);
    return $out;
}

function adr0005_insert(string $table, array $values): int {
    global $DB;
    $row = [];
    foreach ($DB->get_columns($table, false) as $name=>$column) {
        if ($column->auto_increment || !$column->not_null || $column->default_value !== null) { continue; }
        $row[$name] = in_array($column->meta_type, ['I','N','F','R','L'], true) ? 0 : '';
    }
    return $DB->insert_record($table, (object)array_merge($row, $values));
}

function adr0005_seed(): void {
    global $DB;
    foreach (['A','B'] as $tenant) {
        $company = adr0005_insert('local_kbacorp_company', ['name'=>'ADR0005 synthetic '.$tenant]);
        foreach ([0,1] as $employee) {
            $userid = adr0005_insert('user', ['username'=>'adr0005'.strtolower($tenant).$employee,
                'mnethostid'=>1, 'auth'=>'manual', 'confirmed'=>1, 'firstname'=>'Synthetic',
                'lastname'=>$tenant.$employee, 'email'=>'adr0005'.$tenant.$employee.'@example.invalid']);
            adr0005_insert('local_kbacorp_member', ['companyid'=>$company,'userid'=>$userid,
                'membertype'=>$employee ? 'employee' : 'manager', 'isemployee'=>$employee]);
            foreach (['draft','active','pending'] as $status) {
                adr0005_insert('local_kbacorp_devplan', ['companyid'=>$company,'userid'=>$userid,
                    'title'=>'Preserve '.$status,'status'=>$status]);
            }
        }
    }
}
