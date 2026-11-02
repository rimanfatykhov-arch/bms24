#!/usr/bin/env bash
# BMS24 stage 1881.14.4 — staging-only KBA Connect preflight.
# Does NOT install bitrixsync, touch WireGuard/firewall or modify active Docker networks.
set -Eeuo pipefail
BASE=/srv/bms24-staging/moodle-test
COMPOSE_DIR="$BASE/docker"
WEB_CONTAINER=bms24_stage1880-webserver-1
LIVE_NETWORK=bms24_stage1880_default
PROBE_NETWORK="bms24_stage1881_probe_$$"
PROBE_CREATED=0
cleanup() {
    if [ "$PROBE_CREATED" -eq 1 ]; then
        docker network rm "$PROBE_NETWORK" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
if [ "$(id -u)" -ne 0 ]; then
    echo 'ERROR: run from Timeweb root console (not RUWEB).' >&2
    exit 1
fi
if [ ! -r "$BASE/staging.env" ] || [ ! -x "$COMPOSE_DIR/bin/moodle-docker-compose" ]; then
    echo 'ERROR: staging.env or Moodle Docker wrapper is missing; no changes made.' >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo 'ERROR: Docker daemon not available; no changes made.' >&2
    exit 1
fi
if ! docker network inspect "$LIVE_NETWORK" >/dev/null 2>&1; then
    echo 'ERROR: expected staging Docker network not found; no changes made.' >&2
    exit 1
fi
if [ "$(docker inspect -f '{{.State.Running}}' "$WEB_CONTAINER")" != true ]; then
    echo 'ERROR: staging webserver is not running; no changes made.' >&2
    exit 1
fi
# Never print staging.env, Docker configuration, API credentials or tokens.
source "$BASE/staging.env"
cd "$COMPOSE_DIR"
echo '=== STAGING CRON: disable and wait (max 60s) ==='
./bin/moodle-docker-compose exec -T webserver php admin/cli/cron.php --disable-wait=60
echo '=== STAGING CRON STATUS ==='
./bin/moodle-docker-compose exec -T webserver php admin/cli/cfg.php --name=cron_enabled
echo '=== CURRENT RUNNING TASKS ==='
./bin/moodle-docker-compose exec -T webserver php admin/cli/cron.php --list
echo '=== CURRENT STAGING NETWORK ==='
docker network inspect "$LIVE_NETWORK" --format 'name={{.Name}} internal={{.Internal}} driver={{.Driver}}'
echo '=== WEBSERVER NETWORK ATTACHMENTS ==='
docker inspect "$WEB_CONTAINER" --format '{{range $name, $details := .NetworkSettings.Networks}}{{println $name}}{{end}}'
echo '=== WEBSERVER HOST-BOUND PORT ==='
docker port "$WEB_CONTAINER" 80/tcp || true
echo '=== TEMPORARY INTERNAL NETWORK ISOLATION PROBE ==='
docker network create --driver bridge --internal "$PROBE_NETWORK" >/dev/null
PROBE_CREATED=1
docker network inspect "$PROBE_NETWORK" --format 'probe_internal={{.Internal}}'
# Public IP check avoids DNS assumptions; this probe never contacts Bitrix24.
docker run --rm --pull never --network "$PROBE_NETWORK" --entrypoint php moodlehq/moodle-php-apache:8.0 -r '
    $errorno = 0;
    $errorstr = "";
    $socket = @fsockopen("1.1.1.1", 443, $errorno, $errorstr, 3);
    if ($socket !== false) {
        fclose($socket);
        fwrite(STDERR, "PROBE_FAILED: public internet was reachable\n");
        exit(2);
    }
    echo "PROBE_BLOCKED: external TCP connection denied\n";
'
echo 'STAGE1881_PREFLIGHT_OK — current network untouched, staging cron disabled.'
echo 'NEXT: inspect Compose definitions before switching services to internal:true.'
