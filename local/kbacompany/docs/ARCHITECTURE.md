## BMS Workspace navigation v2 (0.4.6.4)

The daily workspace and the architecture navigator are now separate projections of the same BMS model. `bms_workspace.php` is action-oriented and does not render the architecture tree. `bms_architecture.php` is the dedicated Business Studio-like navigator using the existing KBA Theme tree/split-view presentation components. `bms_ui_service` owns route membership, group assignment, counters and active state.

# BMS architecture

## v0.4.6 — Unified Communication & Task Center + App Shell

Daily work is a projection across distinct source-of-truth domains; the App Shell is presentation only.

```text
BMS Workspace / Communication Center
        │
        ├─ Communication Core
        │    ├─ Direct / Group / Object Threads
        │    ├─ Messages / Mentions / Read State
        │    └─ Notifications
        │
        └─ TaskProviderService
             ├─ BMS Task        → canonical execution object
             ├─ Legacy workflow → read-only KBACorporate projection
             └─ KBA Element     → read-only learning/completion projection

Battle Plan ──references──► provider + external_id
```

`bms_ui_service` owns route/navigation/counter models but does not render theme HTML. `bms_workspace.php` selects pagelayout before first `$OUTPUT` use, preserving the 0.4.5 bootstrap fix. KBA Theme may render the App Shell, but cannot decide permissions, business routes or task-source semantics.

Communication privacy is structural: explicit thread participation is required to read private content. Task authority does not imply private-message access. Message → Task is an explicit conversion that creates a separate canonical BMS Task and preserves source-message traceability.

Task-provider aggregation does not transfer ownership. `local_kbacompany_tasksrc` stores explicit company bindings for external task sources such as Moodle courses. KBA Element remains learning source of truth; BMS reads activity metadata and standard completion only.

Upgrade from the App Shell line is rebased above build `2026091336`: communication tables are created at `2026091337`, provider/Battle Plan references at `2026091338`.

## v0.4.2.2 — Unified Task & Communication Center

The daily-work surface is a projection, not a new ownership silo.

```text
Communication Center
  ├─ Inbox / Messages / Discussions / Notifications → Communication Core
  └─ Tasks → TaskProviderService
              ├─ BMS provider      → canonical BMS Task
              ├─ Legacy provider   → KBACorporate workflow task (read-only)
              └─ KBA Element       → bound Moodle learning activity/completion (read-only)

Battle Plan ──references──► provider + external_id
```

`local_kbacompany_tasksrc` stores explicit company/provider source bindings. For KBA Element the source key is `course:<id>`. Binding never transfers course ownership to BMS. Source discovery is constrained by the binding actor's Moodle course visibility. KBA Element projections expose task metadata and completion only; submissions, grades and evidence stay with the learning subsystem.

The theme remains presentation-only. BMS owns available tabs, source labels, permissions, counts and routes; KBA Theme may render these through the separately maintained UI contract.

# BMS architecture

## v0.4.2.1 — Communication Core

Communication is a context layer around BMS work objects, not a replacement for those objects.

```text
Direct / Group / Object discussion
              │
              ▼
     Communication Thread
              │
      ┌───────┼────────┐
      ▼       ▼        ▼
   Message  Mention  Notification
      │
      └── explicit conversion ──► Canonical Task
                                  (source = message UUID)
```

The core stores `CommunicationThread`, explicit participants, append-only `CommunicationMessage`, per-user read state, mentions, notifications and attachment references. An object thread can preserve a context type, external/canonical object identifier and revision. The supported context vocabulary already reserves Task, Project, Process, Document, Goal, Statistic, KBA Element, Improvement and Meeting so later providers do not require a second communication model.

Privacy is structural: thread content is visible only to active participants. Company owner/manager permissions can administer communication features, but do not automatically expose a private thread. A message, notification and task remain different business objects with different lifecycle semantics. Message → Task creates a canonical Task and records `sourcekind=communication`, preserving the originating message UUID.

The current release intentionally does not aggregate KBA Element, legacy KBACorporate or Bitrix24 tasks/messages yet. Those adapters belong to v0.4.2.2 Unified Task & Communication Center. Meetings and formal Decisions remain v0.4.2.3.

