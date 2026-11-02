#!/usr/bin/env bash
# BMS24 1883: read-only Moodle file inventory, never loads config.php.
set -Eeuo pipefail
export LC_ALL=C
umask 077
ROOT='/var/www/p706494/data/www/edu.kzn.mba'
if [[ $# -eq 2 && "$1" == '--root' ]]; then ROOT=$2;
elif [[ $# -eq 1 && "$1" == '--help' ]]; then
  echo 'Usage: bash inventory_moodle.sh [--root /absolute/path/to/moodle]'; exit 0
elif [[ $# -ne 0 ]]; then echo 'Invalid arguments' >&2; exit 2; fi
[[ "$ROOT" == /* && -d "$ROOT" && -f "$ROOT/version.php" && -d "$ROOT/lib" ]] || {
  echo 'STOP: expected Moodle root not found; no data collected' >&2; exit 2;
}
ROOT=$(cd "$ROOT" && pwd -P)
# Extract only a fixed allowlist of static version.php assignments; never execute them.
field() {
  local path=$1 mode=$2 key=$3 line value
  [[ -r "$path" ]] || { printf 'UNKNOWN'; return; }
  if [[ "$mode" == core ]]; then
    line=$(grep -m1 -E '^[[:space:]]*\$'"$key"'[[:space:]]*=' "$path" || true)
  else
    line=$(grep -m1 -E '^[[:space:]]*\$plugin->'"$key"'[[:space:]]*=' "$path" || true)
  fi
  [[ -n "$line" ]] || { printf 'UNKNOWN'; return; }
  value=${line#*=}
  value=${value%%;*}
  value=$(printf '%s' "$value" | sed -E 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^["\x27]//;s/["\x27]$//')
  # Ensure version metadata cannot insert new records or alter delimiter format.
  value=$(printf '%s' "$value" | tr '\r\n\t|' '    ')
  [[ -n "$value" ]] || value='UNKNOWN'
  printf '%s' "$value"
}
status() { [[ -f "$1" ]] && printf 'PRESENT' || printf 'MISSING'; }
printf 'FORMAT|bms24-inventory-v1\n'
printf 'COLLECTED_AT_UTC|%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'ROOT|%s\n' "$ROOT"
printf 'MOODLE|%s|%s|%s\n' \
  "$(field "$ROOT/version.php" core version)" \
  "$(field "$ROOT/version.php" core release)" \
  "$(field "$ROOT/version.php" core branch)"
printf 'CONFIG|%s\n' "$(status "$ROOT/config.php")"
if command -v php >/dev/null 2>&1; then
  printf 'PHP_CLI|%s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo UNKNOWN)"
else
  printf 'PHP_CLI|NOT_IN_PATH\n'
fi
printf 'PHP_WEB|UNKNOWN_NEEDS_ADMIN_CONFIRMATION\n'
printf 'PLUGIN_FIELDS|path|state|component|version|requires|release\n'
plugins=(
  'local/kbacompany' 'theme/kba' 'mod/kbaelement'
  'local/kbaadmin' 'local/kbaai' 'local/kbacorporate'
  'local/bitrixsync' 'local/kbagamification' 'mod/customcert'
)
for plugin in "${plugins[@]}"; do
  file="$ROOT/$plugin/version.php"
  if [[ -f "$file" ]]; then
    printf 'PLUGIN|%s|PRESENT|%s|%s|%s|%s\n' "$plugin" \
      "$(field "$file" plugin component)" \
      "$(field "$file" plugin version)" \
      "$(field "$file" plugin requires)" \
      "$(field "$file" plugin release)"
  else
    printf 'PLUGIN|%s|MISSING|UNKNOWN|UNKNOWN|UNKNOWN|UNKNOWN\n' "$plugin"
  fi
done
printf 'END|bms24-inventory-v1\n'
