<?php
// CLI metadata inventory. Never loads Moodle setup/config, writes files or upgrades.
if (PHP_SAPI !== 'cli') { exit(2); }
ini_set('display_errors', '0');
function inventory_literal(string $text, string $name, $default = null) {
    $pattern = '/\$CFG->' . preg_quote($name, '/') . '\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[0-9]+)\s*;/';
    if (!preg_match($pattern, $text, $match)) { return $default; }
    $raw = $match[1];
    if (ctype_digit($raw)) { return (int)$raw; }
    if ($raw[0] === "'") { return str_replace(["\\'", "\\\\"], ["'", "\\"], substr($raw, 1, -1)); }
    if (strpos($raw, '$') !== false) { throw new RuntimeException('nonliteral_config'); }
    return stripcslashes(substr($raw, 1, -1));
}
try {
    $root = $argv[1] ?? '/var/www/html';
    $config = @file_get_contents($root . '/config.php');
    if ($config === false) { throw new RuntimeException('config_unavailable'); }
    $values = [];
    foreach (['dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix'] as $name) {
        $values[$name] = inventory_literal($config, $name);
        // Support only a literal absolute local secret-file reference; never evaluate config.
        if ($name === 'dbpass' && $values[$name] === null &&
                preg_match('/\$CFG->dbpass\s*=\s*trim\(file_get_contents\(([\'"])([^\'"]+)\1\)\)\s*;/', $config, $secret)) {
            $path = $secret[2];
            if (substr($path, 0, 1) !== '/' || strpos($path, '://') !== false) {
                throw new RuntimeException('nonliteral_config');
            }
            $secretvalue = @file_get_contents($path);
            if ($secretvalue === false) { throw new RuntimeException('config_unavailable'); }
            $values[$name] = trim($secretvalue);
        }
        if ($values[$name] === null) { throw new RuntimeException('nonliteral_config_' . $name); }
    }
    if (!in_array($values['dbtype'], ['mysqli', 'mariadb'], true) ||
            !preg_match('/^[a-zA-Z0-9_]+$/D', $values['prefix'])) {
        throw new RuntimeException('unsupported_database');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($values['dbhost'], $values['dbuser'], $values['dbpass'], $values['dbname']);
    $db->query('START TRANSACTION READ ONLY');
    $server = $db->query('SELECT VERSION() AS version, @@version_comment AS engine, @@collation_database AS collation')->fetch_assoc();
    $prefix = $values['prefix'];
    $installed = [];
    foreach ($db->query("SELECT plugin,value FROM {$prefix}config_plugins WHERE name='version' AND plugin IN ('local_kbacompany','local_kbacorporate')") as $row) {
        $installed[$row['plugin']] = $row['value'];
    }
    $coredb = $db->query("SELECT value FROM {$prefix}config WHERE name='version'")->fetch_assoc();
    $fields = [];
    $stmt = $db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    foreach (['task' => ['starttime','duetime'], 'deviation' => ['executionstatus']] as $table => $wanted) {
        $tablename = $prefix . 'local_kbacompany_' . $table;
        $stmt->bind_param('s', $tablename); $stmt->execute();
        $fields[$table] = [];
        $stmt->bind_result($columnname, $columntype, $nullable, $defaultvalue);
        while ($stmt->fetch()) {
            if (in_array($columnname, $wanted, true)) {
                $fields[$table][] = ['COLUMN_NAME'=>$columnname, 'COLUMN_TYPE'=>$columntype,
                    'IS_NULLABLE'=>$nullable, 'COLUMN_DEFAULT'=>$defaultvalue];
            }
        }
    }
    $indexes = [];
    $stmt = $db->prepare('SELECT INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    foreach (['task','tasklog','bplanitem'] as $table) {
        $tablename = $prefix . 'local_kbacompany_' . $table;
        $stmt->bind_param('s', $tablename); $stmt->execute();
        $stmt->bind_result($indexname, $sequence, $columnname, $nonunique);
        while ($stmt->fetch()) {
            $indexes[$table][] = ['INDEX_NAME'=>$indexname, 'SEQ_IN_INDEX'=>$sequence,
                'COLUMN_NAME'=>$columnname, 'NON_UNIQUE'=>$nonunique];
        }
    }
    $db->rollback();
    $code = [];
    foreach (['moodle' => '/version.php', 'local_kbacompany' => '/local/kbacompany/version.php',
            'local_kbacorporate' => '/local/kbacorporate/version.php'] as $component => $path) {
        $content = @file_get_contents($root . $path);
        if ($content === false) { $code[$component] = null; continue; }
        preg_match('/\$(?:plugin->)?version\s*=\s*([0-9.]+)/', $content, $version);
        preg_match('/\$(?:plugin->)?release\s*=\s*[\'"]([^\'"]+)/', $content, $release);
        $code[$component] = ['version' => $version[1] ?? null, 'release' => $release[1] ?? null,
            'version_file_sha256' => hash('sha256', $content)];
    }
    $hashes = [];
    foreach (['local/kbacompany/db/install.xml','local/kbacompany/db/upgrade.php',
              'local/kbacorporate/classes/local/bridge.php'] as $file) {
        $hashes[$file] = is_file($root . '/' . $file) ? hash_file('sha256', $root . '/' . $file) : null;
    }
    echo json_encode(['captured_utc' => gmdate('c'), 'read_only' => true, 'moodle_bootstrap' => false,
        'php_cli' => PHP_VERSION, 'php_sapi' => PHP_SAPI, 'server' => $server, 'code' => $code,
        'installed_plugin_versions' => $installed, 'installed_core_version' => $coredb['value'] ?? null,
        'fields' => $fields, 'indexes_metadata' => $indexes, 'source_file_sha256' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    // Do not disclose DSNs, credentials, config contents or database errors.
    $reason = $error instanceof RuntimeException && preg_match('/^(nonliteral_config(?:_[a-z]+)?|config_unavailable|unsupported_database)$/D', $error->getMessage()) ? $error->getMessage() : get_class($error);
    file_put_contents('php://stderr', "HOLD: metadata inventory unavailable ($reason); no Moodle bootstrap.\n");
    exit(2);
}
