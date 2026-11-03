#!/usr/bin/env bash
# BMS24 stage1897 — guarded promotion of stage41 ACME-only HTTP to HTTPS+Basic Auth.
# Requires stage1896 certificate. Never touches test.bms24.online,
# production, Compose, Moodle DB, cron or mail integrations.
set -Eeuo pipefail
umask 077
export LC_ALL=C

MODE="${1:---preflight}"
DOMAIN=stage41.bms24.online
IP=186.246.110.254
BASE=/srv/bms24-staging/parity41
ACME_SRC="$BASE/https-assets/acme-only.conf.example"
HTTPS_SRC="$BASE/https-assets/https.conf.example"
ACME_SITE=/etc/nginx/sites-available/bms24-stage41-acme
ACME_LINK=/etc/nginx/sites-enabled/bms24-stage41-acme
HTTPS_SITE=/etc/nginx/sites-available/bms24-stage41
HTTPS_LINK=/etc/nginx/sites-enabled/bms24-stage41
AUTH_DIR=/etc/nginx/private
AUTH="$AUTH_DIR/bms24-stage41.htpasswd"
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
PROBE_URL="http://$DOMAIN/.well-known/acme-challenge/bms24-stage1895-probe"
MARKER=BMS24_STAGE1895_HTTP01_OK

case "$MODE" in --preflight|--make-auth|--activate|--status) ;;
  *) echo "Usage: $0 --preflight|--make-auth|--activate|--status" >&2; exit 2 ;;
esac
[[ "$(id -u)" == 0 ]] || { echo 'STOP: root required' >&2; exit 2; }
for command in nginx openssl curl stat systemctl readlink; do
  command -v "$command" >/dev/null 2>&1 ||
    { echo "STOP: command unavailable: $command" >&2; exit 2; }
done
nginx -t >/dev/null 2>&1 || { echo 'STOP: current Nginx syntax invalid' >&2; exit 2; }
[[ "$(systemctl is-active nginx 2>/dev/null || true)" == active ]] ||
  { echo 'STOP: Nginx is not active' >&2; exit 2; }
[[ -e /etc/nginx/sites-enabled/bms24-test ]] ||
  { echo 'STOP: existing bms24-test site not found' >&2; exit 2; }
[[ -f "$BASE/moodle/config.php" && ! -L "$BASE/moodle/config.php" ]] ||
  { echo 'STOP: separate parity41 Moodle config absent' >&2; exit 2; }
grep -Fq "$DOMAIN" "$BASE/moodle/config.php" ||
  { echo 'STOP: parity41 wwwroot mismatch' >&2; exit 2; }

if [[ "$MODE" == --status ]]; then
  [[ -s "$CERT" && -s "$KEY" ]] && echo STAGE1897_CERT_FILES_PRESENT ||
    echo STAGE1897_CERT_FILES_MISSING
  [[ -s "$AUTH" ]] && echo STAGE1897_AUTH_PRESENT ||
    echo STAGE1897_AUTH_MISSING
  [[ -L "$ACME_LINK" ]] && echo STAGE1897_ACME_LINK_PRESENT ||
    echo STAGE1897_ACME_LINK_MISSING
  [[ -L "$HTTPS_LINK" ]] && echo STAGE1897_HTTPS_LINK_PRESENT ||
    echo STAGE1897_HTTPS_LINK_MISSING
  echo 'STAGE1897_STATUS_READ_ONLY'
  exit 0
fi

[[ -s "$CERT" && -s "$KEY" ]] ||
  { echo 'STAGE1897_CERT_GATE_PENDING: first finish stage1896 and confirm certificate' >&2; exit 4; }
openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: certificate expires within 30 days or is unreadable' >&2; exit 4; }
openssl x509 -checkhost "$DOMAIN" -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: certificate does not match stage41 hostname' >&2; exit 4; }
certpub=$(openssl x509 -in "$CERT" -pubkey -noout |
  openssl pkey -pubin -outform DER | sha256sum | cut -d' ' -f1)
