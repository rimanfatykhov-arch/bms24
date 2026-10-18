# KBA Corporate Dashboard 0.18.6 — Test Report

Build: `2026090221`

## Local gate

- XMLDB and upgrade build are consistent.
- Scheduled task registration and language strings are present.
- Russian and English language keys are unique and in parity.
- PHP and CSS structural checks pass.
- Release ZIP integrity and `kbacorporate/` root are checked before delivery.

## Regression scope

- Only active plans and unfinished actions linked to completed Moodle courses are changed.
- Moodle's `course_completions.timecompleted` value is preserved.
- Each affected plan is refreshed once and completes when no unfinished actions remain.
- Unlinked and non-completed courses do not change actions.
- Competency levels and competency evidence are never written by this synchronisation.

## Server gate still required

- Run `php -l` for every PHP file.
- Run Moodle PHPUnit, including `development_plans_test`.
- Run the scheduled task once from Moodle task administration.
- Verify manual synchronisation for a manager and an active employee plan.
