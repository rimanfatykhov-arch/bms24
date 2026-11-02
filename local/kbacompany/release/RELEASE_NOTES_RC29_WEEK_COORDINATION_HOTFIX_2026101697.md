# BMS24 1.1.0-rc29 / build2026101697

## Weekly Management Coordination hotfix

Fixes the first live acceptance defects found in build2026101696.

- Replace invalid `bms_page_shell_service::end()` with the canonical `::finish()` used by existing BMS24 pages.
- Refresh Moodle caches during the 1697 upgrade so the RU/EN `battleplan_weekcoord_*` strings shipped in 1696 are reloaded and `[[...]]` placeholders disappear.
- No DDL. No business-data changes. No ACL/RBAC/lifecycle/company-isolation changes.

## Live evidence from build1696

- Fatal: `Call to undefined method local_kbacompany\local\bms_page_shell_service::end()` at `battle_plan_week_coordination.php:149`.
- New coordination title rendered as `[[battleplan_weekcoord_title]]`, while the corresponding RU/EN strings are present in the plugin language files.

## Acceptance for build1697

After installation, open Weekly Management Coordination and verify:
1. page renders inside the owned BMS24 shell without a fatal error;
2. no `[[battleplan_weekcoord_*]]` placeholders are visible;
3. filters and agenda links remain functional.

Live acceptance: NOT RUN.
