<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$rows = [
    ['PRODUCT_ID' => 235],
    ['PRODUCT_ID' => 237],
    ['PRODUCT_ID' => 227],
];

$result = (new \local_bitrixsync\resolver\product_mapping_resolver())
    ->resolve($rows);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
