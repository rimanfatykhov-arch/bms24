# Test Report — KBA Corporate Dashboard 0.17.4

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness and new field/index definitions: checked.
- English/Russian language-key parity: checked.
- PHP structural and local string-reference scans: checked.
- Conflict-marker and archive-root checks: checked.
- ZIP integrity and SHA256: performed during packaging.

## Added regression coverage

- Company role copies the complete global template profile.
- Repeated creation is idempotent for the same template/company.
- Applied template version is recorded.
- New template requirements are added during update.
- Manual company overrides remain unchanged.
- Company-only requirements are not removed.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `business_role_management_test.php`.
- Upgrade from the installed build through savepoint `2026090201`.
- Runtime tests with site administrator and corporate manager permissions.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
