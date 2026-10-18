# KBA Corporate Dashboard 0.21.0 — Test Report

Build: `2026090239`

## Local checks

- Install XML parses successfully and contains all four v0.21.0 tables.
- Upgrade schema matches install schema names and build.
- RU/EN language key parity passes.
- All referenced PHP pages, form classes and service classes are present.
- ZIP integrity and `kbacorporate/` root are checked during packaging.

## Moodle acceptance required

1. Upgrade a copy of v0.20.0.1 to v0.21.0.
2. Open the new dashboard card `Аудит внедрения инструментов BMS`.
3. Create a tool and at least two weighted criteria.
4. Create a baseline audit and confirm that criteria are copied into it.
5. Upload a file, assign a 0–5 score and mark evidence verified.
6. Confirm that an audit with a missing required criterion is rejected.
7. Confirm a complete audit and verify BMS Index and Evidence Coverage.
8. Verify that the confirmed audit cannot be edited.
9. Verify company isolation with two companies.
10. Delete a test company and confirm that audit records and files are removed safely.

PHP lint and runtime Moodle PHPUnit cannot be executed in the current build environment because PHP/Moodle runtime is unavailable.
