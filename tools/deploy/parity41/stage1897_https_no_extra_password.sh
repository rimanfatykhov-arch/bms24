#!/usr/bin/env bash
# BMS24 1897 — replace ACME-only vhost by HTTPS maintenance (NO Basic Auth).
# --status and --check are read-only. --activate changes only stage41 vhost.
# Moodle/Compose/DB remain untouched. Test bms24-test preserved.
set -Eeuo pipefail
umask 077
export LC_ALL=C

MODE="$1"
DOMAIN=stage41.bms24.online
BASE=/srv/bms24-staging/parity41
SRC="$BASE/https-assets/https-maintenance-no-auth.conf.example"
ACME_SITE=/etc/nginx/sites-available/bms24-stage41-acme
ACME_LINK=/etc/nginx/sites-enabled/bms24-stage41-acme
SITE=/etc/nginx/sites-available/bms24-stage41
LINK=/etc/nginx/sites-enabled/bms24-stage41
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
PROBE="http://$DOMAIN/.well-known/acme-challenge/bms24-stage1895-probe"
MARKER=BMS24_STAGE1895_HTTP01_OK
MAINT=BMS24_PARITY41_HTTPS_MAINTENANCE

case "$MODE" in
  --status|--check|--activate) ;;
  *) echo "Usage: $0 --status|--check|--activate" >&2; exit 2 ;;
esac
[[ "$(id -u)" == 0 ]] || { echo 'STOP: run as root' >&2; exit 2; }
for cmd in nginx systemctl curl openssl stat tar; do
  command -v "$cmd" >/dev/null || { echo "STOP: missing $cmd" >&2; exit 2; }
done
nginx -t >/dev/null 2>&1 || { echo 'STOP: current Nginx syntax failure' >&2; exit 2; }
[[ "$(systemctl is-active nginx 2>/dev/null || true)" == active ]] ||
  { echo 'STOP: Nginx inactive' >&2; exit 2; }
[[ -e /etc/nginx/sites-enabled/bms24-test ]] ||
  { echo 'STOP: original bms24-test vhost absent' >&2; exit 2; }
if [[ "$MODE" == --status ]]; then
  [[ -s "$CERT" && -s "$KEY" ]] && echo STAGE1897_CERT_FILES_PRESENT ||
    echo STAGE1897_CERT_FILES_MISSING
  [[ -L "$ACME_LINK" ]] && echo STAGE1897_ACME_SITE_ENABLED ||
    echo STAGE1897_ACME_SITE_NOT_ENABLED
  [[ -L "$LINK" ]] && echo STAGE1897_HTTPS_SITE_ENABLED ||
    echo STAGE1897_HTTPS_SITE_NOT_ENABLED
  echo STAGE1897_NO_BASIC_AUTH_STATUS_READ_ONLY
  exit 0
fi
[[ -f "$SRC" && ! -L "$SRC" ]] || { echo 'STOP: reviewed maintenance template absent' >&2; exit 3; }
[[ -s "$CERT" && -s "$KEY" ]] || { echo 'STOP: certificate missing' >&2; exit 3; }
openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: certificate expires too soon' >&2; exit 3; }
openssl x509 -checkhost "$DOMAIN" -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: certificate does not match host' >&2; exit 3; }
certpub=$(openssl x509 -in "$CERT" -pubkey -noout |
  openssl pkey -pubin -outform DER | sha256sum | cut -d' ' -f1)
keypub=$(openssl pkey -in "$KEY" -pubout -outform DER |
  sha256sum | cut -d' ' -f1)
[[ "$certpub" == "$keypub" ]] || { echo 'STOP: TLS private key mismatch' >&2; exit 3; }
echo STAGE1897_TLS_CERT_OK
[[ -e "$ACME_SITE" && ! -L "$ACME_SITE" && -L "$ACME_LINK" &&
   "$(readlink "$ACME_LINK")" == "$ACME_SITE" ]] ||
  { echo 'STOP: expected original ACME-only site not enabled' >&2; exit 3; }