## v0.4.2 — Task Center & Battle Plan

Execution is now separated into a canonical Task Center and a weekly planning projection.

```text
Accepted Meeting Decision ─┐
Legacy Administrative Scale task ─┤
Position Work Program ────────────┼──► Canonical Task Center ──► execution/result/history
Manual / current work ────────────┤             │
Learning provider work ───────────┘             └──► Weekly Battle Plan reference
```

A Task is the execution object. It stores action/title, expected product, assignee, optional result reviewer, priority, deadline, planned/actual time, source/revision, execution state, result, checklist, parent task and auditable task history. Generated work pins the exact published source revision. A later archived source is never silently resurrected. Task parent cycles and stale optimistic revisions are rejected.

The Battle Plan is one employee + one current Position + one Monday–Sunday period. It contains references to canonical/provider task identities grouped into five streams: **Change** (including tasks from accepted Administrative Cycle decisions), **Run** (Position Work Program), **Operate** (current role work), **Develop** (learning/development) and **Unplanned**. A plan is reviewed through `draft → submitted → active → closed`; closing requires all referenced tasks to be done or cancelled. KPI/statistic definitions are displayed as position context, while actual observations and trend graphs remain the responsibility of v0.5 Information Center.

Legacy `wfwork` execution data remains untouched and visible through the previous Execution workspace. No implicit legacy migration is performed because previous workflow semantics are not guaranteed to map losslessly to the new product-first Task model.

## v0.4.1 — Administrative Scale

The versioned Administrative Scale remains the compatibility/storage foundation for Goal → Policy → Plan → Program → Project. Starting with v0.4.8, the customer-facing management surface is the **Administrative Cycle**, which projects canonical Meetings, Decisions, Tasks, Battle Plans and Results instead of storing duplicate decision/task definitions. The employee Battle Plan remains a separate weekly execution projection.

```text
Published BSC Strategic Goal
          ↓
Administrative Goal
          ↓
Controlled Organizational Policy
          ↓
Plan
          ↓
Program
          ↓
Project
          ↓
Decision / Order
          ↓
Task
```

`local_kbacompany_adminitem` stores versioned scale items. A child stores the exact published parent revision that justified it, so a task can always answer “why does this exist?”. A root Administrative Goal pins a published BSC Strategic Goal. An Administrative Policy pins a published policy from the ISO/governance layer. A Decision may pin a published management directive. Project/Task results can additionally reuse canonical Product/CKP, Metric and Position references. Publication rejects stale dependencies. The scale defines work and its origin; actual task execution, plan/fact and weekly Battle Plan state are deliberately deferred to v0.4.2.

The pre-v0.3 legacy `acobj` administrative cascade is retained for compatibility and is not silently converted.

## v0.4.0 — Ownership / Strategy / BSC

Strategy is a design layer between owner intent and the later Administrative Scale. It does not duplicate operational execution objects.

```text
Ownership Intent / ownership purpose
              ↓
       Long-term goal
              ↓
           Strategy
              ↓
     Balanced Scorecard
 Finance | Customer | Process | People/Development
              ↓
   Strategic KPI / Process / Accountable Position
              ↓
      Strategic causal map
```

Ownership Intent, Strategy, Strategic Goal and causal link are versioned `draft → published → archived` design objects. Publication enforces published parent dependencies. A Strategy may explicitly reference a published Business Model. BSC goal KPI references reuse `local_kbacompany_stat`; process and position references reuse canonical BMS objects. Causal links are checked as an acyclic directed graph. This release creates no programs, projects or tasks; those belong to v0.4.1 Administrative Scale.

## v0.3.4 — Position Operating System

The Position Operating System is a projection over existing BMS truth, not a second copy of it.

```text
Position + CKP + published Functions
             ↓
    Generated Job Description
             ↓
     Position Work Program
             ↓
       Digital Job Folder
```

A Work Program item pins the exact published Function revision that justified the action and product. Published work items are immutable history; source drift is visible. The job folder only surfaces effective controlled documents and exact effective STP snapshots relevant to source processes.

## v0.3.3 — ISO Document Control

