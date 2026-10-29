<?php
// CLI-only transient Odoo JSON-2 connectivity probe.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_kbacompany\local\odoo_adapter_service;
use local_kbacompany\local\odoo_json2_transport;

require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params([
    'company' => null,
    'environment' => null,
    'help' => false,
], [
    'c' => 'company',
    'e' => 'environment',
    'h' => 'help',
]);
if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}
if ($options['help'] || empty($options['company']) || empty($options['environment'])) {
    $help = <<<TXT
BMS24 Odoo JSON-2 transient transport probe.

Required:
  --company=UUID        BMS Company UUID
  --environment=NAME    Existing Odoo mapping environment

Secrets are read only from process environment and are never stored:
  BMS_ODOO_BASE_URL     e.g. https://example.odoo.com
  BMS_ODOO_API_KEY      dedicated Odoo API key
  BMS_ODOO_DATABASE     optional database header value

Example:
  BMS_ODOO_BASE_URL=https://example.odoo.com \\
  BMS_ODOO_API_KEY='***' \\
  php local/kbacompany/cli/odoo_transport_probe.php --company=<uuid> --environment=prod

The probe is read-only. It verifies the authenticated user context and the mapped res.company record.
TXT;
    echo $help . PHP_EOL;
    exit($options['help'] ? 0 : 2);
}

$baseurl = (string)getenv('BMS_ODOO_BASE_URL');
$apikey = (string)getenv('BMS_ODOO_API_KEY');
$database = (string)getenv('BMS_ODOO_DATABASE');
$admin = get_admin();

try {
    $readiness = odoo_adapter_service::readiness((string)$options['company'], (int)$admin->id,
        (string)$options['environment']);
    if (empty($readiness['ready']) || empty($readiness['company_mapping']['external_id'])) {
        cli_error(get_string('bms_odoo_company_mapping_required', 'local_kbacompany'));
    }
    $probe = odoo_json2_transport::probe($baseurl, $apikey, $database,
        (string)$readiness['company_mapping']['external_id']);
    echo json_encode([
        'ok' => true,
        'company_uuid' => (string)$readiness['company_uuid'],
        'environment' => (string)$readiness['environment'],
        'mapping_uuid' => (string)$readiness['company_mapping']['mapping_uuid'],
        'probe' => $probe,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    cli_error('Odoo transport probe failed: ' . $e->getMessage());
}