keypub=$(openssl pkey -in "$KEY" -pubout -outform DER |
  sha256sum | cut -d' ' -f1)
[[ "$certpub" == "$keypub" ]] ||
  { echo 'STOP: certificate and private key do not correspond' >&2; exit 4; }
echo STAGE1897_CERT_VERIFIED

[[ -f "$ACME_SITE" && ! -L "$ACME_SITE" &&
   -L "$ACME_LINK" && "$(readlink "$ACME_LINK")" == "$ACME_SITE" ]] ||
  { echo 'STOP: stage41 ACME-only site is not in the expected state' >&2; exit 4; }
[[ -f "$HTTPS_SRC" && ! -L "$HTTPS_SRC" ]] ||
  { echo 'STOP: stage1893 HTTPS template missing' >&2; exit 4; }
cmp -s "$ACME_SRC" "$ACME_SITE" ||
  { echo 'STOP: live ACME config differs from the reviewed template' >&2; exit 4; }
for marker in "server_name $DOMAIN;" \
  "ssl_certificate $CERT;" "ssl_certificate_key $KEY;" \
  "auth_basic_user_file $AUTH;" \
  "proxy_pass http://127.0.0.1:18081;" \
  "proxy_set_header X-Forwarded-Proto https;" \
  "root /var/www/letsencrypt;"; do
  grep -Fq "$marker" "$HTTPS_SRC" ||
    { echo "STOP: HTTPS template safety marker absent: $marker" >&2; exit 4; }
done
[[ ! -e "$HTTPS_SITE" && ! -L "$HTTPS_SITE" &&
   ! -e "$HTTPS_LINK" && ! -L "$HTTPS_LINK" ]] ||
  { echo 'STOP: a stage41 HTTPS site already exists; refusing overwrite' >&2; exit 4; }

# Existing ACME must remain functional to renew certificates.
probe=$(curl --noproxy '*' -sS --fail --max-time 8 \
  --resolve "$DOMAIN:80:127.0.0.1" "$PROBE_URL") ||
  { echo 'STOP: current ACME probe cannot be fetched locally' >&2; exit 4; }
[[ "$probe" == "$MARKER" ]] ||
  { echo 'STOP: current ACME probe content mismatch' >&2; exit 4; }
echo STAGE1897_ACME_BEFORE_SWITCH_OK

if [[ "$MODE" == --make-auth ]]; then
  command -v htpasswd >/dev/null 2>&1 ||
    { echo 'STOP: htpasswd unavailable; install apache2-utils in a separate reviewed step' >&2; exit 4; }
  id www-data >/dev/null 2>&1 ||
    { echo 'STOP: www-data system account absent; inspect nginx user' >&2; exit 4; }
  [[ ! -e "$AUTH" && ! -L "$AUTH" ]] ||
    { echo 'STOP: authentication file already exists; refusing overwrite' >&2; exit 4; }
  if [[ -e "$AUTH_DIR" ]]; then
    [[ -d "$AUTH_DIR" && ! -L "$AUTH_DIR" ]] ||
      { echo 'STOP: private auth directory is not an ordinary directory' >&2; exit 4; }
  else
    install -d -m 0750 -o root -g www-data "$AUTH_DIR"
  fi
  # Restrict traversal for Nginx worker but not other users.
  [[ "$(stat -c '%U:%G:%a' "$AUTH_DIR")" == 'root:www-data:750' ]] ||
    { echo 'STOP: auth directory permissions differ; review manually' >&2; exit 4; }
  tmp=$(mktemp "$AUTH_DIR/.stage41-passwd.XXXXXXXX")
  trap 'rm -f -- "$tmp"' EXIT
  echo 'Enter a NEW independent Basic Auth password when prompted; it will not be echoed.'
  htpasswd -cBC 12 "$tmp" stage41reviewer
  [[ -s "$tmp" ]] || { echo 'STOP: htpasswd did not create a hash' >&2; exit 4; }
  chown root:www-data "$tmp"
  chmod 0640 "$tmp"
  ln "$tmp" "$AUTH" || { echo 'STOP: could not create exclusive auth file' >&2; exit 4; }
  rm -f -- "$tmp"
  trap - EXIT
  echo 'STAGE1897_BASIC_AUTH_CREATED (no password displayed)'
  echo 'Next: run --preflight then --activate.'
  exit 0
