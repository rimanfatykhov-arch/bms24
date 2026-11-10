<?php
/** Isolated ADR-0004 repair acceptance; no deployed targets. See ADR-0004. */
declare(strict_types=1);

function env_required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') { throw new RuntimeException('Missing ' . $name); }
    return $value;
}
function under(string $path, string $root): bool {
    return strpos($path, rtrim($root, '/') . '/') === 0;
}
function expect(bool $ok, string $id): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $id); }
    echo 'PASS ' . $id . "\n";
}
function json_write(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
function run_command(array $args, string $cwd, string $log): void {
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'],
        2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        if (is_file($log)) { echo implode('', array_slice(file($log), -50)); }
        throw new RuntimeException('Command failed: ' . basename($log));
    }
}
function identifier(string $name): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name)) { throw new RuntimeException('Unsafe identifier'); }
    return '`' . $name . '`';
}
function schema_snapshot(): array {
    global $DB, $CFG;
    $tables = [];
    foreach (['kbacompany', 'kbacorporate'] as $plugin) {
        $xml = simplexml_load_file($CFG->dirroot . '/local/' . $plugin . '/db/install.xml');
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string)$table['NAME'];
            $columns = [];
            foreach ($DB->get_columns($name, false) as $field => $column) {
                $columns[$field] = ['type' => $column->meta_type, 'length' => $column->max_length,
                    'scale' => $column->scale, 'not_null' => $column->not_null,
                    'default' => $column->default_value, 'auto' => $column->auto_increment];
            }
            ksort($columns);
            $indexes = $DB->get_indexes($name, false); ksort($indexes);
            $tables[$name] = ['columns' => $columns, 'indexes' => $indexes];
        }
    }
    ksort($tables);
    return $tables;
}

$runner = realpath(env_required('RUNNER_TEMP'));
$root = realpath(env_required('TEST_ROOT'));
$candidate = realpath(env_required('CANDIDATE_ROOT'));
if (env_required('GITHUB_ACTIONS') !== 'true' || !$runner || !$root || !$candidate ||
        !under($root, $runner) || is_link(env_required('TEST_ROOT')) ||
        env_required('DBHOST') !== '127.0.0.1' ||
        !in_array(env_required('DBTYPE'), ['mariadb', 'mysqli'], true)) {
    throw new RuntimeException('Requires isolated GitHub runner and loopback DB');
}
$evidence = $root . '/evidence';
$core = $root . '/core';
$mode = $argv[1] ?? '';
$sourcefixture = $candidate . '/docs/deploy/RC30_PRODUCTION_SCHEMA_DIFF.json';
$fixture = json_decode(file_get_contents($sourcefixture), true, 512, JSON_THROW_ON_ERROR);
$decimals = $fixture['decimal_type_differences'];
expect(count($decimals) === 11, 'fixture.eleven-decimals');
foreach ($decimals as $item) {
    identifier($item['table']); identifier($item['column']);
    expect((bool)preg_match('/^decimal\((18|8),0\)$/D', $item['actual_type']), 'fixture.type.' . $item['column']);
}

