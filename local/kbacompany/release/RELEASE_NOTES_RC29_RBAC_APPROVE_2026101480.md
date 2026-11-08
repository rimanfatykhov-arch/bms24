# RC29 build2026101480 — restricted department plan approval

Adds `battle_plan:approve` with `department` scope only. Grant is effective **only** when actor actually holds a manager assignment for the same organizational unit as plan owner; neither UI role title nor grant by itself is sufficient. Checked server-side for both `battle_plan_service::review()` (approve and return for corrections) and `transition(..., approve)`; existing self-approval/own reviewer continues unchanged. Grant does not grant plan edit, close, selfapprove, or company management. Existing management-wide grants remain backwards compatible.

Task approval is intentionally NOT implemented: canonical tasks have distinct completion/control semantics that require separate design. UI may not yet surface approve controls on all plan pages; live Moodle, full review workflow and negative cross-company checks required. No new DB tables. FINAL NOT READY.
