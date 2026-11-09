#!/usr/bin/env bash
# BMS24 Stage 1898: isolated parity41 webserver, only after stage1897 TLS/Auth.
set -Eeuo pipefail
umask 077
export LC_ALL=C
MODE="${1:---check}"
BASE=/srv/bms24-staging/parity41
DOMAIN=stage41.bms24.online
PROJECT=bms24_parity41
COMPOSE="$BASE/compose.yml"
OVERRIDE="$BASE/runtime.override.yml"
ENVFILE="$BASE/.env"
WEB=bms24_parity41-webserver-1
DB=bms24_parity41-db-1
AUTH=/etc/nginx/private/bms24-stage41.htpasswd
SITE=/etc/nginx/sites-enabled/bms24-stage41
ACME=http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe
EXPECTED=BMS24_STAGE1895_HTTP01_OK

case "$MODE" in --check|--start|--status) ;; *) echo 'Usage: stage1898_webserver.sh --check|--start|--status' >&2; exit 2;; esac
[[ "$(id -u)" == 0 ]] || { echo 'STOP: root required' >&2; exit 2; }
for cmd in docker curl stat grep openssl readlink ss; do command -v "$cmd" >/dev/null || { echo "STOP: missing $cmd" >&2; exit 2; }; done
[[ -d "$BASE" && ! -L "$BASE" && -f "$COMPOSE" && ! -L "$COMPOSE" &&
   -f "$OVERRIDE" && ! -L "$OVERRIDE" && -f "$ENVFILE" && ! -L "$ENVFILE" &&
   "$(stat -c '%a' "$ENVFILE")" == 600 ]] ||
  { echo 'STOP: isolated parity41 files/permissions missing' >&2; exit 3; }
[[ -f "$BASE/moodle/version.php" && ! -L "$BASE/moodle/config.php" &&
   -f "$BASE/moodle/config.php" && -d "$BASE/moodledata" ]] ||
  { echo 'STOP: Moodle Core/config/moodledata not ready' >&2; exit 3; }
grep -Eq '^[[:space:]]*\$version[[:space:]]*=[[:space:]]*2022112822\.00[[:space:]]*;' "$BASE/moodle/version.php" ||
  { echo 'STOP: unexpected Moodle Core version (expected 4.1.22)' >&2; exit 3; }
grep -Fq 'https://stage41.bms24.online' "$BASE/moodle/config.php" ||
  { echo 'STOP: Moodle wwwroot not HTTPS stage41' >&2; exit 3; }
grep -Fq 'name: bms24_parity41' "$COMPOSE" &&
grep -Fq '127.0.0.1:18081:80' "$COMPOSE" &&
grep -Fq 'bms24_parity41_db' "$COMPOSE" &&
grep -Fq 'internal: true' "$COMPOSE" ||
  { echo 'STOP: parity41 Compose isolation mismatch' >&2; exit 3; }
ARGS=(-p "$PROJECT" --project-directory "$BASE" --env-file "$ENVFILE" -f "$COMPOSE" -f "$OVERRIDE")
docker compose "${ARGS[@]}" config --quiet
echo STAGE1898_COMPOSE_SAFE

if [[ "$MODE" == --status ]]; then
  docker compose "${ARGS[@]}" ps
  echo STAGE1898_STATUS_READ_ONLY
  exit 0
fi

[[ -L "$SITE" && "$(readlink "$SITE")" == /etc/nginx/sites-available/bms24-stage41 &&
   -s "$AUTH" && ! -L "$AUTH" &&
   "$(stat -c '%U:%G:%a' "$AUTH")" == root:www-data:640 ]] ||
  { echo 'STOP: stage1897 HTTPS site and Basic Auth not activated' >&2; exit 4; }
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
[[ -s "$CERT" ]] && openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null &&
openssl x509 -checkhost "$DOMAIN" -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: valid stage41 certificate required' >&2; exit 4; }
HTTP_CODE=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' --max-time 8 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" ) ||
  { echo 'STOP: HTTPS cannot be reached with trusted certificate' >&2; exit 4; }
[[ "$HTTP_CODE" == 401 ]] || { echo "STOP: unauthenticated HTTPS must be 401, got $HTTP_CODE" >&2; exit 4; }
ACME_TEXT=$(curl --noproxy '*' -fsS --max-time 8 --resolve "$DOMAIN:80:127.0.0.1" "$ACME") ||
  { echo 'STOP: ACME renewal route inaccessible' >&2; exit 4; }
[[ "$ACME_TEXT" == "$EXPECTED" ]] || { echo 'STOP: ACME probe changed' >&2; exit 4; }
echo STAGE1898_TLS_AUTH_ACME_GATE_OK

[[ "$(docker inspect -f '{{.State.Running}}' "$DB" 2>/dev/null || true)" == true &&
   "$(docker inspect -f '{{.State.Health.Status}}' "$DB" 2>/dev/null || true)" == healthy ]] ||
  { echo 'STOP: isolated parity41 DB must be running and healthy' >&2; exit 4; }
echo STAGE1898_PARITY_DB_HEALTHY
docker image inspect moodlehq/moodle-php-apache:8.1-bookworm >/dev/null 2>&1 ||
  { echo 'STOP: Moodle PHP image unavailable locally; no implicit pull' >&2; exit 4; }
[[ ! -e "/proc/1/root/srv/bms24-staging/parity41/DO_NOT_START" ]] ||
  { echo 'STOP: explicit DO_NOT_START sentinel exists' >&2; exit 4; }

if docker ps -a --format '{{.Names}}' | grep -Fxq "$WEB"; then
  echo 'STOP: web container already exists; inspect manually, never recreate' >&2; exit 4
fi
if ss -lnt '( sport = :18081 )' | grep -q LISTEN; then
  echo 'STOP: 127.0.0.1:18081 already in use' >&2; exit 4
fi
[[ "$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)" -ge 1048576 ]] ||
  { echo 'STOP: less than 1GiB available RAM' >&2; exit 4; }
echo STAGE1898_WEB_PREFLIGHT_OK
[[ "$MODE" == --start ]] || exit 0

# Start only the isolated parity41 webserver. Existing DB must stay untouched.
docker compose "${ARGS[@]}" up -d --no-deps --pull never webserver
[[ "$(docker inspect -f '{{.State.Running}}' "$WEB" 2>/dev/null || true)" == true ]] ||
  { echo 'STOP: parity41 webserver did not start; inspect logs' >&2; exit 5; }
for attempt in {1..15}; do
  code=$(curl --noproxy '*' -s -o /dev/null -w '%{http_code}' --max-time 4 http://127.0.0.1:18081/ || true)
  [[ "$code" != 000 ]] && break
  sleep 2
done
[[ "$code" != 000 && "$code" != 502 && "$code" != 503 ]] ||
  { echo "STOP: localhost web HTTP=$code; inspect container; no acceptance" >&2; exit 5; }
# Reconfirm no unauthenticated external access after backend starts.
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' --max-time 8 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" )
[[ "$code" == 401 ]] || { echo 'STOP: HTTPS gate changed after startup; inspect urgently' >&2; exit 5; }
echo "STAGE1898_LOCAL_WEB_HTTP_$code"
echo STAGE1898_HTTPS_STILL_401
echo STAGE1898_ISOLATED_WEB_STARTED
echo 'Moodle install/upgrade NOT executed. Inspect localhost response and logs before acceptance.'
