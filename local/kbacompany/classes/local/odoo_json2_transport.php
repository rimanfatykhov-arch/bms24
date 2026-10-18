<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal Odoo 19+ JSON-2 transport probe.
 *
 * Credentials are runtime-only. This class does not persist secrets, does not
 * mutate Odoo, and does not write operational actuals into BMS.
 */
class odoo_json2_transport {
    public const CONTRACT_VERSION = '0.9.1.2';
    public const USER_AGENT = 'BMS24-OdooAdapter/0.9.1.2';
    public const TIMEOUT_SECONDS = 20;

    /** Return transport guarantees exposed by this slice. */
    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'transport' => 'odoo_json2_https',
            'odoo_minimum_transport_version' => '19.0',
            'credentials_persisted' => false,
            'api_key_source' => 'runtime_environment_only',
            'remote_write_enabled' => false,
            'actual_ingestion_enabled' => false,
            'actual_read_staging_enabled' => true,
            'probe_operations' => [
                'res.users/context_get',
                'res.company/read',
                '<mapped_model>/read',
            ],
            'rules' => [
                'https_required',
                'no_credentials_in_url',
                'mapped_res_company_required',
                'no_remote_mutation',
                'no_bms_actual_write',
                'mapped_record_field_reads_only',
                'secrets_never_returned',
            ],
        ];
    }

    /**
     * Probe one mapped Odoo company using JSON-2.
     *
     * $requester is an optional test double. It receives ($url, $headers, $body)
     * and returns ['status' => int, 'body' => string].
     */
    public static function probe(string $baseurl, string $apikey, string $database,
            string $externalcompanyid, callable $requester = null): array {
        $baseurl = self::normalise_base_url($baseurl);
        $apikey = trim($apikey);
        $database = trim($database);
        $companyid = self::positive_id($externalcompanyid);
        if ($apikey === '') {
            throw new \moodle_exception('bms_odoo_transport_missing_key', 'local_kbacompany');
        }

        $context = self::call($baseurl, $apikey, $database, 'res.users', 'context_get', [], $requester);
        $company = self::call($baseurl, $apikey, $database, 'res.company', 'read', [
            'ids' => [$companyid],
            'fields' => ['id', 'name'],
        ], $requester);
        if (!is_array($company) || empty($company[0]) || (int)($company[0]['id'] ?? 0) !== $companyid) {
            throw new \moodle_exception('bms_odoo_transport_company_missing', 'local_kbacompany');
        }

        return [
            'transport_version' => self::CONTRACT_VERSION,
            'remote_verified' => true,
            'base_host' => (string)parse_url($baseurl, PHP_URL_HOST),
            'database_header_sent' => $database !== '',
            'odoo_user_context' => self::safe_context($context),
            'company' => [
                'id' => $companyid,
                'name' => clean_param((string)($company[0]['name'] ?? ''), PARAM_TEXT),
            ],
            'writes_performed' => 0,
            'actual_ingestion_performed' => false,
            'verified_at' => gmdate('c'),
        ];
    }



    /** Read selected fields of one already human-mapped Odoo record. */
    public static function read_record_fields(string $baseurl, string $apikey, string $database,
            string $model, string $externalid, array $fields, callable $requester = null): array {
        $baseurl = self::normalise_base_url($baseurl);
        $apikey = trim($apikey);
        $database = trim($database);
        if ($apikey === '') {
            throw new \moodle_exception('bms_odoo_transport_missing_key', 'local_kbacompany');
        }
        $model = self::model_name($model);
        $id = self::positive_id($externalid);
        $fields = self::field_names($fields);
        $rows = self::call($baseurl, $apikey, $database, $model, 'read', [
            'ids' => [$id],
            'fields' => $fields,
        ], $requester);
        if (!is_array($rows) || empty($rows[0]) || (int)($rows[0]['id'] ?? 0) !== $id) {
            throw new \moodle_exception('bms_odoo_actual_record_missing', 'local_kbacompany');
        }
        return $rows[0];
    }

    private static function call(string $baseurl, string $apikey, string $database,
            string $model, string $method, array $payload, callable $requester = null) {
        $operation = $model . '/' . $method;
        $allowed = $operation === 'res.users/context_get' ||
            ($method === 'read' && preg_match('/^[a-z][a-z0-9_.]{0,62}$/D', $model));
        if (!$allowed) {
            throw new \coding_exception('Unsupported Odoo JSON-2 read operation');
        }
        $url = $baseurl . '/json/2/' . rawurlencode($model) . '/' . rawurlencode($method);
        $headers = [
            'Authorization: bearer ' . $apikey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($database !== '') {
            $headers[] = 'X-Odoo-Database: ' . self::header_value($database);
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \coding_exception('Unable to encode Odoo request');
        }

        if ($requester !== null) {
            $response = $requester($url, $headers, $body);
        } else {
            $response = self::http_post($url, $headers, $body);
        }
        $status = (int)($response['status'] ?? 0);
        $raw = (string)($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception('bms_odoo_transport_http_error', 'local_kbacompany', '', $status);
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('bms_odoo_transport_invalid_json', 'local_kbacompany');
        }
        return $decoded;
    }

    /** Perform the real Moodle cURL call. */
    private static function http_post(string $url, array $headers, string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader($headers);
        $result = $curl->post($url, $body, [
            'CURLOPT_TIMEOUT' => self::TIMEOUT_SECONDS,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $info = $curl->get_info();
        return [
            'status' => (int)($info['http_code'] ?? 0),
            'body' => is_string($result) ? $result : '',
        ];
    }

    private static function normalise_base_url(string $baseurl): string {
        $baseurl = rtrim(trim($baseurl), '/');
        if ($baseurl === '' || filter_var($baseurl, FILTER_VALIDATE_URL) === false) {
            throw new \moodle_exception('bms_odoo_transport_invalid_url', 'local_kbacompany');
        }
        $parts = parse_url($baseurl);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) ||
                !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \moodle_exception('bms_odoo_transport_invalid_url', 'local_kbacompany');
        }
        return $baseurl;
    }



    private static function model_name(string $value): string {
        $value = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_.]{0,62}$/D', $value)) {
            throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
        }
        return $value;
    }

    private static function field_names(array $fields): array {
        if (!$fields || count($fields) > 16) {
            throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
        }
        $out = [];
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D', $field)) {
                throw new \moodle_exception('bms_odoo_actual_source_invalid', 'local_kbacompany');
            }
            $out[$field] = true;
        }
        return array_keys($out);
    }

    private static function positive_id(string $value): int {
        if (!preg_match('/^[1-9][0-9]*$/', trim($value))) {
            throw new \moodle_exception('bms_odoo_transport_company_id', 'local_kbacompany');
        }
        return (int)$value;
    }

    private static function header_value(string $value): string {
        if ($value === '' || preg_match('/[\r\n]/', $value)) {
            throw new \moodle_exception('bms_odoo_transport_invalid_database', 'local_kbacompany');
        }
        return $value;
    }

    private static function safe_context($context): array {
        if (!is_array($context)) {
            return [];
        }
        $allowed = ['uid', 'lang', 'tz', 'allowed_company_ids'];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $out[$key] = $context[$key];
            }
        }
        return $out;
    }
}
