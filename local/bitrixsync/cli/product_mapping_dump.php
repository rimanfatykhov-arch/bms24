<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

echo "KBA Connect Product Mapping Data Inspector\n";
echo "==========================================\n\n";

$tables = [
    'local_bitrixsync_productmap',
    'local_bitrixsync_coursemap',
    'local_bitrixsync_catmap',
];

foreach ($tables as $table) {
    echo "TABLE: {$table}\n";
    echo "----------------\n";

    if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
        echo "Missing\n\n";
        continue;
    }

    $records = $DB->get_records($table, null, 'id ASC', '*', 0, 20);

    if (!$records) {
        echo "No records\n\n";
        continue;
    }

    foreach ($records as $record) {
        echo json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    echo "\n";
}

echo "Inspection complete. No changes made.\n";
