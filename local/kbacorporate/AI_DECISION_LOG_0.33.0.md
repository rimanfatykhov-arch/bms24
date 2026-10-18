# AI DECISION LOG — v0.33.0

## ADR-0330-01 — Reuse managed implementation tasks

Decision: use `local_kbacorp_impl_task` as the action entity instead of adding a new action table.

Reason: the component already has ownership, status workflow, deadlines, evidence files, checklist, discussion and notification mechanisms. A second store would create conflicting semantics.

## ADR-0330-02 — Confirmed audit is the only creation source

Decision: actions may be created only from a confirmed audit item with a known positive gap.

Reason: draft and unassessed values are not reliable management commitments. Unknown is not zero.

## ADR-0330-03 — Explicit selection before writes

Decision: the builder is preview-first and selects nothing implicitly. A user must select criteria and submit with a valid session key.

Reason: prevent accidental mass creation and keep the workflow auditable.

## ADR-0330-04 — Non-destructive idempotency

Decision: if any task already exists for the audit item, the builder reports it and does not create or overwrite another action.

Reason: protect existing owner, deadline, comments, evidence and workflow history.

## ADR-0330-05 — Priority-based default deadlines

Decision: critical/core/additional actions receive 30/60/90-day default windows.

Reason: provide a usable baseline while preserving manual editing after creation.

## ADR-0330-06 — Route is optional

Decision: a positive gap can create a basic action even when no BMS tool/course route is configured.

Reason: lack of methodology mapping must not prevent the company from assigning responsibility for an identified management gap. The missing route remains visible for later enrichment.

## ADR-0330-07 — Commercial programme gate remains in force

Decision: creation requires an active paid implementation programme; preview remains available.

Reason: preserve the existing product boundary and avoid bypassing programme activation through a new page.

## Rejected alternatives

- Create actions automatically when an audit is confirmed — rejected because selection, ownership and timing require an explicit decision.
- Create one action for every configured route — rejected because it can multiply tasks for a single criterion without user intent.
- Update existing tasks on every builder run — rejected because it would overwrite operational decisions and history.
- Treat unassessed criteria as maximum gap — rejected as methodologically unreliable.
