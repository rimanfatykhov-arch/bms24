# KBA Corporate Dashboard 0.18.7 — Test Report

Build: `2026090222`

## Local gate

- XMLDB and incremental upgrade definitions are consistent.
- Both scheduled task and message provider are registered.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and ZIP integrity checks pass before delivery.

## Regression scope

- Completed actions are excluded from reminders.
- Overdue actions and actions due through the end of the seventh day are selected.
- Employees and authorised scoped managers are deduplicated.
- A plan is marked sent only when all intended notifications succeeded.
- The daily guard prevents repeat delivery on the same day.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Execute the reminder task manually from Scheduled tasks.
- Verify popup/email preferences and department-manager scope on the production stack.
