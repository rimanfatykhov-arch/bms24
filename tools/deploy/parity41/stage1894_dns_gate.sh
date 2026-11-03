#!/usr/bin/env bash
# BMS24 Stage 1894: DNS / Nginx read-only gate.
# Never modifies DNS, Nginx, certificates, firewall, Docker or Moodle.
set -Eeuo pipefail
export LC_ALL=C
DOMAIN=stage41.bms24.online
EXPECTED_IP=""
if (($#)); then
  if (($# != 2)) || [[ "$1" != "--expected-ip" ]] ||
     ! [[ "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
     echo "Usage: bash $0 [--expected-ip PUBLIC_VDS_IPV4]" >&2; exit 2
  fi
  EXPECTED_IP="$2"
fi
[[ "$(id -u)" -eq 0 ]] || { echo "STOP: run as root" >&2; exit 2; }
nginx -t >/dev/null 2>&1 || { echo "STOP: nginx -t failed" >&2; exit 2; }
echo "NGINX_SYNTAX_OK"
[[ "$(systemctl is-active nginx 2>/dev/null || true)" == active ]] ||
  { echo "STOP: nginx not active" >&2; exit 2; }
echo "NGINX_ACTIVE"
[[ -e /etc/nginx/sites-enabled/bms24-test ]] &&
  echo "EXISTING_TEST_SITE_PRESENT" || echo "EXISTING_TEST_SITE_PATH_REVIEW"
for port in 80 443 18080 18081; do
  if ss -lnt "( sport = :$port )" | grep -q LISTEN; then
    echo "HOST_PORT_$port LISTEN"
  else
    echo "HOST_PORT_$port FREE"
  fi
done
CFG=/srv/bms24-staging/parity41/moodle/config.php
[[ -f "$CFG" && ! -L "$CFG" ]] || { echo "STOP: parity41 config.php missing" >&2; exit 2; }
grep -Fq "$DOMAIN" "$CFG" || { echo "STOP: parity41 wwwroot hostname mismatch" >&2; exit 2; }
echo "PARITY41_WWWROOT_HOST_OK"
[[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]] &&
  echo "TLS_CERT_PRESENT_REVIEW" || echo "TLS_CERT_NOT_YET_ISSUED"
if ! command -v dig >/dev/null 2>&1; then
  echo "DIG_MISSING: public DNS not verified; no packages installed"
  echo "STAGE1894_DNS_AUDIT_INCOMPLETE"
  exit 0
fi
good=0
v6present=0
for resolver in 1.1.1.1 8.8.8.8; do
  echo "=== PUBLIC DNS $resolver ==="
  rec=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" A |
    grep -E '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | sort -u || true)
  ipv6=$(dig +time=3 +tries=1 +short @"$resolver" "$DOMAIN" AAAA | grep ':' || true)
  [[ -n "$rec" ]] && echo "DNS_A $rec" || echo "DNS_A_NOT_RETURNED"
  if [[ -n "$ipv6" ]]; then
    v6present=1
    echo "DNS_AAAA_PRESENT_REVIEW_IPV6_ROUTING"
  else
    echo "DNS_AAAA_NOT_RETURNED"
  fi
  if [[ -n "$EXPECTED_IP" ]]; then
    if [[ "$rec" == "$EXPECTED_IP" ]]; then
      echo "DNS_A_MATCHES_EXPECTED_IP"
      good=$((good + 1))
    else
      echo "DNS_A_NOT_READY_OR_MISMATCH (expected $EXPECTED_IP)"
    fi
  fi
done
if [[ -n "$EXPECTED_IP" && "$good" == 2 && "$v6present" == 0 ]]; then
  echo "STAGE1894_PUBLIC_DNS_GATE_OK"
else
  echo "STAGE1894_DNS_GATE_PENDING"
fi
echo "STAGE1894_READ_ONLY_DNS_AUDIT_COMPLETE"
echo "External HTTP-01 reachability still untested; nothing modified."
