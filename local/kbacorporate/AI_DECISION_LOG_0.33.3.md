# AI Decision Log v0.33.3

## ADR-0333-01 — Confirmed audit remains immutable

**Decision:** section maturity is calculated only from the selected confirmed audit; task completion and accepted evidence never rewrite its scores.

**Reason:** audit results must remain historically reproducible. The effect of implementation is confirmed by a later audit, not by operational task state.

## ADR-0333-02 — Separate maturity, coverage and execution

**Decision:** expose three different measures:

- maturity — score from the audit;
- action coverage — share of gap criteria with at least one non-cancelled action;
- execution — weighted progress of actions by workflow status.

**Reason:** creating an action is not the same as executing it, and executing it is not the same as proving higher BMS maturity.

## ADR-0333-03 — Read-only analytical projection

**Decision:** use existing audit, task and evidence tables; do not create a section-progress table.

**Reason:** all required facts already have authoritative sources. Persisting a second derived state would introduce staleness and synchronization risk.

## ADR-0333-04 — Latest evidence version per action

**Decision:** evidence status is derived from the latest version of each action's evidence.

**Reason:** the current review state is operationally relevant, while previous versions remain available in the evidence history.

## ADR-0333-05 — Cancelled actions do not cover gaps

**Decision:** cancelled actions are visible in source history but excluded from action coverage and execution progress.

**Reason:** an abandoned action cannot be treated as an active response to a criterion gap.

## ADR-0333-06 — Status priority

**Decision:** overdue risk takes precedence over coverage/completion labels; unassessed sections never receive “target achieved”.

**Reason:** the dashboard should surface the condition requiring management attention and must not infer success from missing assessment data.

## ADR-0333-07 — Previous audit comparison

**Decision:** compare only with the immediately preceding confirmed audit of the same company and BMS tool.

**Reason:** section codes and scoring are comparable only within the same tool lineage; draft audits must not affect historical dynamics.

## Rejected alternatives

- Recalculate BMS Index from completed actions: rejected because actions are not maturity assessments.
- Store daily section snapshots: deferred until a dedicated analytics warehouse is justified.
- Count every task as separate criterion coverage: rejected because coverage is criterion-based.
- Treat missing scores as zero: rejected because unknown evidence is not confirmed absence of maturity.

## Known technical debt

- The latest-evidence query reads all evidence versions for selected tasks before retaining the latest in PHP; optimize with a database-specific-neutral strategy if portfolios become large.
- Default audit selection relies on programme baseline metadata and should be profiled where multiple historical programmes reference the same audit.
- Runtime performance with many companies, audits and actions has not yet been benchmarked.
