<?php
namespace local_bitrixsync\security;

use local_bitrixsync\logging\logger;
use local_bitrixsync\repositories\corporate_connection_repository;

defined('MOODLE_INTERNAL') || die();

/** Bearer authentication for Corporate Dashboard -> KBA Connect endpoints. */
class corporate_endpoint_auth {
    public static function validate_request(): array {
        $header = self::authorization_header();
        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return ['success' => false, 'httpcode' => 401, 'code' => 'authorization_required', 'message' => 'Bearer authorization is required'];
        }
        $provided = trim((string)$matches[1]);
        if ($provided === '') {
            return ['success' => false, 'httpcode' => 403, 'code' => 'invalid_integration_token', 'message' => 'Integration token is invalid'];
        }

        // Preferred v5.0.33 path: revocable per-connection tokens stored only as hashes.
        $hash = hash('sha256', $provided);
        $repo = new corporate_connection_repository();
        $connection = null;
        foreach ($repo->active_all() as $candidate) {
            if (hash_equals((string)$candidate->tokenhash, $hash)) {
                $connection = $candidate;
                break;
            }
        }
        if ($connection) {
            $repo->touch((int)$connection->id);
            logger::corporate_pairing('Connection authenticated', ['connection_id' => $connection->connectionid, 'token_fingerprint' => $connection->tokenfingerprint]);
            return ['success' => true, 'httpcode' => 200, 'connection' => $connection];
        }

        // One-release backward compatibility for the manually configured token.
        $legacy = trim((string)get_config('local_bitrixsync', 'corporate_integration_token'));
        if ($legacy !== '' && hash_equals($legacy, $provided)) {
            return ['success' => true, 'httpcode' => 200, 'legacy' => true];
        }
        return ['success' => false, 'httpcode' => 403, 'code' => 'invalid_integration_token', 'message' => 'Integration token is invalid'];
    }

    private static function authorization_header(): string {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim((string)$_SERVER[$key]);
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0) {
                        return trim((string)$value);
                    }
                }
            }
        }
        return '';
    }
}
