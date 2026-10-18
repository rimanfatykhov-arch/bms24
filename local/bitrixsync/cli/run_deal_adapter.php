<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$id = 0;

foreach ($argv as $arg) {
    if (strpos($arg, '--id=') === 0) {
        $id = (int)substr($arg, 5);
    }
}

if (!$id) {
    echo "Usage: php local/bitrixsync/cli/run_deal_adapter.php --id=3761\n";
    exit(1);
}

echo "KBA Connect Real Deal Adapter Call\n";
echo "=================================\n";

$result = (new \local_bitrixsync\debug\deal_adapter_runner())
    ->run($id);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
