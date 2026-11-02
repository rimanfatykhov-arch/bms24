# 0.5.14 — Management Week Batch Transition

Bundled cumulatively into v0.5.15.

- User explicitly selects Battle Plans on the readiness screen.
- `submit` and `approve` are processed independently.
- Every plan passes through `battle_plan_service::transition()`; there is no direct bulk status update.
- Already-transitioned plans are skipped on repeated submission.
- No background automation is introduced.
