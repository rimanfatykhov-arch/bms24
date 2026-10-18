#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
lint_log="$(mktemp)"
trap 'rm -f "$lint_log"' EXIT
find . -type f -name '*.php' -print0 | xargs -0 -P 8 -n 1 php -l > "$lint_log"
if grep -qv 'No syntax errors detected' "$lint_log"; then cat "$lint_log"; exit 1; fi
node --check js/task_ui.js
python - <<'PY'
from pathlib import Path
from xml.etree import ElementTree as ET
import re
p=Path('.')
assert ET.parse('db/install.xml').getroot().get('VERSION')=='2026101634'
assert '$plugin->version = 2026101635;' in (p/'version.php').read_text()
u=(p/'db/upgrade.php').read_text()
assert u.count('if ($oldversion < 2026101635)') == 1
for lang in ('ru','en'):
 keys=re.findall(r"\$string\['([^']+)'\]",(p/f'lang/{lang}/local_kbacompany.php').read_text())
 assert len(keys)==len(set(keys))
 if lang=='ru': base=set(keys)
 else: assert set(keys)==base
print('RC29 1635 basic QA passed')
PY
