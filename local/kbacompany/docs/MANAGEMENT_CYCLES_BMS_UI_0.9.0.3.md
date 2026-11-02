# BMS-UX-CRUD-001 application — Management Cycles 0.9.0.3

Canonical domain remains management_cycle_template_service + management_cycle_rollover_service.
No new DB tables or UI-owned permissions. New management_cycle_ui_service contains field schema
and rendering only; the existing route dispatches read/create/draft-edit/confirmed actions.
KBA Theme is not edited and does not own domain routes. New core event uses Moodle log storage.

## Source basis

User FireShot Capture 090 (one-page screenshot): inline create form occupies list entry page,
first member is implicitly chosen as owner and curator, period/role purpose is unclear.
BMS-UX-CRUD-001 v0.3: ListView → DetailView → EditView; domain-owned schemas; secondary Actions;
company-scoped pickers/search; field validation that preserves input; meaningful Russian labels.
BMS-MASTER-001 v1.5.2 baseline: 0.9.0.2, shared runtime and canonical engines preserved.

## Route compatibility

management_cycles.php?uuid=COMPANY stays the ListView.
management_cycles.php?uuid=COMPANY&cycle=CYCLE stays the canonical record.
New view=create (template choice/form), view=edit (draft), view=action (confirmation).
Tabs are GET navigation, not mutation. Existing submitted action names remain handled through
POST/sesskey with stronger explicit confirmation and form freshness. Old forms must be reloaded.
All validation is also enforced by the domain writer; renderer hiding does not authorize writes.

## State matrix

Draft: permitted creator/owner/curator/site admin may edit; owner/site admin may start/cancel.
Active: parameters locked; owner/site admin may close/cancel; owner/curator with management
permission may create/open checkpoint meetings or complete checkpoints. Meeting content remains
participant-scoped in the meeting domain. Active/closed may create next or supported child period.
Closed/cancelled: no direct parameter edit or implicit reopen. Review history is not rewritten.
Actions always require existing Company access and can_manage_management; no capability grants.

## Read scopes / bounds

List: COUNT and row SELECT share company + filter predicate; 25 rows per page.
Header summary: current company, all statuses. Pickers: current Company, eligible record fields only.
Detail: selected Company + UUID, max50 child rows with full count; no all-company projection needed.
History: authorized company-scoped mcycle record resolved first; exact object ID/table/event/component
passed to core SQL reader, limit50 before retrieval; no fetch-global-and-filter-PHP.
Battle plans: existing company-wide management permission is the same owner/manager capability gate
as can_manage_tasks; list is additionally scoped to cycle owner and plan periodstart inside cycle.
Rollover: explicit source UUID references, no task or plan cloning. Existing next-date policy retained.

## Persistence safety

Company lock serializes direct cadence writes. Create/edit/transition/checkpoint writes are delegated
transactions. Request key/hash in existing statepayload supports explicit create retry; row SHA256
optimistic token detects stale draft edits. It is not an authentication credential. Outer rollover
transaction includes created cycle, checkpoints and provenance update. Only synthetic sequential
retries/faults were exercised; actual parallel Moodle drivers/locks remain a live acceptance gate.
Core logs record operation/actor/field names from this release; no field-value snapshots and no backfill.

## Deferred deliberately

No new task/indicator/project/meeting/learning/AI engine. No new automatic scheduler, Odoo transport,
new schema, stable-code allocator, active-cycle revision workflow or historical audit reconstruction.
No false claim that completing checkpoints proves causal business effect. Program/project execution
remains in the project card delivered by 0.9.0.2.
