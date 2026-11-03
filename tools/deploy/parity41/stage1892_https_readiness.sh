#!/usr/bin/env bash
# BMS24 Stage 1892 — READ ONLY readiness for a new HTTPS hostname.
# Do not install/enable nginx, edit DNS, start Docker, or read secrets.
set -Eeuo pipefail
export LC_ALL=C

DOMAIN=stage41.bms24.online
BASE=/srv/bms24-staging/parity41
DB=bms24_parity41-db-1
CONFIG="$BASE/moodle/config.php"

[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: run as root on Timeweb VDS' >&2; exit 2; }
[[ -f "$CONFIG" && ! -L "$CONFIG" && -f "$BASE/compose.yml" ]] ||
    { echo 'STOP: stage1891 parity41 config or Compose is missing' >&2; exit 2; }
[[ "$(stat -c '%a' "$CONFIG")" == 640 ]] ||
    { echo 'STOP: parity41 config.php does not have mode 0640' >&2; exit 2; }
[[ "$(stat -c '%u:%g' "$CONFIG")" == '0:33' ]] ||
    { echo 'STOP: parity41 config.php owner/group not 0:33' >&2; exit 2; }

# Check the proposed FQDN without exposing the password inside config.php.
if grep -Fq "$DOMAIN" "$CONFIG"; then
    echo "WWWROOT_HOST_MATCH $DOMAIN"
else
    echo 'STOP: config.php does not reference the expected stage41 hostname' >&2
    exit 2
fi
echo '=== DOMAIN A (IPv4) RECORDS ==='
dns_addrs=$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '$2=="STREAM" {print $1}' | sort -u || true)
if [[ -z "$dns_addrs" ]]; then
    echo 'DNS_IPV4_NOT_RESOLVED'
else
    while IFS= read -r addr; do echo "DNS_IPV4 $addr"; done <<< "$dns_addrs"
fi
echo '=== HOST IPV4 ADDRESSES (MAY BE BEHIND NAT) ==='
ip -4 -o addr show scope global | awk '{print $2 " " $4}' || true

echo '=== PROXY / SSL TOOLS ==='
if command -v nginx >/dev/null 2>&1; then
    nginx -v 2>&1
    if nginx -t >/dev/null 2>&1; then
        echo 'NGINX_SYNTAX_OK'
    else
        echo 'NGINX_SYNTAX_CHECK_FAILED'
    fi
else
    echo 'NGINX_NOT_INSTALLED'
fi
if command -v systemctl >/dev/null 2>&1; then
    echo "NGINX_SERVICE $(systemctl is-active nginx 2>/dev/null || true)"
fi
if command -v certbot >/dev/null 2>&1; then
    certbot --version 2>&1
else
    echo 'CERTBOT_NOT_INSTALLED'
fi

echo '=== EXISTING SITE CONFIG FILES (NAMES ONLY) ==='
for directory in /etc/nginx/sites-enabled /etc/nginx/conf.d; do
    if [[ -d "$directory" ]]; then
        find "$directory" -maxdepth 1 \( -type f -o -type l \) -printf '%p\n' | sort
    fi
done
echo '=== EXISTING DOMAIN REFERENCES (FILE PATHS ONLY) ==='
found=0
for directory in /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/nginx/sites-available; do
    if [[ -d "$directory" ]]; then
        while IFS= read -r file; do
            [[ -n "$file" ]] || continue
            echo "DOMAIN_REFERENCE_FILE $file"
            found=1
        done < <(grep -RIlF --include='*.conf' --include='*stage41*' \
             "$DOMAIN" "$directory" 2>/dev/null || true)
    fi
done
if [[ "$found" -eq 0 ]]; then
    echo 'DOMAIN_SITE_NOT_FOUND (EXPECTED BEFORE DEPLOY)'
fi
if [[ -s "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" &&
      -s "/etc/letsencrypt/live/$DOMAIN/privkey.pem" ]]; then
    echo 'TLS_CERT_FILES_PRESENT (expiry/hostname still require validation)'
else
    echo 'TLS_CERT_NOT_PRESENT'
fi

echo '=== RELEVANT LISTEN PORTS ==='
ss -lnt '( sport = :80 or sport = :443 or sport = :18080 or sport = :18081 )' || true
if ss -lnt '( sport = :18081 )' | grep -q LISTEN; then
    echo 'PORT_18081_OCCUPIED (REVIEW)'
else
    echo 'PORT_18081_FREE (EXPECTED UNTIL PARITY WEBSERVER START)'
fi

echo '=== PARITY DB HEALTH ==='
docker inspect "$DB" --format 'running={{.State.Running}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}'
echo '=== PARITY CONTAINER NAMES (NO ENVIRONMENT) ==='
docker ps --format '{{.Names}}' --filter name=bms24_parity41
echo 'STAGE1892_HTTPS_READINESS_AUDIT_COMPLETE'
echo 'No Nginx, DNS, Docker, database or Moodle configuration was modified.'
