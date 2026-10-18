<?php
// Isolated pure staging/provenance checks; no Moodle bootstrap and no network.
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception { public function __construct($a='', $b='', $c='', $d=null){ parent::__construct((string)$a); } }
class coding_exception extends Exception {}
const PARAM_TEXT = 'text';
function clean_param($v, $t){ return trim(strip_tags((string)$v)); }
require_once(__DIR__ . '/../classes/local/odoo_actual_staging_service.php');
use local_kbacompany\local\odoo_actual_staging_service;

$cfg = odoo_actual_staging_service::parse_source_config(json_encode([
    'provider'=>'odoo','environment'=>'prod','mode'=>'mapped_record_field',
    'value_field'=>'x_bms_actual','source_timestamp_field'=>'write_date','label_field'=>'display_name',
]), 'prod');
assert($cfg['value_field'] === 'x_bms_actual');
assert($cfg['source_timestamp_field'] === 'write_date');

$prov = [
    'provider'=>'odoo','environment'=>'prod','company_uuid'=>'c','indicator_uuid'=>'i',
    'indicator_code'=>'001','period_start'=>'2026-09-01','mapping_uuid'=>'m',
    'external_model'=>'x.bms.metric','external_id'=>'42','value_field'=>'x_bms_actual',
    'source_timestamp_field'=>'write_date','source_timestamp'=>'2026-09-26 08:00:00',
    'sourceconfig_revision'=>3,
];
$a = odoo_actual_staging_service::packet($prov, '10.5', null, 0);
$b = odoo_actual_staging_service::packet($prov, '10.500000', '10.5', 2);
$c = odoo_actual_staging_service::packet($prov, '11', '10.5', 2);
$d = odoo_actual_staging_service::packet($prov, null, '10.5', 2);
assert($a['canonical_fact']['conflict_state'] === 'no_existing_fact');
assert($b['canonical_fact']['conflict_state'] === 'same_value');
$b2 = odoo_actual_staging_service::packet($prov, '0010.500000', '10.5', 2);
assert($b2['canonical_fact']['conflict_state'] === 'same_value');
assert($c['canonical_fact']['conflict_state'] === 'different_value');
assert($d['canonical_fact']['conflict_state'] === 'missing_remote_value');
assert($d['candidate_value'] === null);
assert($a['canonical_fact_write_performed'] === false);
assert($a['remote_write_performed'] === false);
assert($a['human_confirmation_required'] === true);
assert($a['idempotency_key'] === odoo_actual_staging_service::packet($prov, '10.5', null, 0)['idempotency_key']);
assert($a['idempotency_key'] !== $c['idempotency_key']);

try { odoo_actual_staging_service::parse_source_config('{}', 'prod'); assert(false); } catch (moodle_exception $e) {}
try { odoo_actual_staging_service::parse_source_config(json_encode([
    'provider'=>'odoo','environment'=>'test','mode'=>'mapped_record_field','value_field'=>'amount'
]), 'prod'); assert(false); } catch (moodle_exception $e) {}
try { odoo_actual_staging_service::parse_source_config(json_encode([
    'provider'=>'odoo','environment'=>'prod','mode'=>'mapped_record_field','value_field'=>'bad.field'
]), 'prod'); assert(false); } catch (moodle_exception $e) {}

echo "ODOO_ACTUAL_STAGING_ISOLATED_PASS\n";
