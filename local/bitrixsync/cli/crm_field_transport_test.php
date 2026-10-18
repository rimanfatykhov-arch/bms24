<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$client = new \local_bitrixsync\adapters\bitrix24\client();

$out = [
    'php_version' => PHP_VERSION,
    'transport' => 'v0.4.6-compatible JSON + .json',
    'entities' => [],
];

foreach (['company', 'contact', 'deal'] as $entity) {
    $method = 'crm.' . $entity . '.userfield.list';

    try {
        $response = $client->call_json(
            $method,
            [
                'filter' => ['LANG' => 'ru'],
                'order' => ['ID' => 'ASC'],
                'start' => 0,
            ]
        );

        $result = $response['result'] ?? [];

        $out['entities'][$entity] = [
            'ok' => is_array($result),
            'count_first_page' =>
                is_array($result) ? count($result) : 0,
            'next' => $response['next'] ?? null,
            'http' => $client->get_last_http_code(),
            'diagnostic' =>
                $client->get_last_diagnostic(),
        ];
    } catch (\Throwable $e) {
        $out['entities'][$entity] = [
            'ok' => false,
            'error' => $e->getMessage(),
            'http' => $client->get_last_http_code(),
            'diagnostic' =>
                $client->get_last_diagnostic(),
        ];
    }
}

echo json_encode(
    $out,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;

$ready = true;
foreach ($out['entities'] as $row) {
    if (empty($row['ok'])) {
        $ready = false;
        break;
    }
}

exit($ready ? 0 : 2);
