# Test Report — KBA Corporate Dashboard 0.17.1

Date: 2026-09-01

## Tested locally

| Check | Result |
|---|---|
| PHP delimiter scan across 87 PHP files | PASS |
| `styles.css` delimiter scan | PASS |
| `db/install.xml` well-formed XML parse | PASS |
| RU/EN language-key parity (812 unique keys, no duplicates) | PASS |
| Static `get_string()` literal lookup coverage | PASS |
| Merge/conflict marker scan | PASS |
| Build/release/API marker consistency | PASS |
| Whitespace error check against canonical v0.17.0 | PASS |
| Generated checkpoint patch `git apply --check` against canonical v0.17.0 | PASS |
| v0.17.0 baseline retained as a separate untouched directory | PASS |
| Canonical v0.16.10 equals the captured production source tree | PASS |
| Own-profile access regression guard (no self-view capability requirement) | PASS |
| Reserved role-profile management capability declaration | PASS |
| Recommendation source/scope contract marker | PASS |

## Review corrections after the initial checkpoint

- Restored the v0.16.1 invariant that every authenticated learner can open their own competency profile.
- Added the reserved `local/kbacorporate:managerolecompetencies` capability from the specification.
- Hid the company selector when there is only one unambiguous company.
- Removed hidden/unavailable courses from recommendations and aligned the source marker with the API contract.
- Read level captions from the configured Moodle scale, retaining hardcoded KBA captions only as fallback.
- Wrapped membership deletion and business-role cleanup in one delegated transaction.
- Removed the duplicate `allcourses` language declaration in both language packs.
- Removed the redundant `competency_ix` index after Moodle 4.0 reported its collision with `competency_fk`; both clean-install XML and the direct-upgrade definition were corrected.
- Reduced `local_kbacorp_extmap.system` and `entitytype` from 50 to 30 characters after Moodle/MySQL rejected the 355-character `external_uix`; the corrected index is 315 characters.

## Added automated tests

- `access_test.php`: one membership auto-resolves; multiple memberships require explicit company context.
- `business_roles_test.php`: explicit company role queries and global catalog isolation.
- `target_profile_test.php`: cross-company isolation plus maximum target, strongest priority and required aggregation.
- `competencies_test.php`: configured Moodle scale mapping, `Not assessed` versus real level zero and provisional-course status.
- `course_recommendations_test.php`: company/competency scope guards and hidden-course non-disclosure.
- `repository_lifecycle_test.php`: role preservation during partial access-role removal and cleanup on membership deletion.
- `privacy_provider_test.php`: metadata registration for new personal-data tables.

## Required on the target Moodle stack

This workspace does not provide PHP, Moodle bootstrap, PHPUnit or `xmllint`. Therefore the following release gates are pending:

1. `php -l` for every PHP file.
2. Moodle PHPUnit for all plugin tests.
3. Direct upgrade test from production `0.16.10` / build `2026082410` to build `2026090100`.
4. Upgrade test from prepared `0.17.0` / build `2026082500`.
5. Manual own-profile access, configured-scale labels, multi-company visibility, recommendation, completion/evidence and Privacy API scenarios from `TESTING.md`.
6. Moodle scheduled tasks and public bridge regression smoke tests.

## Packaging decision

A test-only installation candidate may be assembled so the target-stack gate can be run on a disposable Moodle clone. It is not a production-approved release. The production release ZIP remains blocked until every target-stack gate above passes.
