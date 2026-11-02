#!/usr/bin/env bash
# Stage 1881.18 — staging-only transactional bridge with mandatory DB backup.
# Usage: bash stage1881_corporate_bridge_run.sh [--preflight | --apply | --verify]
set -Eeuo pipefail
umask 077

BASE='/srv/bms24-staging/moodle-test'
REL='local/kbacompany/cli/corporate_bridge_staging.php'
UUID='b1d324df-b6fe-461a-8d6a-c38ec9dc9c6d'
BACKUP_DIR='/srv/bms24-staging/backups/corporate-bridge'
MODE='--preflight'
if (( $# > 0 )); then MODE="$1"; fi

if [[ $# -gt 1 || ! "$MODE" =~ ^(--preflight|--apply|--verify)$ ]]; then
    echo 'Usage: bash stage1881_corporate_bridge_run.sh [--preflight|--apply|--verify]' >&2
    exit 2
fi
if [[ $(id -u) -ne 0 ]]; then
    echo 'STOP: run as root on staging only.' >&2
    exit 2
fi
if [[ ! -f "$BASE/staging.env" || ! -f "$BASE/moodle/config.php" ||
    ! -f "$BASE/moodle/$REL" || ! -x "$BASE/docker/bin/moodle-docker-compose" ]]; then
    echo 'STOP: staging layout or installed bridge CLI missing.' >&2
    exit 2
fi
if ! grep -q "VERIFY_OK: company bridge" "$BASE/moodle/$REL"; then
    echo 'STOP: bridge CLI is outdated; reinstall latest branch from PR #17.' >&2
    exit 2
fi

source "$BASE/staging.env"
cd "$BASE/docker"

# This PHP lint is executed in the running Moodle Docker webserver.
./bin/moodle-docker-compose exec -T webserver php -l "$REL"

case "$MODE" in
    --preflight)
        ./bin/moodle-docker-compose exec -T webserver php "$REL" --companyuuid="$UUID"
        echo 'STAGE1881_PREFLIGHT_OK: no database changes made'
        ;;
    --verify)
        ./bin/moodle-docker-compose exec -T webserver php "$REL" --companyuuid="$UUID" --verify
        echo 'STAGE1881_VERIFICATION_OK: no database changes made'
        ;;
    --apply)
        # Read-only preflight. The PHP guard checks fixed HTTPS staging wwwroot before writing.
        ./bin/moodle-docker-compose exec -T webserver php "$REL" --companyuuid="$UUID"
        mkdir -p "$BACKUP_DIR"
        BACKUP="$BACKUP_DIR/bridge-$(date -u +%Y%m%dT%H%M%SZ)-$$.sql.gz"
        TMP="$BACKUP.partial"
        trap 'rm -f "$TMP"' EXIT
        # Credentials remain inside DB container; no secrets printed to the terminal.
        ./bin/moodle-docker-compose exec -T db sh -ec '
            test -n "$MYSQL_USER" && test -n "$MYSQL_DATABASE" &&
            test -n "$MYSQL_PASSWORD" || exit 4
            export MYSQL_PWD="$MYSQL_PASSWORD"
            exec mariadb-dump --single-transaction --quick --skip-lock-tables \
                --user="$MYSQL_USER" "$MYSQL_DATABASE"
        ' | gzip -1 > "$TMP"
        test -s "$TMP"
        gzip -t "$TMP"
        chmod 600 "$TMP"
        mv "$TMP" "$BACKUP"
        echo "STAGE1881_DATABASE_BACKUP_OK: $BACKUP"
        # Do not proceed on failed dump or failed preflight.
        ./bin/moodle-docker-compose exec -T -e BMS24_STAGING_BRIDGE_ALLOW=1 webserver \
            php "$REL" --companyuuid="$UUID" --apply
        ./bin/moodle-docker-compose exec -T webserver php "$REL" --companyuuid="$UUID" --verify
        echo 'STAGE1881_APPLY_AND_VERIFY_OK'
        ;;
esac
