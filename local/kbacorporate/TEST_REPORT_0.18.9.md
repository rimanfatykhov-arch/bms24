# KBA Corporate Dashboard 0.18.9 — Test Report

Build: `2026090224`

## Local gate

- XMLDB and incremental upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and ZIP integrity checks pass before delivery.

## Regression scope

- Baseline is the level captured when the IPR action was created.
- Current level comes only from the authoritative Moodle competency snapshot.
- Improved, newly assessed, target reached, remaining gap and unassessed states are calculated separately.
- Course completion status is not used by the outcome calculation.
- No database schema changes are introduced.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Confirm a follow-up audit and verify the completed IPR outcome refreshes from Moodle.
- Verify scale labels and responsive layout with the production theme.
