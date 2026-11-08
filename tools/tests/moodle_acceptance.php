<?php
/** Disposable full Moodle install/upgrade acceptance. No remote/server targets. */
declare(strict_types=1);

function required_env(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') { throw new RuntimeException('Missing environment: ' . $name); }
    return $value;
}
function inside(string $path, string $parent): bool {
    return strpos($path, rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}
function write_json(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
function command(array $args, string $cwd, string $log): int {
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start test process'); }
    $code = proc_close($process);
    if ($code !== 0 && is_file($log)) {
        echo 'FAIL log tail: ' . basename($log) . "\n";
        $lines = file($log);
        echo implode('', array_slice($lines, -80));
    }
    return $code;
}
function must_command(array $args, string $cwd, string $log): void {
    if (command($args, $cwd, $log) !== 0) { throw new RuntimeException('Command failed; see ' . basename($log)); }
}

$runner = realpath(required_env('RUNNER_TEMP'));
$testroot = realpath(required_env('TEST_ROOT'));
if (!$runner || !$testroot || !inside($testroot, $runner) || is_link(required_env('TEST_ROOT')) ||
        required_env('DBHOST') !== '127.0.0.1' || !in_array(required_env('DBTYPE'), ['mariadb', 'mysqli'], true)) {
    throw new RuntimeException('Unsafe acceptance environment');
}
$mode = $argv[1] ?? '';

// Child processes load only the scratch config created by the parent driver.
if (in_array($mode, ['seed', 'verify', 'snapshot'], true)) {
    $core = realpath($argv[2] ?? '');
    $output = $argv[3] ?? '';
    if (!$core || !inside($core, $testroot) || !inside($output, $testroot)) {
        throw new RuntimeException('Unsafe scratch path');
    }
    define('CLI_SCRIPT', true);
    require $core . '/config.php';
    if ($CFG->dbhost !== '127.0.0.1' || !preg_match('/^bmsci_[a-f0-9]{10}_[a-z0-9]+$/D', $CFG->dbname)) {
        throw new RuntimeException('Refusing non-disposable database');
    }
    if ($mode === 'snapshot') {
        $tables = [];
        foreach (['kbacompany', 'kbacorporate'] as $plugin) {
            $xml = simplexml_load_file($core . '/local/' . $plugin . '/db/install.xml');
            foreach ($xml->TABLES->TABLE as $table) {
                $name = (string)$table['NAME'];
                $columns = [];
                foreach ($DB->get_columns($name, false) as $field => $column) {
                    $columns[$field] = ['meta_type' => $column->meta_type, 'max_length' => $column->max_length,
                        'scale' => $column->scale, 'not_null' => $column->not_null,
                        'default_value' => $column->default_value, 'auto_increment' => $column->auto_increment];
                }
                ksort($columns);
                $indexes = [];
                foreach ($DB->get_indexes($name, false) as $index) {
                    $indexes[] = ['unique' => (bool)$index['unique'], 'columns' => array_values($index['columns'])];
                }
                usort($indexes, static function($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
                $tables[$name] = ['columns' => $columns, 'indexes' => $indexes];
            }
        }
        ksort($tables);
        write_json($output, ['versions' => ['local_kbacompany' => get_config('local_kbacompany', 'version'),
            'local_kbacorporate' => get_config('local_kbacorporate', 'version')], 'tables' => $tables]);
        echo 'PASS plugin schema snapshot: ' . count($tables) . " tables\n";
        exit(0);
    }
    if ($mode === 'seed') {
        $fixtures = [];
        foreach (['local_kbacompany_task', 'local_kbacompany_deviation'] as $table) {
            $columns = $DB->get_columns($table, false);
            foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $tenant) {
                $row = new stdClass();
                foreach ($columns as $name => $column) {
                    if ($column->auto_increment || !$column->not_null || $column->default_value !== null) { continue; }
                    $row->$name = in_array($column->meta_type, ['I', 'N', 'F', 'R', 'L'], true) ? 0 : '';
                }
                $row->companyuuid = $tenant;
                if (isset($columns['uuid'])) {
                    $hex = bin2hex(random_bytes(16));
                    $row->uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
                        '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
                }
                foreach (['starttime' => '09:15', 'duetime' => '17:20', 'executionstatus' => 'inprogress'] as $field => $value) {
                    if (isset($columns[$field])) { $row->$field = $value; }
                }
                $id = $DB->insert_record($table, $row);
                $actual = (array)$DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
                $fixtures[] = ['table' => $table, 'id' => $id, 'row' => $actual];
            }
        }
        write_json($output, $fixtures);
        echo "PASS seeded four synthetic preservation rows across two tenant UUIDs; not an ACL test\n";
        exit(0);
    }
    $fixtures = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixtures as $fixture) {
        $actual = (array)$DB->get_record($fixture['table'], ['id' => $fixture['id']], '*', MUST_EXIST);
        foreach ($fixture['row'] as $field => $value) {
            if ($actual[$field] !== $value && !($actual[$field] !== null && $value !== null && (string)$actual[$field] === (string)$value)) {
                throw new RuntimeException('Preserved fixture changed: ' . $fixture['table'] . '.' . $field);
            }
        }
        $expected = $fixture['table'] === 'local_kbacompany_task' ? ['starttime' => '', 'duetime' => ''] :
            ['executionstatus' => 'pending'];
        foreach ($expected as $field => $default) {
            if (!array_key_exists($field, $fixture['row']) && (!isset($actual[$field]) || $actual[$field] !== $default)) {
                throw new RuntimeException('New field default incorrect: ' . $field);
            }
        }
    }
    echo "PASS preserved synthetic two-tenant data and new-column defaults\n";
    exit(0);
}
if ($mode !== 'run') { throw new RuntimeException('Expected run|seed|verify|snapshot'); }
$source = realpath(required_env('MOODLE_ROOT'));
$candidate = realpath(required_env('CANDIDATE_ROOT'));
if (!$source || !$candidate || inside($testroot, $source) || inside($testroot, $candidate)) {
    throw new RuntimeException('Scratch root must be separate from source trees');
}
if (!is_file($source . '/admin/cli/install_database.php') || is_file($source . '/config.php')) {
    throw new RuntimeException('Expected unconfigured complete Moodle core checkout');
}
$logs = $testroot . '/evidence';
if (file_exists($logs)) { throw new RuntimeException('Use a new TEST_ROOT; previous run exists'); }
mkdir($logs, 0700, true);
$runid = bin2hex(random_bytes(5));
$port = filter_var(required_env('DBPORT'), FILTER_VALIDATE_INT);
if (!$port || $port < 1 || $port > 65535) { throw new RuntimeException('Invalid database port'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', required_env('DBUSER'), required_env('DBPASS'), '', $port);
$dbversion = $connection->server_info;
$versioncomment = $connection->query('SELECT @@version_comment')->fetch_row()[0];
if ((required_env('DBTYPE') === 'mariadb') !== (stripos($dbversion, 'MariaDB') !== false)) {
    throw new RuntimeException('Actual database engine differs from requested profile');
}
if (required_env('DBTYPE') === 'mysqli' && stripos($versioncomment, 'Percona') === false) {
    throw new RuntimeException('mysqli acceptance profile requires actual Percona server');
}
$main = '740fce16db15bb3551071cc11fe584c680fd738d';
$import = 'eccc1eb221df90675067b1011e986702d96a4985';
$report = ['run_id' => $runid, 'php' => PHP_VERSION, 'dbtype' => required_env('DBTYPE'),
    'database_server' => $dbversion, 'database_version_comment' => $versioncomment,
    'scope' => 'isolated Moodle core plus local_kbacompany/local_kbacorporate only',
    'scenarios' => [], 'phpunit' => [], 'schema_comparison' => [],
    'not_covered' => ['browser/UI', 'production/staging deployment', 'full sibling-plugin distribution', 'backup/restore']];
$self = __FILE__;
$fresh = null;
foreach (['fresh' => null, 'from1645' => $main, 'from1879' => $import] as $name => $historical) {
    $scenario = $testroot . '/' . $name;
    $core = $scenario . '/core';
    $entry = ['status' => 'FAIL', 'steps' => [], 'bms_source' => $historical ?? 'candidate working tree',
        'corporate_source' => $historical === $import ? $main : ($historical ?? 'candidate working tree')];
    if ($historical === $import) {
        $entry['note'] = 'Reconstructed pair: import1879 deleted Corporate; Corporate explicitly taken from main1645 SHA.';
    }
    try {
        mkdir($core, 0700, true);
        must_command(['cp', '-a', $source . '/.', $core], $testroot, $logs . '/' . $name . '-copy-core.log');
        foreach (['kbacompany', 'kbacorporate'] as $plugin) {
            if (file_exists($core . '/local/' . $plugin)) { throw new RuntimeException('Core source already contains candidate plugin'); }
            $ref = $plugin === 'kbacorporate' && $historical === $import ? $main : $historical;
            if ($ref) {
                $tar = $scenario . '/' . $plugin . '.tar';
                must_command(['git', '-C', $candidate, 'archive', '--format=tar', '--output=' . $tar, $ref,
                    'local/' . $plugin], $candidate, $logs . '/' . $name . '-' . $plugin . '-archive.log');
                must_command(['tar', '-xf', $tar, '-C', $core], $testroot, $logs . '/' . $name . '-' . $plugin . '-extract.log');
            } else {
                must_command(['cp', '-a', $candidate . '/local/' . $plugin, $core . '/local/'], $testroot,
                    $logs . '/' . $name . '-' . $plugin . '-copy.log');
            }
        }
        $dbname = 'bmsci_' . $runid . '_' . $name;
        $phpunitname = 'bmsci_' . $runid . '_unit';
        $connection->query('CREATE DATABASE `' . $dbname . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if ($name === 'fresh') {
            $connection->query('CREATE DATABASE `' . $phpunitname . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        mkdir($scenario . '/data', 0700);
        mkdir($scenario . '/phpunitdata', 0700);
        $config = ['dbtype' => required_env('DBTYPE'), 'dblibrary' => 'native', 'dbhost' => '127.0.0.1',
            'dbname' => $dbname, 'dbuser' => required_env('DBUSER'), 'dbpass' => required_env('DBPASS'),
            'prefix' => 'mdl_', 'dboptions' => ['dbpersist' => false, 'dbport' => $port, 'dbsocket' => '',
                'dbcollation' => 'utf8mb4_unicode_ci'], 'wwwroot' => 'http://127.0.0.1.invalid/' . $name,
            'dataroot' => $scenario . '/data', 'admin' => 'admin', 'directorypermissions' => 0700,
            'phpunit_prefix' => 'pht_', 'phpunit_dataroot' => $scenario . '/phpunitdata',
            'phpunit_dbname' => $phpunitname, 'passwordpolicy' => 0,
            'noemailever' => true, 'upgradekey' => bin2hex(random_bytes(16))];
        $text = "<?php\nunset(\$CFG);\nglobal \$CFG;\n\$CFG = new stdClass();\n";
        foreach ($config as $key => $value) { $text .= '$CFG->' . $key . ' = ' . var_export($value, true) . ";\n"; }
        $text .= "require_once(__DIR__ . '/lib/setup.php');\n";
        file_put_contents($core . '/config.php', $text);
        chmod($core . '/config.php', 0600);
        $step = static function(string $label, array $args) use (&$entry, $core, $logs, $name): bool {
            $code = command($args, $core, $logs . '/' . $name . '-' . $label . '.log');
            $entry['steps'][$label] = ['status' => $code === 0 ? 'PASS' : 'FAIL', 'exit_code' => $code];
            echo $name . '/' . $label . ': ' . ($code === 0 ? 'PASS' : 'FAIL') . "\n";
            return $code === 0;
        };
        if (!$step('install', [PHP_BINARY, $core . '/admin/cli/install_database.php', '--agree-license',
                '--adminuser=admin', '--adminpass=' . bin2hex(random_bytes(20)), '--adminemail=ci@example.invalid',
                '--fullname=BMS isolated acceptance', '--shortname=BMSCI', '--lang=en'])) {
            throw new RuntimeException('Full Moodle installation failed');
        }
        if ($name === 'fresh') { $fresh = $core; }
        $step('historical-schema', [PHP_BINARY, $core . '/admin/cli/check_database_schema.php']);
        $seeded = $step('seed', [PHP_BINARY, $self, 'seed', $core, $scenario . '/fixtures.json']);
        if ($historical) {
            foreach (['kbacompany', 'kbacorporate'] as $plugin) {
                // Rename only our disposable historical copy; never delete source or user files.
                rename($core . '/local/' . $plugin, $scenario . '/historical-' . $plugin);
                must_command(['cp', '-a', $candidate . '/local/' . $plugin, $core . '/local/'], $testroot,
                    $logs . '/' . $name . '-' . $plugin . '-candidate.log');
            }
            if (!$step('upgrade', [PHP_BINARY, $core . '/admin/cli/upgrade.php', '--non-interactive'])) {
                throw new RuntimeException('Actual Moodle upgrade failed');
            }
        }
        $step('candidate-schema', [PHP_BINARY, $core . '/admin/cli/check_database_schema.php']);
        if ($seeded) { $step('verify-data', [PHP_BINARY, $self, 'verify', $core, $scenario . '/fixtures.json']); }
        $step('snapshot', [PHP_BINARY, $self, 'snapshot', $core, $scenario . '/schema.json']);
        $step('repeat-upgrade', [PHP_BINARY, $core . '/admin/cli/upgrade.php', '--non-interactive']);
        if ($seeded) { $step('verify-repeat', [PHP_BINARY, $self, 'verify', $core, $scenario . '/fixtures.json']); }
        $step('repeat-snapshot', [PHP_BINARY, $self, 'snapshot', $core, $scenario . '/schema-repeat.json']);
        if (is_file($scenario . '/schema.json') && is_file($scenario . '/schema-repeat.json')) {
            $entry['steps']['repeat-schema-equality'] = ['status' =>
                file_get_contents($scenario . '/schema.json') === file_get_contents($scenario . '/schema-repeat.json') ? 'PASS' : 'FAIL'];
        }
        $entry['status'] = count(array_filter($entry['steps'], static function($s) { return $s['status'] !== 'PASS'; })) ? 'FAIL' : 'PASS';
    } catch (Throwable $e) {
        $entry['error'] = $e->getMessage();
        echo $name . ': FAIL (see report/logs)' . "\n";
    }
    $report['scenarios'][$name] = $entry;
    foreach (['schema.json', 'schema-repeat.json', 'fixtures.json'] as $artifact) {
        if (is_file($scenario . '/' . $artifact)) { copy($scenario . '/' . $artifact, $logs . '/' . $name . '-' . $artifact); }
    }
    write_json($logs . '/report.json', $report);
}

foreach (['from1645', 'from1879'] as $name) {
    $a = $testroot . '/fresh/schema.json';
    $b = $testroot . '/' . $name . '/schema.json';
    $report['schema_comparison'][$name] = !is_file($a) || !is_file($b) ? 'NOT RUN' :
        (file_get_contents($a) === file_get_contents($b) ? 'PASS' : 'FAIL');
}

// Independent from historical upgrade results. Initialization uses a fourth database.
if ($fresh) {
    $code = command([PHP_BINARY, $fresh . '/admin/tool/phpunit/cli/init.php'], $fresh, $logs . '/phpunit-init.log');
    $report['phpunit']['init'] = $code === 0 ? 'PASS' : 'FAIL';
    // Run focused files in separate PHP processes before broad legacy discovery.
    foreach (['schema_parity_2026110890_test', 'pr48_tenant_isolation_test'] as $test) {
        if ($code !== 0) { $report['phpunit'][$test] = 'NOT RUN'; continue; }
        $exit = command([PHP_BINARY, $fresh . '/vendor/bin/phpunit', '--configuration', $fresh . '/phpunit.xml',
            '--log-junit', $logs . '/' . $test . '-junit.xml',
            $fresh . '/local/kbacompany/tests/' . $test . '.php'], $fresh, $logs . '/' . $test . '.log');
        $report['phpunit'][$test] = $exit === 0 ? 'PASS' : 'FAIL';
        echo $test . ': ' . $report['phpunit'][$test] . "\n";
    }
    foreach (['local_kbacompany', 'local_kbacorporate'] as $component) {
        if ($code !== 0) { $report['phpunit'][$component] = 'NOT RUN'; continue; }
        $exit = command([PHP_BINARY, $fresh . '/vendor/bin/phpunit', '--configuration', $fresh . '/phpunit.xml',
            '--testsuite', $component . '_testsuite', '--log-junit', $logs . '/' . $component . '-junit.xml'],
            $fresh, $logs . '/' . $component . '-phpunit.log');
        $report['phpunit'][$component] = $exit === 0 ? 'PASS' : 'FAIL';
        echo $component . '/phpunit: ' . $report['phpunit'][$component] . "\n";
    }
} else {
    $report['phpunit'] = ['init' => 'NOT RUN', 'reason' => 'Fresh Moodle installation failed'];
}
$failed = false;
foreach ($report['scenarios'] as $scenario) { $failed = $failed || $scenario['status'] !== 'PASS'; }
foreach ($report['schema_comparison'] as $status) { $failed = $failed || $status !== 'PASS'; }
foreach ($report['phpunit'] as $status) { $failed = $failed || $status !== 'PASS'; }
$report['status'] = $failed ? 'FAIL' : 'PASS';
write_json($logs . '/report.json', $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo 'Full isolated acceptance: ' . $report['status'] . "\n";
exit($failed ? 1 : 0);
