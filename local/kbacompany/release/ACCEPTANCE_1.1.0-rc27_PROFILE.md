# Acceptance — Profile editing in BMS24 (build2026101459)

| Scenario | Status |
|---|---|
| Self profile editable in BMS24 layout without manageorganization | NOT RUN |
| Own first / last name / phone persist | NOT RUN |
| CSRF POST without sesskey rejected | NOT RUN |
| Other user id denied | NOT RUN |
| HR edit for other employees remains manager-only | NOT RUN |
| Other-company context denied | NOT RUN |
| Existing employee work calendar remains working | NOT RUN |
| PHP syntax and unique savepoint | PASS (static) |

Note: User's `moodle/user:editownprofile` capability must be available. No permission bypass or identity auto-merge.
