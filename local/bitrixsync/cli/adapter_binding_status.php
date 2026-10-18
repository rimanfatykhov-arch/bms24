<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Adapter Binding Status\n";
echo "=================================\n";

$status = (new \local_bitrixsync\bitrix24\adapter_binding())->status();

foreach ($status as $name => $value) {
    echo str_pad($name, 25, '.') . ($value ? " BOUND" : " MISSING") . PHP_EOL;
}
