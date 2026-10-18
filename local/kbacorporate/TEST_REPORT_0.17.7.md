# Test Report — KBA Corporate Dashboard 0.17.7

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- PHP structural scan and conflict-marker scan: checked.
- ZIP root and compressed-data integrity: checked during packaging.

## Regression scope

- Existing template cloning and update tests cover applied-version advancement.
- Existing update tests cover new requirements and template-controlled requirement refresh.
- Existing update tests verify that manual overrides remain unchanged.
- The library action validates the selected company and requires `sesskey`.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `business_role_management_test.php`.
- Open the template library for a company with a current role and verify **Up to date**.
- Change the global template profile and verify **Update required**.
- Apply the update and verify the applied version, competency profile, manual overrides and employee assignments.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
