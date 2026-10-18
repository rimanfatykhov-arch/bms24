<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

echo "KBA Connect Product Mapping Source Discovery\n";
echo "============================================\n\n";

$tables = $DB->get_tables();

$candidates = [];

foreach ($tables as $table) {
    $name = strtolower($table);

    if (strpos($name, 'product') !== false ||
        strpos($name, 'catalog') !== false ||
        strpos($name, 'alias') !== false ||
        strpos($name, 'map') !== false ||
        strpos($name, 'course') !== false) {

        $count = $DB->count_records($table);
        $candidates[$table] = $count;
    }
}

if (!$candidates) {
    echo "No candidate tables found.\n";
    exit(0);
}

echo "Candidate tables:\n";
echo "-----------------\n";

foreach ($candidates as $table => $count) {
    echo sprintf("%-45s rows: %s\n", $table, $count);
}

echo "\nDiscovery complete. No changes made.\n";
