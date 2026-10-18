# KBA Corporate Dashboard 0.26.2

This release adds active deadline control to BMS implementation tasks.

- The daily scheduled task checks planned, in-progress and review tasks.
- The task owner receives one reminder before the deadline.
- An overdue task is escalated to the owner, program curator and authorised company managers.
- The same reminder is not sent repeatedly for an unchanged deadline.
- Changing a deadline automatically enables a new reminder cycle.
- Administrators can enable the mechanism and configure a 1–30 day advance window.
- “My BMS tasks” now includes an upcoming-deadline counter and filter.

After installation, complete the Moodle database upgrade, purge all caches and ensure Moodle cron runs normally.

Recommended acceptance path: set the reminder window to three days, create one task due tomorrow and one task due yesterday, run the scheduled task manually, then verify the recipient sets and direct links in Moodle notifications.
