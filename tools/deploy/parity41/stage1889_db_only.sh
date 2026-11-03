#!/usr/bin/env bash
# BMS24 Stage 1889. Prepare PHP and optionally start ONLY isolated parity41 MariaDB.
# No Moodle install, no webserver startup, no updates to existing staging.
set -Eeuo pipefail
umask 077
export LC_ALL=C
BASE=/srv/bms24-staging/parity41
MODE="${1:---prepare}"
OVERRIDE_SRC="${2:-/tmp/bms24-parity41-runtime.override.yml}"
INI_SRC="${3:-/tmp/bms24-parity41-php.ini}"
OVERRIDE="$BASE/runtime.override.yml"
INI="$BASE/config/php/90-bms-parity.ini"
COMPOSE="$BASE/compose.yml"
ENVFILE="$BASE/.env"
PROJECT=bms24_parity41
DB_CONTAINER=bms24_parity41-db-1

case "$MODE" in --prepare|--start-db|--status) ;;
  *) echo "Usage: $0 [--prepare|--start-db|--status] [overlay.yml] [settings.ini]" >&2; exit 2 ;;
esac
[[ "$(id -u)" == 0 ]] || { echo 'STOP: root is required' >&2; exit 2; }
[[ -d "$BASE/moodle" && -f "$BASE/moodle/version.php" &&
   -f "$COMPOSE" && -f "$ENVFILE" && ! -L "$BASE" && ! -L "$ENVFILE" ]] ||
  { echo 'STOP: stage1885-1888 baseline is incomplete' >&2; exit 2; }
[[ "$(stat -c '%a' "$ENVFILE")" == 600 ]] ||
  { echo 'STOP: parity41/.env must have permissions 0600' >&2; exit 2; }
grep -Eq '^[[:space:]]*\$version[[:space:]]*=[[:space:]]*2022112822\.00[[:space:]]*;' "$BASE/moodle/version.php" ||
  { echo 'STOP: not Moodle Core 4.1.22' >&2; exit 2; }
grep -Fqx 'name: bms24_parity41' "$COMPOSE" ||
  { echo 'STOP: unexpected Compose project' >&2; exit 2; }
grep -Fq '127.0.0.1:18081:80' "$COMPOSE" ||
  { echo 'STOP: unexpected Compose web port' >&2; exit 2; }
grep -Fq 'bms24_parity41_db' "$COMPOSE" ||
  { echo 'STOP: separate database volume not defined' >&2; exit 2; }
grep -Fq 'internal: true' "$COMPOSE" ||
  { echo 'STOP: private Docker network not defined' >&2; exit 2; }
docker compose version >/dev/null ||
  { echo 'STOP: Docker Compose v2 required' >&2; exit 2; }

if [[ "$MODE" == "--prepare" ]]; then
  for source in "$OVERRIDE_SRC" "$INI_SRC"; do
    [[ -f "$source" && ! -L "$source" ]] ||
      { echo "STOP: missing local source file $source" >&2; exit 2; }
  done
  grep -Fq './config/php/90-bms-parity.ini:/docker-entrypoint.d/90-bms-parity.ini:ro' "$OVERRIDE_SRC" ||
    { echo 'STOP: overlay must mount only isolated PHP settings' >&2; exit 2; }
  grep -Fq 'memory_limit = 256M' "$INI_SRC" ||
    { echo 'STOP: expected PHP memory limit is not present' >&2; exit 2; }
  install -d -m 0755 "$BASE/config/php"
  for mapping in "$OVERRIDE_SRC:$OVERRIDE" "$INI_SRC:$INI"; do
    source="${mapping%%:*}"
    destination="${mapping#*:}"
    if [[ -e "$destination" || -L "$destination" ]]; then
      [[ ! -L "$destination" ]] || { echo "STOP: symlink $destination" >&2; exit 2; }
      cmp -s "$source" "$destination" ||
        { echo "STOP: $destination already exists with different content" >&2; exit 2; }
    else
      install -m 0644 "$source" "$destination"
    fi
  done
fi

[[ -f "$OVERRIDE" && -f "$INI" && ! -L "$OVERRIDE" && ! -L "$INI" ]] ||
  { echo 'STOP: run --prepare before --start-db or --status' >&2; exit 2; }
COMPOSE_ARGS=(-p "$PROJECT" --project-directory "$BASE" --env-file "$ENVFILE" -f "$COMPOSE" -f "$OVERRIDE")
docker compose "${COMPOSE_ARGS[@]}" config --quiet
echo 'STAGE1889_COMPOSE_CONFIG_OK'

if [[ "$MODE" == "--status" ]]; then
  docker compose "${COMPOSE_ARGS[@]}" ps
  echo 'STAGE1889_STATUS_READ_ONLY'
  exit 0
fi
if [[ "$MODE" == "--prepare" ]]; then
  echo 'STAGE1889_PHP_OVERRIDE_READY'
  echo 'PHP memory_limit=256M max_input_vars=5000'
  echo 'No Docker container created and no database touched.'
  exit 0
fi

# --start-db intentionally creates ONLY the parity41 database container and volume.
if docker ps -a --format '{{.Names}}' | grep -Fxq "$DB_CONTAINER"; then
  echo 'STOP: parity41 DB container already exists; use --status (no recreation)' >&2
  exit 3
fi
if docker volume ls --format '{{.Name}}' | grep -Fxq 'bms24_parity41_db'; then
  echo 'STOP: parity41 DB volume already exists; refusing implicit reuse' >&2
  exit 3
fi
[[ "$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)" -ge 1536000 ]] ||
  { echo 'STOP: less than 1.5 GiB available RAM' >&2; exit 3; }
docker image inspect mariadb:10.11 >/dev/null 2>&1 ||
  { echo 'STOP: mariadb:10.11 image not present locally' >&2; exit 3; }

docker compose "${COMPOSE_ARGS[@]}" up -d --no-deps --pull never db
echo 'STAGE1889_DB_CONTAINER_STARTED (not yet an acceptance pass)'
docker compose "${COMPOSE_ARGS[@]}" ps
echo 'Next: inspect DB health; do not start Moodle webserver yet.'
