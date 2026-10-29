#!/usr/bin/env python3
"""Static wiring gate, complementary to runtime/PHP tests (not browser E2E)."""
from pathlib import Path
p = Path(__file__).resolve().parents[1]
files = {name: (p / name).read_text(encoding='utf-8') for name in (
    'bms_workspace.php', 'bms_home.php', 'bms_home_preferences.php',
    'classes/local/bms_page_shell_service.php', 'classes/local/bms_main_tool_service.php',
    'index.php', 'db/upgrade.php', 'version.php',
)}
checks = {
    'read-only entry uses model ACL': 'bms_ui_service::shell_model(' in files['bms_home.php'],
    'home resolves catalog': 'bms_main_tool_service::main_url(' in files['bms_home.php'],
    'canonical workspace retains explicit URL': "'/local/kbacompany/bms_workspace.php'" in files['bms_home_preferences.php'],
    'no configurable return URL': "optional_param('returnurl'" not in files['bms_home_preferences.php'],
    'POST mutations protected by sesskey': 'require_sesskey()' in files['bms_home_preferences.php'],
    'settings validates company': 'bms_ui_service::shell_model(' in files['bms_home_preferences.php'],
    'company prefs use user id': "set_user_preference(self::preference_key" in files['classes/local/bms_main_tool_service.php'],
    'favorites limit': 'MAX_FAVORITES = 5' in files['classes/local/bms_main_tool_service.php'],
    'workspace on its own route': "'bms_workspace.php'" not in files['bms_home.php'],
    'workspace uses authorized navigation': 'bms_main_tool_service::personalise_groups(' in files['bms_workspace.php'],
    'other pages use navigation': 'bms_main_tool_service::personalise_groups(' in files['classes/local/bms_page_shell_service.php'],
    'both sidebars allow switch': "'utilityitems'" in files['bms_workspace.php'] and "'bmsmain_change'" in files['bms_workspace.php'] and "'utilityitems'" in files['classes/local/bms_page_shell_service.php'] and "'bmsmain_change'" in files['classes/local/bms_page_shell_service.php'],
    'start only from entry links': files['index.php'].count('bms_home.php') >= 3,
    'custom intro has one-time gate': "$mainprefs['intro_dismissed']" in files['bms_workspace.php'],
    'dialog option is server whitelist': 'bms_main_tool_service::main_options(' in files['bms_workspace.php'],
    '1803 migration retained': '2026101803' in files['db/upgrade.php'],
}
for name, ok in checks.items():
    print(f"{'OK' if ok else 'FAIL'}  {name}")
if not all(checks.values()):
    raise SystemExit(2)
print(f"BMS1803 contract: {len(checks)} checks OK")
