<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

require_once($CFG->libdir . '/clilib.php');

$dbman = $DB->get_manager();

$definitions = [];

$table = new xmldb_table('local_bitrixsync_pairing');
$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
$table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
$table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
$table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timeused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
$table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('lastattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
$table->add_index('codehash_uix', XMLDB_INDEX_UNIQUE, ['codehash']);
$table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['used', 'timeexpires']);
$definitions[] = $table;

$table = new xmldb_table('local_bitrixsync_corpconn');
$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
$table->add_field('connectionid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
$table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'local_kbacorporate');
$table->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'production');
$table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
$table->add_field('tokenfingerprint', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
$table->add_field('clientversion', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
$table->add_field('apiversion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
$table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
$table->add_index('connid_uix', XMLDB_INDEX_UNIQUE, ['connectionid']);
$table->add_index('tokenhash_uix', XMLDB_INDEX_UNIQUE, ['tokenhash']);
$table->add_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked', 'environment']);
$definitions[] = $table;

$table = new xmldb_table('local_bitrixsync_pairattempt');
$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
$table->add_field('iphash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
$table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
$table->add_index('iphash_uix', XMLDB_INDEX_UNIQUE, ['iphash']);
$definitions[] = $table;

foreach ($definitions as $table) {
    $name = $table->getName();
    if ($dbman->table_exists($table)) {
        cli_writeln('[OK] ' . $name . ' already exists');
        continue;
    }
    $dbman->create_table($table);
    cli_writeln('[CREATED] ' . $name);
}

if (get_config('local_bitrixsync', 'corporate_pairing_pepper') === false) {
    set_config('corporate_pairing_pepper', bin2hex(random_bytes(32)), 'local_bitrixsync');
}

cli_writeln('Pairing schema repair completed.');
