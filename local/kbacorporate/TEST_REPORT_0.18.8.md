# KBA Corporate Dashboard 0.18.8 — Test Report

Build: `2026090223`

## Local gate

- XMLDB and incremental upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks pass.
- Privacy metadata includes the follow-up audit link.
- Release ZIP integrity and root directory are verified before delivery.

## Regression scope

- A follow-up audit is allowed only for a completed plan with actions.
- Only competencies stored in the completed plan are copied.
- Moodle current levels and evidence remain the authoritative collected sources.
- Repeated creation returns the existing linked audit.
- Deleting the linked audit resets the plan link but does not delete Moodle evidence.
- The end-to-end test covers plan agreement, course completion sync, plan completion and follow-up audit creation.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Upgrade a production database copy and verify the additive `followupauditid` field.
- Complete an IPR and create/confirm its follow-up audit through the production UI.
