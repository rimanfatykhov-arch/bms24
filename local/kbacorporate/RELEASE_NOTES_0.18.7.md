# KBA Corporate Dashboard 0.18.7

Build: `2026090222`

- Employees receive one daily digest for active IPR actions that are overdue or due within seven days.
- Corporate and department managers receive the same reminder only when existing access rules permit them to view the employee.
- Popup and email notifications include deadlines and a direct link to the plan.
- A per-plan timestamp prevents duplicate successful sends on the same day.
- Completed actions and inactive plans are excluded.

This release adds the nullable-safe integer field `lastremindersent` and upgrades directly from 0.18.6 or 0.18.4.1.
