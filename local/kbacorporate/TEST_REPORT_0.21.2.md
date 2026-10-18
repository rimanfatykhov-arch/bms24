# Test report — 0.21.2

Build: `2026090241`

## Local release gate

- XML structure and table definitions checked.
- RU/EN language key parity checked.
- PHP structure, conflict markers and packaging layout checked.
- Point A template remains 15 areas / 137 criteria and now uses target level 7.
- Upgrade path from 0.21.1 includes target backfill and route-table creation.

## Required Moodle runtime acceptance

1. Upgrade a copy of production without uninstalling the plugin.
2. Open Point A and verify target level 7 in criteria and existing audits.
3. Configure a criterion route to a company tool, a company course, and both together.
4. Verify that a course outside the company cannot be saved.
5. Create an audit, score a criterion below target, and open **Implementation plan**.
6. Confirm a baseline audit, create a later audit, and verify the before/after comparison.
7. Delete a test company and verify that its routes, audits and tools are removed safely.

PHP lint and PHPUnit must be run on the Moodle server because PHP is unavailable in this build environment.
