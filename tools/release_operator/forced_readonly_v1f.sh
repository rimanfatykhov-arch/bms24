#!/bin/sh
# BMS24 V1f restricted SSH forced command: read-only inventory/capacity only.
# Install into a root-controlled path; never run from a PR working tree.
set -eu
PATH=/usr/bin:/bin
export PATH
unset ENV BASH_ENV CDPATH GIT_CONFIG_GLOBAL GIT_CONFIG_SYSTEM PYTHONPATH PYTHONHOME
case "${SSH_ORIGINAL_COMMAND:-}" in
  inventory)
    exec /usr/bin/php \
      /var/www/p706494/data/.bms24-inventory/inventory.php \
      /var/www/p706494/data/www/edu.kzn.mba </dev/null
    ;;
  capacity)
    exec /usr/bin/python3 -E -s \
      /var/www/p706494/data/.bms24-inventory/capacity_bridge.py </dev/null
    ;;
  *)
    printf '%s\n' 'DENIED: read-only inventory or capacity only' >&2
    exit 2
    ;;
esac
