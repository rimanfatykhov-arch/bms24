#!/usr/bin/env bash
# BMS24 staging host inventory. READ-ONLY. No passwords, IPs, hostnames or tokens.
# Usage: bash staging-preflight.sh (on your NEW isolated Timeweb VDS).
set -euo pipefail

redact_version() {
  local v="$1"
  printf '%s' "$v" | cut -c1-64 | tr -cd '[:alnum:] ._-'
}

echo 'BMS24 staging VDS: READ-ONLY readiness inventory'
echo 'NO server setup, database access, Moodle installation or deployment performed.'
if [[ -r /etc/os-release ]]; then
  # Source only the standard distribution metadata file.
  . /etc/os-release
  echo "OS: ${PRETTY_NAME:-unknown}"
else
  echo 'OS: unknown'
fi
cpus="$(nproc 2>/dev/null || echo 0)"
memkb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
memmb="$(( ${memkb:-0} / 1024 ))"
diskgb="$(df -Pk / 2>/dev/null | awk 'NR==2 {print int($4/1048576)}' || echo 0)"
echo "vCPU: ${cpus}"
echo "RAM MB: ${memmb}"
echo "Free root filesystem GB: ${diskgb:-unknown}"
for cmd in php nginx mysql mariadb git python3; do
  if command -v "$cmd" >/dev/null 2>&1; then
    echo "$cmd: installed"
  else
    echo "$cmd: not installed"
  fi
done
if command -v php >/dev/null 2>&1; then
  echo "PHP CLI version: $(redact_version "$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)")"
fi
if command -v ufw >/dev/null 2>&1; then
  echo 'UFW: available (review firewall rules separately)'
else
  echo 'UFW: not installed'
fi
if [[ "${cpus:-0}" =~ ^[0-9]+$ && "${memmb:-0}" =~ ^[0-9]+$ && "$cpus" -ge 4 && "$memmb" -ge 7000 ]]; then
  echo 'Resources: baseline staging sizing met'
else
  echo 'Resources: below baseline or cannot verify; review before installing Moodle'
fi
echo 'NOTICE: versions of Moodle and PHP must be verified for compatibility.'
echo 'NOTICE: do not copy real customer data to EU staging.'
