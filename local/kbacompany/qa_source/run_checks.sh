#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

lint_log="$(mktemp)"
trap 'rm -f "$lint_log"' EXIT
find . -type f -name '*.php' -print0 | xargs -0 -P 8 -n 1 php -l > "$lint_log"
if grep -qv 'No syntax errors detected' "$lint_log"; then
  cat "$lint_log"
  exit 1
fi

if command -v node >/dev/null 2>&1 && [ -f js/task_ui.js ]; then
  node --check js/task_ui.js
fi

python - <<'PY'
from pathlib import Path
from xml.etree import ElementTree as ET
import re
p=Path('.')
ET.parse('db/install.xml')
version=(p/'version.php').read_text()
m=re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', version)
assert m, 'Cannot parse plugin version'
build=int(m.group(1))
u=(p/'db/upgrade.php').read_text()
assert u.count(f'if ($oldversion < {build})') == 1, 'Current upgrade checkpoint missing or duplicated'
for lang in ('ru','en'):
    keys=re.findall(r"\$string\['([^']+)'\]",(p/f'lang/{lang}/local_kbacompany.php').read_text())
    assert len(keys)==len(set(keys)), f'duplicate {lang} language key'
    if lang=='ru': base=set(keys)
    else: assert set(keys)==base, 'RU/EN key parity failed'
print(f'BMS24 generic QA passed for build {build}')
PY

php cli/release_docs_checkpoint.php --check
