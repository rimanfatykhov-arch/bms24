<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$status = (new \local_bitrixsync\bitrix24\adapter_pipeline())->candidates();

echo "KBA Connect Alpha.6 Adapter Status\n";
echo "=================================\n";

foreach ($status as $name => $ok) {
    echo str_pad($name, 25, '.') . ($ok ? " FOUND" : " MISSING") . PHP_EOL;
}
