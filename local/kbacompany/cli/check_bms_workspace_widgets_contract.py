#!/usr/bin/env python3
"""Source gate for workspace widget wiring. Not a browser or Moodle integration test."""
from pathlib import Path
p = Path(__file__).resolve().parents[1]
rd = lambda s: (p / s).read_text(encoding='utf-8')
w = rd('bms_workspace.php')
form = rd('bms_workspace_widgets.php')
svc = rd('classes/local/bms_workspace_widget_service.php')
js = rd('js/bms_workspace_widgets.js')
fallback = rd('templates/bms_workspace_fallback.mustache')
builder = rd('cli/build_release.py')
checks = {
    'workspace authorizes company before reading preferences': w.index('company_context_service::context(') < w.index('bms_workspace_widget_service::current('),
    'workspace saves no direct preferences': 'set_user_preference(' not in w,
    'widget content is authorized named blocks': "'attention' => $actioncard" in w and "'management'" in w and "isset($widgetactive['attention'])" in w,
    'layout rendered in selected order': "foreach ($widgetlayout['order'] as $key)" in w,
    'visibility gate before card rendering': "!isset($widgetactive[$key])" in w,
    'calendar work skipped when hidden': "isset($widgetactive['today'])" in w,
    'personal plan database work skipped when hidden': "if (isset($widgetactive['plan']))" in w,
    'fallback supports widget HTML': '{{{widgetcontenthtml}}}' in fallback,
    'fallback content uses server-approved html writer': "html_writer::div($fallbackwidgethtml" in w,
    'settings company membership checked': 'company_context_service::context($uuid, $userid)' in form,
    'settings POST guarded': "if ($method === 'POST')" in form and 'require_sesskey();' in form,
    'mutation only via POST': 'widgets::save(' in form and 'widgets::reset(' in form,
    'settings fixed internal redirect': "'/local/kbacompany/bms_workspace.php'" in form,
    'whitelist and max 9': 'public const MAX_WIDGETS = 9' in svc and 'self::DEFAULT_ORDER' in svc,
    'company-scoped user preference': "hash('sha256', $companyuuid)" in svc and 'set_user_preference(' in svc,
    'reset supported': 'unset_user_preference(' in svc,
    'progressive enhancement not sole route': "name' => 'positions['" in form and 'list.addEventListener' in js,
    'both theme fallback and owned shell route': "'widgetsurl' => $widgetsurl->out(false)" in w,
    'new builder tests mandatory': "check_bms_workspace_widgets_contract.py" in builder and 'test_bms_workspace_widgets.php' in builder,
    '1804 migration retained': 'if ($oldversion < 2026101804)' in rd('db/upgrade.php'),
}
for name,ok in checks.items(): print(('OK' if ok else 'FAIL') + '  ' + name)
if not all(checks.values()): raise SystemExit(2)
print(f"BMS1804 contract: {len(checks)} checks OK")
