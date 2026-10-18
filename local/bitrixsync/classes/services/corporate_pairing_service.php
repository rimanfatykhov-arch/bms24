<?php
namespace local_bitrixsync\services;

use local_bitrixsync\logging\logger;
use local_bitrixsync\repositories\corporate_connection_repository;
use local_bitrixsync\repositories\corporate_pair_attempt_repository;
use local_bitrixsync\repositories\corporate_pairing_repository;

defined('MOODLE_INTERNAL') || die();

class corporate_pairing_service {
    private const TTL = 900;
    private const REQUIRED_COMPONENT = 'local_kbacorporate';
    private const REQUIRED_API = '1.11';

    private $pairings;
    private $connections;
    private $attempts;

    public function __construct() {
        $this->pairings = new corporate_pairing_repository();
        $this->connections = new corporate_connection_repository();
        $this->attempts = new corporate_pair_attempt_repository();
    }

    public function generate(string $environment = 'production'): array {
        $this->ensure_pepper();
        $this->pairings->invalidate_active();
        $code = $this->new_pairing_code();
        $expires = time() + self::TTL;
        $this->pairings->create($this->code_hash($code), $this->normalize_environment($environment), $expires);
        logger::corporate_pairing('Pairing code generated', ['environment' => $environment, 'expires' => $expires]);
        return ['success' => true, 'pairing_code' => $code, 'expires_at' => $expires, 'ttl' => self::TTL];
    }

    public function pair(array $payload, string $remoteip): array {
        $this->ensure_pepper();
        $iphash = hash_hmac('sha256', $remoteip, $this->pepper());
        $ratestate = $this->attempts->state($iphash);
        if ((int)$ratestate->blockeduntil > time()) {
            return $this->error('pairing_rate_limited', 'Too many pairing attempts', 429, ['retry_after' => (int)$ratestate->blockeduntil - time()]);
        }

        $code = $this->normalize_code((string)($payload['pairing_code'] ?? ''));
        logger::corporate_pairing('Pair request received', [
            'component' => (string)($payload['component'] ?? ''),
            'client_version' => (string)($payload['version'] ?? ''),
            'api_version' => (string)($payload['api_version'] ?? ''),
        ]);
        if ($code === '') {
            $this->attempts->fail($iphash);
            return $this->error('pairing_code_invalid', 'Pairing code is invalid', 400);
        }

        $record = $this->pairings->find_by_hash($this->code_hash($code));
        if (!$record) {
            $this->attempts->fail($iphash);
            logger::corporate_pairing('Pairing invalid');
            return $this->error('pairing_code_invalid', 'Pairing code is invalid', 403);
        }
        $this->pairings->mark_attempt((int)$record->id);
        if (!empty($record->used)) {
            $this->attempts->fail($iphash);
            return $this->error('pairing_code_used', 'Pairing code has already been used', 409);
        }
        if ((int)$record->timeexpires < time()) {
            $this->attempts->fail($iphash);
            return $this->error('pairing_code_expired', 'Pairing code has expired', 410);
        }

        $component = trim((string)($payload['component'] ?? self::REQUIRED_COMPONENT));
        $clientversion = trim((string)($payload['version'] ?? ''));
        $apiversion = trim((string)($payload['api_version'] ?? self::REQUIRED_API));
        if ($component !== self::REQUIRED_COMPONENT || version_compare($apiversion, self::REQUIRED_API, '<')) {
            $this->attempts->fail($iphash);
            logger::corporate_pairing('Client incompatible', ['component' => $component, 'api_version' => $apiversion]);
            return $this->error('client_incompatible', 'Corporate Dashboard client is incompatible', 409);
        }

        $token = bin2hex(random_bytes(48));
        $tokenhash = hash('sha256', $token);
        $fingerprint = substr($tokenhash, 0, 4) . '...' . substr($tokenhash, -4);
        $environment = $this->normalize_environment((string)$record->environment);
        $connectionid = 'corporate-dashboard-' . $environment . '-' . substr(bin2hex(random_bytes(5)), 0, 10);
        $this->connections->create([
            'connectionid' => $connectionid,
            'component' => $component,
            'environment' => $environment,
            'tokenhash' => $tokenhash,
            'tokenfingerprint' => $fingerprint,
            'clientversion' => $clientversion,
            'apiversion' => $apiversion,
        ]);
        $this->pairings->mark_used((int)$record->id);
        $this->attempts->clear($iphash);
        logger::corporate_pairing('Pairing successful', ['connection_id' => $connectionid, 'token_fingerprint' => $fingerprint, 'environment' => $environment]);

        return [
            'success' => true,
            'paired' => true,
            'integration_token' => $token,
            'connection' => [
                'id' => $connectionid,
                'component' => $component,
                'environment' => $environment,
                'dashboard_version' => $clientversion,
                'dashboard_api' => $apiversion,
            ],
            'kba_connect_version' => $this->release(),
        ];
    }

    public function latest_connection(): ?\stdClass {
        return $this->connections->latest_active();
    }

    public function revoke_latest(): bool {
        $record = $this->latest_connection();
        if (!$record) {
            return false;
        }
        $this->connections->revoke((int)$record->id);
        logger::corporate_pairing('Connection revoked', ['connection_id' => $record->connectionid]);
        return true;
    }

    private function error(string $code, string $message, int $httpcode, array $extra = []): array {
        return ['success' => false, 'code' => $code, 'message' => $message, '_httpcode' => $httpcode] + $extra;
    }

    private function new_pairing_code(): string {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'KBA-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    private function normalize_code(string $code): string {
        $code = strtoupper(trim($code));
        return preg_match('/^KBA-[23456789A-HJ-NP-Z]{4}-[23456789A-HJ-NP-Z]{4}$/', $code) ? $code : '';
    }

    private function code_hash(string $code): string {
        return hash_hmac('sha256', $code, $this->pepper());
    }

    private function normalize_environment(string $environment): string {
        $environment = strtolower(trim($environment));
        return in_array($environment, ['production', 'test', 'development'], true) ? $environment : 'production';
    }

    private function ensure_pepper(): void {
        if (trim((string)get_config('local_bitrixsync', 'corporate_pairing_pepper')) === '') {
            set_config('corporate_pairing_pepper', bin2hex(random_bytes(32)), 'local_bitrixsync');
        }
    }

    private function pepper(): string {
        return (string)get_config('local_bitrixsync', 'corporate_pairing_pepper');
    }

    private function release(): string {
        global $CFG;
        $plugin = new \stdClass();
        include($CFG->dirroot . '/local/bitrixsync/version.php');
        return (string)($plugin->release ?? 'KBA Connect');
    }
}
