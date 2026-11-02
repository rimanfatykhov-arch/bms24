#!/usr/bin/env python3
"""Moodle 4.0 html_writer and communication UI static API compatibility gate.

No Moodle bootstrap, database, or runtime behavior is claimed.
"""
from pathlib import Path
import re
import sys
root = Path(__file__).resolve().parents[1]
ui = (root / 'classes/local/communication_center_ui_service.php').read_text(encoding='utf-8')
# Allow only the html_writer methods used in this BMS24 UI and supported by Moodle 4.0.
allowed = {'div', 'empty_tag', 'label', 'link', 'select', 'tag'}
used = set(re.findall(r'\\?html_writer::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', ui))
checks = {
    'no unsupported html_writer methods in communication UI': bool(used) and not (used - allowed),
    'Moodle string manager used for optional labels': "get_string_manager()->string_exists($key, 'local_kbacompany')" in ui,
    'no unsupported standalone string_exists()': not bool(re.search(r'(?<!->)\bstring_exists\s*\(', ui)),
    'navigation is created through html_writer tag': ui.count("html_writer::tag('nav'") >= 2,
    'message list scroll region': "'id' => 'bms-comm2-history'" in ui,
    'message composer uses canonical POST': "self::post_base($company, 'send', $thread['uuid'])" in ui,
    'message search is GET': "bms-comm2-message-search" in ui and "'method'=>'get'" in ui,
    'conversation list landmark': "'aria-label' => self::tr('communicationcenter_conversations_title')" in ui,
}
for label, result in checks.items():
    print(('PASS' if result else 'FAIL') + ' ' + label)
if used - allowed:
    print('Unsupported html_writer methods: ' + ', '.join(sorted(used - allowed)))
print('COMMUNICATION MOODLE40 API GATE:', sum(checks.values()), '/', len(checks))
if not all(checks.values()): sys.exit(2)
