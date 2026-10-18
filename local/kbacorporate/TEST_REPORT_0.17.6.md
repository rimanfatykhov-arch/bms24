# Test Report — KBA Corporate Dashboard 0.17.6

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- PHP structural scan and conflict-marker scan: checked.
- ZIP root and compressed-data integrity: checked during packaging.

## Added regression coverage

- A company role creates a global template with the same definition and complete competency profile.
- The initial template version remains 1 after bulk copying requirements.
- The source company role and employee assignments remain unchanged.
- An existing global template code cannot be overwritten.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `business_role_management_test.php`.
- Runtime test as a site administrator and denial test as a corporate manager.
- Verify source assignments and role targets before and after template creation.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
