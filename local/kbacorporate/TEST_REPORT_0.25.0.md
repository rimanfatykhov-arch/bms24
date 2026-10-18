# Test report — 0.25.0

## Local checks

- XMLDB install schema parses successfully.
- Russian and English language packs have matching, non-duplicate keys.
- Clean-install and upgrade definitions include the task discussion table, keys and indexes.
- Version, install schema, upgrade savepoint and release manifest use build `2026090263`.
- Full ZIP uses the required `kbacorporate/` root and passes archive integrity validation.

## Server acceptance

- Open a task from its title and from the **Open task** action.
- Copy the direct URL and verify access as the assigned employee.
- Verify that an unrelated employee cannot open the task.
- Add messages as manager and employee and verify chronological ordering.
- Verify popup/email alerts and direct notification links.
- Confirm that discussion text does not change status, assessment or official evidence.
- Run `php -l`, Moodle upgrade, cache purge, privacy tests and the project test suite on the target server.
