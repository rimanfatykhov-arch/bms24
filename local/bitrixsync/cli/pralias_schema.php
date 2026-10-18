<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

echo "KBA Connect PR Alias Schema Discovery\n";
echo "=====================================\n";

$table = 'local_bitrixsync_pralias';

if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
    echo "Table not found: {$table}\n";
    exit(1);
}

echo "Table: {$table}\n\n";
echo "Fields:\n";

$columns = $DB->get_columns($table);

foreach ($columns as $name => $column) {
    echo "- {$name}\n";
}

echo "\nDiscovery complete. No changes made.\n";
