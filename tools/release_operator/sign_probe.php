<?php
/**
 * V1d: sign a LOCAL, previously produced V1c read-only site probe.
 *
 * This script cannot collect inventory itself and therefore cannot attest
 * that a report actually came from production. A signature proves only that
 * the signing key was used. Provision private key + challenge OUTSIDE Git,
 * chat and untrusted CI. No site, database, network or deploy calls.
 */
declare(strict_types=1);

function bms_sign_hold(): void {
    echo json_encode([
        'schema' => 'bms24.release_operator.signed_probe.v1d',
        'status' => 'HOLD', 'deployable' => false, 'reason' => 'signer rejected input',
        'actions_executed' => [],
    ]) . "\n";
    exit(2);
}

if (PHP_SAPI !== 'cli' || getenv('BMS_RELEASE_SIGNING_OPTIN') !== '1' ||
    $argc !== 4 || !extension_loaded('openssl')) {
    bms_sign_hold();
}

try {
    [$self, $challengeFile, $probeFile, $pubFile] = $argv;
    $privFile = getenv('BMS_RELEASE_PRIVATE_KEY_FILE');
    if (!is_string($privFile) || $privFile === '' || !is_file($privFile) ||
        is_link($privFile) || (fileperms($privFile) & 0077) !== 0 ||
        is_link($challengeFile) || is_link($probeFile) || is_link($pubFile) ||
        !is_file($challengeFile) || !is_file($probeFile) || !is_file($pubFile)) {
        throw new RuntimeException('invalid input');
    }
    $challengeRaw = file_get_contents($challengeFile);
    $probeRaw = file_get_contents($probeFile);
    $publicPem = file_get_contents($pubFile);
    $privatePem = file_get_contents($privFile);
    if ($challengeRaw === false || $probeRaw === false ||
        $publicPem === false || $privatePem === false ||
        strlen($challengeRaw) > 4096 || strlen($probeRaw) > 1048576 ||
        strlen($publicPem) > 16384 || strlen($privatePem) > 16384) {
        throw new RuntimeException('invalid input sizes');
    }
    $challenge = json_decode($challengeRaw, true, 8, JSON_THROW_ON_ERROR);
    $probe = json_decode($probeRaw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($challenge) || !is_array($probe) ||
        array_keys($challenge) !== ['schema', 'release_id', 'target', 'nonce'] ||
        $challenge['schema'] !== 'bms24.release_operator.challenge.v1d' ||
        !is_string($challenge['release_id']) ||
        !preg_match('/^BMS24-[A-Z0-9][A-Z0-9-]{2,48}$/D', $challenge['release_id']) ||
        !is_string($challenge['target']) ||
        !in_array($challenge['target'], ['production', 'staging', 'dev', 'synthetic'], true) ||
        !is_string($challenge['nonce']) ||
        !preg_match('/^[a-f0-9]{64}$/D', $challenge['nonce'])) {
        throw new RuntimeException('invalid challenge');
    }
    if (($probe['schema'] ?? null) !== 'bms24.release_operator.server_probe.v1c' ||
        ($probe['status'] ?? null) !== 'HOLD' ||
        ($probe['deployable'] ?? null) !== false ||
        ($probe['origin_authenticated'] ?? null) !== false ||
        ($probe['target_claimed'] ?? null) !== 'UNVERIFIED_LOCAL_SITE' ||
        ($probe['actions_executed'] ?? null) !== []) {
        throw new RuntimeException('not a read-only probe');
    }
    $stamp = $probe['captured_at'] ?? null;
    if (!is_string($stamp) ||
        !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $stamp)) {
        throw new RuntimeException('invalid timestamp');
    }
    $unix = strtotime($stamp);
    if ($unix === false || $unix < time() - 300 || $unix > time() + 30) {
        throw new RuntimeException('stale/future probe');
    }
    // This framing is identical to verify_signed_probe.py. Signature covers
    // domain, release, target, nonce, byte length and SHA-256 of entire JSON.
    $message = "BMS24-READONLY-INVENTORY-V1D\0" .
        $challenge['release_id'] . "\0" .
        $challenge['target'] . "\0" .
        $challenge['nonce'] . "\0" .
        strlen($probeRaw) . "\0" . hash('sha256', $probeRaw) . "\0";
    $private = openssl_pkey_get_private($privatePem);
    $public = openssl_pkey_get_public($publicPem);
    if ($private === false || $public === false ||
        openssl_pkey_get_details($private)['type'] !== OPENSSL_KEYTYPE_RSA ||
        openssl_pkey_get_details($public)['type'] !== OPENSSL_KEYTYPE_RSA) {
        throw new RuntimeException('RSA keypair required');
    }
    $signature = '';
    if (!openssl_sign($message, $signature, $private, OPENSSL_ALGO_SHA256) ||
        openssl_verify($message, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('RSA keypair/signing error');
    }
    $result = [
        'schema' => 'bms24.release_operator.signed_probe.v1d',
        'algorithm' => 'RSA-SHA256',
        'key_id' => hash('sha256', $publicPem),
        'release_id' => $challenge['release_id'],
        'target' => $challenge['target'],
        'nonce' => $challenge['nonce'],
        'payload_b64' => base64_encode($probeRaw),
        'signature_b64' => base64_encode($signature),
    ];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // Never echo credentials, key material, private paths or OpenSSL/PDO errors.
    bms_sign_hold();
}
