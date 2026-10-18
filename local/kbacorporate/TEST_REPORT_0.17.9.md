# Test Report — KBA Corporate Dashboard 0.17.9

## Local release gate

- Build, release and XMLDB version consistency: checked.
- XML well-formedness: checked.
- English/Russian language-key parity: checked.
- Literal plugin language references: checked.
- PHP structural and conflict-marker scan: checked.
- ZIP root and compressed-data integrity: checked during packaging.

## Added regression coverage

- Global template creation stores an initial version snapshot.
- Competency changes create complete subsequent snapshots with author and comment.
- Comparing versions detects added and changed requirements.
- Privacy metadata declares the template-version author data.
- Existing template update tests continue to cover manual-override and assignment preservation.

## Required on target Moodle

- `php -l` for all PHP files.
- Moodle PHPUnit, especially `business_role_management_test.php` and `privacy_provider_test.php`.
- Upgrade a copy of the production database and verify both new tables and snapshot backfill.
- Create and edit a global template with version comments, then compare versions.
- Preview and apply a company-role update; verify manual overrides and assignments.
- Run Privacy export/delete for a version author and verify author anonymisation.

The local environment has no PHP runtime or Moodle database, so these target-stack checks remain mandatory.
