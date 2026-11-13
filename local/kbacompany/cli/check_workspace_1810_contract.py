#!/usr/bin/env python3
"""RC29/1810 source security & integration gate (not live Moodle acceptance)."""
from pathlib import Path
from xml.etree import ElementTree as ET
import re
ROOT=Path(__file__).resolve().parents[1]
read=lambda file:(ROOT/file).read_text(encoding='utf-8')
page=read('bms_workspace.php')
role=read('classes/local/bms_workspace_focus_service.php')
model=read('classes/local/bms_ui_service.php')
policy=read('classes/local/bms_workspace_load_policy.php')
builder=read('cli/build_release.py')
checks={
    'membership checked before scoped preferences': page.index('company_context_service::context($uuid') < page.index('bms_workspace_widget_service::current('),
    'scope selected only after capability lookup': page.index('can_manage_owner_private(') < page.index('bms_workspace_focus_service::select('),
    'manager view only on manage permission': 'if ($canmanagement) { $modes[] = \'management\'; }' in role,
    'owner view only on owner permission': 'if ($canowner) { $modes[] = \'owner\'; }' in role,
    'unrecognized requested scope falls back to all': "? $requested : 'all'" in role,
    'scope route limited to known enumerations': "optional_param('focus', 'all', PARAM_ALPHA)" in page and "new moodle_url('/local/kbacompany/bms_workspace.php'" in page,
    'no preference mutation from GET': 'set_user_preference(' not in page and 'unset_user_preference(' not in page,
    'per company widget preferences preserved': "bms_workspace_widget_service::current($uuid, (int)$USER->id, $widgetavailable)" in page,
    'focus visible subset controls server read model': 'bms_workspace_focus_service::visible($widgetlayout[\'visible\'], $focusmode)' in page and 'bms_ui_service::workspace_model($uuid, (int)$USER->id, $focusvisible,' in page,
    'personal manager model skipped': 'include_management_decisions($focusmode)' in page and '$includeattentionmanagement && $has(\'attention\')' in policy,
    'extra approval/KPI sources only for manager': '!empty($model[\'management_home\'][\'available\']) && $canmanagewidgets' in page,
    'per view actionable queue is filtered before rendering': 'bms_workspace_focus_service::actions($actionrows, $focusmode)' in page,
    'scoped queue does not change task statuses': 'update_record(' not in role and 'insert_record(' not in role,
    'protected owner module link': "if ($focusmode === 'owner' && $canownerfocus)" in page and "'/local/kbacompany/owner.php'" in page,
    'protected manager module link': 'if ($canmanagewidgets)' in page and "'/local/kbacompany/management.php'" in page,
    'view preserves 9 widget IDs': 'MAX_WIDGETS = 9' in read('classes/local/bms_workspace_widget_service.php'),
    'fallback and owned shell include focus selector': '{{{focushtml}}}' in read('templates/bms_workspace_fallback.mustache') and '$contenthtml .= $focushtml;' in page,
    'empty focus explains hidden widgets': 'bmsfocus_empty' in page,
    'aria and responsive style': "'aria-current' = 'page'" not in page and "'aria-current'] = 'page'" in page and 'max-width:640px' in read('styles.css'),
    'all role labels localized': all(f"$string['{k}']" in read(f'lang/{lg}/local_kbacompany.php') for lg in ['ru','en'] for k in ['bmsfocus_tab_all','bmsfocus_tab_personal','bmsfocus_tab_management','bmsfocus_tab_owner','bmsfocus_empty']),
    'Moodle upgrade checkpoint': 'if ($oldversion < 2026101810)' in read('db/upgrade.php'),
    'version not rolled back below 1810': bool(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')) and int(re.search(r'\$plugin->version\s*=\s*(\d+)\s*;', read('version.php')).group(1)) >= 2026101810),
    'tests part of release gate': 'test_workspace_1810_focus.php' in builder and 'check_workspace_1810_contract.py' in builder,
}
for name,passed in checks.items(): print(('PASS ' if passed else 'FAIL ')+name)
print(f'RC29/1810: {sum(checks.values())}/{len(checks)} source checks')
if not all(checks.values()): raise SystemExit(2)
