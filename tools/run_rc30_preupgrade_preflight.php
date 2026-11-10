<?php
/** Read private JSON connection parameters from stdin; never loads Moodle config. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__ . '/rc30_preupgrade_preflight.php';
$connection = null;
try {
    $config = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    foreach (['host', 'dbname', 'user', 'password', 'prefix'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) { throw new RuntimeException('Invalid input'); }
    }
    foreach (['host', 'dbname'] as $key) {
        if (!preg_match('/\A[a-zA-Z0-9_.:-]+\z/D', $config[$key])) { throw new RuntimeException('Invalid target'); }
    }
    $port = $config['port'] ?? 3306;
    if (!is_int($port) || $port < 1 || $port > 65535) { throw new RuntimeException('Invalid port'); }
    // Use a private local socket/SSH tunnel endpoint; never unencrypted remote credentials.
    if (!in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Local or SSH-tunnel endpoint required');
    }
    $connection = new PDO('mysql:host=' . $config['host'] . ';port=' . $port . ';dbname=' . $config['dbname'] . ';charset=utf8mb4',
        $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
    unset($config['password']);
    $connection->exec('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $result = rc30_preupgrade_preflight(static function(string $sql, array $params) use ($connection): array {
        $statement = $connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }, $config['prefix']);
    $connection->rollBack();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    if ($connection && $connection->inTransaction()) { $connection->rollBack(); }
    // PDO exceptions may contain server/account/SQL details; disclose no exception text.
    echo json_encode(['status' => 'HOLD', 'reason' => $error instanceof PDOException ?
        'Connection/query failed; inspect privately' : $error->getMessage()]) . "\n";
    exit(1);
}
