# KBA Corporate Dashboard 0.18.5 — Test Report

Build: `2026090220`

## Local gate

- XMLDB parses successfully and reports build `2026090220`.
- Upgrade chain contains the no-schema-change savepoint `2026090220`.
- Russian and English language keys are unique and in parity.
- PHP files pass structural delimiter and opening-tag checks.
- CSS block delimiters are balanced.
- Release ZIP integrity and root-folder layout are verified before delivery.

## Regression scope

- Progress percentage uses completed actions divided by all plan actions.
- Completed actions are excluded from overdue totals.
- An unfinished action before today is overdue.
- An unfinished action due today or in the next 14 days is due soon.
- The existing automatic transition to completed remains unchanged.
- No database schema change is made after the 0.18.4.1 hotfix.

## Server gate still required

- Run `php -l` for all plugin PHP files.
- Run Moodle PHPUnit, including `development_plans_test`.
- Upgrade a copy of the production database from build `2026090219`.
- Verify manager and employee views with the production theme.
