#!/usr/bin/env bash
# BMS24 stage 1890: verify the isolated MariaDB and prepared PHP settings.
# READ-ONLY DB queries and a disposable, networkless php -r probe only.
# No Compose up/down, no Moodle install, no migration, no credentials printed.
set -Eeuo pipefail
umask 077
export LC_ALL=C

BASE=/srv/bms24-staging/parity41
DB=bms24_parity41-db-1
IMAGE=moodlehq/moodle-php-apache:8.1-bookworm
PHP_INI="$BASE/config/php/90-bms-parity.ini"

[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: run on Timeweb as root' >&2; exit 2; }
[[ -f "$BASE/moodle/version.php" && -f "$BASE/.env" && -f "$PHP_INI" ]] ||
  { echo 'STOP: parity41 Core, credentials file, or PHP settings missing' >&2; exit 2; }
[[ "$(stat -c '%a' "$BASE/.env")" == 600 ]] ||
  { echo 'STOP: parity41 .env must be mode 600' >&2; exit 2; }
if ! docker inspect "$DB" >/dev/null 2>&1; then
  echo 'STOP: isolated parity41 MariaDB container not found' >&2
  exit 2
fi
running=$(docker inspect "$DB" --format '{{.State.Running}}')
health=$(docker inspect "$DB" --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing-healthcheck{{end}}')
echo "STAGE1890_DB_STATUS running=$running health=$health"
if [[ "$running" != true || "$health" != healthy ]]; then
  echo 'PENDING: DB health is not yet healthy. Do not start webserver or Moodle installation.'
  exit 4
fi

# Confirm the DB is on the new private network and new named volume.
network=$(docker inspect "$DB" --format '{{.HostConfig.NetworkMode}}')
volume=$(docker inspect "$DB" --format '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{.Name}}{{end}}{{end}}')
[[ "$network" == bms24_parity41_internal ]] ||
  { echo "STOP: unexpected DB network: $network" >&2; exit 5; }
[[ "$volume" == bms24_parity41_db ]] ||
  { echo "STOP: unexpected DB volume: $volume" >&2; exit 5; }
if [[ -n "$(docker port "$DB" 3306/tcp 2>/dev/null || true)" ]]; then
  echo 'STOP: DB port is published to host' >&2
  exit 5
fi
echo 'STAGE1890_DB_ISOLATION_OK'

# Variables are expanded INSIDE the new DB container; no secrets on host command line.
# SQL is strictly read-only and checks that this is an empty test database.
sql_result=$(docker exec "$DB" sh -ec '
  export MYSQL_PWD="$MARIADB_PASSWORD"
  mariadb -h 127.0.0.1 -u "$MARIADB_USER" -D "$MARIADB_DATABASE" -N -B \
    -e "SELECT DATABASE(), COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
')
read -r dbname tablecount <<< "$sql_result"
[[ "$dbname" == paritymoodle && "$tablecount" =~ ^[0-9]+$ ]] ||
  { echo 'STOP: unexpected DB name or SQL result' >&2; exit 5; }
echo "STAGE1890_DB_CONNECT_OK database=$dbname tables=$tablecount"
if ((tablecount != 0)); then
  echo 'STOP: database is not empty; manual review required before new Moodle install' >&2
  exit 5
fi

docker image inspect "$IMAGE" >/dev/null 2>&1 ||
  { echo 'STOP: PHP 8.1 image not present locally' >&2; exit 5; }
# The Moodle image copies .ini from docker-entrypoint.d at startup.
# A temporary direct conf.d mount verifies the exact PHP settings without
# starting the persistent parity webserver.
docker run --rm --network none --read-only --memory 384m --cpus 0.4 \
  --pids-limit 64 --security-opt no-new-privileges \
  --tmpfs /tmp:rw,noexec,nosuid,size=32m \
  -v "$PHP_INI:/usr/local/etc/php/conf.d/99-bms-parity.ini:ro" \
  --entrypoint php "$IMAGE" -r '
    $mem=ini_get("memory_limit");
    $vars=ini_get("max_input_vars");
    echo "PHP ", PHP_VERSION, " memory_limit=", $mem, " max_input_vars=", $vars, PHP_EOL;
    if (PHP_MAJOR_VERSION!==8 || PHP_MINOR_VERSION!==1 ||
        $mem!=="256M" || (int)$vars!==5000) { exit(6); }
    echo "STAGE1890_PHP_CONFIG_OK", PHP_EOL;
  '

echo 'STAGE1890_PARITY41_DB_ACCEPTANCE_OK'
echo 'Next: prepare a separate config.php and verified HTTPS host; do not run the webserver yet.'
