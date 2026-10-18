<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Legacy REST Discovery\n";
echo "================================\n\n";

echo "Configuration:\n";
$webhook = get_config('local_bitrixsync', 'bitrix_webhook_url');

echo "Webhook: " . ($webhook ? "FOUND\n" : "NOT FOUND\n");

echo "\nClass candidates:\n";

$paths = [
    __DIR__ . '/../classes',
    __DIR__ . '/../lib',
];

$found = [];

foreach ($paths as $path) {
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $name = strtolower($file->getFilename());

        if (strpos($name, 'rest') !== false ||
            strpos($name, 'bitrix') !== false ||
            strpos($name, 'webhook') !== false ||
            strpos($name, 'client') !== false ||
            strpos($name, 'api') !== false) {

            $found[] = $file->getPathname();
        }
    }
}

if (!$found) {
    echo "No candidate files found in current plugin.\n";
} else {
    foreach ($found as $file) {
        echo "✓ " . $file . "\n";
    }
}

echo "\nMethods to connect:\n";
echo "✓ crm.deal.get\n";
echo "✓ crm.deal.productrows.get\n";
echo "✓ crm.company.get\n";
echo "✓ crm.contact.get\n";

echo "\nDiscovery complete. No REST calls executed.\n";
