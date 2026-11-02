# BMS24 1.1.0-rc29 build2026101778 — RC30 preparation

## Change
- Add `cli/check_upgrade_sequence.py` to guard upgrade checkpoint uniqueness, active-release ordering, last-checkpoint reachability and savepoint coverage.
- Add `cli/test_upgrade_sequence.py` with negative regression cases.
- Fail the release builder before ZIP packaging if migration checks fail.
- No changes to data model or user interface. Current release remains RC29 pending live acceptance and RC30 readiness.

## Important
- Pre-2026101700 checkpoint order has historical inconsistencies and is not treated as a new release failure. Review separately before any major Moodle core upgrade.
- Static checks do not replace a Moodle test installation or database migration test.
