#!/usr/bin/env bash
# BMS24 stage 1884: READ-ONLY capacity preflight for a parallel Moodle staging.
# Does not read config.php, .env, database data or secrets.
# Does not invoke Docker compose up/down, build, pull, prune or any upgrade.
set -Eeuo pipefail
export LC_ALL=C

BASE='/srv/bms24-staging'
MOODLE="$BASE/moodle-test"
if [[ ! -d "$MOODLE/moodle" || ! -d "$MOODLE/docker" ]]; then
    echo 'STOP: expected BMS24 staging layout missing' >&2
    exit 2
fi

echo '=== BMS24 STAGE1884 READ-ONLY PREFLIGHT ==='
echo '1. HOST RAM AND SWAP'
free -h
echo
echo '2. CPU'
nproc
echo
echo '3. FILESYSTEM CAPACITY'
df -h "$BASE"
df -i "$BASE"
echo
echo '4. SPACE USED BY KNOWN STAGING DIRECTORIES'
for dir in "$MOODLE/moodle" "$MOODLE/docker" "$BASE/backups" "$BASE/import"; do
    if [[ -d "$dir" ]]; then
        du -sh "$dir"
    else
        echo "MISSING $dir"
    fi
done
echo
echo '5. DOCKER CAPACITY (NO PRUNE)'
docker system df
echo
echo '6. RUNNING CONTAINERS'
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
echo
echo '7. STAGING WEBSERVER MOUNTS (PATHS ONLY)'
docker inspect bms24_stage1880-webserver-1 \
    --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}'
echo
echo '8. STAGING DB MOUNTS (PATHS ONLY)'
docker inspect bms24_stage1880-db-1 \
    --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}'
echo
echo '9. HOST PORTS IN USE (HTTP/HTTPS/STAGING)'
ss -lnt | grep -E '(:80|:443|:18080|:18081)[[:space:]]' || true
echo
echo 'READ_ONLY_PREFLIGHT_1884_OK'