The controlled-document layer is a cross-cutting management-system service over the existing BMS semantic model.

```text
Business source model
    ↓
Controlled Document draft
    ↓
Review → Agreed → Approved → Published → Effective
                                      ↓
                           Acknowledgements
                                      ↓
                      Records / evidence (immutable)
```

### Document ≠ Record

`local_kbacompany_doc` stores versioned controlled document revisions. `local_kbacompany_record` stores immutable evidence created from an exact effective controlled Form revision. Updating a Form never rewrites historical records.

### STP control

SIPOC + BPMN remain stored in Process Model. `stp_service` remains a generated projection. When an STP is taken under document control, BMS snapshots the published projection as JSON, stores the exact Process Model revision and SHA-256 source fingerprint, then manages that snapshot through the controlled-document lifecycle. The snapshot is evidence of what was released; it does not replace Process Model as source of truth.

### Master List

Master List is computed from controlled document objects. Managers see both the currently effective revision and any working revision. Ordinary users see only effective revisions. Superseded/cancelled content cannot silently become current.

### Semantic graph

Only an **effective** controlled document can be used as a canonical `document` endpoint in Semantic Core relations.

### Lifecycle

`draft → review → agreed → approved → published → effective → superseded → archived`

A non-effective revision can also be `cancelled`. Rejected review is returned to a new review round while previous review evidence remains history.

## Presentation boundary — 0.4.3

`local_kbacompany` now exposes a BMS-owned UI model through `bms_ui_service`.
The service owns routes, labels, counters, visibility and business data. When compatible
KBA Theme 2.3.25+ is active, `bms_workspace.php` renders that model through the optional
`theme_kba/bms/app_shell` presentation contract and the `bms` layout. If the theme is
not active/compatible, the same controller renders a semantic Moodle fallback.

The theme is therefore an enhancement, not a runtime dependency of BMS Core.

## 0.4.6.1 migration hotfix

Battle Plan migration ordering follows Moodle XMLDB dependency rules: dependent legacy index is removed before field default mutation, then provider-aware uniqueness is restored.


## Shared BMS Page Shell Adapter — 0.4.6.7

`bms_page_shell_service` is a presentation adapter, not a controller. It selects the optional `bms` pagelayout before output, buffers the page-owned semantic body, and places that body into the KBA Theme App Shell. `bms_ui_service::shell_model()` supplies BMS-owned navigation, counters, routes and company context. Non-KBA themes continue through the standard Moodle header/footer path.

## Business Architecture semantic order — 0.4.6.8

Business Architecture is a methodological projection of the same BMS objects, not a separate database. `bms_ui_service::architecture_semantics()` defines one source for both the design sequence and the grouped navigator.

```text
Owner intent / Strategy / BSC
        ↓
Market / Business Model
        ↓
OFS / CKP / Organization
        ↓
Process Architecture
        ↓
SIPOC + BPMN → STP
        ↓
Functions / Function Products
        ↓
Positions / Roles
        ↓
Documents / Metrics / Improvement
```

The architecture navigator is grouped into four working layers: Management foundation, Company architecture, Operating model, and Management/regulations. Page routes remain implementation details beneath this semantic order.


## Guided Business Architecture workspace — 0.4.6.9

The Architecture Center uses the stable eight-stage sequence as a guidance layer. Readiness is calculated from existing canonical BMS objects only; no duplicate architecture state is stored. The first incomplete stage becomes the recommended next design step. Selecting a stage keeps the user in the Architecture Center and exposes module links for actual editing.

## OFS Visual Designer (0.4.6.11)

The OFS visual is a projection, not a second organization model. `ofs_visual_designer_service` combines a versioned `OrgDesignVersion` payload with canonical Product-registry fallback data and produces one poster model used by the web designer and planned exporters.

Draft-only visual metadata is versioned with the design revision:

- `node.visualtier` — presentation tier in the agreed OFS form;
- `node.product` — designed product/CKP for the block;
- `companyproduct` — the company Valuable Final Product shown as the final bottom result.

The universal visual tiers are `investment`, `strategic_office`, `strategic_function`, `management`, `management_unit`, `executive`, `department`, and `unit`. Live/applied nodes without explicit visual metadata are projected heuristically from the organization hierarchy.

