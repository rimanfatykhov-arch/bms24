# Test report — 0.21.3

Build: `2026090242`

## Local gate

- XML and PHP structural checks completed.
- RU/EN language parity checked.
- Upgrade path and ZIP root checked.
- Automated test added for converting a Point A gap into a managed task.

## Required Moodle acceptance

1. Upgrade from installed 0.21.2 without uninstalling the plugin.
2. Open an audit implementation plan and create a task from a configured route.
3. Assign a company member, start date, due date and expected result.
4. Change states through in progress, review and completed.
5. Attach evidence and verify it can be downloaded only by an authorised company manager.
6. Verify status, owner and overdue filters.
7. Remove a test employee and confirm the task remains but becomes unassigned.
8. Remove a test company and confirm tasks and task evidence are deleted safely.

PHP lint, PHPUnit and browser acceptance remain required on the Moodle server because PHP is unavailable locally.
