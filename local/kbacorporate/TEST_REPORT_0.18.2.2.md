# Test report — 0.18.2.2

Build: `2026090215`

## Local static gate

- XMLDB XML parsing: passed.
- RU/EN language-key parity: passed.
- PHP structural scan: passed.
- Upgrade savepoint/version consistency: passed.
- ZIP integrity and root directory: passed before delivery.
- Filter regression assertions cover confirmed gaps, unassessed rows and attention rows.

## Required Moodle runtime gate

- Run `php -l` for all PHP files.
- Run PHPUnit, including `competency_map_test.php`.
- Verify automatic selectors, search submit, reset and CSV export on Moodle 4.0.
