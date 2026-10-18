# KBA Corporate Dashboard 0.18.6

Build: `2026090221`

- Active IPR actions linked to a Moodle course are completed when Moodle records that course as completed for the employee.
- Synchronisation runs hourly and can also be started for one active plan by an authorised manager.
- The original Moodle completion timestamp is retained in the action.
- Closing the last action completes the plan through the existing workflow.
- Course completion never changes the confirmed competency level; competency promotion still requires audit confirmation and Moodle evidence.

This release has no database schema changes and upgrades directly from 0.18.5 or 0.18.4.1.