The next export layer must consume the same poster model for SVG, PDF and Draw.io. Draw.io remains an export representation, never the BMS source of truth.


## OFS Export Engine (0.4.6.12)

The visual OFS model has a single projection and layout pipeline:

`OrgDesignVersion + Product/CKP -> OFS poster -> export layout -> SVG / PDF / Draw.io`.

SVG is the canonical rendering layer for exported visuals. PDF embeds the same SVG as vector content. Draw.io uses the same node coordinates and preserves BMS UUID/revision metadata. Export artefacts are not writable sources for BMS.

### OFS Organization Template Library
Industry templates are presentation/design seeds on top of the existing OrgDesignVersion model. They are code-owned normalized payloads and never become a second organization database. Selecting a template creates a normal draft design; subsequent edits, approvals, exports and applies follow the same versioned OrgDesign lifecycle. The original methodology library in Google Drive is treated as read-only source material and is not a runtime dependency.

## Meetings & Decisions (0.4.7)

The management communication/execution chain is now explicit:

```text
Discussion
   ↓
Meeting
   ↓
Agenda item + expected product
   ↓
Decision (proposed → accepted / rejected)
   ↓
Canonical BMS Task
   ↓
Result / review
```

Meeting participation is explicit and participant-scoped. Each meeting has its own `meeting` context communication thread and may point back to a source discussion. Decisions are immutable in content after proposal in this release; governance changes their status rather than silently rewriting the statement. Task Center remains the execution source of truth.


## v0.4.8 — Administrative Cycle

The Administrative Cycle is a cross-domain traceability projection, not a replacement source of truth:

```text
Strategy / BSC
      ↓
Goal → Policy → Plan → Program → Project
                               ↓
                    Project-scoped Meeting
                               ↓
                    Accepted Decision
                               ↓
                    Canonical Task
                               ↓
                 Weekly Battle Plan reference
                               ↓
                      Recorded Result
                               ↺
                 review / next system version
```

A project-scoped Meeting pins `sourcekind=admin_project`, the exact Project UUID and published revision. That source pin is immutable after meeting creation. Decision remains owned by Meetings & Decisions; Task remains owned by Task Center; Battle Plan references the Task rather than copying it. The Administrative Cycle may only show meetings available under explicit participant visibility and may only expose Battle Plan references under the existing Battle Plan access policy. Pre-0.4.8 Administrative Scale decision/task definitions remain compatibility data and are not silently migrated or deleted.


## v0.4.9 — Recurring Tasks

Recurring work is modeled as a versioned definition, not by mutating a completed Task back to OPEN. `RecurringTaskDefinition` revisions are immutable records in `local_kbacompany_taskrec`. An ACTIVE latest revision may generate one canonical Task occurrence per `series UUID + period date + assignee`; each occurrence pins the exact recurrence revision in Task Center source fields. Editing or pausing the series creates a new immutable revision and never rewrites already-generated tasks.

The built-in template library is a methodology snapshot. Google Drive provenance is stored in code metadata only; runtime generation never reads Drive. Choosing a template creates a DRAFT recurring definition. Because the source material does not define a BMS expected product or company assignee, activation requires explicit human confirmation of those fields.

## Owner LPF → Business Model Canvas 2.0 (0.4.10)

The owner-private and corporate architecture domains are intentionally separated:

```text
OWNER PRIVATE CONTOUR
LPF 1/3/5 → professional/financial goals → ownership goals
→ owner role / reserved decisions / boundaries
→ explicitly approved organizational REQ
                         ↓ only REQ crosses boundary
CORPORATE ARCHITECTURE
REQ → BMC as-is (FACT/DATA/HYP) → BMC target
→ GAP → later ACT / DEC / HYP / PRJ / PROGRAM / BAU → KPI
```

`OwnerFrame` is company-scoped and owner-scoped. Managers cannot read the private payload by management authority alone. Published OwnerFrame revisions are immutable. Business Model revisions store the stable OwnerFrame UUID plus the exact published revision; historical pins remain resolvable even after newer owner revisions or later archival.

