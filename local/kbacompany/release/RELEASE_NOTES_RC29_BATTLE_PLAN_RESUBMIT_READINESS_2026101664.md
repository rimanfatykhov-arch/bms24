# BMS24 1.1.0-rc29 — build 2026101664

## Battle Plan: submit / re-submit readiness

- Added a single-plan readiness panel to draft/submitted Battle Plans.
- Reuses the canonical `battle_plan_week_readiness_service::classify()` lifecycle contract.
- Shows concrete hard blockers before submit/re-submit: missing reviewer, invalid reviewer, or no plan tasks.
- Submit/self-approve actions are exposed only when the existing lifecycle transition is valid.
- Returned plans now move naturally from correction feedback to a visible “ready to submit” state once blockers are cleared.
- No DB schema, ACL/RBAC, privacy, lifecycle, or company-isolation changes.
