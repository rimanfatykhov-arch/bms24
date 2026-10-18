# Test Report — KBA Corporate Dashboard 0.17.1.1

Date: 2026-09-01

Build: `2026090101`

## Local hotfix checks

- Clean-install XML is well formed.
- No XMLDB table defines an index that exactly duplicates a key.
- Every character-field composite index is within Moodle's 333-character limit; `external_uix` totals 315 characters.
- `local_kbacorp_rolecomp` contains `competency_fk` without the colliding `competency_ix`.
- Clean-install and upgrade definitions both use 30-character `system` and `entitytype` fields.
- The four-table upgrade remains guarded by `table_exists()`, allowing a failed build `2026090100` attempt to resume.
- Version, release, install schema and upgrade savepoint use build `2026090101`.
- Full archive integrity and the `kbacorporate/` ZIP root are checked after packaging.

## Required on the Moodle server

PHP lint, Moodle PHPUnit, direct upgrade and multi-company acceptance tests remain required because this workspace does not include a PHP/Moodle runtime.
