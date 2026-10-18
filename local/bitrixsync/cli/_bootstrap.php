<?php
defined('CLI_SCRIPT') || die();

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

function kba_cli_bool($value): string {
    return $value ? 'YES' : 'NO';
}

function kba_cli_status_line(
    string $label,
    string $value
): void {
    $label = str_pad($label, 30, '.');
    echo $label . ' ' . $value . PHP_EOL;
}

function kba_cli_separator(): void {
    echo str_repeat('-', 60) . PHP_EOL;
}

function kba_cli_json(array $data): void {
    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}
