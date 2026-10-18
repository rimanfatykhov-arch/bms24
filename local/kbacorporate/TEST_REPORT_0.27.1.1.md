# Static test report — 0.27.1.1

- Version, XMLDB marker and upgrade savepoint match build `2026090279`.
- The selector query includes active current-company and global tools only.
- The company context remains checked by `create_audit()` and is also validated in the Moodle form.
- A PHPUnit scenario covers company, global, other-company and inactive tool visibility.
- Empty companies receive the bundled Point A template through the idempotent installer.
- Russian and English language keys and Moodle 4.0 link attributes pass static validation.

PHP CLI and a Moodle test database are not available locally; runtime verification remains required on the target Moodle server.
