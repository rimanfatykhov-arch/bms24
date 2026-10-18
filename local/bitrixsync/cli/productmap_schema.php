<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

echo "KBA Connect Product Map Schema Discovery\n";
echo "========================================\n";

$table = 'local_bitrixsync_productmap';

if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
    echo "Table not found: {$table}\n";
    exit(1);
}

$columns = $DB->get_columns($table);

echo "Table: {$table}\n\n";
echo "Fields:\n";

foreach ($columns as $name => $column) {
    echo "- {$name}\n";
}

echo "\nDiscovery complete. No changes made.\n";
