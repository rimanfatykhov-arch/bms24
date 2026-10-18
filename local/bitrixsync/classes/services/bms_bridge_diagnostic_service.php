<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\adapters\bitrix24\deal_client;
use local_bitrixsync\adapters\corporate\api_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only diagnostics for the Bitrix24 <-> BMS <-> KBA Corporate bridge.
 *
 * This service never provisions fields, never writes to Bitrix24 and never
 * changes BMS/Corporate state. It only resolves current configuration and
 * reads the existing integration projections/logs.
 */
class bms_bridge_diagnostic_service {
    /** @var client */
    private $client;
    /** @var deal_client */
    private $bitrix;

    public function __construct(?client $client = null, ?deal_client $bitrix = null) {
        $this->client = $client ?? new client();
        $this->bitrix = $bitrix ?? new deal_client($this->client);
    }

    public function contract(): array {
        return [
            'version' => '5.0.36.1.18',
            'storage' => 'none',
            'read_only' => true,
            'company_lookup' => 'bitrix_company_id',
            'sources' => [
                'bitrix24_company_read',
                'crm_field_engine',
                'local_bitrixsync_syncqueue',
                'local_bitrixsync_company',
                'local_bitrixsync_contact',
                'local_kbacompany_integration_service',
                'local_kbacorporate_bridge_status',
            ],
        ];
    }

    public function overview(): array {
        $corporate = new api_client();
        $corporatestatus = [];
        $corporatecontract = [];
        try {
            $corporatestatus = $corporate->status();
            $corporatecontract = $corporate->contract();
        } catch (\Throwable $e) {
            $corporatestatus = ['error' => $e->getMessage()];
        }

        $field = $this->resolve_bms_client_field_readonly();

        return [
            'bridge_enabled' => (bool)get_config('local_bitrixsync', 'bms_bridge_enabled'),
            'company_webhook_enabled' => (bool)get_config('local_bitrixsync', 'bms_company_webhook_enabled'),
            'corporate_employee_sync_enabled' => (bool)get_config('local_bitrixsync', 'corporate_employee_sync_enable'),
            'bitrix_configured' => $this->client->is_configured(),
            'bms_available' => class_exists('\\local_kbacompany\\local\\integration_service'),
            'bms_version' => (string)(get_config('local_kbacompany', 'version') ?: ''),
            'corporate_available' => $corporate->is_available(),
            'corporate_enabled' => $corporate->is_enabled(),
            'corporate_compatible' => $corporate->is_api_compatible(),
            'corporate_status' => $corporatestatus,
            'corporate_contract' => $corporatecontract,
            'bms_client_field' => $field,
            'queue' => $this->queue_summary(),
        ];
    }

