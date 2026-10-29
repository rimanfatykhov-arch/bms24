## UI presentation contract — 0.4.6.9

`bms_ui_service` contract 0.4.6.9 separates the daily workspace from the dedicated Business Architecture Center. The theme consumes presentation data only; BMS Core remains the source of navigation routes, permissions, counters and object destinations.


## Financial Model bounded context — 0.6.1

`company_service` contract 0.6.1 keeps the v0.6.0 `financial_model` subcontract for versioned Financial Scenarios and Financial Responsibility Centers and adds `financial_statements`. Responsibility centres still reference canonical BMS Company/OFS/Position/Product targets rather than duplicating organization structure. `FinancialStatement` revisions pin a published Scenario and optional responsibility-centre revision. v0.6.1 owns planned BDR, BDDS and forecast balance only; accounting facts, bank facts and payment execution remain outside the contract.

# Company Core Contract 0.4.6

## Product boundary

Customer product: **BMS — Business Management System**. `local_kbacompany` is the company-scoped management-model core. KBA Theme is optional presentation only.

## Unified daily-work contract

- Canonical BMS tasks remain owned by `task_service`.
- Legacy KBACorporate workflow tasks remain owned by the legacy workflow and are exposed read-only.
- KBA Element learning work remains owned by Moodle/KBA Element and is exposed only from explicitly company-bound courses.
- `task_provider_service` aggregates projections without copying provider-owned work.
- Battle Plan may reference `(provider, external_id)` instead of requiring a local Task UUID.
- `communication_service` owns participant-scoped threads/messages/mentions/read state/notifications.
- `communication_center_service` combines Inbox, Messages, Tasks, Discussions and Notifications while keeping object lifecycles separate.

## Privacy boundary

Private communication content requires explicit active participation in the thread. Owner/manager/task-management roles do not automatically reveal private message content. KBA Element projections do not copy submissions, grades, learner files or evidence.

## App Shell boundary

- BMS Core owns navigation meaning, routes, permissions, counters and data.
- `bms_ui_service` supplies a renderer-neutral presentation model.
- KBA Theme owns App Shell visual rendering and reusable UI components.
- BMS selects pagelayout before using `$OUTPUT`; the 0.4.5 bootstrap ordering fix is retained.
- BMS must remain functional under standard Moodle layout when KBA Theme enhancement is absent.

## Upgrade boundary

The 0.4.6 package is safe over the App Shell line (`0.4.3–0.4.5`) and over internal communication prototypes. Missing communication/provider structures are created idempotently through rebased upgrade steps `2026091337` and `2026091338`.

## UI entry-point rule (0.4.6.6)

`company.php` is a compatibility URL only. The canonical customer-facing company entry point is `bms_workspace.php`. Business sections must return to the canonical workspace rather than recreate a second company navigation surface.


## UI presentation contract — 0.4.6.7

Ordinary BMS business pages use a shared presentation adapter. BMS Core owns the page body, route, permissions and navigation model. KBA Theme owns the App Shell visual composition. A non-KBA theme must still render the same controller through normal Moodle layout.

## Business Architecture presentation contract — 0.4.6.8

The Business Architecture Center must present objects in BMS methodological order, not in technical route order. `bms_ui_service` owns the sequence, grouping, links and real counters. KBA Theme owns only the visual representation of that model.



## Guided Business Architecture contract — 0.4.6.9

`architecture_model()` accepts optional stage/focus semantics and returns `architecture_readiness`, `architecture_selected`, `architecture_stage_workspace` and `architecture_recommended`. Readiness is derived from existing BMS objects and is guidance only, not a maturity score or compliance decision.

## OFS Visual Designer contract — 0.4.6.11

`ofs_visual_designer_service` projects versioned organization designs into the agreed BMS OFS form. The projection may read canonical product records as fallback, but it must not persist a duplicate OFS store. The bottom company result is named **Valuable Final Product / ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ**. Export providers must use the same visual model and preserve BMS UUID provenance where the format supports metadata.


## OFS Export Engine contract — 0.4.6.12

`ofs_export_service` consumes the same `ofs_visual_designer_service::poster()` projection as the web designer. It is delivery-only and never becomes a business-data source. Supported outputs are standalone SVG, vector PDF (A0-A4 or Auto) and editable Draw.io `.drawio`. Draw.io cells include BMS object UUID, source UUID, visual tier and design revision metadata. Editing an exported Draw.io file does not update BMS automatically.

