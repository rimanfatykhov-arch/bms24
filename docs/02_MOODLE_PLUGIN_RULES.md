# Moodle / BMS24 Plugin Rules (updated W3)

## Core ownership

`local/kbacompany`: company scope, canonical entities, CRUD, services, permissions, task/recurrence/battleplan/coverage, navigation and **BMS24-owned presentation**.

`theme/kba`: standard host Moodle pages and legacy fallback. **Do not add new BMS24 shell templates or BMS24 design tokens to this theme**. Existing `theme_kba/bms/*` is legacy transitional technical debt; remove only after staged parity tests.

Moodle core: authentication, core render/output DB/file APIs, context, capabilities, sesskey, upgrade, privacy. No core hacks.

## Actual plugin source (build2026101642)

- `version.php`: `$plugin->version = 2026101642`, `$plugin->release = '1.1.0-rc29'`, `$plugin->requires = 2022041900`.
- `classes/local/bms24_shell_service.php` and `settings.php`: opt-in `bms24_shell_mode` flag (`legacy` default; `owned` trial).
- `templates/bms24_app_shell.mustache`, `templates/bms24_task_*.mustache`, `templates/bms24_ui_*.mustache`.
- `bms_ui/tokens.css`, `owned_shell.css`, `ui_kit.css`, `task_center.css` (scoped `.bms24-app`).
- `bms24_styleguide.php`: opt-in design samples with company ACL.
- `tasks.php`, `classes/local/task_ui_service.php`: Task Center W3 phase one; don't remove backwards compatible DOM hooks without E2E checks.

## Compatibility caveat

Old documents said target Moodle 4.1.22 / PHP 7.3+. Actual `version.php` requires Moodle 4.0, while actual **host** Moodle/PHP combination must be checked before picking syntax/dependencies. Do NOT infer PHP 7.3 compatibility from a PHP 8.x lint. Confirm target at runtime before upgrading PHP requirements.

## Engineering

Thin controllers; type-safe/escaped view models; canonical validation; RU/EN language packs; scoped styles; no schema changes for UI-only tasks. Check Mustache and cache purge in staging after changing templates; do not hardcode `edu.kzn.mba` in generic code.