The BMC 2.0 model has nine blocks: target customer groups, value proposition, customer interaction, channels, key activities, key resources, partners/suppliers, revenue structure and cost structure. Each block keeps current state and target state distinct. A GAP is a difference between states and is not silently converted into a project.


## v0.5.2 — Trend Analytics and Derived Indicator Events

Trend analytics is a read-model over the same canonical `local_kbacompany_statval` history. `indicator_trend_service` classifies the form of recent factual movement separately from its direction-aware management assessment. It uses the last three factual observations and exact increase/decrease/equality comparisons; no hidden percentage corridor is applied.

`indicator_event_service` derives reversal, new maximum, new minimum, outside-norm and back-within-norm events on read. Norm-transition events are binding-scoped because quotas belong to `statbind/statnorm`, not to the indicator definition itself. No trend/event table is introduced and no analytical rows are written to `statval`. The future management-condition engine remains a separate layer.

## v0.5.0 — Indicator Registry and Trees

BMS uses one canonical indicator definition (`local_kbacompany_stat`) and append-only observations (`local_kbacompany_statval`). Strategy, OFS, positions and later Battle Plans do not own copies of an indicator.

Projection model:

`BSC / Strategic Goal -> Indicator <- OFS Unit / Position`

The same indicator can have many versioned bindings. A binding may carry primary/critical flags, weight and display order. Norms/quotas are versioned separately from the indicator definition and from observations, so a quota change does not rewrite historical Battle Plans or facts.

Compatibility relations from earlier releases (`StrategicGoal.metricuuid` and product target links) are projected read-only until explicitly replaced by new bindings. No destructive migration is performed.

## v0.5.3 — Indicator Conditions → Management Actions

The condition layer is a binding-scoped read model over the canonical indicator chain:

`stat -> statbind -> statnorm/statpolicy -> statval history -> trend/events -> condition -> recommended management formula`.

`local_kbacompany_statpolicy` is not a second metric registry. It stores only versioned interpretation policy for qualitative methodology boundaries that have no universal numeric value. An automatic condition is never persisted back into `statval`.

The condition engine can automatically resolve Danger, Emergency, Normal Operation, Affluence and Power when evidence and explicit policy are sufficient. Non-Existence and Power Change remain organization-context states and must later be sourced from canonical assignment/transition events instead of being guessed from the statistic.

Legacy Battle Plan quota bands are compatibility behavior for pinned historical quota revisions only. New indicator conditions are owned by `indicator_condition_service`; Battle Plan is a consumer, not an independent condition authority.

Recommended condition formulas are advisory. When a manager converts a recommendation to execution, the execution object remains the canonical BMS Task and Battle Plan stores only a reference to it.

## v0.5.4 — Governance bridge: Indicator Condition → Task → Battle Plan

Автоматическое состояние показателя остаётся вычисляемым read-model. BMS не хранит копию вычисленного состояния и не создаёт задачу непосредственно из аналитики.

После решения руководителя создаётся immutable `ConditionStateEvent` в `local_kbacompany_stateevt`. Событие привязано к canonical Indicator и точной revision его `statbind`, фиксирует период и аналитический вывод на момент решения, но не копирует `statval`.

Исполнение конвергирует в существующий canonical Task:

`ConditionStateEvent → local_kbacompany_task(sourcekind=condition) → local_kbacompany_bplanitem(reference)`.

Ни ConditionStateEvent, ни Battle Plan не являются вторым Task registry. Ссылки события на Task/Battle Plan разрешаются on-read по provenance и существующим reference-объектам. Доступ к связанным объектам проверяется их собственными ACL; экран показателя не расширяет права Task Center/Battle Plan.

## v0.5.5 — Management Exceptions read model

Management Exceptions is a projection over existing domains, not a canonical object:

`Indicator Binding -> fact/norm -> trend/events -> condition -> manager decision -> canonical Task`.

`indicator_management_exception_service` aggregates simultaneous attention reasons into one current row per active `statbind`. It never writes an exception row and never writes an analytical result into `statval`.

The queue uses explicit semantic severity only. All numerical condition sensitivity remains in versioned `statpolicy`; v0.5.5 adds no hidden percentage or age thresholds.

