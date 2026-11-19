<?php
/**
 * BMS Release Operator V1c — local read-only Moodle probe (PARTIAL evidence).
 *
 * CLI only. No Moodle bootstrap, web route, SSH, write operation or installer.
 * A dedicated SELECT-only database user must be provided by the hosting
 * administrator through private stdin. Connection restricted to local MySQL.
 *
 * IMPORTANT: No signature / remote authentication. This produces partial raw
 * observations, NOT the authenticated V1b inventory schema and NEVER GO.
 */
declare(strict_types=1);

function bms_release_probe_hold(string $reason): void {
    echo json_encode([
        'schema' => 'bms24.release_operator.server_probe.v1c',
        'status' => 'HOLD', 'deployable' => false,
        'reason' => $reason, 'actions_executed' => [],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(2);
}

if (PHP_SAPI !== 'cli' || getenv('BMS_RELEASE_READONLY_PROBE') !== '1') {
    bms_release_probe_hold('CLI opt-in required');
}
if ($argc !== 3) {
    bms_release_probe_hold('two local directory arguments required');
}

function bms_release_probe_version(string $file, string $expression): int {
    $data = @file_get_contents($file);
    if ($data === false || !preg_match($expression, $data, $match)) {
        throw new RuntimeException('plugin version file not readable');
    }
    return (int)$match[1];
}

function bms_release_probe_tree(string $root): array {
    $root = realpath($root);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('missing plugin directory');
    }
    $pending = [$root];
    $files = [];
    $total = 0;
    while ($pending) {
        $dir = array_pop($pending);
        $items = @scandir($dir);
        if (!is_array($items)) {
            throw new RuntimeException('unreadable plugin directory');
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_link($path)) {
                throw new RuntimeException('plugin symlinks prohibited');
            }
            if (is_dir($path)) {
                $pending[] = $path;
            } elseif (is_file($path)) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/',
                    substr($path, strlen($root) + 1));
                if (preg_match('/(^|\/)(config\.php|\.env|id_rsa|id_ed25519)$/', $relative)) {
                    throw new RuntimeException('unexpected private file in plugin tree');
                }
                $size = @filesize($path);
                if ($size === false || $size > 67108864) {
                    throw new RuntimeException('plugin file exceeds inspection bound');
                }
                $total += $size;
                if ($total > 536870912 || count($files) >= 10000) {
                    throw new RuntimeException('plugin tree exceeds inspection bound');
                }
                $files[$relative] = $path;
            } else {
                throw new RuntimeException('unexpected plugin filesystem entry');
            }
        }
    }
    ksort($files, SORT_STRING);
    $hash = hash_init('sha256');
    foreach ($files as $name => $path) {
        $digest = @hash_file('sha256', $path);
        if ($digest === false) {
            throw new RuntimeException('plugin file hash failed');
        }
        hash_update($hash, $name . "\0" . $digest . "\n");
    }
    return ['algorithm' => 'bms24-relative-path-null-file-sha256-v1',
            'sha256' => hash_final($hash), 'files' => count($files)];
}

function bms_release_probe_config(PDO $pdo, string $prefix, string $plugin): int {
    $stmt = $pdo->prepare('SELECT value FROM ' . $prefix .
        'config_plugins WHERE plugin = :plugin AND name = :name LIMIT 1');
    $stmt->execute(['plugin' => $plugin, 'name' => 'version']);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || !preg_match('/^[0-9]{8,12}$/', $value)) {
        throw new RuntimeException('installed plugin DB version unavailable');
    }
    return (int)$value;
}

