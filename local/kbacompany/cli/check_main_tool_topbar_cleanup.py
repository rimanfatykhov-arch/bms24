#!/usr/bin/env python3
"""1805 static acceptance gate for removal of redundant upper-right main-tool switch.
Not a substitute for a real browser check on Moodle/KBA Theme.
"""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
workspace = (root / 'bms_workspace.php').read_text(encoding='utf-8')
shell = (root / 'classes/local/bms_page_shell_service.php').read_text(encoding='utf-8')
fallback = (root / 'templates/bms_workspace_fallback.mustache').read_text(encoding='utf-8')
styles = (root / 'styles.css').read_text(encoding='utf-8')
builder = (root / 'cli/build_release.py').read_text(encoding='utf-8')
upgrade = (root / 'db/upgrade.php').read_text(encoding='utf-8')
version = (root / 'version.php').read_text(encoding='utf-8')

checks = {
    'workspace topbar only shows Moodle user menu':
        "'usermenuhtml' => $OUTPUT->user_menu()," in workspace,
    'shared shell topbar only shows Moodle user menu':
        "'usermenuhtml' => $OUTPUT->user_menu()," in shell,
    'no topbar switch injected on workspace':
        "'usermenuhtml' => html_writer::link(" not in workspace,
    'no topbar switch injected on shared shell':
        "'usermenuhtml' => \\html_writer::link(" not in shell,
    'workspace sidebar retains switch':
        bool(re.search(r"'utilityitems'\s*=>.*?'label'\s*=>\s*get_string\('bmsmain_change'", workspace, re.S)),
    'shared shell sidebar retains switch':
        bool(re.search(r"'utilityitems'\s*=>.*?'label'\s*=>\s*get_string\('bmsmain_change'", shell, re.S)),
    'fallback header no longer duplicates switch':
        'bmsmain_change' not in fallback.split('</header>')[0],
    'fallback sidebar offers switch':
        'bmsmain_change' in fallback.split('<aside', 1)[1].split('</aside>', 1)[0],
    'unused switch link class removed from plugin':
        all('bms-main-switch-link' not in text for text in (workspace, shell, styles)),
    'canonical preference endpoint remains':
        "'/local/kbacompany/bms_home_preferences.php'" in workspace
        and "'/local/kbacompany/bms_home_preferences.php'" in shell,
    'builder runs new regression gate':
        "check_main_tool_topbar_cleanup.py" in builder,
    'version updated with no schema change':
        int(re.search(r'\$plugin->version\s*=\s*(\d+);', version).group(1)) >= 2026101805
        and "if ($oldversion < 2026101805)" in upgrade,
}
for label, result in checks.items():
    print(('OK  ' if result else 'FAIL ') + label)
if not all(checks.values()):
    raise SystemExit(2)
print(f'BMS1805 topbar cleanup contract: {len(checks)} checks OK')