if (in_array($mode, ['seed', 'verify', 'fractions', 'partial'], true)) {
    $marker = json_decode(file_get_contents($root . '/disposable.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!realpath($core) || !under(realpath($core), $root) || is_link($core) ||
            is_link($root . '/data') || realpath($root . '/data') !== $root . '/data' ||
            !preg_match('/^bmsdrift_[a-f0-9]{16}$/D', $marker['dbname']) ||
            !hash_equals($marker['config_sha256'], hash_file('sha256', $core . '/config.php'))) {
        throw new RuntimeException('Invalid disposable config provenance');
    }
    define('CLI_SCRIPT', true);
    require $core . '/config.php';
    expect($CFG->dbname === $marker['dbname'] && $CFG->dbhost === '127.0.0.1' &&
        $CFG->dataroot === $root . '/data', 'disposable.identity');
    require_once($core . '/lib/ddllib.php');
    $dbman = $DB->get_manager();
    $task = 'local_kbacompany_task';
    $admin = 'local_kbacompany_adminitem';
    $ordered = ['companyuuid', 'status', 'duedate'];
    if ($mode === 'seed') {
        expect(!file_exists($evidence . '/synthetic-rows.json'), 'fixture.not-reused');
        expect((string)get_config('local_kbacompany', 'version') === '2026111002', 'fresh.version1002');
        json_write($evidence . '/fresh-schema.json', schema_snapshot());
        foreach (['starttime', 'duetime'] as $field) {
            $dbman->drop_field(new xmldb_table($admin), new xmldb_field($field));
        }
        $dbman->drop_field(new xmldb_table('local_kbacompany_deviation'), new xmldb_field('executionstatus'));
        foreach ($decimals as $item) {
            $column = $DB->get_columns($item['table'], false)[$item['column']];
            expect($column->meta_type === 'N' && (int)$column->max_length === (int)$item['expected']['max_length'] &&
                (int)$column->scale === (int)$item['expected']['scale'], 'fresh.' . $item['table'] . '.' . $item['column']);
            // Fixture-only DDL, before inserting values. Preserve nullability and zero/null defaults.
            $nullable = $item['actual_metadata']['IS_NULLABLE'] === 'YES';
            $defaultnull = $item['actual_metadata']['default_is_null'] === '1';
            $default = $item['column'] === 'weight' ? '1' : '0';
            expect($defaultnull || $item['actual_metadata']['default_sha256'] === hash('sha256', $default), 'fixture.default');
            $sql = 'ALTER TABLE ' . identifier($CFG->prefix . $item['table']) . ' MODIFY COLUMN ' .
                identifier($item['column']) . ' ' . $item['actual_type'] . ($nullable ? ' NULL' : ' NOT NULL') .
                ($defaultnull ? ' DEFAULT NULL' : ' DEFAULT ' . $default);
            $DB->change_database_structure([$sql], [$item['table']]);
        }
        $dropped = 0;
        foreach ($DB->get_indexes($task, false) as $name => $index) {
            if (array_values($index['columns']) === $ordered && !$index['unique']) {
                $DB->change_database_structure(['DROP INDEX ' . identifier($name) . ' ON ' .
                    identifier($CFG->prefix . $task)], [$task]);
                $dropped++;
            }
        }
        expect($dropped > 0, 'control.ordered-index-removed');
        $DB->change_database_structure(['CREATE INDEX `drift_legacy_due_ix` ON ' .
            identifier($CFG->prefix . $task) . ' (`companyuuid`,`duedate`,`status`)'], [$task]);
        json_write($evidence . '/legacy-indexes.json', $DB->get_indexes($task, false));
        $rows = [];
        foreach ($decimals as $item) {
            $digits = (int)$item['expected']['max_length'] - (int)$item['expected']['scale'];
            $limit = str_repeat('9', $digits);
            $outside = '1' . str_repeat('0', $digits);
            $oldlimit = str_repeat('9', (int)$item['expected']['max_length']);
            // Exact decimal strings; never PHP floats. Both companies receive each boundary.
            foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $tenant) {
                foreach (['0', '1', '-1', $limit, '-' . $limit] as $value) {
                    $record = new stdClass();
                    foreach ($DB->get_columns($item['table'], false) as $name => $column) {
                        if (!$column->auto_increment && $column->not_null && $column->default_value === null) {
                            $record->$name = in_array($column->meta_type, ['I', 'N', 'F', 'R', 'L'], true) ? 0 : '';
                        }
                    }
                    $record->companyuuid = $tenant;
                    if (isset($DB->get_columns($item['table'], false)['linecode'])) {
                        $record->linecode = 'synthetic-' . count($rows);
                    }
                    if (isset($DB->get_columns($item['table'], false)['uuid'])) {
                        $hex = bin2hex(random_bytes(16));
                        $record->uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
                            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
                    }
                    $record->{$item['column']} = $value;
                    $id = $DB->insert_record($item['table'], $record);
                    $actual = (array)$DB->get_record($item['table'], ['id' => $id], '*', MUST_EXIST);
                    expect((string)$actual[$item['column']] === $value, 'seed.exact-decimal');
                    $rows[] = ['table' => $item['table'], 'id' => $id, 'row' => $actual];
                }
            }
        }
        json_write($evidence . '/synthetic-rows.json', $rows);
        expect(count($rows) === 110, 'seed.exactly-110-boundary-rows');
        $idsets = [];
        foreach (array_unique(array_column($decimals, 'table')) as $table) {
            $ids = $DB->get_fieldset_sql('SELECT id FROM {' . $table . '} ORDER BY id');
            $idsets[$table] = array_map('strval', $ids);
        }
        json_write($evidence . '/row-ids.json', $idsets);
        require __DIR__ . '/rc30_schema_repair_failures.php';
        set_config('version', '2026101878', 'local_kbacompany');
        // set_config invalidates config cache, not the installed-plugin version cache.
        // This fixture changes DB metadata without swapping code, so invalidate that cache explicitly.
        core_plugin_manager::reset_caches();
        $installed = core_plugin_manager::instance()->get_installed_plugins('local');
        expect((string)$installed['kbacompany'] === '2026101878', 'fixture.plugin-manager-sees1878');
        // Moodle's CLI first compares the cached aggregate code-version hash, not DB plugin versions.
        // Invalidate it only in this synthetic DB, since code files have deliberately not changed.
        unset_config('allversionshash');
        unset($CFG->allversionshash);
        expect(moodle_needs_upgrading(), 'fixture.actual-cli-upgrade-pending');
        echo "PASS synthetic version1878 set only in disposable DB\n";
        exit(0);
    }
    if ($mode === 'partial') { require __DIR__ . '/rc30_schema_repair_partial.php'; exit(0); }
    if ($mode === 'fractions') { require __DIR__ . '/rc30_schema_repair_fractions.php'; exit(0); }
    $results = [];
    expect((string)get_config('local_kbacompany', 'version') === '2026111002', 'upgrade.version1002');
    expect(!moodle_needs_upgrading(), 'upgrade.lifecycle-complete');
    foreach (['starttime', 'duetime'] as $field) {
        $id = 'repaired.' . $admin . '.' . $field;
        expect(isset($DB->get_columns($admin, false)[$field]), $id);
        $results[$id] = 'PASS: field restored';
    }
    foreach ($decimals as $item) {
        $column = $DB->get_columns($item['table'], false)[$item['column']];
        $id = 'repaired.' . $item['table'] . '.' . $item['column'];
        expect($column->meta_type === 'N' && (int)$column->max_length === (int)$item['expected']['max_length'] &&
            (int)$column->scale === (int)$item['expected']['scale'] && $column->not_null === $item['expected']['not_null'], $id);
        $results[$id] = 'PASS: fresh precision and scale';
    }
    $control = $DB->get_columns('local_kbacompany_deviation', false)['executionstatus'] ?? null;
    expect($control && $control->meta_type === 'C' && (int)$control->max_length === 16 &&
        $control->not_null && $control->default_value === 'pending', 'control.deviation-repaired');
    $indexes = $DB->get_indexes($task, false);
    expect(count(array_filter($indexes, static function($i) use ($ordered) {
        return !$i['unique'] && array_values($i['columns']) === $ordered;
    })) === 1, 'control.ordered-index-repaired');
    foreach (json_decode(file_get_contents($evidence . '/legacy-indexes.json'), true) as $name => $index) {
        expect(isset($indexes[$name]) && $indexes[$name] === $index, 'preserved.index.' . $name);
    }
    $rows = json_decode(file_get_contents($evidence . '/synthetic-rows.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(count($rows) === 110, 'preserved.fixture-row-count');
    foreach (json_decode(file_get_contents($evidence . '/row-ids.json'), true) as $table => $ids) {
        $actualids = array_map('strval', $DB->get_fieldset_sql('SELECT id FROM {' . $table . '} ORDER BY id'));
        expect($actualids === $ids, 'preserved.exact-row-id-set.' . $table);
    }
    foreach ($rows as $row) {
        $actual = (array)$DB->get_record($row['table'], ['id' => $row['id']], '*', MUST_EXIST);
        foreach ($row['row'] as $field => $value) {
            $column = $DB->get_columns($row['table'], false)[$field];
            if ($column->meta_type === 'N' && $value !== null && $actual[$field] !== null) {
                $canonical = static function($v) {
                    return strpos((string)$v, '.') === false ? (string)$v : rtrim(rtrim((string)$v, '0'), '.');
                };
                expect($canonical($actual[$field]) === $canonical($value), 'preserved.decimal.' . $row['table'] . '.' . $field);
            } else {
                expect($actual[$field] === $value, 'preserved.' . $row['table'] . '.' . $field);
            }
        }
    }
    $schema = schema_snapshot();
    $fresh = json_decode(file_get_contents($evidence . '/fresh-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $differences = [];
    foreach ($fresh as $table => $definition) {
        if ($table !== $task) {
            expect($schema[$table]['indexes'] === $definition['indexes'], 'schema.preserved-indexes.' . $table);
        }
        foreach ($definition['columns'] as $field => $column) {
            if (($schema[$table]['columns'][$field] ?? null) !== $column) { $differences[] = $table . '.' . $field; }
        }
        expect(!array_diff_key($schema[$table]['columns'], $definition['columns']), 'schema.no-extra-fields.' . $table);
    }
    expect($differences === [], 'schema.zero-column-differences');
    json_write($evidence . '/current-schema.json', $schema);
    json_write($evidence . '/repair.json', ['repair' => 'PASS', 'schema_parity' => 'PASS',
        'release_acceptance' => 'HOLD', 'individual_results' => $results, 'preserved_rows' => count($rows),
        'positive_controls' => ['deviation_field' => 'PASS', 'ordered_index' => 'PASS'],
        'not_covered' => ['real1878/Corporate2613 stack', 'production data', 'ACL', 'full PHPUnit']]);
    exit(0);
}
if ($mode !== 'run') { throw new RuntimeException('Expected run|seed|verify'); }
$source = realpath(env_required('MOODLE_ROOT'));
if (!$source || is_file($source . '/config.php') || file_exists($core) || is_link($core) ||
        file_exists($evidence) || is_link($evidence) || file_exists($root . '/data') || is_link($root . '/data') ||
        under($root, $source) || under($root, $candidate)) {
    throw new RuntimeException('Requires clean core source and unused scratch root');
}
foreach ([$evidence, $core, $root . '/data'] as $directory) {
    if (!mkdir($directory, 0700) || realpath($directory) !== $directory) {
        throw new RuntimeException('Cannot create isolated directory');
    }
}
$port = filter_var(env_required('DBPORT'), FILTER_VALIDATE_INT);
if (!$port || $port < 1 || $port > 65535) { throw new RuntimeException('Invalid port'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', env_required('DBUSER'), env_required('DBPASS'), '', $port);
$server = $connection->server_info;
$comment = $connection->query('SELECT @@version_comment')->fetch_row()[0];
expect(env_required('DBTYPE') === 'mariadb' ? strpos($server, '10.11.') === 0 && stripos($server, 'MariaDB') !== false :
    strpos($server, '8.0.') === 0 && stripos($comment, 'Percona') !== false, 'engine.identity');
$db = 'bmsdrift_' . bin2hex(random_bytes(8));
$connection->query('CREATE DATABASE ' . identifier($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
run_command(['cp', '-a', $source . '/.', $core], $root, $evidence . '/copy-core.log');
foreach (['kbacompany', 'kbacorporate'] as $plugin) {
    expect(!file_exists($core . '/local/' . $plugin), 'core.clean.' . $plugin);
    run_command(['cp', '-a', $candidate . '/local/' . $plugin, $core . '/local/'], $root, $evidence . '/copy-' . $plugin . '.log');
}
$config = ['dbtype' => env_required('DBTYPE'), 'dblibrary' => 'native', 'dbhost' => '127.0.0.1',
    'dbname' => $db, 'dbuser' => env_required('DBUSER'), 'dbpass' => env_required('DBPASS'), 'prefix' => 'mdl_',
    'dboptions' => ['dbpersist' => false, 'dbport' => $port, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'],
    'wwwroot' => 'http://127.0.0.1.invalid/drift', 'dataroot' => $root . '/data', 'admin' => 'admin',
    'directorypermissions' => 0700, 'passwordpolicy' => 0, 'noemailever' => true];
$text = "<?php\nunset(\$CFG);\nglobal \$CFG;\n\$CFG = new stdClass();\n";
foreach ($config as $key => $value) { $text .= '$CFG->' . $key . ' = ' . var_export($value, true) . ";\n"; }
$text .= "require_once(__DIR__ . '/lib/setup.php');\n";
file_put_contents($core . '/config.php', $text); chmod($core . '/config.php', 0600);
json_write($root . '/disposable.json', ['dbname' => $db, 'config_sha256' => hash_file('sha256', $core . '/config.php')]);
json_write($evidence . '/environment.json', ['php' => PHP_VERSION, 'database' => $server, 'vendor' => $comment,
    'fixture_sha256' => hash_file('sha256', $sourcefixture), 'github_sha' => env_required('GITHUB_SHA'),
    'scope' => 'Synthetic metadata-derived repair acceptance; no deployed environment contacted']);
run_command([PHP_BINARY, $core . '/admin/cli/install_database.php', '--agree-license', '--adminuser=admin',
    '--adminpass=' . bin2hex(random_bytes(20)), '--adminemail=ci@example.invalid',
    '--fullname=Isolated schema repair', '--shortname=DRIFT', '--lang=en'], $core, $evidence . '/install.log');
run_command([PHP_BINARY, __FILE__, 'seed'], $core, $evidence . '/seed.log');
foreach (['first', 'repeat'] as $pass) {
    run_command([PHP_BINARY, $core . '/admin/cli/upgrade.php', '--non-interactive'], $core, $evidence . '/' . $pass . '-upgrade.log');
    run_command([PHP_BINARY, __FILE__, 'verify'], $core, $evidence . '/' . $pass . '-verify.log');
    copy($evidence . '/repair.json', $evidence . '/' . $pass . '-repair.json');
    copy($evidence . '/current-schema.json', $evidence . '/' . $pass . '-schema.json');
}
expect(file_get_contents($evidence . '/first-schema.json') === file_get_contents($evidence . '/repeat-schema.json'),
    'repeat.full-schema-equality');
expect(file_get_contents($evidence . '/first-repair.json') === file_get_contents($evidence . '/repeat-repair.json'),
    'repeat.results-equality');
run_command([PHP_BINARY, __FILE__, 'partial'], $core, $evidence . '/partial-failure.log');
run_command([PHP_BINARY, $core . '/admin/cli/upgrade.php', '--non-interactive'], $core, $evidence . '/partial-retry-upgrade.log');
run_command([PHP_BINARY, __FILE__, 'verify'], $core, $evidence . '/partial-retry-verify.log');
expect(file_get_contents($evidence . '/current-schema.json') === file_get_contents($evidence . '/first-schema.json'),
    'partial.retry-restores-complete-schema');
run_command([PHP_BINARY, __FILE__, 'fractions'], $core, $evidence . '/fractions.log');
echo "Repair PASS; column parity PASS; RC30 HOLD pending full acceptance.\n";
