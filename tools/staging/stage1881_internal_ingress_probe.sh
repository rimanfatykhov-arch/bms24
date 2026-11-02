#!/usr/bin/env bash
# BMS24 Stage 1881.14.5: safe-only internal network + localhost ingress experiment.
# Never mutates the running Moodle containers, their networks, or host firewall.
set -Eeuo pipefail

BASE=/srv/bms24-staging/moodle-test
COMPOSE_DIR="$BASE/docker"
MNET=bms24_stage1880_default
PROBE_NET="bms24_stage1881_ingress_net_$$"
PROBE_APP="bms24_stage1881_ingress_app_$$"
CREATED_NET=0
CREATED_APP=0
CONFIG_TMP=
cleanup() {
  if (( CREATED_APP )); then
    docker rm -f "$PROBE_APP" >/dev/null 2>&1 || true
  fi
  if (( CREATED_NET )); then
    docker network rm "$PROBE_NET" >/dev/null 2>&1 || true
  fi
  if [[ -n "$CONFIG_TMP" ]]; then
    rm -f "$CONFIG_TMP"
  fi
}
trap cleanup EXIT
if (( EUID != 0 )); then
  echo "ERROR: run as root in Timeweb, not on production RUWEB" >&2
  exit 1
fi
if [[ ! -f "$BASE/staging.env" || ! -x "$COMPOSE_DIR/bin/moodle-docker-compose" ]]; then
  echo "ERROR: unexpected test Moodle layout; no changes" >&2
  exit 1
fi
if ! docker network inspect "$MNET" >/dev/null 2>&1; then
  echo "ERROR: expected staging network absent; no changes" >&2
  exit 1
fi
if ! docker image inspect moodlehq/moodle-php-apache:8.0 >/dev/null 2>&1; then
  echo "ERROR: local PHP image missing; no pulling external images" >&2
  exit 1
fi

set -a
# Never display staging.env, Docker JSON config, passwords or tokens.
source "$BASE/staging.env"
set +a
cd "$COMPOSE_DIR"

CONFIG_TMP=$(mktemp)
chmod 600 "$CONFIG_TMP"
./bin/moodle-docker-compose config --format json > "$CONFIG_TMP"
python3 - "$CONFIG_TMP" <<'PY'
import json
import sys
import os
text = open(sys.argv[1], encoding="utf-8").read()
start = text.find("{")
if start < 0:
    raise SystemExit("ERROR: compose JSON configuration not available")
data = json.loads(text[start:])
services = data.get("services", {})
required = {"webserver", "db", "mailpit"}
missing = required - set(services)
if missing:
    raise SystemExit("ERROR: staging services missing: " + ", ".join(sorted(missing)))
print("=== COMPOSE SANITIZED TOPOLOGY ===")
print("Services:", ", ".join(sorted(services)))
for name in sorted(required):
    item = services[name]
    nets = item.get("networks", ["default"])
    if isinstance(nets, dict):
        nets = list(nets)
    print(f"{name} networks:", ", ".join(nets))
print("Local override present:", "yes" if os.path.exists("local.yml") else "no")
print("No secrets or configuration values printed")
PY
echo "=== ACTIVE NETWORK (UNCHANGED) ==="
docker network inspect "$MNET" --format 'internal={{.Internal}} driver={{.Driver}}'
echo "=== TEST LOCALHOST PUBLISHED PORT INSIDE SEPARATE INTERNAL NETWORK ==="
docker network create --driver bridge --internal "$PROBE_NET" >/dev/null
CREATED_NET=1
docker run -d --rm --pull never --name "$PROBE_APP" \
  --network "$PROBE_NET" -p 127.0.0.1::8080 \
  --entrypoint php moodlehq/moodle-php-apache:8.0 \
  -S 0.0.0.0:8080 -t /tmp >/dev/null
CREATED_APP=1
mapping=$(docker port "$PROBE_APP" 8080/tcp)
if [[ "$mapping" != 127.0.0.1:* ]]; then
  echo "ERROR: ingress published on a non-local interface: $mapping" >&2
  exit 1
fi
port=${mapping##*:}
status=000
for i in 1 2 3 4 5 6 7 8 9 10; do
  status=$(curl -sS --max-time 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null) || status=000
  if [[ "$status" =~ ^[2345][0-9][0-9]$ ]]; then
    break
  fi
  sleep 1
done
if [[ "$status" == 000 ]]; then
  echo "INGRESS_NOT_AVAILABLE: internal bridge blocked localhost published port" >&2
  echo "Do not cut over existing Moodle network. Review proxy alternatives." >&2
  exit 1
fi
echo "INTERNAL_LOCALHOST_PORT_OK HTTP_${status}"
docker network inspect "$PROBE_NET" --format 'probe_internal={{.Internal}}'
echo "STAGE1881_INGRESS_PROBE_OK"
echo "No live networks, Moodle containers, database, WireGuard, or production changed."
