# Test Report — 0.23.1

Build: `2026090254`

Local gate covers XML parsing, language parity and duplicates, PHP structural checks, version consistency, conflict markers and ZIP integrity. Browser acceptance and `php -l` remain required on the target Moodle server because PHP is unavailable in the build environment.

Acceptance checklist:

1. On a company-scoped page, the top bar shows “BMS implementation” beside “My competencies”.
2. A manager with exactly one company sees the shortcut outside company-scoped pages.
3. A user with several companies and no selected company is not sent to an arbitrary tenant.
4. With no audit, tariff selection is replaced by a Point A explanation and audit action.
5. With a latest audit and no request, all active editable packages are shown as cards.
6. Selecting a package creates one company-scoped request for that audit and redirects to the workspace.
7. If Bitrix24 automatic creation is enabled, the existing deal-creation workflow still runs.
8. An existing request/program displays its package and statuses and no duplicate tariff buttons.
9. Package cards collapse to one column on tablet/mobile without horizontal page scrolling.
