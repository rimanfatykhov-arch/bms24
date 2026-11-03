#!/usr/bin/env bash
# BMS24 Stage 1896 — issue an independent stage41 Let's Encrypt certificate.
# --check is read-only; --issue invokes certbot in webroot certonly mode.
# DOES NOT modify Nginx, production, Docker, database, or Moodle.
set -Eeuo pipefail
umask 077
export LC_ALL=C

MODE="$1"
DOMAIN=stage41.bms24.online
IP=186.246.110.254
WEBROOT=/var/www/letsencrypt
PROBE="$WEBROOT/.well-known/acme-challenge/bms24-stage1895-probe"
SITE=/etc/nginx/sites-enabled/bms24-stage41-acme
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
URL="http://$DOMAIN/.well-known/acme-challenge/bms24-stage1895-probe"
MARKER=BMS24_STAGE1895_HTTP01_OK

if [[ "$MODE" != --check && "$MODE" != --issue ]]; then
  echo "Usage: bash $0 --check|--issue" >&2
  exit 2
fi
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: root required' >&2; exit 2; }
for c in nginx systemctl dig curl certbot openssl; do
  command -v "$c" >/dev/null 2>&1 ||
    { echo "STOP: missing tool $c" >&2; exit 2; }
done
[[ "$(systemctl is-active nginx 2>/dev/null || true)" == active ]] ||
  { echo 'STOP: nginx not active' >&2; exit 2; }
nginx -t >/dev/null 2>&1 ||
  { echo 'STOP: nginx -t failed' >&2; exit 2; }
[[ -L "$SITE" && -f "$SITE" ]] ||
  { echo 'STOP: stage41 ACME-only site not enabled' >&2; exit 2; }
[[ -f /etc/nginx/sites-enabled/bms24-test || -L /etc/nginx/sites-enabled/bms24-test ]] ||
  { echo 'STOP: existing test vhost missing' >&2; exit 2; }
[[ -f "$PROBE" && ! -L "$PROBE" && "$(cat "$PROBE")" == "$MARKER" ]] ||
  { echo 'STOP: ACME probe missing or does not match' >&2; exit 2; }
snap=$(nginx -T 2>&1)
grep -Fq '# configuration file /etc/nginx/sites-enabled/bms24-stage41-acme:' <<< "$snap" ||
  { echo 'STOP: stage41 ACME-only site not in active nginx config' >&2; exit 3; }

for resolver in 1.1.1.1 8.8.8.8; do
  a=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" A |
    grep -E '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | sort -u || true)
  aaaa=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" AAAA | grep ':' || true)
  [[ "$a" == "$IP" && -z "$aaaa" ]] ||
    { echo "STOP: public DNS A/AAAA mismatch at $resolver" >&2; exit 3; }
done
localbody=$(curl --noproxy '*' --silent --show-error --fail --max-time 8 \
  --resolve "$DOMAIN:80:127.0.0.1" "$URL") ||
  { echo 'STOP: local HTTP-01 unreachable' >&2; exit 3; }
[[ "$localbody" == "$MARKER" ]] ||
  { echo 'STOP: local HTTP-01 body differs' >&2; exit 3; }

if [[ -e "$CERT" || -e "$KEY" ]]; then
  echo 'STOP: stage41 certificate already exists; do not reissue without review' >&2
  exit 3
fi
echo 'STAGE1896_CERTBOT_WEBROOT_PREFLIGHT_OK'
echo 'Local ACME matches; external browser HTTP-01 success was confirmed by user.'
if [[ "$MODE" == --check ]]; then
  echo 'Read-only check complete. No certificate requested.'
  exit 0
fi

echo 'Requesting certificate for stage41 only, webroot authenticator, NO nginx installer.'
echo 'If Certbot asks for contact email and Terms, enter your own details interactively.'
certbot certonly --webroot --webroot-path "$WEBROOT" \
  --domain "$DOMAIN" --cert-name "$DOMAIN" --no-eff-email

[[ -s "$CERT" && -s "$KEY" ]] ||
  { echo 'STOP: expected stage41 certificate/key not found' >&2; exit 4; }
openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null ||
  { echo 'STOP: certificate expires too soon' >&2; exit 4; }
openssl x509 -noout -ext subjectAltName -in "$CERT" |
  grep -Fq "DNS:$DOMAIN" ||
  { echo 'STOP: stage41 SAN not found in certificate' >&2; exit 4; }
echo 'STAGE1896_CERT_ISSUED_AND_VALIDATED'
echo 'Nginx is still ACME-only; HTTPS virtual host and Basic Auth are NOT activated.'
echo 'Do not start Moodle until HTTPS+auth gate is completed.'