function bms_release_probe_coreconfig(PDO $pdo, string $prefix, string $name): ?string {
    $stmt = $pdo->prepare('SELECT value FROM ' . $prefix .
        'config WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

try {
    $root = realpath($argv[1]);
    $storage = realpath($argv[2]);
    if ($root === false || $storage === false || !is_dir($root) || !is_dir($storage) ||
            is_link($argv[1]) || is_link($argv[2])) {
        throw new RuntimeException('invalid local site/storage directories');
    }
    if (!is_file($root . '/version.php') ||
            !is_file($root . '/local/kbacompany/version.php') ||
            !is_file($root . '/local/kbacorporate/version.php')) {
        throw new RuntimeException('Moodle/BMS/Corporate versions unavailable');
    }

    $raw = stream_get_contents(STDIN, 16384);
    $credentials = json_decode((string)$raw, true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($credentials) ||
        array_keys($credentials) !== ['host', 'port', 'dbname', 'user', 'password', 'prefix'] ||
        !in_array($credentials['host'], ['127.0.0.1', 'localhost', '::1'], true) ||
        !is_int($credentials['port']) || $credentials['port'] < 1 ||
        $credentials['port'] > 65535) {
        throw new RuntimeException('invalid readonly DB connection input');
    }
    foreach (['dbname', 'user', 'prefix'] as $key) {
        if (!is_string($credentials[$key]) ||
                !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $credentials[$key])) {
            throw new RuntimeException('invalid DB identifier');
        }
    }
    if (!is_string($credentials['password']) ||
            strlen($credentials['password']) > 4096) {
        throw new RuntimeException('invalid private DB input');
    }

    $pdo = new PDO(
        'mysql:host=' . $credentials['host'] . ';port=' . $credentials['port'] .
        ';dbname=' . $credentials['dbname'] . ';charset=utf8mb4',
        $credentials['user'], $credentials['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]
    );
    unset($credentials['password']);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $prefix = $credentials['prefix'];

    $bmsdb = bms_release_probe_config($pdo, $prefix, 'local_kbacompany');
    $corpdb = bms_release_probe_config($pdo, $prefix, 'local_kbacorporate');
    $sql = 'SELECT COUNT(*) AS present FROM information_schema.TABLES ' .
           'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?, ?)';
    $names = ['dreport', 'dreportver', 'dreportlink', 'dreportrev', 'dreportlog'];
    $tableNames = [];
    foreach ($names as $name) {
        $tableNames[] = $prefix . 'local_kbacompany_' . $name;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($tableNames);
    $reportTables = (int)$stmt->fetchColumn();
    $dbinfo = $pdo->query('SELECT @@version AS server_version, ' .
                           '@@version_comment AS server_comment')->fetch(PDO::FETCH_ASSOC);
    $maintenance = bms_release_probe_coreconfig($pdo, $prefix, 'maintenance_enabled');
    $pdo->rollBack();

    $core = @file_get_contents($root . '/version.php');
    if ($core === false || !preg_match('/\$release\s*=\s*[\x27"](\d+\.\d+\.\d+)/',
                                       $core, $moodle)) {
        throw new RuntimeException('Moodle release not readable');
    }
    $bmsCode = bms_release_probe_version(
        $root . '/local/kbacompany/version.php', '/\$plugin->version\s*=\s*(\d+)\s*;/');
    $corpCode = bms_release_probe_version(
        $root . '/local/kbacorporate/version.php', '/\$plugin->version\s*=\s*(\d+)\s*;/');
    $bmsTree = bms_release_probe_tree($root . '/local/kbacompany');
    $corpTree = bms_release_probe_tree($root . '/local/kbacorporate');

    $serverComment = (string)($dbinfo['server_comment'] ?? '');
    $dbFamily = stripos($serverComment, 'Percona') !== false ? 'Percona' :
        (stripos($serverComment, 'MariaDB') !== false ? 'MariaDB' : 'UNKNOWN');
    $freeBytes = @disk_free_space($storage);
    if ($freeBytes === false) {
        throw new RuntimeException('storage availability not measurable');
    }
    $result = [
        'schema' => 'bms24.release_operator.server_probe.v1c',
        'status' => 'HOLD', 'deployable' => false,
        'target_claimed' => 'UNVERIFIED_LOCAL_SITE',
        'origin_authenticated' => false,
        'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'software' => [
            'moodle_version' => $moodle[1],
            'php_version' => PHP_VERSION,
            'db_family_detected' => $dbFamily,
            'db_version_detected' => (string)($dbinfo['server_version'] ?? ''),
        ],
        'components' => [
            'bms_code_version' => $bmsCode,
            'bms_db_version' => $bmsdb,
            'corporate_code_version' => $corpCode,
            'corporate_db_version' => $corpdb,
            'bms_tree' => $bmsTree,
            'corporate_tree' => $corpTree,
        ],
        'database' => ['daily_report_tables_count' => $reportTables],
        'storage' => ['free_bytes_on_selected_volume' => (int)$freeBytes,
                      'free_inodes' => null],
        'services' => [
            'maintenance_db_flag' => $maintenance,
            'cli_maintenance_marker' => 'NOT_MEASURED',
            'cron_expected_enabled' => 'NOT_MEASURED',
            'release_lock_active' => 'NOT_MEASURED',
        ],
        'actions_executed' => [],
        'blockers' => [
            'Probe output is not signed or remotely authenticated',
            'Tree algorithm is different from earlier operator runtime-tree identity',
            'Cron, lock, CLI maintenance marker and inode capacity remain unverified',
            'No fresh backup/isolated restore, writer pause or DDL recovery evidence',
            'No owner approval and no publication executor',
        ],
    ];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // Never echo PDO exceptions, credentials, local paths or query parameters.
    bms_release_probe_hold('read-only probe could not complete; inspect privately');
}