[[ ! -e "$SITE" && ! -L "$SITE" && ! -e "$LINK" && ! -L "$LINK" ]] ||
  { echo 'STOP: HTTPS site already exists; refusing to overwrite' >&2; exit 3; }
for expected in "listen 443 ssl;" "server_name $DOMAIN;" \
  "ssl_certificate $CERT;" "ssl_certificate_key $KEY;" \
  "return 503 \"$MAINT" "root /var/www/letsencrypt;" ; do
  grep -Fq "$expected" "$SRC" || { echo "STOP: template marker absent: $expected" >&2; exit 3; }
done
# No extra password and no Moodle proxy until the actual Moodle is reviewed.
if grep -Eq 'auth_basic|auth_basic_user_file|proxy_pass' "$SRC"; then
  echo 'STOP: maintenance template must not have Basic Auth or proxy to Moodle' >&2; exit 3
fi
localbody=$(curl --noproxy '*' -sS --fail --max-time 8 \
  --resolve "$DOMAIN:80:127.0.0.1" "$PROBE") ||
  { echo 'STOP: existing local HTTP-01 probe unavailable' >&2; exit 3; }
[[ "$localbody" == "$MARKER" ]] || { echo 'STOP: HTTP-01 text mismatch' >&2; exit 3; }
echo STAGE1897_ACME_RENEWAL_ROUTE_OK
if [[ "$MODE" == --check ]]; then
  echo STAGE1897_NO_BASIC_AUTH_PREFLIGHT_OK
  echo 'Read-only: Nginx unchanged, no additional password needed.'
  exit 0
fi

backupdir=/srv/bms24-staging/backups
install -d -m 0700 "$backupdir"
backup="$backupdir/nginx-pre-stage1897-noauth-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
[[ ! -e "$backup" ]] || { echo 'STOP: backup collision' >&2; exit 4; }
tar -C /etc -czf "$backup" nginx
chmod 0600 "$backup"
tar -tzf "$backup" >/dev/null || { echo 'STOP: backup unreadable' >&2; exit 4; }
echo "STAGE1897_NGINX_BACKUP_OK $backup"

added_site=0
added_link=0
removed_acme_link=0
rollback() {
  echo 'ROLLBACK: restoring stage41 ACME-only virtual host' >&2
  if ((added_link)); then rm -f -- "$LINK"; fi
  if ((added_site)); then rm -f -- "$SITE"; fi
  if ((removed_acme_link)); then ln -s "$ACME_SITE" "$ACME_LINK" || true; fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
}
trap rollback ERR
install -m 0644 "$SRC" "$SITE"
added_site=1
rm -- "$ACME_LINK"
removed_acme_link=1
ln -s "$SITE" "$LINK"
added_link=1
nginx -t >/dev/null
systemctl reload nginx

code=000
body=""
probe_ok=0
for attempt in {1..12}; do
  code=$(curl --noproxy '*' --silent --output /dev/null \
    --write-out '%{http_code}' --max-time 4 \
    --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || true)
  body=$(curl --noproxy '*' --silent --max-time 4 \
    --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || true)
  p=$(curl --noproxy '*' --silent --max-time 4 \
    --resolve "$DOMAIN:80:127.0.0.1" "$PROBE" || true)
  [[ "$p" == "$MARKER" ]] && probe_ok=1 || probe_ok=0
  if [[ "$code" == 503 && "$body" == "$MAINT" && "$probe_ok" == 1 ]]; then break; fi
  sleep 1
done
if [[ "$code" != 503 || "$body" != "$MAINT" || "$probe_ok" != 1 ]]; then
  echo "STOP: HTTPS=$code, body_expected=$MAINT, ACME_ok=$probe_ok" >&2
  false
fi
trap - ERR
echo STAGE1897_HTTPS_CERT_AND_MAINTENANCE_OK
echo STAGE1897_HTTP01_RENEWAL_PATH_OK
echo STAGE1897_NO_EXTRA_PASSWORD_ACTIVE
echo 'Moodle is NOT publicly available. Next: stage1898 verified Moodle installation then login-only proxy.'
