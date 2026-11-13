<?php
/** Byte identity of retained Corporate source before and after isolated BMS upgrade. */
declare(strict_types=1);
$root = realpath($argv[1] ?? ''); $manifest = $argv[2] ?? '';
$scratch = realpath(getenv('TEST_ROOT') ?: '');
if (!$root || !$scratch || strpos($root, $scratch . '/') !== 0) { throw new RuntimeException('Scratch source only'); }
$expected = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
$seen = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isLink()) { throw new RuntimeException('Symlink in retained source'); }
    if (!$file->isFile()) { continue; }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    if (!isset($expected['files'][$relative]) || !hash_equals($expected['files'][$relative], hash_file('sha256', $file->getPathname()))) {
        throw new RuntimeException('Retained Corporate changed: ' . $relative);
    }
    $seen[$relative] = true;
}
if (count($seen) !== 830 || count($expected['files']) !== 830) { throw new RuntimeException('Incomplete Corporate2613 tree'); }
echo "PASS exact retained Corporate2613:830 files, no replacement/deletion/modification\n";
