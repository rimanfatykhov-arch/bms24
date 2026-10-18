<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

global $DB;

echo "KBA Connect PR Alias Data Inspector\n";
echo "==================================\n\n";

$table = 'local_bitrixsync_pralias';

if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
    echo "Table not found: {$table}\n";
    exit(1);
}

echo "Table: {$table}\n\n";

$records = $DB->get_records($table, null, 'id ASC', '*', 0, 50);

if (!$records) {
    echo "No records found.\n";
    exit(0);
}

echo "ID | legacy_product_id | canonical_product_id | courseid | active | status\n";
echo "-----------------------------------------------------------------------\n";

foreach ($records as $r) {
    echo sprintf(
        "%s | %s | %s | %s | %s | %s\n",
        $r->id ?? '',
        $r->legacy_product_id ?? '',
        $r->canonical_product_id ?? '',
        $r->courseid ?? '',
        $r->active ?? '',
        $r->status ?? ''
    );
}

echo "\nInspector complete. No changes made.\n";
