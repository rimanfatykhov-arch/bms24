#!/usr/bin/env bash
# BMS24 Stage 1888 — fetch PHP 8.1 Moodle test image, probe it with NO network.
# Does NOT start the parity41 Compose stack, touch the DB, or alter existing staging.
set -Eeuo pipefail
umask 077
export LC_ALL=C

PARITY=/srv/bms24-staging/parity41
IMAGE=moodlehq/moodle-php-apache:8.1-bookworm
MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--pull-and-probe" ]]; then
    echo "Usage: bash $0 [--check|--pull-and-probe]" >&2
    exit 2
fi
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: run as root on Timeweb' >&2; exit 2; }
[[ -f "$PARITY/compose.yml" && -f "$PARITY/.env" &&
   -f "$PARITY/moodle/version.php" ]] ||
    { echo 'STOP: stage 1887 files or Moodle 4.1.22 code missing' >&2; exit 2; }
[[ "$(stat -c '%a' "$PARITY/.env")" == '600' ]] ||
    { echo 'STOP: parity41 .env permissions must be 600' >&2; exit 2; }
grep -qE '^[[:space:]]*\$version[[:space:]]*=[[:space:]]*2022112822\.00[[:space:]]*;' "$PARITY/moodle/version.php" ||
    { echo 'STOP: unexpected Moodle Core version' >&2; exit 2; }

# Existing staging must remain untouched: this script never runs docker compose.
if docker ps --format '{{.Names}}' | grep -Eq '^bms24_parity41[-_]'; then
    echo 'STOP: parity41 containers already running, inspect them first' >&2; exit 3
fi

if [[ "$MODE" == "--pull-and-probe" ]]; then
    echo '=== PULL OFFICIAL MOODLEHQ PHP 8.1 IMAGE ==='
    docker pull "$IMAGE" >/dev/null
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "IMAGE_NOT_PRESENT $IMAGE"
    echo 'STAGE1888_PENDING: rerun with --pull-and-probe'
    exit 0
fi

echo '=== IMAGE ID / ARCHITECTURE ==='
docker image inspect "$IMAGE" --format 'ID={{.Id}} OS={{.Os}} ARCH={{.Architecture}}'
echo '=== REGISTRY DIGEST (RECORD FOR REPRODUCIBILITY) ==='
docker image inspect "$IMAGE" --format '{{range .RepoDigests}}{{println .}}{{end}}'

# Ephemeral interpreter-only container. No network, no site mounts, no writable rootfs.
echo '=== PHP 8.1 / EXTENSIONS / INI CHECK ==='
docker run --rm --network none --read-only --memory 512m --cpus 0.5 \
    --pids-limit 64 --security-opt no-new-privileges \
    --tmpfs /tmp:rw,noexec,nosuid,size=32m \
    --entrypoint php "$IMAGE" -r '
echo "PHP_VERSION ", PHP_VERSION, PHP_EOL;
echo "PHP_SAPI ", PHP_SAPI, PHP_EOL;
$required = ["curl", "dom", "gd", "intl", "json", "mbstring",
             "mysqli", "openssl", "simplexml", "soap",
             "xml", "xmlreader", "xmlwriter", "zip"];
$missing = [];
foreach ($required as $name) {
    if (!extension_loaded($name)) {
        $missing[] = $name;
    }
}
echo "REQUIRED_EXTENSIONS ", $missing ? "MISSING: ".implode(",", $missing) : "OK", PHP_EOL;
echo "MAX_INPUT_VARS ", ini_get("max_input_vars"), PHP_EOL;
echo "MEMORY_LIMIT ", ini_get("memory_limit"), PHP_EOL;
echo "OPCACHE ", extension_loaded("Zend OPcache") ? "PRESENT" : "NOT_FOUND", PHP_EOL;
if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID >= 80200) {
    fwrite(STDERR, "STOP: expected PHP 8.1.x".PHP_EOL);
    exit(3);
}
if ($missing) {
    fwrite(STDERR, "STOP: Moodle required PHP extensions missing".PHP_EOL);
    exit(4);
}
if ((int) ini_get("max_input_vars") < 5000) {
    echo "REVIEW: max_input_vars must be configured for Moodle install", PHP_EOL;
}
echo "STAGE1888_PHP81_RUNTIME_OK", PHP_EOL;
'

echo 'STAGE1888_IMAGE_PREFLIGHT_OK'
echo 'No persistent parity41 container created; no database or Moodle installation.'
