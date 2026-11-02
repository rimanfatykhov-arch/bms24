# BMS24 1.1.0-rc29 — build2026101702

## Management recurring-problem detection

Weekly Management Coordination now derives transparent recurring management signals from the same bounded 4/8-week canonical management history.

Rules are explicit and shown to the user:
- critical KPI exceptions in at least 3 of the latest 4 weeks;
- overdue tasks in both latest 2 weeks;
- open management tasks in both latest 2 weeks;
- data coverage below 80% in at least 3 of the latest 4 weeks with available data;
- indicators requiring a decision in both latest 2 weeks.

Each signal displays the exact factual rule and matched weeks. There is no composite score, employee ranking, diagnosis or causal claim.

## Architecture

- `battle_plan_week_coordination_service` contract: `0.5.0`.
- New packaged canonical doc: `docs/BMS24_MANAGEMENT_PATTERN_RULES.md`.
- No DDL.
- No pattern/history persistence.
- No new lifecycle/status/entity.
- No new write path.
- Existing Battle Plan / Task / Indicator ACL, RBAC and company isolation remain authoritative.

## Live acceptance

NOT RUN.
