#!/usr/bin/env bash
# BMS24 Stage 1895 — ACME-only HTTP-01 vhost for the NEW stage41 domain.
# --check: read-only. --activate: add ONE host-only Nginx vhost and a public
# non-secret probe; never touch Moodle, Compose, database, certbot or other vhosts.
set -Eeuo pipefail
umask 077
export LC_ALL=C

MODE="${1:---check}"
EXPECTED_IP="${2:-}"
DOMAIN=stage41.bms24.online
BASE=/srv/bms24-staging/parity41
SOURCE="$BASE/https-assets/acme-only.conf.example"
SITE=/etc/nginx/sites-available/bms24-stage41-acme
ENABLED=/etc/nginx/sites-enabled/bms24-stage41-acme
WEBROOT=/var/www/letsencrypt
PROBE="$WEBROOT/.well-known/acme-challenge/bms24-stage1895-probe"
MARKER=BMS24_STAGE1895_HTTP01_OK

usage() { echo "Usage: $0 --check|--activate 186.246.110.254" >&2; exit 2; }
[[ "$MODE" == --check || "$MODE" == --activate ]] || usage
[[ "$EXPECTED_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || usage
[[ "$(id -u)" == 0 ]] || { echo "STOP: root required" >&2; exit 2; }

for tool in nginx dig systemctl curl tar ss; do
  command -v "$tool" >/dev/null 2>&1 ||
    { echo "STOP: missing command $tool" >&2; exit 2; }
done
[[ "$(systemctl is-active nginx)" == active ]] ||
  { echo "STOP: Nginx is not active" >&2; exit 2; }
nginx -t >/dev/null 2>&1 || { echo "STOP: existing nginx -t failed" >&2; exit 2; }
[[ -e /etc/nginx/sites-enabled/bms24-test ]] ||
  { echo "STOP: existing bms24-test vhost not found" >&2; exit 2; }
[[ -f "$SOURCE" && ! -L "$SOURCE" ]] ||
  { echo "STOP: Stage 1893 ACME-only example not found" >&2; exit 2; }
[[ -f "$BASE/moodle/config.php" ]] ||
  { echo "STOP: parity41 Moodle config absent" >&2; exit 2; }
grep -Fq "$DOMAIN" "$BASE/moodle/config.php" ||
  { echo "STOP: parity41 wwwroot hostname mismatch" >&2; exit 2; }
for marker in "listen 80;" "server_name $DOMAIN;" "return 404;" \
              "root /var/www/letsencrypt;" "try_files \$uri =404;"; do
  grep -Fq "$marker" "$SOURCE" ||
    { echo "STOP: ACME-only config missing required marker" >&2; exit 2; }
done
if grep -Eq 'proxy_pass|listen[[:space:]]+443|ssl_certificate|auth_basic_user_file' "$SOURCE"; then
  echo 'STOP: insecure or unexpected directives in ACME-only config' >&2
  exit 2
fi

good=0
for resolver in 1.1.1.1 8.8.8.8; do
  rec=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" A |
    grep -E '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | sort -u || true)
  aaaa=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" AAAA |
    grep ':' || true)
  if [[ "$rec" == "$EXPECTED_IP" && -z "$aaaa" ]]; then
    good=$((good+1))
  else
    echo "STOP: stage41 DNS mismatch or AAAA present at resolver $resolver" >&2
    exit 3
  fi
done
echo "STAGE1895_DNS_GATE_OK"
snapshot=$(nginx -T 2>&1)
if ! grep -Eq 'include[[:space:]]+/etc/nginx/sites-enabled/\*;' <<< "$snapshot"; then
  echo 'STOP: sites-enabled glob not found in active Nginx config; manual review' >&2
  exit 3
fi
if ss -lnt "( sport = :80 )" | grep -q LISTEN; then :; else
  echo "STOP: port 80 is not listening"; exit 3
fi
if [[ -e "$ENABLED" || -L "$ENABLED" || -e "$SITE" || -L "$SITE" ]]; then
  echo 'STAGE1895_EXISTING_SITE_PATH_REVIEW: do not overwrite'
  exit 3
fi
if grep -Eq '^[[:space:]]*server_name[[:space:]][^;]*stage41\.bms24\.online' <<< "$snapshot"; then
  echo 'STOP: stage41 server_name already configured elsewhere' >&2
  exit 3
fi

if [[ "$MODE" == --check ]]; then
  echo "STAGE1895_ACME_ONLY_PREFLIGHT_OK"
  echo "State: no Nginx edits, no Certbot run, no Docker changes"
  exit 0
fi

# Pre-activation backup, restricted to root. No secrets are printed.
BACKUP_DIR=/srv/bms24-staging/backups
install -d -m 0700 "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/nginx-pre-stage1895-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
[[ ! -e "$BACKUP" ]] || { echo 'STOP: backup name collision' >&2; exit 4; }
tar -C /etc -czf "$BACKUP" nginx
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null ||
  { echo 'STOP: Nginx configuration backup is not readable' >&2; exit 4; }
echo "STAGE1895_NGINX_BACKUP_OK $BACKUP"

# ACME webroot: only create directories if not symlinks.
for d in /var/www /var/www/letsencrypt /var/www/letsencrypt/.well-known \
         /var/www/letsencrypt/.well-known/acme-challenge; do
  [[ ! -L "$d" ]] || { echo "STOP: symlink in ACME webroot path $d" >&2; exit 4; }
done
install -d -m 0755 "$WEBROOT/.well-known/acme-challenge"
if [[ -e "$PROBE" || -L "$PROBE" ]]; then
  [[ -f "$PROBE" && ! -L "$PROBE" ]] ||
    { echo "STOP: ACME probe collision" >&2; exit 4; }
  [[ "$(cat "$PROBE")" == "$MARKER" ]] ||
    { echo "STOP: unexpected ACME probe content" >&2; exit 4; }
else
  printf '%s\n' "$MARKER" > "$PROBE"
  chmod 0644 "$PROBE"
fi

# Exact site paths only. Rollback only files added in this invocation.
added_site=0
added_symlink=0
rollback() {
  echo "ROLLBACK: restoring Nginx to its prior configuration" >&2
  if ((added_symlink)); then rm -f -- "$ENABLED"; fi
  if ((added_site)); then rm -f -- "$SITE"; fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
}
trap 'rollback' ERR
install -m 0644 "$SOURCE" "$SITE"
added_site=1
ln -s "$SITE" "$ENABLED"
added_symlink=1
nginx -t >/dev/null
systemctl reload nginx
# This tests ONLY local Nginx routing, not Internet HTTP-01 reachability.
BODY=$(curl --noproxy '*' --silent --show-error --fail --max-time 8 \
  --resolve "$DOMAIN:80:127.0.0.1" \
  "http://$DOMAIN/.well-known/acme-challenge/bms24-stage1895-probe")
[[ "$BODY" == "$MARKER" ]] || { echo 'STOP: local HTTP-01 body mismatch' >&2; false; }
ROOT_CODE=$(curl --noproxy '*' --silent --output /dev/null --write-out '%{http_code}' \
  --max-time 8 --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/")
[[ "$ROOT_CODE" == 404 ]] || { echo "STOP: unsafe normal HTTP response $ROOT_CODE" >&2; false; }
trap - ERR
echo 'STAGE1895_ACME_ONLY_NGINX_ACTIVE'
echo 'STAGE1895_LOCAL_HTTP01_PROBE_OK'
echo "External test URL: http://$DOMAIN/.well-known/acme-challenge/bms24-stage1895-probe"
echo "Expected external body: $MARKER"
echo 'Certificate NOT requested. Test from outside Timeweb before certbot certonly --webroot.'
