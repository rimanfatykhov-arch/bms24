#!/usr/bin/env python3
"""Release gate for autonomous routes bypassing shared shell adapter."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]
needle="js_call_amd('local_kbacompany/bms_shell_navigation', 'init')"
routes=['bms_workspace.php','bms_architecture.php']
for route in routes:
    text=(p/route).read_text(encoding='utf-8')
    assert text.count(needle)==1, f'{route}: global nav AMD missing or duplicate'
    assert text.index(needle)<text.index('echo $OUTPUT->header()'), f'{route}: AMD must register before output'
assert needle in (p/'classes/local/bms_page_shell_service.php').read_text(encoding='utf-8')
print('Shared shell route integration: 3/3 PASS')
