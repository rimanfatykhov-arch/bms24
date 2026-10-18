# Test report — 0.18.4

Build: `2026090219`

## Local static gate

- XMLDB XML parsing and field ordering: passed.
- RU/EN language-key parity: passed.
- PHP structural scan and form balance: passed.
- Upgrade savepoint/version consistency: passed.
- Upgrade source contains no indexed `status` default mutation.
- Workflow regression source covers submit, accept, reopen and request-changes paths.
- Privacy metadata declarations cover submitter and employee comment fields.
- ZIP integrity and `kbacorporate/` root: passed before delivery.

## Required Moodle runtime gate

- Run `php -l` for all PHP files and the plugin PHPUnit suite.
- Upgrade a copy of the current database and verify the four new plan columns.
- Test manager draft editing, submission, employee acceptance and employee return with comment.
- Verify that an employee cannot decide another employee’s plan.
- Verify existing active plans remain active after upgrade.
