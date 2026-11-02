#!/usr/bin/env bash
# BMS24 Stage 1881.15.1: read-only route syntax + XMLDB warnings inventory.
# Not an authenticated functionality test. Does not mutate Moodle or reach external APIs.
set -Eeuo pipefail
umask 077

BASE=/srv/bms24-staging/moodle-test
SITE="$BASE/moodle"
COMPOSE="$BASE/docker/bin/moodle-docker-compose"
if (( EUID != 0 )) || [[ ! -r "$BASE/staging.env" || ! -x "$COMPOSE" ]]; then
  echo "ERROR: Timeweb staging root shell and staging Docker expected" >&2
  exit 1
fi
source "$BASE/staging.env"
cd "$BASE/docker"

echo "=== BMS24 STAGE1881: ROUTE PHP SYNTAX ==="
failed=0
routes=(
  index.php bms_home.php bms_workspace.php
  tasks.php battle_plan.php recurring_tasks.php calendar.php
  communication_center.php indicators.php information_center.php
  bms_architecture.php processes.php employees.php
  people.php learning.php bms_settings.php
)
for route in "${routes[@]}"; do
  file="local/kbacompany/$route"
  if [[ -f "$SITE/$file" ]] && "$COMPOSE" exec -T webserver php -l "$file" >/dev/null 2>&1; then
    echo "PASS route-syntax $file"
  else
    echo "FAIL route-syntax $file"
    failed=$((failed + 1))
  fi
done

echo "=== XMLDB INVALID EMPTY DEFAULT INVENTORY ==="
python3 - "$SITE" <<'PY'
from __future__ import annotations
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

site = Path(sys.argv[1])
issues = []
parse_failures = []
scan_count = 0
# Include core and extra plugins, not only BMS24. Their XMLDB descriptions
# can contribute to Moodle check_database_schema.php warnings.
for xml in site.rglob("db/install.xml"):
    if not xml.is_file():
        continue
    scan_count += 1
    try:
        tree = ET.parse(xml)
    except ET.ParseError:
        parse_failures.append(xml.relative_to(site).as_posix())
        continue
    for table in tree.findall(".//TABLE"):
        for field in table.findall("./FIELDS/FIELD"):
            attrs = field.attrib
            dtype = attrs.get("TYPE", "").lower()
            notnull = attrs.get("NOTNULL", "").lower()
            if dtype in {"char", "text", "clob"} and notnull in {"true", "1"} and attrs.get("DEFAULT") == "":
                issues.append((xml.relative_to(site).as_posix(), table.get("NAME", "?"), attrs.get("NAME", "?"), dtype))
print("XMLDB files scanned:", scan_count)
print("Distinct suspicious definitions:", len(issues))
for path, table, field, dtype in issues[:35]:
    print(f"  {path}: table={table} field={field} type={dtype}")
if len(issues) > 35:
    print("  ...", len(issues) - 35, "more; not all shown")
print("XML parse failures:", len(parse_failures))
for path in parse_failures[:12]:
    print("  PARSE_ERROR", path)
print("NOTE: This is an XMLDB source inventory, not a count of runtime warning messages.")
print("NOTE: Upgrade migrations may also generate warnings and are not covered here.")
PY

if (( failed )); then
  echo "STAGE1881_ROUTE_PREFLIGHT_FAILED route_count=$failed" >&2
  exit 2
fi
echo "STAGE1881_ROUTE_PREFLIGHT_OK — authenticated UX tests still required"