Task existence is resolved from condition-event provenance. Task details are exposed only if `task_service::can_view_task()` allows access, so the Indicator workspace does not become an ACL bypass for Task Center.


## v0.5.7 — Battle Plan row → governance decision → canonical Task

The Battle Plan does not own a management-action object. An authorised manager acts on the canonical position-indicator row, but the server first rebuilds that row for the selected plan week. The resulting decision is stored only as the existing immutable `local_kbacompany_stateevt`, and execution converges into the existing canonical Task.

`Battle Plan W -> historical statbind/statval/statnorm/statpolicy projection -> stateevt -> Task(sourcekind=condition) -> bplanitem reference`.

Task responsibility is derived from Battle Plan placement: assignee = plan employee, reviewer/observer = plan governance roles. Browser input cannot redirect execution to another employee. Exact repeated submission reuses the identical event/task/reference; a soft-deleted Task remains a tombstone and is not revived. No schema, capability or external-service expansion is introduced.

## v0.5.8 — Management action control without an action-status entity

The Battle Plan indicator row remains a projection over canonical sources. Execution status is read from `local_kbacompany_task`; checklist progress is derived from `local_kbacompany_taskcheck`; result is read from `task.result`.

For Battle Plan week W, action follow-up is intentionally limited to a governance event with canonical execution from exactly W-7. The service compares the factual observation at W-7 with W from append-only `local_kbacompany_statval`. Direction-aware assessment is descriptive only and never stored as a separate effect/status record. BMS does not infer causality from temporal sequence.

No schema, capability, privacy or external-service change is introduced by v0.5.8.

## v0.5.9 — Management Exceptions as an execution-control cycle

The management exception queue is extended as a schema-neutral read model:

`current indicator binding -> attention reason -> immutable manager decision -> canonical Task -> accessible Battle Plan -> exact next-week fact`.

The queue does not own Incident, Action or Effect objects. Task state/result/checklist remain canonical Task data. Battle Plan remains a weekly reference projection. Follow-up outcome is recomputed from append-only `statval` and is descriptive, not causal.

If the next fact worsens or stays unchanged, a new decision is requested only when no later `stateevt` already exists for the follow-up period or later. Protected Task details and task-to-plan provenance are exposed only through canonical Task ACL.


## v0.5.15 — Stabilization / Weekly Management Acceptance

- 0.5.14 batch transition is orchestration only: selected plans pass one-by-one through the canonical Battle Plan transition service.
- Weekly Management Acceptance is a schema-neutral read-model over coverage, readiness, management overview and canonical-reference integrity.
- Acceptance is never stored as a second approval/status registry.
- Hard failures and attention signals are explicit and threshold-free.
- Server transition revalidates reviewer presence for submit/approve/selfapprove so the lifecycle matches readiness.
- After this stabilization checkpoint the next major functional contour is 0.6.0 Financial Model.

## v0.5.16 — Company & Employee Integration Boundary

BMS now owns canonical Company and Employee membership independently from Corporate Learning. Bitrix24 transport remains exclusively in KBA Connect. New Bitrix companies require explicit `Клиент BMS = Да`; employee identity is Moodle User + historical BMS membership; provider-neutral external mappings bind Bitrix Company/Contact IDs; outbound synchronization is persisted in a durable BMS outbox. `local_kbacorporate` remains the learning bounded context and Deal-driven course enrolment is unchanged. Existing Corporate-backed companies/members are retained through compatibility projections during staged adoption.


## v0.6.0 — Financial Model foundation

Financial Model is owned by `local_kbacompany` and remains company-scoped. It does not move finance business logic into KBA Theme, KBA Connect or KBA Corporate.

Canonical foundation:

`Published Strategy / Business Model -> Financial Scenario`

`Canonical Company / OFS Org Unit / Position / Product -> Financial Responsibility Center -> optional responsible Position / Indicator`

`FinancialScenario` and `FinancialResponsibilityCenter` are versioned definitions. A published revision is immutable; editing creates a new draft revision and archiving appends an archived revision instead of deleting history. Active business uniqueness is enforced while holding the company-scoped Financial Model lock.

