#!/usr/bin/env bash
# BMS24 Stage 1881.14.6 — KBA Connect pre-install audit; read-only.
# Staging only. Never modify Moodle / DB / Docker networks / firewall / WireGuard.
set -Eeuo pipefail

BASE=/srv/bms24-staging/moodle-test
SOURCE=/srv/bms24-staging/import/production-snapshot/source/local/bitrixsync
SITE="$BASE/moodle"
WRAPPER="$BASE/docker/bin/moodle-docker-compose"

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: run as root on the Timeweb staging VDS" >&2
  exit 1
fi
if [[ ! -r "$BASE/staging.env" || ! -x "$WRAPPER" ]]; then
  echo "ERROR: expected staging Docker installation missing" >&2
  exit 1
fi
if [[ ! -r "$SOURCE/version.php" || ! -d "$SITE/local/kbacompany" ]]; then
  echo "ERROR: snapshot or BMS24 Core missing" >&2
  exit 1
fi
if [[ -e "$SITE/local/bitrixsync" ]]; then
  echo "ERROR: plugin already present in staging; inspect manually" >&2
  exit 1
fi

# Do not print the staging environment or pass passwords on the command line.
source "$BASE/staging.env"
cd "$BASE/docker"

echo '=== STAGING ONLY: cron configuration ==='
CRON_STATUS=$("$WRAPPER" exec -T webserver php admin/cli/cfg.php --name=cron_enabled | tr -d '[:space:]')
if [[ "$CRON_STATUS" != '0' ]]; then
  echo 'BLOCKED: staging cron is not confirmed disabled (expected 0)' >&2
  exit 1
fi
echo 'CRON_DISABLED_OK'
echo '=== STAGING: active containers ==='
"$WRAPPER" ps --format json > /dev/null
echo 'COMPOSE_ACCESS_OK'

echo '=== PLUGIN STATIC AUDIT: no PHP execution ==='
python3 - "$SOURCE" <<'PY'
import pathlib
import re
import sys
import xml.etree.ElementTree as ET

root = pathlib.Path(sys.argv[1])
version = (root / 'version.php').read_text(encoding='utf-8', errors='replace')
def field(key):
    match = re.search(r'\$plugin->' + key + r"\s*=\s*['\"]?([A-Za-z0-9_.-]+)", version)
    return match.group(1) if match else 'UNKNOWN'

component = field('component')
build = field('version')
requires = field('requires')
print('Component:', component)
print('Version:', build)
print('Requires:', requires)
if component != 'local_bitrixsync' or build != '2026083634':
    sys.exit('BLOCKED: unexpected component/build. Confirm snapshot before proceeding.')

xml = root / 'db/install.xml'
if xml.exists():
    try:
        ET.parse(xml)
    except ET.ParseError as e:
        sys.exit('BLOCKED: invalid XMLDB install.xml: ' + str(e))
    print('XMLDB: parsed (not executed)')
else:
    print('XMLDB: not present')

entrypoints = [
    'db/install.php', 'db/upgrade.php', 'db/tasks.php', 'db/events.php',
    'settings.php', 'lib.php', 'webhooks.php', 'webhook_v5.php',
    'classes/services/production_activation_service.php',
    'classes/services/sync_manager.php',
]
print('Lifecycle, event and external-integration entrypoints:')
for name in entrypoints:
    print(' ', name, 'PRESENT' if (root / name).is_file() else 'ABSENT')

# Report only filenames and category labels. Never print secret values,
# endpoints, source lines, configuration contents or webhook URLs.
categories = {
    'network/http': r'https?://|curl_exec\s*\(|curl_init\s*\(|->(?:get|post|request)\s*\(',
    'credentials': r'access_token|refresh_token|client_secret|api_key|webhook_url|auth_token',
    'outbound/email': r'email_to_user\s*\(|->send\s*\(|message_send\s*\(',
    'task/event registration': r'adhoc_task|scheduled_task|queue_adhoc_task|event\\|observers',
    'production toggle': r'production_activation|production_launch|production_enabled',
}
print('Potential integration files (not proof of an active connection):')
for label, pattern in categories.items():
    matches = []
    for file in root.rglob('*.php'):
        if 'vendor' in file.parts:
            continue
        content = file.read_text(encoding='utf-8', errors='replace')
        if re.search(pattern, content, flags=re.IGNORECASE):
            matches.append(file.relative_to(root).as_posix())
    print(' ', label + ':', len(matches), 'file(s)')
    for name in sorted(matches)[:12]:
        print('    ', name)

# Only metadata/checks. Installation is intentionally a separate approved step.
print('STATIC_AUDIT_COMPLETE')
print('MANUAL_REVIEW_REQUIRED: bridge network retains internet access.')
print('DO_NOT_INSTALL until lifecycle hooks and outbound integration behavior are reviewed.')
PY
echo 'STAGE1881_KBA_CONNECT_READONLY_AUDIT_COMPLETE'
