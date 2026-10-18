<?php
// CLI-only read-only staging of one Odoo operational actual candidate.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_kbacompany\local\odoo_actual_staging_service;

require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params([
    'company' => null,
    'environment' => null,
    'indicator' => null,
    'period' => null,
    'help' => false,
], [
    'c' => 'company',
    'e' => 'environment',
    'i' => 'indicator',
    'p' => 'period',
    'h' => 'help',
]);
if ($unrecognised) { cli_error('Unknown options: ' . implode(', ', $unrecognised)); }
if ($options['help'] || empty($options['company']) || empty($options['environment']) ||
        empty($options['indicator']) || empty($options['period'])) {
    $help = <<<TXT
BMS24 Odoo actual staging preview (read-only).

Required:
  --company=UUID        BMS Company UUID
  --environment=NAME    Existing Odoo mapping environment
  --indicator=UUID      Canonical BMS indicator UUID with active Odoo mapping
  --period=YYYY-MM-DD   Canonical indicator period start

Runtime-only secrets:
  BMS_ODOO_BASE_URL     e.g. https://example.odoo.com
  BMS_ODOO_API_KEY      dedicated Odoo API key
  BMS_ODOO_DATABASE     optional database header value

The indicator passport must use sourcekind=integration and sourceconfig JSON:
  {"provider":"odoo","environment":"prod","mode":"mapped_record_field",
   "value_field":"x_bms_actual","source_timestamp_field":"write_date",
   "label_field":"display_name"}

This command never writes Odoo and never writes canonical BMS fact history.
TXT;
    echo $help . PHP_EOL;
    exit($options['help'] ? 0 : 2);
}

$admin = get_admin();
try {
    $packet = odoo_actual_staging_service::stage(
        (string)$options['company'], (int)$admin->id, (string)$options['environment'],
        (string)$options['indicator'], (string)$options['period'],
        (string)getenv('BMS_ODOO_BASE_URL'), (string)getenv('BMS_ODOO_API_KEY'),
        (string)getenv('BMS_ODOO_DATABASE'));
    echo json_encode(['ok' => true, 'stage' => $packet],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    cli_error('Odoo actual staging failed: ' . $e->getMessage());
}
