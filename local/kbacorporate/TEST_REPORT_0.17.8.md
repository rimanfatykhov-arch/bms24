# Test Report — KBA Corporate Dashboard 0.17.8

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- Literal plugin language references: checked.
- PHP structural and conflict-marker scan: checked.
- ZIP root and compressed-data integrity: checked during packaging.

## Added regression coverage

- One global template is resolved across two companies.
- The rollout list is ordered by company name.
- A company role on the current applied version is reported as current.
- A company role on an older applied version is reported as requiring an update.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `business_role_management_test.php`.
- Verify that **Company usage** is visible to a site administrator and denied to a non-site administrator.
- Verify two companies with different applied versions.
- Update one company and confirm that the other company is unchanged.
- Confirm preservation of manual competency overrides and employee assignments.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
