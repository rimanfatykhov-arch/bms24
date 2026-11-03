#!/usr/bin/env bash
# STAGE 1893: stage Nginx config EXAMPLES under isolated parity41 directory.
# Read-only --check; --prepare modifies only /srv/bms24-staging/parity41/https-assets.
# NEVER touches /etc/nginx, Docker, DNS, firewall, Certbot, database or production.
set -Eeuo pipefail
umask 077
export LC_ALL=C

MODE="${1:---check}"
ACME_SRC="${2:-/tmp/bms24-stage41-acme-only.conf}"
HTTPS_SRC="${3:-/tmp/bms24-stage41-https.conf}"
ROOT=/srv/bms24-staging/parity41
DEST="$ROOT/https-assets"
DOMAIN=stage41.bms24.online

[[ "${EUID}" -eq 0 ]] || { echo "STOP: run as root on Timeweb VDS" >&2; exit 2; }
case "$MODE" in
  --check|--prepare) ;;
  *) echo 'Usage: script [--check|--prepare] [acme-template] [https-template]' >&2; exit 2 ;;
esac
[[ -d "$ROOT" && ! -L "$ROOT" && -f "$ROOT/moodle/version.php" ]] ||
  { echo 'STOP: isolated parity41 source directory missing' >&2; exit 2; }
[[ -f "$ROOT/moodle/config.php" && ! -L "$ROOT/moodle/config.php" ]] ||
  { echo 'STOP: parity41 Moodle config.php not found' >&2; exit 2; }
if ! grep -Fq "$DOMAIN" "$ROOT/moodle/config.php"; then
  echo 'STOP: expected stage41 hostname is absent from Moodle config.php' >&2
  exit 2
fi
if [[ "$MODE" == --check ]]; then
  if [[ -d "$DEST" && ! -L "$DEST" &&
        -f "$DEST/acme-only.conf.example" &&
        -f "$DEST/https.conf.example" ]]; then
    echo "STAGE1893_ASSETS_PRESENT $DEST"
  else
    echo "STAGE1893_ASSETS_NOT_PREPARED"
  fi
  echo 'Only read-only checks performed; no site or certificates provisioned.'
  exit 0
fi

for f in "$ACME_SRC" "$HTTPS_SRC"; do
  [[ -f "$f" && ! -L "$f" ]] ||
    { echo "STOP: missing template $f" >&2; exit 2; }
done
# Protect against accidental promotion of an unguarded PHP backend.
grep -Fq 'server_name stage41.bms24.online;' "$ACME_SRC" ||
  { echo 'STOP: wrong ACME hostname' >&2; exit 2; }
grep -Fq 'return 404;' "$ACME_SRC" ||
  { echo 'STOP: ACME-only template must reject normal HTTP requests' >&2; exit 2; }
if grep -Eq 'proxy_pass|listen 443' "$ACME_SRC"; then
  echo 'STOP: ACME template must not start HTTPS or proxy to Moodle' >&2
  exit 2
fi
for expected in 'server_name stage41.bms24.online;'   'auth_basic_user_file /etc/nginx/private/bms24-stage41.htpasswd;'   'proxy_pass http://127.0.0.1:18081;'   'proxy_set_header X-Forwarded-Proto https;'; do
  grep -Fq "$expected" "$HTTPS_SRC" ||
    { echo "STOP: HTTPS template safety marker missing: $expected" >&2; exit 2; }
done

install -d -m 0700 "$DEST"
for item in "$ACME_SRC:$DEST/acme-only.conf.example"             "$HTTPS_SRC:$DEST/https.conf.example"; do
  src="${item%%:*}"
  dst="${item#*:}"
  if [[ -e "$dst" || -L "$dst" ]]; then
    [[ ! -L "$dst" ]] || { echo "STOP: symlink destination $dst" >&2; exit 2; }
    cmp -s "$src" "$dst" ||
      { echo "STOP: destination exists with different content: $dst" >&2; exit 2; }
  else
    install -m 0600 "$src" "$dst"
  fi
done
echo 'STAGE1893_HTTPS_ASSETS_PREPARED'
echo "Location: $DEST"
echo 'State: templates staged only; Nginx NOT reconfigured; no DNS, TLS, or Docker changes.'
echo 'Next gate: review Stage 1892 audit and verify DNS before enabling ACME-only vhost.'
