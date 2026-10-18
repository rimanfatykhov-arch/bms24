<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client for the external KBA Connect service.
 *
 * Corporate Dashboard never stores Bitrix24 credentials and never calls the
 * Bitrix24 REST API directly. Pairing exchanges a short-lived code for the
 * internal bearer token used only between Corporate Dashboard and KBA Connect.
 */
class kba_connect_client {
    /** @var string */
    private $baseurl;
    /** @var string */
    private $token;

    public function __construct($baseurl = null, $token = null) {
        $this->baseurl = rtrim((string)($baseurl !== null ? $baseurl : get_config('local_kbacorporate', 'kbaconnectbaseurl')), '/');
        $this->token = (string)($token !== null ? $token : get_config('local_kbacorporate', 'kbaconnecttoken'));
    }

    public function configuration_status() {
        return [
            'configured' => $this->baseurl !== '',
            'paired' => $this->baseurl !== '' && $this->token !== '',
            'baseurl' => $this->baseurl,
            'tokenconfigured' => $this->token !== '',
            'pairedat' => (int)(get_config('local_kbacorporate', 'kbaconnectpairedat') ?: 0),
        ];
    }

    /** Exchange one-time pairing code for a persistent integration token. */
    public function pair($pairingcode) {
        $pairingcode = trim((string)$pairingcode);
        if ($pairingcode === '') {
            return ['success' => false, 'available' => false, 'errorcode' => 'pairingcodeempty', 'message' => get_string('kbaconnectpairingcodeempty', 'local_kbacorporate'), 'httpcode' => 0];
        }
        return $this->request('POST', '/corporate/pair', [
            'pairing_code' => $pairingcode,
            'component' => bridge::COMPONENT,
            'module' => bridge::get_module_info(),
            'contract' => bridge::get_kba_connect_pairing_contract(),
            'site' => $this->site_identity(),
        ], false);
    }

    /** Query paired connection health. */
    public function connection_status() {
        return $this->request('GET', '/corporate/connection/status');
    }

    public function schema_status() {
        return $this->request('GET', '/corporate/schema/status');
    }

    public function provision_schema(array $schema) {
        return $this->request('POST', '/corporate/schema/provision', [
            'component' => bridge::COMPONENT,
            'module' => bridge::get_module_info(),
            'schema' => $schema,
        ]);
    }

    private function site_identity() {
        global $CFG;
        return [
            'wwwroot' => isset($CFG->wwwroot) ? (string)$CFG->wwwroot : '',
            'component' => bridge::COMPONENT,
        ];
    }

    /** Perform a JSON request; transport/HTTP errors are normalised, not thrown. */
    private function request($method, $path, array $payload = [], $useauth = true) {
        global $CFG;
        if ($this->baseurl === '') {
            return ['success' => false, 'available' => false, 'errorcode' => 'notconfigured', 'message' => get_string('kbaconnectnotconfigured', 'local_kbacorporate'), 'httpcode' => 0];
        }
        $url = $this->baseurl . '/' . ltrim($path, '/');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'available' => false, 'errorcode' => 'invalidurl', 'message' => get_string('kbaconnectinvalidurl', 'local_kbacorporate'), 'httpcode' => 0];
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader('Accept: application/json');
        if ($useauth && $this->token !== '') {
            $curl->setHeader('Authorization: Bearer ' . $this->token);
        }
        $options = ['CURLOPT_CONNECTTIMEOUT' => 8, 'CURLOPT_TIMEOUT' => 20];
        if (strtoupper($method) === 'POST') {
            $curl->setHeader('Content-Type: application/json');
            $raw = $curl->post($url, json_encode($payload), $options);
        } else {
            $raw = $curl->get($url, [], $options);
        }
        $info = $curl->get_info();
        $httpcode = !empty($info['http_code']) ? (int)$info['http_code'] : 0;
        $curlerror = $curl->get_errno() ? $curl->error : '';
        if ($curlerror !== '') {
            return ['success' => false, 'available' => false, 'errorcode' => 'transport', 'message' => $curlerror, 'httpcode' => $httpcode];
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'available' => $httpcode > 0, 'errorcode' => 'invalidjson', 'message' => get_string('kbaconnectinvalidresponse', 'local_kbacorporate'), 'httpcode' => $httpcode, 'raw' => substr((string)$raw, 0, 1000)];
        }
        $decoded['httpcode'] = $httpcode;
        if (!array_key_exists('available', $decoded)) $decoded['available'] = ($httpcode >= 200 && $httpcode < 500);
        if (!array_key_exists('success', $decoded)) $decoded['success'] = ($httpcode >= 200 && $httpcode < 300);
        return $decoded;
    }
}
