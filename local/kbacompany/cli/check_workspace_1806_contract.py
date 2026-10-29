#!/usr/bin/env python3
"""RC29/1806 source integration contract. NOT a substitute for live Moodle performance test."""
from pathlib import Path
import re
root = Path(__file__).resolve().parents[1]
read = lambda p: (root / p).read_text(encoding='utf-8')
workspace = read('bms_workspace.php')
service = read('classes/local/bms_ui_service.php')
policy = read('classes/local/bms_workspace_load_policy.php')
settings = read('bms_workspace_widgets.php')
styles = read('styles.css')
upgrade = read('db/upgrade.php')
version = read('version.php')
builder = read('cli/build_release.py')
# Extract only the workspace method; other BMS pages must retain their own full read models.
model = service.split('public static function workspace_model(',1)[1].split('public static function shell_model(',1)[0]
checks = {
    'company authorization before preference lookup': workspace.index('company_context_service::context(') < workspace.index('bms_workspace_widget_service::current('),
    'widget capability whitelist before reading preferences': workspace.index('bms_workspace_widget_service::available(') < workspace.index('bms_workspace_widget_service::current('),
    'visible widgets handed to server read model': "bms_ui_service::workspace_model($uuid, (int)$USER->id, $focusvisible," in workspace,
    'null preserves legacy caller signature': '?array $visiblewidgets = null' in model and '$legacy = $visible === null' in policy,
    'server honors only approved card IDs': 'array_intersect(bms_workspace_widget_service::DEFAULT_ORDER, $visible)' in policy,
    'separate organization dependency': "if ($load['organization'])" in model or "= $load['organization']" in model,
    'separate process dependency': "= $load['processes']" in model,
    'full communications requires work card': "= $load['work']" in model and 'communication_center_service::context(' in model,
    'lite counters do not hydrate messages and tasks': 'communication_center_service::shell_counters(' in model and "if ($load['work'])" in model,
    'canonical task list is in full-model branch': model.index("if ($load['work'])") < model.index('task_service::list_page(') < model.index('} else {\n            // Counters'),
    'lightweight navigation control count not silently zero': "$counters['control'] = (int)$light['control']" in model,
    'learning contour gated by overview': "if ($load['learning'])" in model,
    'management projection gated by rights and visible cards': "if ($canmanagement && $load['management'])" in model,
    'hidden manager card preserves capability availability': "if ($canmanagement && !$load['management'])" in model,
    'task context always initialized': "$tasks = $work['task_center'];" in model,
    'header does not show fabricated employee count': ("!empty($model['organization_loaded'])" in workspace or "'meta' => ['items' => []]" in workspace),
    'calendar and plan retain independent guards': "isset($widgetactive['today'])" in workspace and "if (isset($widgetactive['plan']))" in workspace,
    'settings screen only checks membership and capabilities': 'company_context_service::context($uuid, $userid)' in settings and 'bms_ui_service::workspace_model(' not in settings,
    'CSS adapts layout to small screens': '@media (max-width: 640px)' in styles and 'grid-template-columns: minmax(0, 1fr)' in styles,
    'CSS avoids clipped widgets': 'overflow-wrap: anywhere' in styles and '.bms-workspace-widget--narrow' in styles,
    'builder enforces new policy and integration tests': 'check_workspace_1806_contract.py' in builder and 'test_workspace_1806_policy.php' in builder,
    'Moodle upgrade checkpoint exists': 'if ($oldversion < 2026101806)' in upgrade,
    'version >= 1806': int(re.search(r'\$plugin->version\s*=\s*(\d+);', version).group(1)) >= 2026101806,
}
for label, result in checks.items():
    print(('OK  ' if result else 'FAIL ') + label)
if not all(checks.values()): raise SystemExit(2)
print(f'BMS1806: {len(checks)} integration-contract checks OK')