## OFS Template Library contract — 0.4.6.13
- Template catalogue is owned by BMS Core.
- Template selection creates a normal draft OrgDesign project.
- Industry templates are suggestions, not mandatory organizational standards.
- The original methodology library is read-only source material and is not a runtime dependency.
- KBA Theme renders only the supplied catalogue/preview model.

## Legacy Draw.io Import contract — 0.4.6.14
- Source diagrams are read-only inputs; importer never modifies external source files.
- Automatic recognition is advisory and requires human confirmation.
- Confirmed mapping creates a company-scoped normalized template **DRAFT**, never a company OrgDesign project directly.
- Template publication is explicit: `DRAFT → REVIEWED → AVAILABLE`; only `AVAILABLE` templates can be cloned into independent OrgDesign drafts.
- Imported template payloads are immutable revisions with source checksum and provenance; Draw.io never becomes the source of truth.
- Plain and compressed diagrams.net XML are supported up to 2 MiB.
- Import snapshots are user/company scoped and expire after one hour.

## Meetings & Decisions contract — 0.4.7

- `Meeting`, `Discussion`, `Decision` and `Task` are separate BMS objects.
- Meeting visibility is explicit-participant scoped; company management authority alone does not reveal a meeting.
- A meeting owns a dedicated object discussion thread and may preserve a source discussion reference.
- Agenda items start from an expected product/result, consistent with the BMS product-first methodology.
- Decisions are proposed first and become execution sources only after explicit acceptance.
- Accepted decisions may generate canonical Task Center tasks with `sourcekind=decision`, `sourceuuid=<decision uuid>` and pinned decision revision.
- Closing a meeting requires minutes and no unresolved agenda item or proposed decision.


## Administrative Cycle contract — 0.4.8

- Customer-facing management flow: `Strategy/BSC → Goal → Policy → Plan → Program → Project → Meeting → Decision → Task → Battle Plan → Result`.
- Goal/Policy/Plan/Program/Project reuse the versioned Administrative Scale storage; Decision and Task are never duplicated into that storage by the new cycle UI.
- A project-scoped Meeting pins an exact published Project UUID + revision. Historic meetings never repoint silently when the project changes.
- Meeting privacy remains explicit-participant scoped; the Administrative Cycle is not a manager-role privacy bypass.
- Canonical Tasks preserve `sourcekind=decision`; the project link is obtained through Decision → Meeting → pinned Project revision.
- Battle Plan remains a reference projection over tasks and never becomes the execution source of truth.
- Legacy Administrative Cascade and pre-0.4.8 Administrative Scale decision/task data remain non-destructively available for compatibility only.


## Recurring Task contract — 0.4.9

- RecurringTaskDefinition is a source definition, not a Task execution fact.
- Each due occurrence is created in the canonical Task Center and pins the exact definition revision.
- At most one occurrence is generated for a recurrence series, period date and assignee.
- Library templates create DRAFT definitions only; activation requires expected product, assignee, start date and supported cadence.
- Google Drive is provenance/source material only and is not a runtime dependency.

## Owner LPF / Business Model contract — 0.4.10

- Private LPF content belongs to the owner contour and is visible only to the owning company owner or site administrator.
- Management authority alone never reveals owner-private LPF content.
- Only explicitly approved `REQ-xx` requirements are projected into Business Architecture.
- Published OwnerFrame revisions are immutable; edit creates a new draft revision.
- Business Model Canvas 2.0 revisions pin the exact published OwnerFrame requirement revision used as input.
- BMC keeps `as-is` separate from `target` and distinguishes FACT / DATA / HYP.
- GAP is a state difference, not a project or prescribed solution.
- Delete is an audited archive revision; referenced historical revisions remain available for traceability.
- Google Drive source documents are methodology provenance only and are never runtime master data.


## Resource Gate contract — 0.6.3

- ResourceGate is company-scoped and pins an exact published Goal/Program/Project revision.
- Every submitted gate covers money, people, competencies, time, technology, information and infrastructure.
- Required checks can become Ready only as `pass` or explicit `waived` with evidence; `pending`/`block` prevents Ready.
- Money reuses approved Budget revisions; people reuse Position/Staffing; competencies reuse the read-only Business Role competency projection; information can reuse controlled documents.
- Resource Gate does not duplicate resource master data and cannot mutate staffing, employee memberships, competency assessments, learning, payments, procurement, banking or ERP records.
- Append-only revisions preserve decisions. Draft/submitted amendments do not replace an effective Ready; a later Blocked decision does.
