#!/usr/bin/env bash
# BMS24 stage 1881.17: deploy exactly one CLI file to staging; dry-run only.
# This helper deliberately does not touch the database or run --apply.
set -Eeuo pipefail
umask 077

if [[ $(id -u) -ne 0 ]]; then
    echo 'STOP: run this installer as root on the staging VDS' >&2
    exit 2
fi

BASE='/srv/bms24-staging/moodle-test'
REPO='/srv/bms24-staging/workspace/bms24'
BRANCH='fix/1881-corporate-staging-bridge'
REL='local/kbacompany/cli/corporate_bridge_staging.php'
TARGET="$BASE/moodle/$REL"
BACKUPDIR='/srv/bms24-staging/backups/corporate-bridge'
UUID='b1d324df-b6fe-461a-8d6a-c38ec9dc9c6d'

[[ -f "$BASE/staging.env" && -f "$BASE/moodle/config.php" && -d "$REPO/.git" ]] || {
    echo 'STOP: expected staging config or Git checkout missing' >&2
    exit 2
}
[[ -d "$(dirname "$TARGET")" && ! -L "$TARGET" ]] || {
    echo 'STOP: target directory missing or target is a symbolic link' >&2
    exit 2
}
command -v runuser >/dev/null || { echo 'STOP: runuser is unavailable' >&2; exit 2; }
id bmsdev >/dev/null || { echo 'STOP: bmsdev account is missing' >&2; exit 2; }

# GitHub private repo credentials live with bmsdev, not root.
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
    "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

TMP=$(mktemp)
BACKUP=''
EXISTED=0
INSTALLED=0
cleanup() { rm -f "$TMP"; }
rollback() {
    if (( INSTALLED )); then
        if (( EXISTED )); then
            cp -p "$BACKUP" "$TARGET"
        else
            rm -f "$TARGET"
        fi
        echo 'STAGING CLI FILE RESTORED AFTER ERROR' >&2
    fi
}
trap 'rollback; cleanup' ERR
trap cleanup EXIT

runuser -u bmsdev -- git -C "$REPO" show \
    "refs/remotes/origin/$BRANCH:$REL" > "$TMP"
if ! grep -q 'BMS24 stage 1881.16' "$TMP"; then
    echo 'STOP: unexpected source file in remote branch' >&2
    exit 3
fi
test -s "$TMP" || { echo 'STOP: downloaded CLI file is empty' >&2; exit 3; }
mkdir -p "$BACKUPDIR"
if [[ -f "$TARGET" ]]; then
    EXISTED=1
    BACKUP="$BACKUPDIR/corporate_bridge_staging-$(date +%Y%m%d-%H%M%S-%N).php"
    cp -p "$TARGET" "$BACKUP"
fi
install -m 0644 -o root -g root "$TMP" "$TARGET"
INSTALLED=1

source "$BASE/staging.env"
cd "$BASE/docker"
./bin/moodle-docker-compose exec -T webserver \
    php -l "$REL"
./bin/moodle-docker-compose exec -T webserver \
    php "$REL" --companyuuid="$UUID"

INSTALLED=0
trap - ERR
echo 'STAGE1881_BRIDGE_INSTALLED_DRY_RUN_OK: no database changes made'
if [[ -n "$BACKUP" ]]; then
    echo "Old CLI backup: $BACKUP"
fi
