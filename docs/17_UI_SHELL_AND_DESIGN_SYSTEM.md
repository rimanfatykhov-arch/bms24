# BMS24 Design System and Moodle Theme Decoupling

## Strategic decision

BMS24 owns its own design system and business application shell in `local_kbacompany`. Moodle themes may render basic Moodle host layout, login, core courses, etc., but not BMS24 domain menu or BMS24 UI contracts. Do not revert this decision to the old assumption that `theme_kba` is owner of the App Shell.

## BMOS palette (from approved infographic/standard)

Navy `#0B2B41`; Emerald `#0C6C52`; Sky `#E4F2FB`; Mint `#ECF7F3`; Sand `#FDF6ED`; Blue `#1777B5`; AI Violet `#6B43B5`; White `#FFFFFF`.

Appearance: light surfaces, dark navy typography, disciplined emerald CTA, semantic statuses, subtle borders, responsive grids, accessible focus, readable RU/EN labels. Module colors support meaning but should not dominate.

## Implementation anchors in build1642

- `local/kbacompany/classes/local/bms24_shell_service.php`: server opt-in boundary. `is_enabled` reads plugin config, no automatic business permission changes.
- `local/kbacompany/bms_ui/`: `tokens.css`, `owned_shell.css`, `ui_kit.css`, `task_center.css`.
- `local/kbacompany/templates/bms24_app_shell.mustache`, `bms24_task_*.mustache`, `bms24_ui_*.mustache`.
- `local/kbacompany/classes/local/bms24_ui_kit_service.php`: typed view-data/allowlist; do not inject raw HTML or arbitrary URLs.
- `local/kbacompany/bms24_styleguide.php`: company scope + demo UI.
- Admin config `local_kbacompany/bms24_shell_mode`: default `legacy`, opt-in `owned`, rollback by switching and purging Moodle cache.

## Next migration sequence

W3.2 recurring series List/Create/Edit/Detail, schedule/revision/occurrences; W3.3 Battle Plan; W3.4 People/Position Coverage; W3.5 KPI and Management. W4 audit **all** host CSS selectors and theme assumptions, compare KBA vs Boost, mobile and keyboard, and remove deprecated mappings only after sign-off.

Never change task form field names, RPC endpoints, GET context params, sesskey, accepted revisions, or company ACL for cosmetics. A mock screenshot is evidence of markup/CSS only; not of live Moodle integration.
