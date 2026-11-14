<?php
// Disposable synthetic fixture only. Never run on an existing environment.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('BMS_CI_BROWSER') !== '1') { exit(2); }
define('CLI_SCRIPT', true);
require getenv('MOODLE_ROOT') . '/config.php';
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || $CFG->dbname !== 'bmsci_web' || strpos(realpath($CFG->dataroot), realpath(getenv('RUNNER_TEMP')) . '/') !== 0) { exit(2); }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['owned', 'legacy'], true)) { exit(2); }
set_config('bms24_shell_mode', $mode, 'local_kbacompany');
purge_all_caches();
