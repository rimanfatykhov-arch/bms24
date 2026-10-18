<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

$dealid = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--dealid=') === 0) {
        $dealid = (int)substr($arg, 9);
    }
}

if (!$dealid) {
    echo "Usage: php local/bitrixsync/cli/simulate_deal.php --dealid=3761\n";
    exit(1);
}

$result = (new \local_bitrixsync\shadow\real_deal_simulator())
    ->run($dealid);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
