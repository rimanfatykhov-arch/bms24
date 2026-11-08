#!/usr/bin/env python3
"""Release guard: shared compact sidebar must not collapse KBA shell into zero-width content."""
from pathlib import Path
p = Path(__file__).resolve().parents[1]
css = (p/'styles.css').read_text(encoding='utf-8')
js = (p/'amd/src/bms_shell_navigation.js').read_text(encoding='utf-8')
comm = (p/'amd/src/communication_live_ui.js').read_text(encoding='utf-8')
shell = (p/'classes/local/bms_page_shell_service.php').read_text(encoding='utf-8')
checks = [
    "js_call_amd('local_kbacompany/bms_shell_navigation', 'init')" in shell,
    'sidebar.parentElement !== shell' in js,
    'style.display' in js,
    "shell.classList.toggle('bms-shell-nav-compact', compact)" in js,
    "sidebar.insertBefore(header, sidebar.firstChild)" in js and "header.appendChild(toggle)" in js and "header.appendChild(brand)" in js,
    'aria-expanded' in js,
    '.bms-shell-nav-header' in css and '.bms-shell-nav-compact .bms-shell-nav-header > :not(.bms-shell-nav-toggle)' in css,
    'localStorage' in js,
    '.bms24-app.bms-shell-nav-compact' in css,
    '.kba-bms.bms-shell-nav-compact > .kba-bms-sidebar' in css,
    'bms-comm-sidebar-collapsed' not in css,
    'bms-comm-shell-toggle' not in comm,
]
assert all(checks), [i for i, x in enumerate(checks, 1) if not x]
# Explicit expanded/compact brand contract: BMS24 text must never be zero-sized.
checks.extend([
    'bms-shell-nav-brand .bms-sidebar-brandmark-text { font-size:0' not in css,
    ':not(.bms-shell-nav-compact) .bms-shell-nav-header .bms-sidebar-brandmark-text' in css,
    '.bms-shell-nav-compact .bms-shell-nav-header > .bms-shell-nav-brand' in css,
])
assert all(checks), [i for i, x in enumerate(checks, 1) if not x]
print(f'Shared shell compact navigation: {len(checks)}/{len(checks)} PASS')
