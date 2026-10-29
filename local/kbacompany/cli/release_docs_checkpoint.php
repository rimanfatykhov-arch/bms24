<?php
/**
 * BMS24 release documentation checkpoint.
 *
 * This script is intentionally standalone: it does not bootstrap Moodle and may be
 * called by repository/build automation before the plugin ZIP is created.
 *
 * Prepare/update checkpoint:
 *   php local/kbacompany/cli/release_docs_checkpoint.php \
 *     --summary="Short release summary" --live="NOT RUN"
 *
 * Verify/block packaging:
 *   php local/kbacompany/cli/release_docs_checkpoint.php --check
 *
 * Exit codes: 0 = ready, 2 = documentation checkpoint incomplete/invalid.
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve plugin root.\n");
    exit(2);
}

function bms24_release_docs_usage() {
    return "BMS24 release documentation checkpoint\n\n"
        . "Prepare/update:\n"
        . "  php cli/release_docs_checkpoint.php --summary=\"...\" [--live=\"NOT RUN\"] [--note=release/FILE.md]\n\n"
        . "Verify only:\n"
        . "  php cli/release_docs_checkpoint.php --check\n\n"
        . "Options:\n"
        . "  --summary=TEXT   Required when preparing a checkpoint.\n"
        . "  --live=TEXT      Live acceptance status, default NOT RUN.\n"
        . "  --note=PATH      Explicit release-note path relative to plugin root.\n"
        . "  --check          Validate current docs and exit without modifying files.\n"
        . "  --json           Print machine-readable result.\n"
        . "  --help           Show this help.\n";
}

function bms24_release_docs_args(array $argv) {
    $out = array('check' => false, 'json' => false, 'help' => false, 'summary' => '', 'live' => 'NOT RUN', 'note' => '');
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') { $out['check'] = true; continue; }
        if ($arg === '--json') { $out['json'] = true; continue; }
        if ($arg === '--help' || $arg === '-h') { $out['help'] = true; continue; }
        if (strpos($arg, '--summary=') === 0) { $out['summary'] = substr($arg, 10); continue; }
        if (strpos($arg, '--live=') === 0) { $out['live'] = substr($arg, 7); continue; }
        if (strpos($arg, '--note=') === 0) { $out['note'] = substr($arg, 7); continue; }
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    return $out;
}

function bms24_release_docs_version($root) {
    $text = @file_get_contents($root . '/version.php');
    if ($text === false) { throw new RuntimeException('Cannot read version.php'); }
    if (!preg_match('/\\$plugin->version\\s*=\\s*(\\d+)\\s*;/', $text, $m)) {
        throw new RuntimeException('Cannot parse $plugin->version from version.php');
    }
    if (!preg_match('/\\$plugin->release\\s*=\\s*[\'\"]([^\'\"]+)[\'\"]\\s*;/', $text, $r)) {
        throw new RuntimeException('Cannot parse $plugin->release from version.php');
    }
    return array('build' => (int)$m[1], 'release' => $r[1]);
}

function bms24_release_docs_note($root, $build, $explicit) {
    if ($explicit !== '') {
        $candidate = $root . '/' . ltrim(str_replace('\\\\', '/', $explicit), '/');
        return is_file($candidate) ? $candidate : null;
    }
    $matches = glob($root . '/release/RELEASE_NOTES_*' . $build . '*.md');
    if (!$matches) { return null; }
    sort($matches, SORT_STRING);
    return end($matches);
}

function bms24_release_docs_atomic_write($path, $content) {
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('Cannot write temporary file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace file: ' . $path);
    }
}

function bms24_release_docs_load_json($path) {
    if (!is_file($path)) { return array(); }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : array();
}

function bms24_release_docs_escape_cell($value) {
    return str_replace(array("\r", "\n", '|'), array(' ', ' ', '\\|'), trim((string)$value));
}

function bms24_release_docs_register($root, $build, $note, $summary) {
    $path = $root . '/release/RELEASE_REGISTER_LOCAL.md';
    $text = is_file($path) ? (string)file_get_contents($path) : "# BMS24 local release register\n\n| Build | Release note | Summary |\n|---:|---|---|\n";
    $noteName = basename($note);
    $row = '| ' . $build . ' | `' . bms24_release_docs_escape_cell($noteName) . '` | '
        . bms24_release_docs_escape_cell($summary) . ' |';
    $pattern = '/^\\|\\s*' . preg_quote((string)$build, '/') . '\\s*\\|.*$/m';
    if (preg_match($pattern, $text)) {
        $text = preg_replace($pattern, $row, $text, 1);
    } else {
        if ($text !== '' && substr($text, -1) !== "\n") { $text .= "\n"; }
        $text .= $row . "\n";
    }
    bms24_release_docs_atomic_write($path, $text);
}

function bms24_release_docs_validate($root, array $version) {
    $issues = array();
    $build = $version['build'];
    $release = $version['release'];
    $manifestPath = $root . '/release/CURRENT_RELEASE.json';
    $checkpointPath = $root . '/release/DOCUMENTATION_CHECKPOINT_' . $build . '.json';
    $registerPath = $root . '/release/RELEASE_REGISTER_LOCAL.md';
    $manifest = bms24_release_docs_load_json($manifestPath);
    $checkpoint = bms24_release_docs_load_json($checkpointPath);

    if ((int)($manifest['build'] ?? 0) !== $build) { $issues[] = 'CURRENT_RELEASE.json build does not match version.php'; }
    if ((string)($manifest['release'] ?? '') !== $release) { $issues[] = 'CURRENT_RELEASE.json release does not match version.php'; }
    $noteName = (string)($manifest['release_notes'] ?? '');
    if ($noteName === '' || !is_file($root . '/release/' . basename($noteName))) { $issues[] = 'Current release note is missing'; }
    if (!is_file($registerPath) || !preg_match('/^\\|\\s*' . preg_quote((string)$build, '/') . '\\s*\\|/m', (string)@file_get_contents($registerPath))) {
        $issues[] = 'RELEASE_REGISTER_LOCAL.md has no row for current build';
    }
    if ((int)($checkpoint['build'] ?? 0) !== $build || (string)($checkpoint['release'] ?? '') !== $release) {
        $issues[] = 'Documentation checkpoint JSON is missing or stale';
    }
    if (!is_file($root . '/docs/MASTER_INDEX.md')) { $issues[] = 'docs/MASTER_INDEX.md is missing'; }
    if (!is_file($root . '/docs/DOCUMENTATION_PACKAGING_STANDARD.md')) { $issues[] = 'docs/DOCUMENTATION_PACKAGING_STANDARD.md is missing'; }
    return $issues;
}

$args = bms24_release_docs_args($argv);
if ($args['help']) {
    echo bms24_release_docs_usage();
    exit(0);
}

try {
    $version = bms24_release_docs_version($root);
    $build = $version['build'];
    $release = $version['release'];

    if (!$args['check']) {
        if (trim($args['summary']) === '') {
            throw new RuntimeException('--summary is required when preparing a documentation checkpoint');
        }
        $note = bms24_release_docs_note($root, $build, $args['note']);
        if ($note === null) {
            throw new RuntimeException('Release note for build ' . $build . ' is required before checkpoint generation');
        }
        $manifestPath = $root . '/release/CURRENT_RELEASE.json';
        $manifest = bms24_release_docs_load_json($manifestPath);
        $manifest['component'] = 'local_kbacompany';
        $manifest['release'] = $release;
        $manifest['build'] = $build;
        $manifest['summary'] = trim($args['summary']);
        $manifest['code_status'] = 'Code ready';
        $manifest['live_acceptance'] = trim($args['live']) !== '' ? trim($args['live']) : 'NOT RUN';
        $manifest['release_notes'] = basename($note);
        $manifest['documentation_checkpoint'] = 'DOCUMENTATION_CHECKPOINT_' . $build . '.json';
        $manifest['generated_at'] = gmdate('c');
        if (empty($manifest['documentation_layout'])) {
            $manifest['documentation_layout'] = array('technical' => 'docs/', 'release' => 'release/');
        }
        bms24_release_docs_atomic_write($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $checkpoint = array(
            'component' => 'local_kbacompany',
            'release' => $release,
            'build' => $build,
            'summary' => trim($args['summary']),
            'code_status' => 'Code ready',
            'live_acceptance' => $manifest['live_acceptance'],
            'release_notes' => basename($note),
            'register' => 'RELEASE_REGISTER_LOCAL.md',
            'generated_at' => $manifest['generated_at'],
            'guard' => 'Packaging must fail when cli/release_docs_checkpoint.php --check exits non-zero.'
        );
        bms24_release_docs_atomic_write(
            $root . '/release/DOCUMENTATION_CHECKPOINT_' . $build . '.json',
            json_encode($checkpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
        bms24_release_docs_register($root, $build, $note, $args['summary']);
    }

    $issues = bms24_release_docs_validate($root, $version);
    $result = array(
        'ok' => empty($issues),
        'component' => 'local_kbacompany',
        'release' => $release,
        'build' => $build,
        'mode' => $args['check'] ? 'check' : 'prepare+check',
        'issues' => $issues,
    );
    if ($args['json']) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo 'BMS24 release docs: ' . ($result['ok'] ? 'READY' : 'BLOCKED') . "\n";
        echo 'Release: ' . $release . '; build: ' . $build . "\n";
        foreach ($issues as $issue) { echo '- ' . $issue . "\n"; }
    }
    exit(empty($issues) ? 0 : 2);
} catch (Throwable $e) {
    if ($args['json']) {
        echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, 'BMS24 release docs: BLOCKED - ' . $e->getMessage() . "\n");
    }
    exit(2);
}
