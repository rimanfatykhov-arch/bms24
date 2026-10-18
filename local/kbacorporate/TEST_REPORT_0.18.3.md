# Test report — 0.18.3

Build: `2026090217`

## Local static gate

- XMLDB XML parsing: passed.
- RU/EN language-key parity: passed.
- PHP structural scan: passed.
- CSS brace and responsive-table checks: passed.
- Upgrade savepoint/version consistency: passed.
- ZIP integrity and `kbacorporate/` root: passed before delivery.
- PHPUnit regression source covers metadata update, item update and deletion.

## Required Moodle runtime gate

- Run `php -l` for all PHP files.
- Run PHPUnit, including `development_plans_test.php`.
- Verify manager edit/add/delete controls and employee status-only access on Moodle 4.0.
- Verify company-course validation and cross-company access isolation.
