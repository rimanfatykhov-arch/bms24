# Test Report — KBA Corporate Dashboard 0.17.5

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- PHP structural scan and conflict-marker scan: checked.
- ZIP root and compressed-data integrity: checked during packaging.

## Added regression coverage

- Renaming changes only the audit title and modification time.
- Deleting an audit removes its structured evidence before assessment rows and the audit session.
- Confirmed Moodle competency levels are not part of the deletion operation.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `competency_audits_test.php`.
- Runtime rename and delete tests with a corporate manager and a site administrator.
- Verification that cross-company audit identifiers are rejected.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
