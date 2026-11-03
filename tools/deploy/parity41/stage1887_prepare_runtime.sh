#!/usr/bin/env bash
# Stage 1887: validate isolated parity41 files and optionally create NEW test DB credentials.
# NO Docker start, pull, exec or database migration. Never reads the old site config.
set -Eeuo pipefail
umask 077
export LC_ALL=C

BASE=/srv/bms24-staging/parity41
CORE="$BASE/moodle"
COMPOSE="$BASE/compose.yml"
ENVFILE="$BASE/.env"
MODE="${1:---check}"

case "$MODE" in
    --check|--init-secrets) ;;
    *) echo "Usage: bash $0 [--check|--init-secrets]" >&2; exit 2 ;;
esac

[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: run as root on staging VDS' >&2; exit 2; }
for path in "$BASE" "$CORE" "$BASE/moodledata" "$COMPOSE"; do
    [[ -e "$path" && ! -L "$path" ]] ||
        { echo "STOP: expected parity41 path absent or symlink: $path" >&2; exit 2; }
done
[[ -f "$CORE/version.php" && -d "$CORE/lib" && -d "$CORE/admin" ]] ||
    { echo 'STOP: Moodle Core structure is incomplete' >&2; exit 2; }
grep -Eq '^[[:space:]]*\$version[[:space:]]*=[[:space:]]*2022112822\.00[[:space:]]*;' "$CORE/version.php" ||
    { echo 'STOP: expected Moodle 4.1.22 core version not found' >&2; exit 2; }
grep -Eq '^[[:space:]]*\$branch[[:space:]]*=[[:space:]]*.*401.*;' "$CORE/version.php" ||
    { echo 'STOP: expected Moodle branch 401 not found' >&2; exit 2; }

# Reject accidental use of the existing staging network, project or host port.
grep -Fqx 'name: bms24_parity41' "$COMPOSE" ||
    { echo 'STOP: separate Compose project name missing' >&2; exit 2; }
grep -Fq '127.0.0.1:18081:80' "$COMPOSE" ||
    { echo 'STOP: separate localhost port missing' >&2; exit 2; }
grep -Fq 'internal: true' "$COMPOSE" ||
    { echo 'STOP: internal-only Docker network missing' >&2; exit 2; }
grep -Fq 'bms24_parity41_db' "$COMPOSE" ||
    { echo 'STOP: dedicated database volume missing' >&2; exit 2; }
if ss -lnt '( sport = :18081 )' | grep -q 'LISTEN'; then
    echo 'STOP: localhost port 18081 is occupied' >&2
    exit 2
fi
if docker ps -a --format '{{.Names}}' | grep -Eq '^bms24_parity41[-_]'; then
    echo 'STOP: parity41 containers already exist; check their status before continuing' >&2
    exit 2
fi
if docker volume ls --format '{{.Name}}' | grep -Fxq 'bms24_parity41_db'; then
    echo 'STOP: parity41 DB volume already exists; refusing to assume it is empty' >&2
    exit 2
fi
command -v openssl >/dev/null || { echo 'STOP: openssl is required' >&2; exit 2; }
docker compose version >/dev/null || { echo 'STOP: Docker Compose v2 missing' >&2; exit 2; }

if [[ "$MODE" == '--init-secrets' ]]; then
    if [[ -e "$ENVFILE" || -L "$ENVFILE" ]]; then
        echo 'STAGE1887_ENV_ALREADY_EXISTS: secrets unchanged; no overwrite'
    else
        TMP=$(mktemp "$BASE/.stage1887-env.XXXXXXXX")
        cleanup() { [[ ! -e "${TMP:-}" ]] || rm -f -- "$TMP"; }
        trap cleanup EXIT
        password=$(openssl rand -hex 32)
        rootpassword=$(openssl rand -hex 32)
        {
            printf 'PARITY_DB_PASSWORD=%s\n' "$password"
            printf 'PARITY_DB_ROOT_PASSWORD=%s\n' "$rootpassword"
        } > "$TMP"
        unset password rootpassword
        chmod 600 "$TMP"
        # Hard link fails if .env appeared concurrently: never overwrite credentials.
        ln -- "$TMP" "$ENVFILE"
        rm -f -- "$TMP"
        trap - EXIT
        echo 'STAGE1887_TEST_DB_SECRETS_CREATED (content intentionally hidden)'
    fi
fi

# Validate Compose interpolation without printing resolved database passwords.
if [[ -f "$ENVFILE" && ! -L "$ENVFILE" ]]; then
    mode=$(stat -c '%a' "$ENVFILE")
    [[ "$mode" == 600 ]] || { echo 'STOP: .env must be mode 600; no permissions changed' >&2; exit 2; }
    docker compose -p bms24_parity41 --project-directory "$BASE" \
        --env-file "$ENVFILE" -f "$COMPOSE" config --quiet
    echo 'STAGE1887_COMPOSE_CONFIG_OK (using local .env)'
else
    env PARITY_DB_PASSWORD=placeholder_not_for_real_start \
        PARITY_DB_ROOT_PASSWORD=placeholder_not_for_real_start \
        docker compose -p bms24_parity41 --project-directory "$BASE" \
        -f "$COMPOSE" config --quiet
    echo 'STAGE1887_COMPOSE_CONFIG_OK (placeholder validation only)'
fi

for image in moodlehq/moodle-php-apache:8.1-bookworm mariadb:10.11; do
    if docker image inspect "$image" >/dev/null 2>&1; then
        echo "IMAGE_LOCAL $image"
    else
        echo "IMAGE_NOT_PRESENT $image"
    fi
done

echo 'STAGE1887_PARITY41_RUNTIME_PREP_OK'
echo 'No container started, no Docker image downloaded, no Moodle install performed.'
