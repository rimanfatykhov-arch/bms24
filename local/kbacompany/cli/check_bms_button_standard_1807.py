#!/usr/bin/env python3
"""RC29/1807 button styling/source contract. Requires browser acceptance on target Moodle."""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
read = lambda name: (root / name).read_text(encoding='utf-8')
widget = read('bms_workspace_widgets.php')
preferences = read('bms_home_preferences.php')
workspace = read('bms_workspace.php')
shell = read('classes/local/bms_page_shell_service.php')
css = read('styles.css')
kit = read('bms_ui/ui_kit.css')
tokens = read('bms_ui/tokens.css')
upgrade = read('db/upgrade.php')
version = read('version.php')
runner = read('cli/build_release.py')
owned = read('classes/local/bms24_shell_service.php')
docs = read('docs/BMS24_BUTTON_ACTION_STANDARD_RC29.md')

# Restrict inspection to rendered content: topbar uses a separate, backwards-compatible contract.
content = workspace.split("$attentionhtml = '';", 1)[0]
topbar = workspace.split("$attentionhtml = '';", 1)[1]
checks = {
    'widget form primary save uses canonical class': bool(re.search(r"get_string\('bmswidgets_save'.*?'class'\s*=>\s*'bms24-ui-btn bms24-ui-btn--primary'", widget, re.S)),
    'widget cancel uses neutral link': "get_string('bmsmain_cancel', 'local_kbacompany')" in widget and "['class' => 'bms24-ui-btn']" in widget,
    'widget reset remains secondary': "'value' => 'reset', 'class' => 'bms24-ui-btn'" in widget,
    'widget save/cancel grouped': "start_div('bms24-ui-actions')" in widget and "end_div();" in widget,
    'widget reset has own fallback form class': "'class' => 'bms-widget-reset-form mt-3'" in widget,
    'main tool save uses canonical primary': bool(re.search(r"get_string\('bmsmain_save_button'.*?'class'\s*=>\s*'bms24-ui-btn bms24-ui-btn--primary'", preferences, re.S)),
    'main tool cancel neutral': "get_string('bmsmain_cancel', 'local_kbacompany'), ['class' => 'bms24-ui-btn']" in preferences,
    'main tool save/cancel grouped': "start_div('bms24-ui-actions')" in preferences,
    'content workspace actions use canonical classes': content.count('bms24-ui-btn') >= 16 and 'kba-bms-button' not in content,
    'topbar compatibility intentionally retained': "'class' => 'kba-bms-button kba-bms-button--plan'" in topbar and "'kba-bms-button'" in shell,
    'form writes require POST and sesskey': all("$method === 'POST'" in source and 'require_sesskey();' in source and "'method' => 'post'" in source for source in [widget, preferences]),
    'form membership checked before display': all('company_context_service::context(' in source or 'bms_ui_service::shell_model(' in source for source in [widget, preferences]),
    'widget reset uses explicit action with no GET mutation': "'value' => 'reset'" in widget and "if ($action === 'reset')" in widget,
    'preferences post destination fixed internal path': 'Never accept returnurl from POST or GET' in preferences,
    'owned shell loads canonical UI kit stylesheet': "'/local/kbacompany/bms_ui/ui_kit.css'" in owned,
    'UI Kit uses primary and secondary variants': '.bms24-app .bms24-ui-btn--primary' in kit and '.bms24-app .bms24-ui-btn:hover' in kit,
    'UI Kit ensures white primary focus label': '.bms24-app .bms24-ui-btn--primary:focus-visible {color:#fff;}' in kit,
    'UI Kit has disabled primary contrast': '.bms24-app .bms24-ui-btn--primary:disabled {color:#fff;}' in kit,
    'design token green primary preserved': '--bms24-color-primary: #0C6C52;' in tokens,
    'fallback supports neutral form buttons': '.bms-widget-reset-form' in css and 'background:#fff;color:#0b2b41' in css,
    'fallback supports primary form buttons': '.bms24-ui-btn--primary' in css and 'background:#0c6c52;border-color:#0c6c52;color:#fff' in css,
    'fallback focus and hover defined': '.bms24-ui-btn:focus-visible' in css and '.bms24-ui-btn--primary:hover' in css,
    'compact form actions support wrapping': '.bms24-ui-actions {display:flex;align-items:center;gap:9px;flex-wrap:wrap' in css,
    'canonical UI documentation exists': all(x in docs for x in ['bms24-ui-btn--primary', 'bms24-ui-btn--danger', 'bms24-ui-btn--quiet', 'require_sesskey']),
    'release runner includes contract': "check_bms_button_standard_1807.py" in runner,
    'version and upgrade checkpoint match': int(re.search(r'\$plugin->version\s*=\s*(\d+);', version).group(1)) >= 2026101807 and 'if ($oldversion < 2026101807)' in upgrade,
}
for name, ok in checks.items():
    print(('PASS ' if ok else 'FAIL ') + name)
print(f'RC29/1807 BUTTON STANDARD: {sum(checks.values())}/{len(checks)} checks')
if not all(checks.values()):
    raise SystemExit(2)