    public function inspect_company(int $bitrixcompanyid, int $viewerid): array {
        global $DB;

        if ($bitrixcompanyid <= 0) {
            throw new \invalid_parameter_exception('Bitrix Company ID must be positive.');
        }

        $result = [
            'company_id' => $bitrixcompanyid,
            'bitrix' => ['loaded' => false, 'company' => [], 'error' => ''],
            'field' => ['ready' => false],
            'bms_client' => null,
            'queue_items' => [],
            'latest_queue' => null,
            'bms_mapping' => null,
            'bms_diagnostics' => null,
            'bms_error' => '',
            'corporate_mapping' => null,
            'contacts' => [],
            'contacts_total' => 0,
            'contacts_truncated' => false,
        ];

        $result['field'] = $this->resolve_bms_client_field_readonly();

        try {
            $company = $this->bitrix->get_company($bitrixcompanyid);
            if ($company) {
                $result['bitrix']['loaded'] = true;
                $result['bitrix']['company'] = $company;
                $fieldname = trim((string)($result['field']['field_name'] ?? ''));
                if ($fieldname !== '') {
                    $rawvalue = $company[$fieldname] ?? null;
                    $result['bitrix']['bms_client_raw'] = $rawvalue;
                    $result['bms_client'] = $this->to_bool($rawvalue);
                }
            } else {
                $result['bitrix']['error'] = 'Bitrix24 returned an empty Company card.';
            }
        } catch (\Throwable $e) {
            $result['bitrix']['error'] = $e->getMessage();
        }

        $queueitems = $DB->get_records('local_bitrixsync_syncqueue', [
            'entity_type' => 'company',
            'entity_id' => $bitrixcompanyid,
            'action' => 'bms_sync',
        ], 'id DESC', '*', 0, 20);
        foreach ($queueitems as $item) {
            $row = clone $item;
            $row->decodedpayload = $this->decode_json((string)$item->payload);
            $result['queue_items'][] = $row;
        }
        if (!empty($result['queue_items'])) {
            $result['latest_queue'] = $result['queue_items'][0];
        }

        $corporatemap = $DB->get_record('local_bitrixsync_company', [
            'bitrix_company_id' => $bitrixcompanyid,
        ], '*', IGNORE_MULTIPLE);
        $result['corporate_mapping'] = $corporatemap ?: null;

        if (class_exists('\\local_kbacompany\\local\\integration_service')) {
            try {
                $integration = '\\local_kbacompany\\local\\integration_service';
                $map = $integration::mapping_by_external('bitrix24', 'company', (string)$bitrixcompanyid);
                $result['bms_mapping'] = $map ?: null;
                if ($map && !empty($map->localref)) {
                    try {
                        $result['bms_diagnostics'] = $integration::diagnostics((string)$map->localref, $viewerid);
                    } catch (\Throwable $e) {
                        $result['bms_error'] = $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $result['bms_error'] = $e->getMessage();
            }
        } else {
            $result['bms_error'] = 'local_kbacompany integration_service is unavailable.';
        }

        if ($result['bitrix']['loaded']) {
            try {
                $contactresult = $this->load_company_contacts($bitrixcompanyid, 200);
                $result['contacts'] = $contactresult['contacts'];
                $result['contacts_total'] = $contactresult['total'];
                $result['contacts_truncated'] = $contactresult['truncated'];
            } catch (\Throwable $e) {
                $result['contacts_error'] = $e->getMessage();
            }
        }

        return $result;
    }


    /** Resolve the explicitly selected BMS Company field read-only. */
    private function resolve_bms_client_field_readonly(): array {
        $configured = trim((string)get_config('local_bitrixsync', 'bms_client_company_field'));
        if ($configured === '') {
            return [
                'ready' => false,
                'field_name' => '',
                'source' => 'not_configured',
                'error' => get_string('bms_client_company_field_required', 'local_bitrixsync'),
            ];
        }
        try {
            $resolved = (new crm_field_manager())->resolve_explicit_field('company', $configured);
            if (empty($resolved['ready'])) {
                $resolved['error'] = get_string('bms_client_company_field_missing', 'local_bitrixsync', $configured);
                return $resolved;
            }
            if ((string)($resolved['user_type_id'] ?? '') !== 'boolean' || !empty($resolved['multiple'])) {
                $resolved['ready'] = false;
                $resolved['error'] = get_string('bms_client_company_field_invalid_type', 'local_bitrixsync', $configured);
            }
            return $resolved;
        } catch (\Throwable $e) {
            return ['ready' => false, 'field_name' => $configured, 'error' => $e->getMessage()];
        }
    }

    private function match_field(array $fields, array $def): ?array {
        $xmlids = array_merge([(string)($def['XML_ID'] ?? '')], (array)($def['LEGACY_XML_IDS'] ?? []));
        foreach ($fields as $field) {
            $xmlid = (string)($field['XML_ID'] ?? '');
            if ($xmlid !== '' && in_array($xmlid, $xmlids, true)) {
                $field['_source'] = $xmlid === (string)$def['XML_ID'] ? 'xml_id' : 'legacy_xml_id';
                return $field;
            }
        }

        $fieldnames = array_merge([(string)($def['FIELD_NAME'] ?? '')], (array)($def['LEGACY_FIELD_NAMES'] ?? []));
        foreach ($fields as $field) {
            $actual = strtoupper((string)($field['FIELD_NAME'] ?? ''));
            foreach ($fieldnames as $wantedraw) {
                $wanted = strtoupper((string)$wantedraw);
                if ($wanted === '') {
                    continue;
                }
                $suffix = '_' . $wanted;
                $suffixmatches = strlen($actual) >= strlen($suffix) && substr($actual, -strlen($suffix)) === $suffix;
                if ($actual === $wanted || $suffixmatches) {
                    $field['_source'] = $wanted === strtoupper((string)($def['FIELD_NAME'] ?? ''))
                        ? 'field_name' : 'legacy_field_name';
                    return $field;
                }
            }
        }

        $labels = array_merge([(string)($def['LABEL'] ?? '')], (array)($def['LEGACY_LABELS'] ?? []));
        $wantedlabels = [];
        foreach ($labels as $label) {
            $wantedlabels[] = $this->normalize_label((string)$label);
        }
        foreach ($fields as $field) {
            foreach ($this->field_labels($field) as $label) {
                if ($label !== '' && in_array($this->normalize_label($label), $wantedlabels, true)) {
                    $field['_source'] = 'legacy_label';
                    return $field;
                }
            }
        }
        return null;
    }

    private function field_labels(array $field): array {
        $out = [];
        foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL', 'LABEL'] as $key) {
            if (empty($field[$key])) {
                continue;
            }
            if (is_string($field[$key])) {
                $out[] = $field[$key];
            } else if (is_array($field[$key])) {
                if (!empty($field[$key]['ru'])) {
                    $out[] = (string)$field[$key]['ru'];
                }
                foreach ($field[$key] as $label) {
                    if (is_string($label)) {
                        $out[] = $label;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function normalize_label(string $value): string {
        $value = trim($value);
        $value = str_replace(['–', '—'], '-', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return \core_text::strtolower((string)$value);
    }

    private function queue_summary(): array {
        global $DB;
        $summary = [];
        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $summary[$status] = (int)$DB->count_records('local_bitrixsync_syncqueue', ['status' => $status]);
        }
        return $summary;
    }

    private function load_company_contacts(int $companyid, int $limit): array {
        global $DB;
        $limit = max(1, min(500, $limit));
        $contacts = [];
        $ids = [];
        $start = 0;
        $guard = 0;
        $truncated = false;

        while (count($contacts) < $limit && $guard < 20) {
            $response = $this->client->call('crm.contact.list', [
                'filter' => ['COMPANY_ID' => $companyid],
                'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'COMPANY_ID'],
                'order' => ['ID' => 'ASC'],
                'start' => $start,
            ]);
            $page = $response['result'] ?? [];
            if (!is_array($page) || !$page) {
                break;
            }
            foreach ($page as $contact) {
                if (count($contacts) >= $limit) {
                    $truncated = true;
                    break;
                }
                $id = (int)($contact['ID'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $ids[] = $id;
                $contacts[$id] = [
                    'id' => $id,
                    'name' => trim((string)($contact['NAME'] ?? '')),
                    'lastname' => trim((string)($contact['LAST_NAME'] ?? '')),
                    'email' => $this->extract_email($contact),
                    'userid' => 0,
                    'corporate_employee' => false,
                ];
            }
            if (empty($response['next'])) {
                break;
            }
            if (count($contacts) >= $limit) {
                $truncated = true;
                break;
            }
            $start = (int)$response['next'];
            $guard++;
        }

        if ($ids) {
            $mapped = $DB->get_records_list('local_bitrixsync_contact', 'bitrix_contact_id', $ids);
            foreach ($mapped as $mapping) {
                $id = (int)$mapping->bitrix_contact_id;
                if (isset($contacts[$id])) {
                    $contacts[$id]['userid'] = (int)$mapping->userid;
                    $contacts[$id]['corporate_employee'] = !empty($mapping->corporate_employee);
                }
            }
        }

        return [
            'contacts' => array_values($contacts),
            'total' => count($contacts),
            'truncated' => $truncated,
        ];
    }

    private function extract_email(array $contact): string {
        foreach ((array)($contact['EMAIL'] ?? []) as $row) {
            if (is_array($row)) {
                $value = trim((string)($row['VALUE'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function decode_json(string $json): array {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function to_bool($value): bool {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }
        $normal = \core_text::strtolower(trim((string)$value));
        return in_array($normal, ['1', 'y', 'yes', 'true', 'да', 'on'], true);
    }
}
