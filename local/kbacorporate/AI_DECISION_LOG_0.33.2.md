# AI Decision Log v0.33.2

## ADR-0332-01 — One task entity, many projections

**Decision:** Kanban cards reuse `local_kbacorp_impl_task`; no second Kanban table is introduced.

**Reason:** a duplicate entity would require bidirectional synchronization and could create conflicting owners, dates and statuses. A projection guarantees that the task page, audit, plan, evidence workspace and board show the same record.

## ADR-0332-02 — No unrestricted drag-and-drop

**Decision:** status columns are read projections of the controlled lifecycle. Drag-and-drop between arbitrary columns is not implemented.

**Reason:** `v0.33.1` requires independent review of evidence. Directly dragging a task to «Выполнено» would bypass the evidence state machine and weaken auditability.

## ADR-0332-03 — Quick edit is limited to management metadata

**Decision:** the board may update only owner, due date and priority. Title, source criterion, current/target scores and evidence results stay in their authoritative workflows.

**Reason:** these three fields are operational planning metadata; editing immutable audit provenance from the board would corrupt traceability.

## ADR-0332-04 — Immutable Kanban audit events

**Decision:** extend the existing task history with `eventtype` and `detailsjson` rather than create a separate log table.

**Reason:** users need one chronological history for status transitions and operational changes. The additive fields preserve old rows with default `status` and avoid another audit-log relationship.

## ADR-0332-05 — Notification recipients

**Decision:** notify the new owner, previous owner, company managers with implementation capability, and program curator; exclude the actor.

**Reason:** assignment and deadline changes affect both delivery responsibility and oversight. Excluding the actor prevents redundant self-notifications.

## Rejected alternatives

- A separate Kanban-card table: rejected because of synchronization risk.
- Automatic status based on deadline or checklist percentage: rejected because time and checklist progress do not prove implementation.
- Automatic completion on course completion: rejected because learning evidence is not implementation evidence.
- Silent metadata updates: rejected because owner/deadline changes require traceability.

## ADR-0332-06 — Privacy-preserving history

**Decision:** privacy deletion replaces matching `ownerfrom` / `ownerto` IDs in Kanban JSON payloads with `0`, while preserving the operational event.

**Reason:** the organisation needs an audit trail that a reassignment occurred, but a deleted user must no longer remain identifiable through historical payloads.

## Known technical debt

- Board data enrichment currently performs per-task queries for comments and latest evidence; performance should be profiled with large portfolios before Stable.
- Bulk edit and saved filter views are deferred.
- Real-time collaborative updates are deferred; the current model is request/response with explicit refresh.