A Financial Responsibility Center is a governance link, not a second organization tree. Its `targettype + targetuuid` points to an existing BMS object. v0.6.0 therefore does not copy OFS nodes, positions, products or indicator definitions into finance storage.

Scope boundary: v0.6.0 stores planning/governance definitions only. BDR, BDDS, forecast balance, budgeting rows, payment facts and ERP/accounting facts are intentionally excluded. BDR/BDDS/forecast balance belong to v0.6.1; budgeting belongs to v0.6.2. No Bitrix24 REST or KBA Corporate learning write is introduced.


## v0.6.1 — Planned financial statements

The v0.6.0 Financial Model remains the governance foundation. v0.6.1 adds a separate `financial_statement_service` bounded context for planned BDR, BDDS and forecast balance.

Canonical traceability:

`Published Financial Scenario revision -> Financial Statement revision`

`Optional published Financial Responsibility Centre revision -> Financial Statement revision`

Statement headers are append-only (`draft / published / archived`) and input cells are immutable per statement revision. Derived BDR, BDDS and balance rows are calculated on-read; they are not copied into a second editable fact registry.

BDR expresses planned economic result. BDDS expresses planned cash movement across four rolling weekly buckets. Forecast balance expresses planned assets, liabilities and equity and cannot be published unless assets equal liabilities plus equity within 0.01 currency units.

Scope boundary: v0.6.1 is planning/forecast storage only. It does not create accounting postings, bank transaction facts, payment execution, an ERP ledger or plan/fact actuals. Budget approval/limits remain v0.6.2. KBA Connect and KBA Corporate are unchanged.


## v0.6.2 — Budgeting

Budgeting is a separate `budgeting_service` bounded context layered on top of the v0.6.0 Financial Model and v0.6.1 planned statements.

Canonical traceability:

`Published Financial Scenario revision -> Budget revision`

`Optional published Financial Responsibility Centre revision -> Budget revision`

`Published Administrative Scale Goal / Program / Project revision OR active canonical OFS Org Unit -> Budget target`

`Budget revision -> immutable BudgetLine snapshots -> aggregate BudgetFactSnapshot -> derived plan/fact variance`

Budget lifecycle is append-only: `draft -> submitted -> approved/rejected`; archiving appends history instead of deleting it. An approved revision remains the effective plan while a later amendment draft/rejected revision exists. Spend limits are explicit on spending/investment/financing/reserve lines and are checked before submission/approval.

`BudgetFactSnapshot` is an aggregate management observation for a budget line with an as-of date and mandatory source provenance. It is not a journal entry, bank transaction, invoice, payment instruction or ERP ledger row. Raw operational/accounting source-of-truth data remains external and may be connected later through provider-neutral integrations.

Scope boundary: v0.6.2 does not execute payments, create accounting postings, own bank facts or implement ERP synchronization. KBA Connect and KBA Corporate are unchanged. The next architecture step is v0.6.3 Resource Gate.


## v0.6.3 — Resource Gate

Resource Gate is a separate `resource_gate_service` bounded context layered over Administrative Scale, Financial Model/Budgeting, Organization/Staffing, Business Role competency projection and Document Control.

Canonical traceability:

`Published Goal / Program / Project revision -> Resource Gate revision`

`Optional published Financial Scenario revision -> Resource Gate revision`

`Resource Gate revision -> immutable ResourceGateItem checks -> explicit Ready / Blocked decision`

The seven resource dimensions are money, people, competencies, time, technology, information and infrastructure. Money may pin an exact approved Budget revision for the same target/scenario. People reference canonical Position/Staffing. Competency requirements reuse the existing read-only Business Role projection and never mutate assessed competency. Information may reference a published/effective controlled document. Technology and infrastructure remain evidence-backed readiness checks until BMS gains a canonical asset/CMDB bounded context.

A Resource Gate is an assessment and governance decision, not a resource master registry. It does not execute payments, recruit/assign employees, change competency levels, trigger training, purchase assets, reserve calendar capacity or mutate an ERP. Draft/submitted amendments keep the previous Ready revision effective; a new Blocked decision revokes it.
