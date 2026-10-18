<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

$required = [
    'local_bitrixsync_enrollment' => ['dealid', 'userid', 'courseid', 'status'],
    'local_bitrixsync_productmap' => ['bitrix_product_id', 'courseid', 'active'],
    'local_bitrixsync_coursemap' => ['productid', 'courseid'],
    'local_bitrixsync_company' => ['bitrix_company_id'],
    'local_bitrixsync_contact' => ['bitrix_contact_id', 'userid'],
    'local_bitrixsync_dealrun' => ['dealid', 'dryrun', 'status', 'payload'],
    'local_bitrixsync_syncqueue' => ['entity_type', 'entity_id', 'action', 'status'],
];

$out = ['ready' => true, 'tables' => []];

foreach ($required as $name => $fields) {
    $table = new xmldb_table($name);
    $exists = $DB->get_manager()->table_exists($table);
    $item = ['exists' => $exists, 'fields' => []];

    if ($exists) {
        $columns = $DB->get_columns($name);
        foreach ($fields as $field) {
            $ok = array_key_exists($field, $columns);
            $item['fields'][$field] = $ok;
            if (!$ok) {
                $out['ready'] = false;
            }
        }
    } else {
        $out['ready'] = false;
    }

    $out['tables'][$name] = $item;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($out['ready'] ? 0 : 2);
