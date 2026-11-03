#!/usr/bin/env bash
# BMS24 Stage 1885 - creates ONLY a separate empty parity41 working directory.
# NO Docker image pulls, NO container starts, NO DB access, NO production writes.
set -Eeuo pipefail
umask 077
export LC_ALL=C

ROOT='/srv/bms24-staging/parity41'
EXISTING='/srv/bms24-staging/moodle-test/moodle'
TEMPLATE="${1:-}"
if [[ $# -ne 1 || -z "$TEMPLATE" || ! -f "$TEMPLATE" ]]; then
    echo 'Usage: bash prepare_parity41.sh /tmp/bms24-parity41-compose.yml' >&2
    exit 2
fi
if [[ "$(id -u)" -ne 0 || ! -f "$EXISTING/version.php" ||
      -L "$ROOT" || ! -d /srv/bms24-staging ]]; then
    echo 'STOP: root account or expected staging safety conditions missing' >&2
    exit 2
fi
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    echo 'STOP: Docker Compose v2 required; no files created' >&2
    exit 2
fi
if ! grep -q '127.0.0.1:18081:80' "$TEMPLATE" ||
   ! grep -q 'name: bms24_parity41' "$TEMPLATE"; then
    echo 'STOP: unexpected compose template; refusing to prepare' >&2
    exit 2
fi
if ss -lnt '( sport = :18081 )' | grep -q 'LISTEN'; then
    echo 'STOP: localhost:18081 is already in use' >&2
    exit 2
fi

install -d -m 0755 "$ROOT"
if [[ -e "$ROOT/compose.yml" ]]; then
    if ! cmp -s "$TEMPLATE" "$ROOT/compose.yml"; then
        echo 'STOP: different compose file already present; no overwrite' >&2
        exit 3
    fi
else
    install -m 0644 "$TEMPLATE" "$ROOT/compose.yml"
fi
install -d -m 0755 "$ROOT/moodle"
install -d -m 0700 -o 33 -g 33 "$ROOT/moodledata"

# Show only directory structure and compose project identity; secrets never generated or printed.
echo 'STAGE1885_PARITY41_DIRECTORY_PREPARED'
echo "Location: $ROOT"
echo 'State: no Moodle Core download; no database; no containers started'
echo 'NEXT: obtain verified Moodle 4.1.22 code and review PHP/DB setup in separate step.'
