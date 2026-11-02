#!/usr/bin/env bash
# BMS24 stage 1881.19: install only read-only ACL audit, then run it.
# Requires configured staging Docker and bmsdev access to the private GitHub repo.
set -Eeuo pipefail
umask 077

BASE='/srv/bms24-staging/moodle-test'
REPO='/srv/bms24-staging/workspace/bms24'
BRANCH='fix/1881-corporate-staging-bridge'
REL='local/kbacompany/cli/corporate_acl_acceptance_staging.php'
FILE="$BASE/moodle/$REL"
UUID='b1d324df-b6fe-461a-8d6a-c38ec9dc9c6d'
BACKUPDIR='/srv/bms24-staging/backups/acl-acceptance'

if [[ $(id -u) -ne 0 || ! -d "$REPO/.git" || ! -f "$BASE/staging.env" ||
      ! -d "$(dirname "$FILE")" || -L "$FILE" ]]; then
    echo 'STOP: expected root/staging environment is missing' >&2
    exit 2
fi

TMP=$(mktemp)
BACKUP=''
EXISTED=0
INSTALLED=0
cleanup() { rm -f "$TMP"; }
rollback() {
    if (( INSTALLED )); then
        if (( EXISTED )); then cp -p "$BACKUP" "$FILE"; else rm -f "$FILE"; fi
        echo 'STAGE1881_ACL_CLI_RESTORED_AFTER_ERROR' >&2
    fi
}
trap 'rollback; cleanup' ERR
trap cleanup EXIT

# Read source from isolated feature branch. Never modify main or production.
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
    "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
    "refs/remotes/origin/$BRANCH:$REL" > "$TMP"
if ! grep -q 'Stage 1881.19' "$TMP"; then
    echo 'STOP: retrieved file is not the expected acceptance CLI' >&2
    exit 3
fi

mkdir -p "$BACKUPDIR"
if [[ -f "$FILE" ]]; then
    EXISTED=1
    BACKUP="$BACKUPDIR/acl-acceptance-$(date -u +%Y%m%dT%H%M%SZ)-$$.php"
    cp -p "$FILE" "$BACKUP"
fi
install -m 0644 -o root -g root "$TMP" "$FILE"
INSTALLED=1

source "$BASE/staging.env"
cd "$BASE/docker"
./bin/moodle-docker-compose exec -T webserver php -l "$REL"

# Syntax check success makes installation persistent; audit failure is a test failure,
# not a reason to restore the code under test.
INSTALLED=0
trap - ERR
echo 'STAGE1881_ACL_CLI_INSTALLED'

# Public smoke: correct HTTPS host, no server error and no localhost redirect.
HEADERS=$(mktemp)
trap 'rm -f "$TMP" "$HEADERS"' EXIT
curl --noproxy '*' --silent --show-error --max-time 20 \
    --resolve test.bms24.online:443:127.0.0.1 \
    --dump-header "$HEADERS" --output /dev/null \
    https://test.bms24.online/login/index.php
STATUS=$(awk '/^HTTP\// {code=$2} END {print code}' "$HEADERS")
case "$STATUS" in
    2??|3??) ;;
    *) echo "STAGE1881_HTTP_FAILED: status=$STATUS" >&2; exit 5 ;;
esac
if grep -Eiq '^location:.*(localhost|127[.]0[.]0[.]1)' "$HEADERS"; then
    echo 'STAGE1881_HTTP_FAILED: localhost redirect' >&2
    exit 5
fi
echo "STAGE1881_HTTPS_SMOKE_OK: status=$STATUS"

./bin/moodle-docker-compose exec -T webserver \
    php "$REL" --companyuuid="$UUID" --outsiderusername=bms24_acl_outsider

# In-browser acceptance of Implementation.php remains a separate manual step.
echo 'STAGE1881_ACL_PHP_ACCEPTANCE_COMPLETED'
