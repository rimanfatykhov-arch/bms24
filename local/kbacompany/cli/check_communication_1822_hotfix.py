#!/usr/bin/env python3
"""Static regression gate for Communication Center scrollbar and new-thread hotfix 1822."""
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
ui = (ROOT / 'classes/local/communication_center_ui_service.php').read_text(encoding='utf-8')
css = (ROOT / 'styles.css').read_text(encoding='utf-8')
version = (ROOT / 'version.php').read_text(encoding='utf-8')
checks = {
    'version advanced': int(__import__('re').search(r'\$plugin->version\s*=\s*(\d+);', version).group(1)) >= 2026101822,
    'new-thread trigger located in sidebar header': "'bms-comm2-sidebar-head'" in ui and
        ' . $newthread' in ui,
    'not displayed in standalone row': "$html .= self::new_conversation_form($company, $data['employees']" not in ui,
    'new-thread action retains POST': "['method' => 'post', 'class' => 'bms-comm2-new-form']" in ui,
    'new-thread action retains sesskey': "self::post_base($company, 'createthread')" in ui,
    'new-thread label accessible': "['class' => 'sr-only']" in ui and "'aria-label' => $label" in ui,
    'sidebar is positioned to anchor form': 'body.bms-comm-page .bms-comm2 .bms-comm2-sidebar {' in css and
        'position: relative;' in css[css.index('/* RC29/1822:'):],
    'scroll area uses block layout': 'display: block;\n    flex: 1 1 0%;' in css,
    'scroll area has definite flex height': 'flex: 1 1 0%;\n    height: 0;' in css,
    'scroll area scrolls vertically': 'overflow-y: scroll;' in css[css.index('/* RC29/1822:'):],
    'mobile scroll area has explicit height': 'height: 260px;' in css[css.index('/* RC29/1822:'):],
    'new-thread form remains available': "'id' => 'bms-comm2-start-conversation'" in ui,
}
for label, result in checks.items():
    print(('PASS' if result else 'FAIL') + ' ' + label)
assert all(checks.values()), 'Communication Center hotfix regression failed'
print('COMMUNICATION 1822 GATE:', len(checks), '/', len(checks))
