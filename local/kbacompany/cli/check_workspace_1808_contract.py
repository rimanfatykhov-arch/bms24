#!/usr/bin/env python3
"""RC29/1808 action-first Workspace source contract; browser acceptance still required."""
from pathlib import Path
import re
p = Path(__file__).resolve().parents[1]
read = lambda name: (p / name).read_text(encoding='utf-8')
w = read('bms_workspace.php')
style = read('styles.css')
widget = read('classes/local/bms_workspace_widget_service.php')
service = read('classes/local/bms_ui_service.php')
owned = read('bms_ui/owned_shell.css')
ru, en = read('lang/ru/local_kbacompany.php'), read('lang/en/local_kbacompany.php')
upgrade, version = read('db/upgrade.php'), read('version.php')
fallback = read('templates/bms_workspace_fallback.mustache')
runner = read('cli/build_release.py')
# Audit read-only presentation and security-sensitive boundaries separately.
checks = {
    'company context authorization before user preferences': w.index('company_context_service::context(') < w.index('bms_workspace_widget_service::current('),
    'page retains Moodle login and capability': 'require_login();' in w and "require_capability('local/kbacompany:viewowncompany'" in w,
    'no client-provided arbitrary return routes': "optional_param('returnurl'" not in w,
    'main tool keeps preferences and permission-filtered menu': 'bms_main_tool_service::personalise_groups(' in w,
    'main preference change retained in sidebar': "'label' => get_string('bmsmain_change'" in w,
    'widget settings moved into header': "['label' => get_string('bmswidgets_title', 'local_kbacompany'), 'url' => $widgetsurl->out(false)]" in w,
    'workspace-only redundant tabs absent': "workspace_nav($uuid, 'workspace')" not in w,
    'persistent onboarding banner removed': "'bms-main-callout mb-3'" not in w,
    'first-use modal retained': "'id' => 'bms-main-intro'" in w and "intro_dismissed" in w,
    'intro POST has CSRF protection': "'name' => 'sesskey'" in w and "'method' => 'post'" in w,
    'no new widget IDs or preference migration': 'MAX_WIDGETS = 9' in widget and "'today', 'plan', 'actions', 'overview', 'attention', 'tasks'" in widget,
    'widget saved order and visibility retained': "foreach ($widgetlayout['order'] as $key)" in w and '!isset($widgetactive[$key])' in w,
    'company/user-scoped workspace model retained': "workspace_model($uuid, (int)$USER->id, $focusvisible," in w,
    'quiet Today requires no upcoming events and no due tasks': "empty($todayctx['next'])" in w and "(int)($todayctx['summary']['tasks_due'] ?? 0) === 0" in w,
    'quiet Today retains calendar route and accessible label': "'class' => 'bms-workspace-today-strip'" in w and "'aria-label' => get_string('bmsworkspace_today_title'" in w,
    'populated Today uses existing events': "foreach ($todayctx['next'] as $event)" in w,
    'hidden Today skips calendar': r"? \local_kbacompany\local\calendar_workspace_service::today_snapshot" in w and ": null;" in w,
    'task creation targets canonical read-only GET form': "'/local/kbacompany/tasks.php', ['uuid' => $uuid, 'view' => 'create']" in w,
    'actions resolve only permission-filtered routes': "visible_routes($model['navigation_groups'])" in w and "isset($mainroutes['tasks'])" in w,
    'plan action references actual plan instead of arbitrary route': "if (isset($mainroutes['battleplan']))" in w and 'html_writer::link($planlink,' in w,
    'company-wide totals are not labelled as personal': "'meta' => ['items' => []]" in w and "'description' => get_string('bmsworkspace_focus_description'" in w,
    'task due date uses user-local date': "userdate(time(), '%Y-%m-%d')" in w,
    'page-scoped styles do not alter other modules': "add_body_class('bms24-workspace-page')" in w and '.bms-workspace-1808 .bms-workspace-today-strip' in style,
    'owned shell workspace-specific layout': '.bms24-app .bms-workspace-1808 .bms-workspace-widget--overview' in owned,
    'mobile layout rules': '@media(max-width:640px)' in style and 'bms-main-quicklinks--actions' in style,
    'fallback remains functional': '{{{widgetcontenthtml}}}' in fallback and 'bmsworkspace_focus_title' in fallback,
    'localized focus title and guidance RU EN': all(f"$string['{key}']" in ru and f"$string['{key}']" in en for key in ['bmsworkspace_focus_title','bmsworkspace_focus_description']),
    'no schema upgrade': 'if ($oldversion < 2026101808)' in upgrade and 'upgrade_plugin_savepoint(true, 2026101808' in upgrade,
    'version correctly advanced': bool(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', version) and int(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', version).group(1)) >= 2026101808),
    'builder enforces workspace contract': 'check_workspace_1808_contract.py' in runner,
}
for label, ok in checks.items():
    print(('PASS ' if ok else 'FAIL ') + label)
print(f'RC29/1808: {sum(checks.values())}/{len(checks)} static-contract checks')
if not all(checks.values()):
    raise SystemExit(2)
