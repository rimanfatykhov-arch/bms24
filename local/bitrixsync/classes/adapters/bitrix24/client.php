<?php
namespace local_bitrixsync\adapters\bitrix24;

defined('MOODLE_INTERNAL') || die();

class client {
    /** @var string */
    private $baseurl;

    /** @var array|null */
    private $lastresponse = null;

    /** @var int */
    private $lasthttpcode = 0;

    /** @var array */
    private $lastdiagnostic = [];

    public function __construct(?string $baseurl = null) {
        $configured = trim((string)(
            $baseurl ??
            get_config('local_bitrixsync', 'bitrix_webhook_url')
        ));

        if ($configured === '') {
            // Compatibility with earlier KBA Connect setting name.
            $configured = trim((string)get_config(
                'local_bitrixsync',
                'bitrix_url'
            ));
        }

        $this->baseurl = self::normalize_webhook_url($configured);
    }

    public static function normalize_webhook_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match(
                '~^(https://[^/]+/rest/\d+/[^/]+/)~i',
                $url,
                $matches
            )) {
            return $matches[1];
        }

        return rtrim($url, '/') . '/';
    }

    public function get_baseurl(): string {
        return $this->baseurl;
    }

    public function is_configured(): bool {
        return $this->baseurl !== '';
    }

    public function get_last_response(): ?array {
        return $this->lastresponse;
    }

    public function get_last_http_code(): int {
        return $this->lasthttpcode;
    }

    public function get_last_diagnostic(): array {
        return $this->lastdiagnostic;
    }

    /**
     * Call Bitrix24 REST.
     *
     * Primary transport remains form-urlencoded for backward compatibility
     * with the already working deal/catalog path.
     *
     * If a READ-ONLY method returns a non-JSON response, retry exactly once
     * with:
     *   - explicit .json transport suffix
     *   - JSON request body
     *   - longer timeout
     *
     * Mutating methods are NEVER retried to prevent duplicate writes.
     */
    public function call(string $method, array $params = []): array {
        if (!$this->is_configured()) {
            throw new \moodle_exception(
                'bitrix_not_configured',
                'local_bitrixsync'
            );
        }

        $method = trim($method, '/');
        $readonly = $this->is_readonly_method($method);

        $first = $this->perform(
            $method,
            $params,
            false,
            false
        );

        if (!empty($first['decoded'])) {
            return $this->validate_decoded(
                $method,
                $first['decoded'],
                $first['httpcode']
            );
        }

        // Safe one-time fallback for list/get/fields/profile methods.
        if ($readonly) {
            $second = $this->perform(
                $method,
                $params,
                true,
                true
            );

            if (!empty($second['decoded'])) {
                return $this->validate_decoded(
                    $method,
                    $second['decoded'],
                    $second['httpcode']
                );
            }

            $this->throw_invalid_json($method, $second);
        }

        // Do not retry create/update/add/delete calls.
        $this->throw_invalid_json($method, $first);
    }

    /**
     * Proven v0.4.6 transport:
     * POST JSON body to `<method>.json`.
     *
     * CRM userfield discovery/creation uses this explicit mode because that
     * is the transport that was stable in the original working plugin.
     */
    public function call_json(
        string $method,
        array $params = []
    ): array {
        if (!$this->is_configured()) {
            throw new \moodle_exception(
                'bitrix_not_configured',
                'local_bitrixsync'
            );
        }

        $method = trim($method, '/');

        $attempt = $this->perform(
            $method,
            $params,
            true,
            true
        );

        if (empty($attempt['decoded'])) {
            $this->throw_invalid_json(
                $method,
                $attempt
            );
        }

        return $this->validate_decoded(
            $method,
            $attempt['decoded'],
            $attempt['httpcode']
        );
    }

    private function perform(
        string $method,
        array $params,
        bool $jsonbody,
        bool $jsonsuffix
    ): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $requestmethod = preg_replace(
            '/\.json$/i',
            '',
            $method
        );

        $endpoint = rtrim($this->baseurl, '/') . '/' . $requestmethod;
        if ($jsonsuffix) {
            $endpoint .= '.json';
        }

        $curl = new \curl();

        $connecttimeout = (int)get_config(
            'local_bitrixsync',
            'bitrix_connect_timeout'
        );
        if ($connecttimeout <= 0) {
            $connecttimeout = 5;
        }
        $connecttimeout = max(1, min(15, $connecttimeout));

        $requesttimeout = (int)get_config(
            'local_bitrixsync',
            'bitrix_request_timeout'
        );
        if ($requesttimeout <= 0) {
            $requesttimeout = 15;
        }

        // Userfield discovery may be noticeably slower than deal.get.
        if (strpos($requestmethod, '.userfield.') !== false) {
            $requesttimeout = max($requesttimeout, 45);
        }

        if ($jsonbody) {
            $requesttimeout = max($requesttimeout, 45);
        }

        $requesttimeout = max(
            $connecttimeout,
            min(90, $requesttimeout)
        );

        if ($jsonbody) {
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ];
            $body = json_encode(
                $params,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($body === false) {
                throw new \moodle_exception(
                    'bitrix_request_encode_error',
                    'local_bitrixsync'
                );
            }
        } else {
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ];
            $body = http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }

        $raw = $curl->post($endpoint, $body, [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_CONNECTTIMEOUT' => $connecttimeout,
            'CURLOPT_TIMEOUT' => $requesttimeout,
            'CURLOPT_NOSIGNAL' => 1,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 3,
            // Let libcurl decode gzip/deflate/br where supported.
            'CURLOPT_ENCODING' => '',
        ]);

        $info = $curl->get_info();
        $httpcode = isset($info['http_code'])
            ? (int)$info['http_code']
            : 0;

        $this->lasthttpcode = $httpcode;

        $rawstring = $raw === false
            ? ''
            : (string)$raw;

        // Strip UTF-8 BOM and whitespace before JSON decoding.
        $clean = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $rawstring
        );
        $clean = trim((string)$clean);

        $decoded = $clean !== ''
            ? json_decode($clean, true)
            : null;

        $this->lastresponse = is_array($decoded)
            ? $decoded
            : null;

        $contenttype = '';
        if (isset($info['content_type'])) {
            $contenttype = (string)$info['content_type'];
        }

        $this->lastdiagnostic = [
            'method' => $requestmethod,
            'http_code' => $httpcode,
            'content_type' => $contenttype,
            'response_bytes' => strlen($rawstring),
            'json_error' => is_array($decoded)
                ? ''
                : json_last_error_msg(),
            'transport' => $jsonbody
                ? 'application/json'
                : 'application/x-www-form-urlencoded',
            'json_suffix' => $jsonsuffix,
            'timeout' => $requesttimeout,
        ];

        return [
            'raw' => $rawstring,
            'decoded' => is_array($decoded) ? $decoded : null,
            'httpcode' => $httpcode,
            'info' => $info,
            'diagnostic' => $this->lastdiagnostic,
        ];
    }

    private function validate_decoded(
        string $method,
        array $decoded,
        int $httpcode
    ): array {
        $this->lastresponse = $decoded;
        $this->lasthttpcode = $httpcode;

        // Bitrix Catalog may return {"error":0,...}.
        if (array_key_exists('error', $decoded)) {
            $code = (string)$decoded['error'];
            $description = trim((string)(
                $decoded['error_description'] ?? ''
            ));

            $message = $description !== ''
                ? $description
                : ($code !== ''
                    ? $code
                    : 'Bitrix24 REST error');

            throw new \local_bitrixsync\exception\bitrix_api_exception(
                $method,
                $httpcode,
                $code,
                $message,
                $decoded
            );
        }

        if ($httpcode >= 400) {
            throw new \moodle_exception(
                'bitrix_http_error',
                'local_bitrixsync',
                '',
                $httpcode
            );
        }

        return $decoded;
    }

    private function throw_invalid_json(
        string $method,
        array $attempt
    ): void {
        $diagnostic = $attempt['diagnostic'] ?? [];
        $raw = (string)($attempt['raw'] ?? '');

        // Safe preview: collapse tags/whitespace, never include webhook URL.
        $preview = strip_tags($raw);
        $preview = preg_replace('/\s+/u', ' ', $preview);
        $preview = trim((string)$preview);
        if (\core_text::strlen($preview) > 160) {
            $preview = \core_text::substr($preview, 0, 160) . '…';
        }

        $a = (object)[
            'method' => $method,
            'httpcode' => (int)($diagnostic['http_code'] ?? 0),
            'contenttype' => (string)(
                $diagnostic['content_type'] ?? ''
            ),
            'bytes' => (int)(
                $diagnostic['response_bytes'] ?? 0
            ),
            'jsonerror' => (string)(
                $diagnostic['json_error'] ?? ''
            ),
            'transport' => (string)(
                $diagnostic['transport'] ?? ''
            ),
            'preview' => $preview !== ''
                ? $preview
                : '[empty response]',
        ];

        throw new \moodle_exception(
            'bitrix_invalid_json_detail',
            'local_bitrixsync',
            '',
            $a,
            json_encode(
                $diagnostic,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function is_readonly_method(string $method): bool {
        $method = strtolower(
            preg_replace('/\.json$/i', '', trim($method))
        );

        if (in_array($method, [
            'profile',
            'method.get',
            'scope',
        ], true)) {
            return true;
        }

        foreach ([
            '.get',
            '.list',
            '.fields',
        ] as $suffix) {
            if (substr($method, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }
}
