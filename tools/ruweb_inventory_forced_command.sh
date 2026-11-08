#!/bin/sh
set -eu
case "${SSH_ORIGINAL_COMMAND:-}" in
  inventory)
    exec /usr/bin/php /var/www/p706494/data/.bms24-inventory/inventory.php /var/www/p706494/data/www/edu.kzn.mba </dev/null
    ;;
  *) printf 'DENIED\n' >&2; exit 2 ;;
esac