fi

if [[ -s "$AUTH" && ! -L "$AUTH" &&
      "$(stat -c '%U:%G:%a' "$AUTH")" == 'root:www-data:640' ]]; then
  echo STAGE1897_BASIC_AUTH_READY
else
  if [[ "$MODE" == --preflight ]]; then
    echo 'STAGE1897_BASIC_AUTH_PENDING: run --make-auth before --activate'
    echo STAGE1897_PREFLIGHT_CERT_AND_ACME_OK
    exit 0
  fi
  echo 'STOP: Basic Auth file missing or has incorrect permissions' >&2
  exit 4
fi
[[ "$MODE" == --activate ]] ||
  { echo STAGE1897_PREFLIGHT_OK; exit 0; }

# Only at activation do we modify one new Nginx site and its symlink.
# No existing site content is ever overwritten.
BACKUP_DIR=/srv/bms24-staging/backups
install -d -m 0700 "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/nginx-pre-stage1897-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
[[ ! -e "$BACKUP" ]] || { echo 'STOP: backup name collision' >&2; exit 5; }
tar -C /etc -czf "$BACKUP" nginx
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null ||
  { echo 'STOP: Nginx backup archive is unreadable' >&2; exit 5; }
echo "STAGE1897_NGINX_BACKUP_OK $BACKUP"

added_site=0
added_https_link=0
removed_acme_link=0
rollback() {
  echo 'ROLLBACK: restoring only stage41 site links and files' >&2
  if ((added_https_link)); then rm -f -- "$HTTPS_LINK"; fi
  if ((added_site)); then rm -f -- "$HTTPS_SITE"; fi
  if ((removed_acme_link)); then
    ln -s "$ACME_SITE" "$ACME_LINK" || true
  fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
}
trap 'rollback' ERR
install -m 0644 "$HTTPS_SRC" "$HTTPS_SITE"
added_site=1
rm "$ACME_LINK"
removed_acme_link=1
ln -s "$HTTPS_SITE" "$HTTPS_LINK"
added_https_link=1
nginx -t >/dev/null
systemctl reload nginx

# Wait for new Nginx workers. TLS verification remains ENABLED.
sslcode=000
probe_ok=0
for attempt in {1..12}; do
  sslcode=$(curl --noproxy '*' --silent --output /dev/null \
    --write-out '%{http_code}' --max-time 4 \
    --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || true)
  probe_body=$(curl --noproxy '*' --silent --max-time 4 \
    --resolve "$DOMAIN:80:127.0.0.1" "$PROBE_URL" || true)
  [[ "$probe_body" == "$MARKER" ]] && probe_ok=1 || probe_ok=0
  if [[ "$sslcode" == 401 && "$probe_ok" == 1 ]]; then
    break
  fi
  sleep 1
done
[[ "$sslcode" == 401 && "$probe_ok" == 1 ]] ||
  { echo "STOP: HTTPS= $sslcode, HTTP-01 retained= $probe_ok (expected 401 and 1)" >&2; false; }
trap - ERR
echo STAGE1897_HTTPS_401_UNAUTHENTICATED_OK
echo STAGE1897_HTTP01_RENEWAL_PATH_OK
echo STAGE1897_HTTPS_BASIC_AUTH_ACTIVATED
echo 'Moodle webserver NOT started. Authenticated users may see 502 until backend is ready.'
echo 'Next: verify remote HTTPS certificate + 401 and certbot renew --dry-run.'
