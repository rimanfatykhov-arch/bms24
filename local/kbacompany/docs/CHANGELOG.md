# Changelog

## 1.1.0-rc25 — build 2026101352 — Workspace simplification
- Simplified the main BMS24 Workspace screen without changing the underlying domains.
- Grouped daily counters into one compact overview card with direct links to Inbox, Tasks, Review and Learning.
- Removed the duplicate four-card management pulse row and kept only the clearer attention/execution blocks.
- Reordered the screen so Today, overview, attention and active work appear first; architecture and company context are moved lower as reference.
- No DB schema, capability or privacy changes.

## 1.1.0-rc19 — build 2026101340 — Communication Center 2.0
- Replaced the split registry/legacy messenger UX with one responsive three-pane Communication Center workspace: discussions, message history, object context and actions.
- Added All / Inbox / Unread / Messages / Discussions / Mentions / Notifications / Meetings views over the existing canonical communication tables.
- Added access-aware canonical object resolution for Company, Task, Project, Process, Document, Goal, Metric, Learning element, Improvement and Meeting; technical object identifiers are no longer exposed in the normal UI.
- Enforced one active communication history per canonical object. An authorised object viewer may safely join that history; users without object access cannot discover or join it.
- Hardened object discussions, participant additions, notifications and protected discussion files against revoked/private object access.
- Preserved structured @mentions, explicit read-state, protected Moodle File API uploads, canonical Message → Task conversion and replay-safe Message → proposed Meeting Decision.
- Added direct discussion entry points from Document and Metric cards and routed Task/Meeting/internal links to Communication Center 2.0; legacy `communications.php` GET links redirect compatibly.
- No DB schema or capability changes.

## 1.1.0-rc18 — build 2026101238 — Management Cycle 2.0
- Added a read-only five-function management projection: Planning → Organization → Leadership → Control → Correction.
- The projection reuses canonical cycles, Battle Plans, tasks, meetings, decisions, Metrics and A3 reviews; no parallel lifecycle/storage was introduced.
- Added a current Year → Quarter → Month → Week cadence panel with direct open/create actions.
- Added explainable stage states and advisory closure-attention signals with no hidden percentage score.
- Limited the new five-function projection to regular Week / Month / Quarter / Year cycles; project/advisory templates remain unchanged.
- No DB schema, capability or privacy storage changes.

## 1.1.0-rc17 — build 2026101237 — Battle Plan 2.0
- Unified the weekly Battle Plan overview around Week goal / Period metrics / Tasks / Meetings / Load / Week outcome / Carry-forward.
- Stored week goal/outcome inside the existing Battle Plan state payload; no parallel table was introduced.
- Added privacy-safe weekly meeting projection: full details only for the plan owner, aggregate load for other authorised viewers.
- Added informational weekly load from canonical task plan minutes plus canonical meeting duration.
- Added explicit carry-forward of unfinished canonical task references into next week without copying tasks or rewriting deadlines/history.
- Week close now accepts unfinished work only when the exact source item was explicitly carried and is referenced by the next Battle Plan.
- No DB schema, capability or privacy storage changes.

## 1.1.0-rc16 — build 2026101236 — Metrics 2.2
- Added a schema-neutral period performance status read model: Within target / Risk / Critical / No data.
- Period plan has explicit priority over the effective versioned norm; no hidden percentage thresholds are used.
- Information Center now summarizes main company metrics by BMS24 performance status.
- Metric drill-down history now shows Plan / Fact / Variance / Status.
- Weekly Battle Plan automatically presents position metrics with period plan, norm, fact, variance, status and trend without copying metric data.
- Added pure regression tests for the performance status rules.
- No DB schema, capability, route or privacy storage changes.

## 1.1.0-rc15 — build 2026101235 — Employee 2.0
- Unified the employee record into Overview / Positions and roles / Metrics / Battle Plan / Tasks / Competencies / Learning / Development / Documents / History.
- Added a company-scoped read model over canonical assignments, business roles, position metrics, Battle Plans, tasks, competency evidence, learning status, development plans and document acknowledgements.
- Sensitive personal management tabs are visible only to the employee or users with people/organization management permission.
- Preserved the existing Employee CRUD and integration diagnostics; no second employee/task/metric/learning/document storage was introduced.
- No DB schema, capability or privacy storage changes.

## 1.1.0-rc14 — build 2026101234 — Position 2.0
- Unified the BMS24 position record into Main / ЦКП / Functions / Metrics / Competencies / Authorities / Processes / Learning / Staffing / History.
- Reused canonical position products, functions, metric bindings, competency profiles, function transfers, process sources and learning contour without copied storage.
- Added a compact position-profile completeness summary and mobile-friendly tab navigation.
- Added source/target position UUIDs to the existing function-transfer DTO so the Position card can scope authority projections by stable identifiers.
- No DB schema, capability or privacy storage changes.

# 1.1.0-rc13 (build 2026101233)

- Staffing Placement 2.0: assignment CRUD, transfer, FTE share, temporary/additional assignments and history.
- Added factual assignment FTE with overlap-safe staffing-slot capacity checks.
- Historical plan versions are read-only; transfers preserve dated assignment history.

## 1.1.0-rc12 (build 2026101232) — Staffing plan acceptance hardening
- Added a pre-approval impact preview for factual employee assignments.
- Shows current assigned FTE, compatible carry-forward FTE and assignments that will require placement.
- Removed-position and incompatible-capacity conflicts are visible by employee/position before approval.
- Approval with placement conflicts requires an explicit user acknowledgement in the BMS24 UI.
- Added a direct link from the impact preview to Staffing Placement.
- Kept validation errors inside the BMS24 staffing editor for expected staffing-form errors.
- No database schema, ACL or privacy changes.

## 1.1.0-rc11.4 (build 2026101231) — One-click staffing plan editing
- Added a clear «Edit staffing plan» action to the current approved version.
- The action opens the existing branch draft when present, otherwise creates a new draft copied from the current approved version and opens it immediately.
- Approved staffing history remains immutable; all changes continue through the versioned draft → save → approve workflow.
- No database schema, ACL or privacy changes.

## 1.1.0-rc11.3 (build 2026101230) — Staffing assignment carry-forward hotfix
- Preserve current employee staffing assignments when approving a new version if the canonical position and slot FTE remain compatible.
- Never silently end or resize an assignment when staffing capacity changes; incompatible carried assignments remain active and are surfaced as «Placement required».
- Occupancy follows canonical position assignments across staffing-plan versions.
- Staffing salary/FTE validation now returns an in-page BMS24 error notification instead of the Moodle system error page.
- No database schema, ACL or privacy changes.

## 1.1.0-rc11.2 (build 2026101229) — Staffing add-unit form UX hotfix
- Rebuilt the «Add staffing unit» block as a responsive BMS24 grid instead of overflowing Bootstrap columns.
- Position, FTE, salary range, monthly payroll and action now have readable widths on desktop and collapse cleanly on tablet/mobile.
- Renamed the action from the technical «Add or update line» to «Add staffing unit» / «Add to staffing plan».
- No database schema, ACL, privacy or staffing-domain changes.

## 1.1.0-rc11.1 (build 2026101228) — Staffing SQL compatibility hotfix
- Fixed MySQL 5.7 read error in staffing plan summary caused by the aggregate alias `lines`.
- Aggregate alias renamed to `linecount`; the public/view-model key remains `lines`, so UI and contracts are unchanged.
- No database schema, ACL, privacy or staffing data changes.

## 1.1.0-rc11 (build 2026101227) — Metric editor UX
- Reworked metric editing into a full-width BMS24 card with clear management sections.
- Added metric passport, management parameters, method, role and formula/source blocks.
- Added responsive grids, summary chips, highlighted main-company role and sticky save actions.
- No database schema, ACL or metric identity changes.

# v1.1.0-rc10 — Штатная укомплектованность в штатном расписании

- В штатном расписании показаны «Плановые ставки / Занято / Вакансии» по каждой штатной единице.
- Для черновика фактическая занятость берётся из текущей утверждённой версии, поэтому изменения можно оценить до утверждения.
- Добавлены статусы «Укомплектовано / Есть вакансии / Сверх штата».
- Сводка версии дополнена занятыми и вакантными ставками; группировка по подразделениям показывает те же показатели.
- Расчёт выполняется из канонической штатной расстановки; копии сотрудников или назначений не создаются.
- Схема БД не менялась.

# v1.1.0-rc9 — Штатное расписание CRUD BMS24

- Полноценный версионный CRUD: создать версию, открыть/сравнить, редактировать черновик, отменить черновик; утверждённые версии неизменяемы.
- Новая версия по умолчанию копирует действующую утверждённую версию; пустой черновик создаётся только осознанно.
- Полноширинный табличный редактор с группировкой по подразделениям и единым сохранением черновика.
- Сводка по подразделениям, должностям, ставкам и месячному ФОТ.
- Безопасное исключение штатной единицы с возможностью вернуть её из сравнения с предыдущей версией.
- Реестр версий с быстрым открытием, статусом, периодом, ставками и ФОТ.
- Схема БД не менялась.

# v1.1.0-rc8 — Метрики 2.1 CRUD BMS24

- Единый понятный «Реестр метрик»: создать, открыть, изменить, архивировать и восстановить.
- Поиск, фильтр Активные / Архив / Все, сводка и быстрые действия.
- Безопасное удаление через архив с сохранением UUID, истории, норм и связей.
- Архивная метрика автоматически снимается с роли «Главная метрика компании».
- Схема БД не менялась.

# v1.1.0-rc7 — Метрики 2.0

- Информационный центр: только «Главная метрика компании».
- Полное редактирование канонической метрики без смены UUID.
- Пользовательская терминология «Метрики».

# v1.1.0-rc6 — Показатели должности → Боевой план

- В карточке должности добавлена вкладка «Показатели».
- Показатели назначаются должности через канонический `local_kbacompany_statbind`, без копирования KPI.
- Для связи задаются вес, критичность, признак основного показателя, сортировка и версионируемая норма/цель.
- Недельные показатели должности автоматически отображаются в недельном Боевом плане сотрудника через существующую каноническую проекцию.
- Дневные, месячные, квартальные и годовые показатели видны в должности, но помечаются как не входящие в недельный Боевой план.
- Исключение показателя архивирует только связь с должностью и не удаляет сам показатель или историю фактов.
- Схема БД, capabilities и privacy storage не менялись.

# v1.1.0-rc5 — Полноценный редактор штатного расписания

- Новая версия по умолчанию копирует действующее утверждённое штатное расписание.
- Черновик редактируется построчно: ставки, диапазон оплаты, ФОТ.
- Строки можно добавлять и исключать без изменения прошлых утверждённых версий.
- Добавлено сравнение с предыдущей версией перед утверждением.
- Параметры версии: название, дата начала действия, валюта.
- Утверждённые версии остаются неизменяемыми; штатная расстановка остаётся отдельным фактическим слоем.

## 1.1.0-rc4.2 — 2026-10-12
- Expanded Battle Plan quick task form: priority, reviewer, observer, start/end time, attachment.

## 1.1.0-rc4.1 (build 2026101219)

- Task Center hotfix: start/end time fields and fixed Save button translation.

## 1.1.0-rc4 (build 2026101218)
- Battle Plan edit is now a full workspace: period, reviewer, observer, design references, lifecycle, summary and task composition are visible together.
- Managers may reassign employee/position only for an empty draft; plans with task references must be emptied first to preserve ownership semantics.
- Existing canonical tasks can be linked and new canonical tasks can be created directly from the edit screen without copying task data.
- Draft period edits are duplicate-safe and stale-form protected; lifecycle status still changes only through explicit transitions.
- No schema, capability or privacy-storage change.

## 1.1.0-rc3 (build 2026101217)
- Task create form now groups accessible draft Battle Plans by employee, with position and week shown explicitly.
- Managers can clearly select another employee's accessible draft Battle Plan when the task assignee is that employee.
- Battle Plan selection is validated against the task assignee with a field-level error; the task remains canonical and is referenced without copying.
- Existing Battle Plan ownership/access rules remain unchanged; no schema, ACL or privacy-storage change.

## 1.1.0-rc2 (build 2026101216)
- Task Center: assignee may also be reviewer; self-review remains an explicit submit -> accept lifecycle.
- Task create form: `Add to Battle Plan` and file upload surfaced from Advanced into the main form.
- Added regression coverage for self-assigned/self-reviewed tasks in `My tasks`.
- No schema, ACL or privacy-storage change.

## 1.1.0-rc1 (build 2026101215)
- Calendar 1.1 release candidate over exact beta2 functional behaviour; feature freeze remains in force.
- Maturity moved to `MATURITY_RC`; no new schema, capabilities, tasks, provider contracts or business features.
- Added explicit live acceptance checklist for Company isolation, roles, CRUD, recurrence, meetings, resources, notifications, Google Calendar, timezone/DST, large-company performance and cross-contour regression.
- Production readiness remains blocked until target-environment acceptance passes.

## 1.1.0-beta2 (build 2026101214)
- Feature-freeze runtime hardening: bounded/paginated Moodle calendar reads, cron overlap lock, streaming recipient scan, timezone fallback and DST-invalid local datetime rejection.
- No new schema, capabilities or product features.
## 1.1.0-alpha18 (build 2026101210) — Meeting Resources & Availability
- Added Company-scoped canonical Meeting Resource registry for rooms and places.
- Added conflict-safe resource booking, capacity checks and online meeting links to canonical BMS Meeting.
- Added resource occupancy UI with participant-safe privacy: non-participants see busy time without private meeting titles.
- Added create/edit/archive resource workflow and resource availability.
- Added additive schema upgrade for meeting resource/link fields and resource registry.
- Communication Center object-type selector now uses canonical BMS24 names instead of internal keys.

## 1.1.0-alpha14.2 (build 2026101206) — Learning Vocabulary UI
- Replaced user-facing Moodle product wording with BMS24 learning vocabulary (`Обучение` / learning platform).
- Updated Calendar, Employees, Learning, Development, Competencies, file/storage and notification UI labels while preserving all technical Moodle identifiers and APIs.
- No schema, ACL, privacy-storage or business-logic changes.


## 1.1.0-alpha14.1 build2026101205
- Calendar Moodle 4.1 compatibility hotfix: replaced unsupported `html_writer::strong()` calls with `html_writer::tag('strong', ...)`.
- No data/schema/permission/business-rule changes.
# 1.1.0-alpha14 — Recurring Events & Regular Meetings — build 2026101204

- Added bounded recurrence expansion for manual Moodle Calendar events and manual BMS meetings.
- Supported weekly, monthly and selected-weekday rules, up to 52 explicit occurrences.
- Each occurrence remains an ordinary canonical event/meeting; no recurrence table or second source of truth is introduced.
- Existing single-create behavior is unchanged when recurrence is disabled.
- Management-cycle/discussion sourced meetings are not expanded as recurring series.
- Series-wide edit/delete is intentionally deferred; existing per-occurrence lifecycle remains authoritative.
- Schema, ACL and privacy provider unchanged.

# 1.1.0-alpha13.1 — Calendar Meeting Finder URL Hotfix

- Fixed Moodle 4.1 coding error `Url parameters values can not be arrays!` in Meeting Time Finder.
- Meeting prefill participant IDs are now transported as a scalar CSV URL parameter and parsed back into validated integer IDs by `meetings.php`.
- Legacy `prefillparticipants[]` input remains supported for backward compatibility.
- No database, ACL, privacy, calendar, meeting, or notification schema changes.

## 1.1.0-alpha9 (2026100705) — Calendar Team Workload
- Permission-scoped employee workload projection and employee filter; no cross-user Moodle calendar reads.

# 1.1.0-alpha8 — Calendar Day Planner & Conflict Awareness

- Added an hourly vertical planner for the Day view.
- Timed Moodle/BMS projections are positioned on a shared time axis; all-day BMS items stay in a separate strip.
- Added read-only overlap diagnostics and occupied-time summary without mutating canonical sources.
- Added quick-create links on hourly rows; manual Moodle event editor opens with the selected date/time prefilled.
- Conflict analysis is presentation-only; tasks, meetings, Battle Plans and checkpoints remain owned by their canonical workspaces.
- No schema, ACL or privacy-provider changes.


## 1.1.0-alpha5 — BMS Calendar Usability — build 2026100701
- Added BMS event detail card, filters and quick day creation.
- Preserved Moodle Calendar source-of-truth and alpha4 CRUD/security boundaries.
- Schema/ACL/privacy unchanged.
## 1.1.0-alpha4 — BMS Calendar CRUD — build 2026100618
- Adds create/edit/delete of manual Moodle Calendar events directly from the BMS24 Calendar workspace.
- Moodle Core Calendar remains the source of truth; no local calendar storage is introduced.
- Personal event create/edit uses Moodle own-calendar permission; course event create/edit is restricted to current-Company course bindings and Moodle calendar management permission.
- Module/activity, subscription and group events remain read-only and are changed only in their owning Moodle subsystem.
- Mutations are POST-only with sesskey; delete requires explicit confirmation.
- Schema, ACL and privacy storage declarations are unchanged.

## 1.0.0-rc2 — OFS Workspace Unification — build 2026100614
- Unified current OFS and OrgDesign into one `ofs.php` workspace with Current / Design / Compare / Check modes.
- `ofs_designer.php` remains a compatibility POST/legacy route and redirects GET traffic to the unified workspace.
- Current and design states use one visual renderer so the same structure no longer appears as two unrelated products.
- Added optional canonical Product UUID bindings inside versioned OrgDesign payload for company and unit valuable final products; legacy text remains readable and is explicitly flagged for formalisation.
- Added live-vs-design comparison and read-only pre-apply validation; no automatic Product creation or hidden live writes were introduced.
- Existing OrgDesign projects remain readable and applicable; database schema, ACL and privacy storage contracts are unchanged.

## 1.0.0-rc1 — Stabilization & Acceptance Baseline (build 2026100613)
- Frozen Release Candidate over exact 0.9.17 functional behaviour for Moodle 4.1.22 live acceptance.
- No new business feature, schema, ACL, privacy storage contract, scheduled task or integration provider contract.
- Added explicit RC acceptance contract covering install/upgrade, Company isolation, Manager/Reader visibility, Process Architecture, IDEF0, SIPOC/BPMN, Process Passport/export and cross-contour regression.
- Release maturity moved to `MATURITY_RC`; production readiness remains blocked until live acceptance gates pass.

## 0.9.15 — IDEF0 / Process Architecture Visual Finalization
- Finalized the read-only Process Architecture visual canvas and L0/L1/L2 hierarchy connectors.
- Added presentation-only IDEF0 I/C/O/M connector arrows and scroll/print fallbacks.
- No domain, schema, ACL, privacy or persistence changes.

## 0.9.14 — Process Architecture Workspace (build 2026100609)
- Added a full non-paginated L0→L1→L2 Process Architecture workspace over the existing canonical Process hierarchy.
- Added bulk model/IDEF0↔SIPOC diagnostics and boundary-balance projection for architecture nodes.
- Added readiness/attention health states and direct Passport/IDEF0/SIPOC navigation without a second hierarchy or new storage.
- Registry layout and existing CRUD semantics remain unchanged; schema/ACL/privacy unchanged.
# 0.9.13 (2026100608) — IDEF0 ↔ SIPOC Consistency

- Added read-only integrity diagnostics between IDEF0 and SIPOC over the same canonical Process Model; no copy/synchronisation layer was introduced.
- Input and secondary Output projections are compared by canonical element UUID plus Product UUID binding.
- The primary Process Product is verified as the shared primary Output for both views.
- Projection drift is separated from missing Product formalisation (`consistent`, `needs_formalisation`, `inconsistent`).
- IDEF0 now shows a dedicated consistency section and SIPOC shows the same state with navigation back to IDEF0.
- Remediation uses existing Process/ICOM/SIPOC CRUD only; Supplier/Customer remain SIPOC-only context.
- No schema, ACL, privacy, BPMN/procflow or execution-semantics changes; ALPHA live Moodle/browser/production acceptance remains NOT RUN.

# 0.9.12 (2026100607) — IDEF0 Controls & Mechanisms

- Added explicit canonical Control/Mechanism bindings over the existing Semantic Core; no second IDEF0 storage or schema.
- Controls can link a Process to Policy/Directive or effective Document via `Process → governed_by → object`, and reuse `Metric → measures → Process`.
- Mechanisms can reuse/create `OrgUnit/Position → executes → Process` relations.
- Existing free-form `control` / `resource` procelem rows remain descriptive ICOM; canonical BMS-object bindings are rendered separately in the same IDEF0 areas.
- Manager can create/archive these relations with sesskey, request receipt, reason and optimistic Process revision guard; Reader sees only visible canonical endpoints.
- Roles and information systems are not added as Semantic Core endpoints in this slice and remain descriptive ICOM elements.
- No Process Model, SIPOC, BPMN, schema, ACL or privacy mutation; ALPHA live Moodle/browser/production acceptance remains NOT RUN.

# 0.9.11 (2026100606) — IDEF0 Boundary Balancing

- Added read-only decomposition boundary diagnostics over canonical Process/Product/procelem/Semantic Core data.
- Parent Inputs are covered only by exact canonical Product UUID usage in direct child Inputs; names are never matched as a fallback.
- Existing sibling `Process → supplies → Process` ICOM balance is reused for internal transfers.
- Terminal child primary Products are checked against parent primary/Output Products; parent primary Product must be produced by a terminal child.
- Unbound boundary I/O and missing primary Products are reported as explicit formalisation issues.
- Remediation uses existing Process/ICOM CRUD only; no automatic ICOM/SIPOC/BPMN writes or hidden draft creation.
- No schema, ACL or privacy changes; ALPHA live Moodle/browser/production acceptance remains NOT RUN.

# 0.9.10.3 (2026100605) — IDEF0 UI Polish

- Polished the existing IDEF0 workspace without changing routes, permissions, model services or canonical data.
- Added compact I/C/O/M count summary, stronger central function node and lighter working-area cards.
- Simplified decomposition cards and changed long section notices into compact descriptions.
- Improved interconnection scanability with separate notation badges, source/target nodes and explicit balance-state labels.
- Improved mobile density and action alignment.
- No schema, ACL, privacy, Process/ICOM/Semantic Core or balancing-behaviour changes.
- ALPHA; live Moodle/KBA Theme desktop/mobile visual acceptance remains NOT RUN.

# 0.9.10.2 (2026100604) — IDEF0 Tab Routing Fix

- Fixed `tab=idef0` routing in `processes.php` and `process_design.php`: tab parameters now use `PARAM_ALPHANUMEXT`, so the digit in `idef0` is preserved.
- Kept the existing explicit allowlists (`passport/idef0/sipoc/bpmn` and `idef0/sipoc/bpmn`) as the final route guard.
- No business logic, schema, ACL, privacy, Process/ICOM/Semantic Core data or UI composition changes.
- ALPHA hotfix; live Moodle/KBA Theme/browser/production acceptance remains NOT RUN.

# 0.9.4 (2026092901) — Visual BPMN

- Added bpmn-js visual BPMN canvas over the existing versioned process model.
- BPMN XML is a version-scoped layout artifact in Moodle file storage; structural BMS nodes/flows remain canonical.
- Server rejects canvas saves that change node/flow/lane semantics; published revisions remain immutable.
- New draft clones published layout; semantic structural edits invalidate stale layout; publish guarantees an XML snapshot.
- No new DB schema, capabilities, cron, process engine, task engine, KBA Theme or AI plugin.
- ALPHA: static/isolated QA only; live Moodle/browser/production acceptance NOT RUN.

# 0.9.2 (2026092604) — Bitrix24 Provider Extension

- Added restricted Bitrix24 Provider API v1 for the existing KBA Connect transport.
- Added readiness, scoped/legacy mappings and Company/Employees/Positions reconciliation manifest.
- Preserved no-direct-Bitrix-REST boundary in local_kbacompany and existing legacy command/event outboxes.
- Added Integration Center provider readiness card.
- No schema, ACL capability, privacy, cron or KBA Theme changes.

## 0.9.1 — Odoo Adapter Foundation
Build: 2026092600

- Added disabled-by-default restricted BMS Odoo Adapter API v1.
- Added company/environment readiness, Public API snapshot manifest and paged active Odoo mappings.
- No credentials, remote Odoo calls, direct DB access or actual ingestion in this slice.

# 0.9.0.12 build2026092508 — Position soft deletion / recovery

- Added Delete and Restore in the existing BMS Position record Actions menu.
- Separate read-only confirmation preview, explicit reason and consent; POST + sesskey + session receipts.
- Conservative company-scoped reference checks including historical links and selected frozen payloads.
- Soft delete updates only active/timemodified; preserves UUID/code and all related data.
- Restore requires an available active department; does not restore/create staffing or permissions.
- Same directory edit lock, freshness guard, transaction and recheck before update; metadata core log events.
- No new schema, ACL, API, cron, privacy declaration or Theme/AI changes; norm_ix fix retained.
- ALPHA; static/isolated/component checks, no live Moodle/production acceptance.

# 0.9.0.11 build2026092507 — Position Directory BMS UI / CRUD

- Separate company-scoped ListView, DetailView, Create/Edit forms on existing positions.php.
- SQL search, type/unit/status filters and 25-row paging; legacy OFS tabs removed on this route only.
- Record tabs: Overview, Functions and results, Staffing, Change history; shared BMS record header.
- Existing stable-code allocator issues POS-*; old codes/UUIDs and API create contract preserved.
- Position definition edits only: name, purpose, manager-work flag and sort order; structural identity immutable.
- Explicit unit picker, field validation, stale-form protection, session replay receipts, locks and transactions.
- Related canonical Function revisions and approved Staffing lines are read-only; no parallel engines.
- Metadata-only core Moodle audit event; no revision table, old-value snapshot or retrospective history.
- No new schema/ACL/API/cron/privacy/Theme/AI changes. Inherited norm_ix upgrade fix unchanged.
- ALPHA: static/isolated/component QA; live Moodle and production acceptance NOT RUN.

# 0.9.0.10 build2026092506 — Employees BMS UI / CRUD

- Modernized existing employee list/detail/forms; removed old OFS tab strip from the daily page.
- Company-scoped SQL search/filter/count/paging, canonical membership precedence and bounded legacy projection.
- Record tabs, separate membership/account states, scoped current Staffing projection and paged membership history.
- Manager-only synchronization details; malformed legacy phone presented for review, not silently rewritten.
- Existing domain writes wrapped in validation, optimistic UI tokens, session receipts, locks and DB transaction.
- Existing active member match is read-only; new form cannot attach an unrelated global account by email.
- Soft membership close preserves user/assignments/tasks; no new schema, ACL/API/cron, Theme or AI changes.
- Critical 2503/2505 upgrade helpers are byte-identical to 0.9.0.9.2.
- ALPHA: static/isolated/component QA only; live Moodle and production acceptance remain NOT RUN.

# 0.9.0.9.1 build2026092504 — Deviation schema recovery hotfix

- Re-runnable versioned 2504 repair for missed/partial 2503 schema, including installed-version 2503.
- No target schema changes from 0.9.0.9; 12 additive fields, six nullable changes and three indexes are reconciled.
- New savepoint follows fresh metadata checks; correct schema performs no repair DDL.
- Read-only per-request schema guard after Company/actor permissions, before deviation data queries/writes.
- Site-admin upgrade link; generic employee maintenance message; no raw SQL or misleading empty registry.
- Read-only CLI metadata diagnostic, no web-triggered DDL or administrative write endpoint.
- Original 2503 block, UUIDs/codes/history, ACL/API/privacy, business lifecycle, UI, Theme remain preserved.
- Reported production symptom observed, not a production repair/acceptance claim.

# local_kbacompany 0.9.0.9 build2026092503
## Отклонения и несоответствия — BMS UI / регистрация пользователем

Полное обновление поверх 0.9.0.8. Одна существующая сущность Deviation, два способа
регистрации: сообщение сотрудника без числовой нормы и управленческое отклонение
от точной утверждённой нормы. Нового движка проблем, задач или показателей нет.

Управление → Отклонения → Зарегистрировать несоответствие → заполнить факт,
место и дату → сохранить → карточка со статусом «Ожидает разбора».
Требование и доказательства можно уточнить: отсутствие утверждённого пакета
функций не блокирует сообщение. Регистрация сама по себе не подтверждает факт
нарушения требования. Руководитель отдельно подтверждает или отклоняет сообщение.

ВАЖНО: в этом выпуске меняется структура существующих таблиц. Обязательны
резервные копии БД, moodledata и кода; тестовая копия; штатное обновление Moodle
после замены local/kbacompany; очистка кэшей. Одной замены файлов недостаточно.
Для отката используйте согласованный снимок БД+кода+moodledata, не простой downgrade.

Для установщика Moodle используется ТОЛЬКО INSTALL_ME ZIP. SOURCE содержит тот же
исходный код; Release Kit — документация, patch, QA и baseline, не install ZIP.

ALPHA. Target: Moodle 4.1.22, PHP 7.3+. Поле requires сохранено от базы (Moodle 4.0)
и не доказывает runtime-совместимость. Проверен PHP 8.4.23; PHP7.3 runtime,
реальная KBA Theme, Moodle install/upgrade, PHPUnit, Behat и production NOT RUN.
Публикация подтверждается только отдельным READBACK_VERIFICATION, не этим README.
Подробности: DEVIATIONS_BMS_CRUD_0.9.0.9.md.

---

# 0.9.0.8 build2026092502 — Политики и директивы: BMS UI / CRUD

Полное обновление local_kbacompany поверх 0.9.0.7. Список с серверным поиском,
типом, статусом и страницами → отдельные создание/карточка/редактирование.
Карточка: Обзор, Связи, Редакции, История; редактирование отдельно, вторичные
действия с причиной и явным подтверждением. Код POL-/DIR- присваивается
существующим stable_code_service, старые коды и UUID сохраняются.
Новый черновик не скрывает предыдущую опубликованную редакцию от читателей.
Каждое сохранение и изменение состояния, как и прежде, добавляет редакцию.
Новые таблицы, миграции, ACL/API/cron/privacy declarations не добавлены.
KBA Theme, Tasks, Battle Plans, Documents и local_kbaai не менялись.
ALPHA: live Moodle4.1.22/KBA Theme, PHP7.3 runtime, PHPUnit/Behat,
реальные SQL drivers/concurrency и production acceptance NOT RUN.
Подробности: POLICIES_BMS_CRUD_0.9.0.8.md.

# 0.9.0.7 build2026092501 — Задачи / Коммуникационный центр BMS CRUD

Полный пакет поверх локального0.9.0.6. Прямой вход в задачи, раздельные List/Detail/Edit, защищённые Actions, company-scoped SQL/pickers, ясные источники и отдельный коммуникационный центр. Общая оболочка использует counts-only read model. Canonical engines сохранены, нового DDL/ACL нет. Подробности: TASKS_COMMUNICATION_BMS_CRUD_0.9.0.7.md. Live Moodle/production NOT RUN.

# 0.9.0.5 build 2026092417 — Файлы и версии управляемых документов

Полное обновление local_kbacompany поверх 0.9.0.4. В существующих редакциях
документа появился основной загружаемый файл. Отдельного document/file-version
engine нет. Файлы хранятся через Moodle File API и выдаются только после
проверки Company и доступа к точной редакции.

Создание/редактирование черновика → файл → сохранить → карточка → «Версии».
Изменение выпущенного документа: «Действия → Создать новую редакцию», затем
редактирование нового черновика и замена его файла с подтверждением.
Старая действующая редакция не меняется, пока новая не введена в действие.

В existing local_kbacompany_doc добавлено одно nullable поле filemanifest.
Нужен штатный Moodle upgrade; старым документам файлы не выдумываются.
ACL/API/cron/Theme не менялись. Privacy provider дополнен для новых файлов.
ALPHA: live Moodle/Theme, PHP7.3 runtime, PHPUnit/Behat/production NOT RUN.
См. README_0.9.0.5.md и DOCUMENT_FILES_0.9.0.5.md.

## Предыдущие выпуски

# 0.9.0.4 build 2026092416 — Управляемые документы: BMS CRUD

Список с серверными фильтрами и страницами → отдельное создание → карточка →
редактирование черновика → подтверждаемые действия. Постоянный код DOC-xxxx
присваивается сервером, существующие коды сохраняются. Рабочая и действующая
редакции не смешиваются. Используются прежние document_service, таблицы
документов/рассмотрений/ознакомлений/записей, aclog и stable_code_service.
Никаких новых document/knowledge engines. Schema/ACL/API/cron/Theme прежние.
База — локальный 0.9.0.3 build2026092415, с сохранением всех его изменений.
ALPHA: live Moodle, PHP7.3 runtime, PHPUnit/Behat/production NOT RUN.
См. README_0.9.0.4.md, RELEASE_NOTES_0.9.0.4.md, DOCUMENTS_BMS_CRUD_0.9.0.4.md.

## История предыдущих выпусков

# 0.9.0.3 — 2026092415 — Управленческие циклы: BMS CRUD

- Main list with company-scoped search, status/template/owner/date filters and pagination; separate template selection, CreateView, DetailView and draft EditView.
- Five record tabs, domain-owned actions with POST/sesskey/confirmation, visible owner/curator accountability and calendar suggestions.
- Strict dates, hierarchy containment including existing children, active member pickers, draft optimistic concurrency and immutable active parameters.
- Atomic cycle/checkpoints and rollover provenance; form request identity prevents duplicate creation. No task/KPI/meeting copies or automatic business-result changes.
- History uses Moodle core logging from this version; no new history table or retrospective events.
- Database schema, upgrade script, capability definitions, API/cron/privacy contracts and KBA Theme unchanged. Live Moodle/Theme/PHP7.3/PHPUnit/Behat/production acceptance NOT RUN.

# 0.9.0.2 build 2026092414 — Исполнение в карточке проекта

Полное устанавливаемое обновление поверх 0.9.0.1. Дублирующий экран
«Исполнение и встречи / Управленческий цикл» исключён из меню и больше не
редактирует планирование. Его полезная часть находится в карточке проекта
Административной шкалы: вкладки «Описание» / «Исполнение».

Read-only проекция существующих задач, Боевых планов, встреч и решений с
проверками доступа. Схема, upgrade, ACL, web services, scheduled tasks и privacy
не изменены. Новых task/project/meeting/KPI engines нет. ALPHA; live acceptance NOT RUN.
См. README_0.9.0.2.md, RELEASE_NOTES_0.9.0.2.md, PROJECT_EXECUTION_0.9.0.2.md.

## Предыдущие выпуски (история, не текущий статус)

# 0.9.0.1 build 2026092413 — Меню и навигация

Двухуровневое меню управления (5 групп / до 4 подразделов), перенос пунктов на узких
экранах, согласованные группы боковой панели и активные родители, устранение
повторяющихся кнопок и `[[open]]` в цепочке управления. Полный устанавливаемый UX-патч
поверх 0.9.0; schema/ACL/domain logic не менялись. Live acceptance NOT RUN.
См. README_0.9.0.1.md, RELEASE_NOTES_0.9.0.1.md, NAVIGATION_0.9.0.1.md.

# 0.9.0 build 2026092412 — Integration Identity Foundation

Рабочий экран «Интеграции», двухэтапное подтверждение связей Компания/Должность/Показатель
с внешними ID Odoo/Bitrix24, фильтры/карточка/безопасное закрытие. Расширен существующий extmap;
старые Bitrix mappings не переписываются. Автообмен и импорт факта не включены.
См. README_0.9.0.md, RELEASE_NOTES_0.9.0.md, INTEGRATION_IDENTITY_0.9.0.md.
Статус ALPHA; live acceptance не выполнен.

## Исторические записи (не текущий статус)

# 0.8.10 build 2026092409 — Owner & Curator Workspaces
- Owner attention workspace: decisions, Battle Plan approvals, checkpoints, coordination and A3 next steps.
- Curator portfolio is limited to explicit curator assignment + active company membership + existing management permission.
- No new ACL or schema; workspaces are read models over canonical BMS objects.


## 0.8.9 — build 2026092408 — Next Period & Rollover
- Explicit Management Cycle continuation with reference-only carryover of unfinished canonical Tasks.
- No Task/Battle Plan cloning and no due-date/history reset.
- Explicit Year → Quarter → Month → Week hierarchy and contained child periods.
- No schema/ACL/services/privacy storage changes.

## 0.8.6.2 build 2026092403 — Battle Plan Approval
- Submitted Battle Plans now have structured reviewer approval.
- Review shows canonical indicators/quotas, task products and plan/fact time.
- Reviewer may approve or return the plan/specific task references for correction.
- Review history is append-only and company-scoped in `local_kbacompany_bplanrev`.
- Correction returns the same plan to draft; no task or plan copy is created.
# 0.8.6.1 — Meeting Preparation
- Checkpoint meetings show previous plan/fact, canonical statistics and quotas, unfinished commitments, current plan and suggested agenda.
- Read-model only; no schema/ACL/services/privacy change.

## 0.8.5 — Management Cycle Templates & Cadence (2026092325)

- Canonical management cycle wrapper with Week / Month / Quarter / Year / Project / Advisory 4×90 templates.
- Cadence checkpoints, owner/curator roles, parent-cycle cascade, canonical indicator bindings and Battle Plan period projection.
- Curator Workspace for launch, overdue checkpoints and closure readiness.
- Additive schema: `local_kbacompany_mcycle`, `local_kbacompany_mcyclecp`.

## 0.8.3 — Management Decision History & Learning
- Added a BMS24-standard management decision history workspace over existing append-only state events and canonical task links.
- Added descriptive recurrence counts by indicator/context binding and search across past cases.
- Recurrence is explicitly non-causal: it does not claim identical root cause or prior action effectiveness.
- Schema/ACL/services/privacy neutral.

## 0.7.8 — Reassessment & Competency Progress
- Approved-assessment progress history and event-based reassessment recommendations.
- BMS24-standard employee competency/progress UI.
- Schema-neutral.

# 0.7.6 — Learning Linkage

- Development Plan actions can link to a company-catalogue Moodle course.
- Course linkage is validated against the KBA Corporate company catalogue.
- Employee enrolment/completion is read live from Moodle and is not persisted as competency attainment.
- Added additive `local_kbacompany_devitem.courseid` field.
- Course completion still requires later formal assessment before competency fact changes.

## 0.7.0.2
- Owner guided workflow now supports proposal-only AI assistance per editable field.
- Business Architecture / Workspace App Shell brand corrected to BMS24.

## 0.7.0.1 build 2026092210
- BMS24 branding and contextual grouped sidebar navigation.

## 0.6.9.11.1 — 2026092206

- Stabilized BMS Assistant integration for Company Philosophy and Market: supported fields are now explicitly allowlisted per domain, and AJAX endpoints do not return raw exception messages.
- Added explicit proposal-only UX: AI output only fills a form after user action; canonical writes still require normal domain validation/save.
- Added `rosstat_open_data`, the first concrete read-only Market Data Tool Gateway adapter. It uses the official Rosstat Open Data catalogue without assuming an undocumented API endpoint.
- Market DetailView can search source candidates and prefill the existing Market Evidence form; no provider result is saved automatically.
- Schema, access.php and services.php are unchanged. local_kbaai remains 0.9.1 build 2026092201.

# Changelog

## 0.6.9.11 — 2026092205

- Company Philosophy CRUD with `PHL-*` stable codes.
- Versioned Decision Principles with `PRN-*` stable codes.
- Enriched Market Assessment CRUD with `MKT-*` stable codes and market position/dynamics fields.
- Versioned Market Evidence with `EVD-*` stable codes.
- Exact published Philosophy/Market pinning for Business Model and Strategy.
- Optional proposal-only BMS Assistant bridge to `local_kbaai`.
- Provider-neutral read-only Market Data Tool Gateway contract.

# 0.6.9.9.3 — Финансовая модель: BMS CRUD — build 2026092203

- Перестроен `financial_model.php` в рабочее пространство BMS CRUD: Обзор / Финансовые сценарии / ЦФО.
- Удалены inline-формы создания сценария и ЦФО из рабочего списка.
- Добавлены отдельные `financial_scenario_view.php` / `financial_scenario_edit.php` и `financial_center_view.php` / `financial_center_edit.php`.
- DetailView показывает Record Header, состояние, основание, ответственность, связанные финансовые контуры, историю редакций и скрытые технические детали.
- ListView получил Company-scoped server-side поиск и фильтры по статусу/типу.
- Stable Code сценария и ЦФО генерируется server-side: `FIN-xxxx` и `CFO-xxxx`; существующие коды сохраняются неизменными.
- ЦФО продолжает ссылаться на canonical Company/Org Unit/Position/Product, Position и BMS Indicator — новая оргструктура или KPI engine не создаются.
- Strategy/Business Model links по-прежнему pin-ят опубликованные revisions.
- Схема БД не менялась; upgrade добавляет только savepoint `2026092203`; access/services/privacy schema неизменны.

# 0.6.9.9.2 — Сотрудники: BMS CRUD — build 2026092202

- Employee ListView no longer contains an inline create form.
- Added dedicated CreateView and moved Save to employee DetailView.
- Added schema-driven employee UI groups and BMS Record Header / Actions pattern.
- Position and organizational unit are read-only projections in Employee EditView and remain owned by Staffing Placement.
- Added company-scoped canonical employee search/status filters and membership history subpanel.
- Moved external IDs, integration diagnostics and reconcile controls out of the working list.
- Russian destructive action is now «Исключить из компании» while preserving soft membership close semantics.
- Schema-neutral; access/services/privacy unchanged; savepoint 2026092202 only.

# 0.6.9.9.1 — Русская терминология бизнес-модели — build 2026092201

- Replaced English BMC abbreviations in the Russian user interface with canonical Russian business terms.
- Methodology flow is now: Требование собственника → Как есть → Факт / Требуются данные / Гипотеза / Открытый вопрос → Как должно быть → Проверка целевой модели → Разрыв → Способ изменения → Показатель результата.
- Evidence counters and Canvas cards use localized labels rather than hard-coded FACT/DATA/HYP/OPEN.
- Internal enum values, UUIDs, Stable Codes and relation codes remain unchanged for compatibility.
- Schema-neutral: only upgrade savepoint `2026092201`; no access/services/privacy/schema changes.

# 0.6.9.8 — SuiteCRM Administrative Scale UX — build 2026092138

- Replaced the inline Administrative Scale editor with ListView → DetailView → EditView navigation.
- Added a schema-driven presentation service so one canonical `adminitem` model renders kind-specific labels and sections without duplicating business logic.
- Fixed label/control readability: labels now sit with their controls in semantic sections instead of relying on the generic two-column `kbacompany-form` grid.
- Added specific field language for Long-term Goal, Goal, Policy, Plan, Program, Program Step, Project, Decision/Order, Task, Ideal State, Statistic and Valuable Final Product.
- Moved `sortorder` into collapsed Advanced options and kept Stable Code read-only/automatic.
- Added DetailView related-record and revision-history subpanels; publish/archive/Battle Plan actions live on the record card.
- Save now returns to DetailView; list rows open record cards.
- Schema-neutral: no install.xml/access/services/privacy changes; only upgrade savepoint 2026092138.

# 0.6.9.7 — Balanced Scorecard Management — build 2026092137

- Reworked `strategy.php` into BSC Overview / Strategic Map / Setup views.
- BSC goals are tracked by multiple existing Indicator Registry bindings and explicit target/range norms.
- Added derived goal health (green/yellow/red/no-data/draft) and trend from canonical fact history; no manual completion percentage is stored.
- Accountability remains Position-first; the UI projects the current employee from active Position Assignments.
- Goal execution summary follows Strategic Goal → Administrative Scale source branch → canonical BMS Tasks → Battle Plan.
- Ownership Intent, Strategy and Strategic Goal stable codes now use the reusable server-side stable-code allocator (`OWN`, `STR`, `GOAL`).
- Schema-neutral: no new KPI/task registries and no install.xml/access/services/privacy changes.

# 0.6.9.6 — Stable Code Autogeneration — build 2026092136

- Added reusable company-scoped `stable_code_service` and monotonic `local_kbacompany_codecounter`.
- Administrative Scale Stable Code is now generated server-side and removed from editable create/update input.
- New records use kind prefixes (`LTG`, `GOAL`, `POL`, `PLN`, `PRG`, `STEP`, `PRJ`, `ORD`, `TSK`, `IDL`, `STAT`, `CKP`) plus a four-digit sequence.
- Existing codes are preserved; new allocation adopts the highest historical generated suffix for the same company/kind.
- Stable code is immutable across revisions and is never reused after archive; UUID remains canonical identity.
- Added regression coverage for company/scope isolation, collision skipping, non-reuse and Administrative Scale code immutability.
- No KBA Corporate, enrolment, Competency, ERP actual, Cloud or Theme business-ownership changes.

# 0.6.9.5 — Administrative Scale Strategy Foundation — build 2026092135

- Promoted the versioned Administrative Scale into the BMS management navigation while keeping routes/menu ownership in `local_kbacompany`.
- Added canonical methodology levels for Long-term Goal, Ideal State, Statistic and Valuable Final Product; Program Step / Project / Task are shown separately as execution support.
- Plan now persists explicit **Situation**, **Goal of this Plan** and **Plan** fields (`situation`, `plangoal`, existing `description`).
- Long-term goals can pin only published Owner Requirement packages; private LPF answers remain outside the corporate strategy surface.
- Statistics reference the existing canonical BMS Indicator/Statistic registry; valuable final products may reference the existing Product registry.
- Published Administrative Scale task definitions create/reuse canonical BMS Tasks and can be added to existing draft Battle Plans; the generated task keeps the exact Administrative Scale source revision and immediate management context.
- Task details now link back to their Administrative Scale source/context so executors can trace why the task exists.
- Existing legacy Administrative Scale records remain readable and are not destructively migrated. New/edited Goal records use the Long-term Goal parent rule.
- Additive schema only: `local_kbacompany_adminitem.situation` and `local_kbacompany_adminitem.plangoal`.
- No changes to KBA Theme business ownership, KBA Corporate learning ownership, enrolment rules, Competency logic, ERP actual ledger, access.php or services.php.

# v0.6.9.2 — Employee Position Projection Hotfix

- Inbound KBA Connect employee sync now accepts Bitrix Contact `POST` as `position`.
- Non-empty CRM position updates the existing Moodle custom profile field with shortname `position`.
- Empty CRM position is non-destructive and never clears an existing profile value.
- The profile value is a compatibility/display projection for KBA Corporate; canonical BMS Position and Position Assignment are not created or modified.
- Added `position_projection` and `profile_position` diagnostics to inbound employee sync results.
- No database schema, ACL, enrolment or competency changes.

## 0.6.9.1 — Employee Contact Projection Hotfix

- Existing Moodle users now receive a valid inbound Bitrix phone when `phone1` is empty or email-like.
- Valid existing Moodle phone values are preserved.
- Moodle `institution` gets a safe BMS Company display projection without replacing unmanaged non-empty values.
- Canonical Company identity remains membership + Company UUID; profile company text is display-only.
- Inbound Contact sync returns phone/company projection diagnostics.
- Employee record shows the Moodle profile company projection separately.
- No schema, ACL, enrolment, Cloud or competency changes.

# 0.6.8 — Cloud Workspace Mapping + Diagnostics (build 2026092129)

- Hardened Company↔Workspace binding and added versioned Cloud projection diagnostics.
- Added ERP application state and provider-neutral integration health to local Cloud projection.
- Added restricted Cloud API endpoints for Workspace projection.

## 0.6.7 — Entitlement Snapshot Client / Cloud Contract — build 2026092128
- Added `local_kbacompany_entsnap` validated last-known-good snapshot storage.
- Added `entitlement_snapshot_service` and Cloud-snapshot resolution in `entitlement_service`.
- Added disabled-by-default restricted Cloud API v1 and `local/kbacompany:cloudsync`.
- Added snapshot diagnostics and safe legacy-compatible rollout.

## 0.6.6 — Provider-Neutral Outbox / Integration Contract — build 2026092127

- Added durable provider-neutral `local_kbacompany_eventout` with at-least-once lifecycle and stable idempotency.
- Existing KBA Connect `local_kbacompany_syncout` command contract remains unchanged.
- Legacy company/employee commands dual-write sanitized provider-neutral change notifications.
- Neutral events contain no Bitrix IDs, Moodle user IDs, employee contact PII or Corporate IDs; consumers refresh canonical snapshots through Public API v1.
- Event envelope upgraded to schema 1.1 with producer, optional Workspace projection and correlation ID.
- Added independent event diagnostics and regression tests.
- Additive schema only; no user-facing UX or capability changes.

## 0.6.5 — BMS Public API Foundation — build 2026092126

- Added stable read-only `BMS Management Public API v1` for future BMS Cloud / Integration Hub / Odoo consumers.
- Added provider-neutral Company, Employee, Organization, Position, Indicator and approved-Budget snapshots.
- Added versioned snapshot envelope and stable payload hash for change detection.
- Public DTOs hide provider-specific Bitrix IDs, legacy storage row IDs and internal assignment IDs.
- Employee contact data is opt-in and requires organization-management permission.
- Budget export contains approved plan/limits only; operational actual ledger remains ERP/provider-owned.
- Added disabled-by-default restricted Moodle service `local_kbacompany_public_v1`.
- No DB schema, capability or current UX changes.

# v0.6.4 — BMS Platform Boundary & Company Isolation Hardening — build 2026092125

- Added canonical `company_context_service` and migrated BMS service context checks to the single Company UUID boundary.
- Added additive `local_kbacompany_cloudmap` as a projection of future external BMS Cloud Workspace identity; existing companies remain unmapped and legacy-compatible.
- Added `entitlement_service` foundation: legacy mode preserves current access, suspended denies, future Cloud snapshot mode fails closed until a valid snapshot is available.
- Added provider-neutral `platform_event_envelope` with stable idempotency key; existing KBA Connect outbox remains unchanged.
- Added management-only Platform diagnostics and `local_kbacompany_platform_context` read API.
- Explicitly company-scoped Staffing Plan child reads and expanded duplicate-company conflict protection for Finance, Resource Gate and Cloud mapping state.
- Added privacy metadata/export/anonymisation for Cloud Workspace mapping actor IDs.
- BMS Cloud Registry, billing, Odoo provisioning and ERP actuals remain outside Moodle.

# v0.6.3.1 — BMS App Shell Hotfix — build 2026091524

- Fixed fatal `Call to undefined method local_kbacompany\local\bms_page_shell_service::end()` on Financial Model page.
- Fixed the same latent defect on Resource Gate page.
- Replaced both invalid `end()` calls with canonical `finish()` calls.
- Schema-neutral; KBA Connect / KBA Corporate unchanged.

# v0.6.3 — Resource Gate — build 2026091523

- Added versioned ResourceGate / ResourceGateItem readiness assessment for goal/program/project launch.
- All seven resource dimensions are explicit before submission: money, people, competencies, time, technology, information and infrastructure.
- Required checks must pass or be explicitly waived with evidence before Ready.
- Approved budgets, canonical positions/staffing, read-only business-role competency profiles and controlled documents are referenced instead of copied.
- A new blocked decision supersedes an earlier Ready decision; a draft/submitted amendment does not.
- No payment, staffing, training, procurement, asset-registry or ERP execution is introduced.
- Additive schema only; build `2026091523`.

## 0.6.2 — Budgeting — build 2026091522

- Added versioned budgets for Administrative Scale goals, programs and projects plus canonical OFS organization units.
- Every budget pins an exact published Financial Scenario revision and may pin an exact published Financial Responsibility Centre revision.
- Added immutable budget-line snapshots with plan amount, spend limit, responsible Position, optional Indicator and source/basis.
- Added explicit `draft → submitted → approved/rejected` lifecycle; approved revisions remain immutable and effective while a later amendment draft is prepared.
- Submission/approval is blocked when a spending/investment/financing/reserve plan exceeds its line limit.
- Added aggregate management fact snapshots with mandatory source provenance and derived plan/fact variance; these are not ledger transactions or bank facts.
- Added company-scoped Budgeting workspace under the existing Financial Model App Shell; KBA Theme remains presentation-only.
- Added privacy metadata/export/anonymisation for Budget, BudgetLine and BudgetFactSnapshot actor fields.
- Additive schema only: `local_kbacompany_finbudget`, `local_kbacompany_finbudline`, `local_kbacompany_finbudfact`; existing capabilities and external services are unchanged.
- KBA Connect and KBA Corporate remain unchanged. Next roadmap step: v0.6.3 Resource Gate.

## 0.6.1 — BDR / BDDS / Forecast Balance — build 2026091521

- Added versioned planned management financial statements pinned to an exact published Financial Scenario revision and, optionally, an exact published Financial Responsibility Centre revision.
- Added BDR with derived contribution margin, operating profit, profit before tax and net profit; calculated rows are not persisted as editable duplicate facts.
- Added four-week BDDS with automatic opening/closing cash roll-forward and explicit negative-cash diagnostics.
- Added forecast balance with publish-time `Assets = Liabilities + Equity` validation.
- Added company-scoped Financial Statements workspace under the existing BMS App Shell; KBA Theme remains presentation-only.
- Added privacy metadata/export/anonymisation for FinancialStatement and FinancialStatementLine actor fields.
- Additive schema only: `local_kbacompany_finstmt` and `local_kbacompany_finline`; existing tables, capabilities and external services are unchanged.
- v0.6.1 stores plan/forecast inputs only and does not import accounting/bank/ERP facts, execute payments or implement v0.6.2 budget approvals/limits.

## 0.6.0 — Financial Model foundation — build 2026091520

- Added versioned Financial Scenarios with base / stretch / crisis / custom types, planning horizon, currency and explicit revision-pinned links to published Strategy and Business Model records.
- Added versioned Financial Responsibility Centers linked to canonical BMS Company / OFS org unit / Position / Product targets instead of copying organizational structure.
- Centers may reference a canonical responsible Position and Indicator and define result, authority rule and review period.
- Added company-scoped Financial Model workspace inside the existing BMS App Shell; KBA Theme remains presentation-only.
- Published revisions are immutable; edit creates a new draft revision and archive preserves history.
- Duplicate scenario/center codes and duplicate active responsibility-center targets are protected under the company-scoped lock.
- Added privacy metadata/export/anonymisation for creator/modifier IDs of the new records.
- Additive schema only: `local_kbacompany_finscenario` and `local_kbacompany_fincenter`; no changes to capabilities or external services.
- v0.6.0 deliberately does not store BDR/BDDS/forecast balance/budget facts or mutate ERP/accounting data; those remain later roadmap stages.

## 0.5.17 — Employee CRM UX — final build 2026091519

- Added employee record page and clickable employee names with membership-aware record identity.
- Added compact View / Edit / Delete actions in the employee registry.
- Added canonical Moodle user editing with outbound `employee_upsert` reconciliation and Moodle same-email policy handling.
- Changed user-facing deletion to an explicit confirmation-page soft removal from company; removed the legacy direct close POST path.
- Soft removal preserves Moodle User, Bitrix Contact and historical BMS data and queues `employee_close`.
- Sync state now reflects pending/error `employee_upsert` and `employee_close` commands even when a Contact mapping already exists.
- Integration diagnostics recognize both the KBA Connect bridge service and `process_bms_bridge` scheduled-task consumer.
- Kept KBA Corporate compatibility rows read-only.
- Schema-neutral; no capability, service, privacy or install-schema changes. Draft build 2026091518 is superseded by final build 2026091519.

## 0.5.16.3
- Fixed outbound employee phone mapping for existing Moodle users.
- Added protection against email-like values in `phone1`.

## 0.5.16.2 — Company Adoption & Integration Stabilization — build 2026091516

- Reconciles a fresh Bitrix-created canonical Company duplicate to the pre-existing legacy-preserved Company UUID when KBA Connect supplies the matching Corporate company ID.
- Transfers only the external Company mapping, active canonical memberships and unfinished integration commands; completed outbox history is retained on the superseded row.
- Refuses automatic reconciliation when the integration duplicate already owns independent BMS business data.
- Corrects KBA Connect consumer detection in BMS integration diagnostics.
- Schema-neutral; no DDL, capability or external-service changes.

## 0.5.16.1 — Canonical Company Membership Null Safety — build 2026091515

- Fixed canonical Company list/context for administrators and other authorised viewers without a `local_kbacompany_member` row.
- Moodle `$DB->get_record()` returns `false` when no record exists; the value is now normalised to `null` before strict `membership_roles(?stdClass)` projection.
- Added regression coverage for a site administrator viewing an integration-created canonical company without membership.
- Schema-neutral; no DDL or permission/service changes.

## 0.5.16 — BMS Company & Employee Sync — build 2026091514

- Added canonical BMS Company registry, historical employee memberships, provider-neutral external mappings and durable integration outbox.
- New Bitrix companies enter BMS only after explicit `Клиент BMS = Да`; removing the flag archives rather than deletes.
- Added inbound KBA Connect company/contact upsert contract and outbound BMS employee reconciliation commands.
- Added company-scoped Employees UI with legacy Corporate-member compatibility projection and integration diagnostics.
- Moodle User remains the person identity; course enrolment remains Deal-driven.
- KBA Corporate remains an independent learning bounded context; no direct writes to `local_kbacorp_*`.
- Corporate Learning roles do not automatically become BMS management permissions.
- Privacy metadata/export/deletion expanded for memberships, external user mappings and outbox records.
- Additive schema upgrade only; `db/access.php` and `db/services.php` unchanged.

## 0.5.15 — Stabilization / Weekly Management Acceptance (build 2026091513)

- Кумулятивно закрыт запланированный 0.5.14: экран готовности получил явный пакетный submit/approve выбранных планов через `battle_plan_service::transition()` по одному плану, без bulk UPDATE статусов.
- Добавлен schema-neutral `weekly_management_acceptance_service`: приёмка недели вычисляется из coverage + readiness + management overview + канонических integrity-инвариантов.
- Жёсткие блокеры: отсутствующий недельный план, blocked/draft/submitted план, дубликат weekly plan key, orphan bplanitem или ссылка на отсутствующий canonical Task.
- Пробелы критических KPI/норм, требующие решения исключения и просроченные задачи остаются сигналами внимания, а не новой state machine.
- Канонический `battle_plan_service::transition()` теперь повторно валидирует reviewer для submit/approve/selfapprove, устраняя расхождение между readiness UI и серверным lifecycle.
- Приёмка не сохраняется в БД; после исправления данных или переходов она пересчитывается on-read.
- Релиз schema-neutral: новых таблиц, capabilities и external services нет.

## 0.5.14 — Management Week Batch Transition (bundled into 0.5.15)

- Явно выбранные ready draft/submitted планы обрабатываются пакетно, но каждый переход выполняется каноническим `battle_plan_service::transition()`.
- Нет фоновой автоотправки/автоактивации и нет прямого массового изменения `status`.
- Повторная отправка безопасно пропускает планы, уже прошедшие запрошенный переход.

## 0.5.13 — Management Week Readiness (build 2026091511)

- Readiness остаётся read-model и повторяет существующий lifecycle.
- Hard blockers: валидный reviewer и наличие задачи перед отправкой draft.
- Пробелы critical KPI/norm остаются advisory.

## 0.5.12 — Management Week Launch (build 2026091510)

- Экран покрытия получил явный пакетный запуск недостающих Боевых планов по выбранным назначениям.
- Наличие назначения само по себе никогда не создаёт Боевой план: требуется POST-команда руководителя с выбранными строками.
- Проверяющий выбирается явно; наблюдатель опционален и также выбирается явно.
- Повторный запуск той же пары `employee + position + week` идемпотентно возвращает существующий план.
- Для исторических недель сервер повторно проверяет фактическое назначение по `effectivefrom/effectiveto`; обычный `create_plan()` остаётся строгим к текущей должности.
- Запуск пишет только в канонический `local_kbacompany_bplan`; отдельного реестра запуска недели нет.
- Релиз schema-neutral: `install.xml`, capabilities, external services и privacy provider не меняются.

## 0.5.11 — Battle Plan Coverage & Management Gaps (build 2026091509)

- Новый schema-neutral `battle_plan_coverage_service` вычисляет покрытие недельными Боевыми планами по фактическим назначениям сотрудников на должности.
- Ожидаемый план считается по уникальной паре `employee + position + week`, а не по количеству штатных слотов.
- Историческое покрытие использует `effectivefrom/effectiveto` назначений и не подменяет старую неделю текущим составом.
- Добавлен экран `battle_plan_coverage.php` с фильтрами по неделе, подразделению и типу пробела.
- Выявляются назначения без Боевого плана, активные должности без критических показателей и критические привязки без действующей на неделю нормы.
- Пробелы агрегируются по подразделениям; отдельный реестр контроля не создаётся.
- `install.xml`, capabilities, external services и privacy provider не меняются.

## 0.5.10 — Battle Plan Management Overview (build 2026091508)

- Новый read-model `battle_plan_management_overview_service` агрегирует доступные активные недельные Боевые планы без summary-storage.
- Добавлен экран `battle_plan_overview.php` с фильтрами по неделе, подразделению, руководителю/проверяющему и сигналу внимания.
- Обзор повторно использует canonical position indicator bindings, Management Exceptions и canonical Task.
- Добавлены групповые сводки по подразделениям и руководителям.
- `install.xml`, capabilities, external services и privacy provider не меняются.

## 0.5.6 — Battle Plan Indicator Header (build 2026091504)

- Боевой план получил верхний read-model канонических недельных показателей, связанных с должностью через `statbind`.
- Для каждого показателя показываются прошлый факт, действующая на неделю норма, состояние, текущий факт и тренд.
- Исторический Боевой план берёт до 52 последних периодов `statval`, заканчивающихся выбранной неделей; нормы и политики разрешаются по своим периодам действия, а подтверждение руководителя сопоставляется только с тем же фактическим периодом.
- Ввод факта из Боевого плана добавляет новую append-only ревизию в `local_kbacompany_statval`; отдельная статистика Боевого плана не создаётся.
- Подтверждённое состояние и canonical Task, созданный из события состояния, проецируются в строку показателя с сохранением Task ACL.
- Старый pinned quota-контур сохранён только как явно обозначенная совместимость; обе формы записи факта используют общую блокировку канонической статистики.
- DOCX-экспорт предпочитает канонические показатели должности и использует legacy quota metrics только как fallback для исторических планов.
- Релиз schema-neutral: новых таблиц, прав и external services нет.

## 0.5.2 (build 2026091385)

- Завершён schema-neutral аналитический слой показателей поверх canonical `stat/statval`.
- Выделены отдельные сервисы расчёта формы тренда и аналитических событий.
- Удалён рабочий порог ±2%; классификация использует точные сравнения трёх последних фактических наблюдений.
- Форма тренда и управленческая оценка разделены.
- Добавлены события: разворот, новый максимум, новый минимум, выход за норму, возврат в норму.
- Добавлены фильтры по растущим, снижающимся, ухудшающимся и показателям с недостаточными данными.
- Тренд отображается в Информационном центре, карточке и общем списке показателей; события — в карточке.
- Новых таблиц и копий `statval` не создаётся.

### 0.5.2 draft build 2026091384

- Предварительная реализация вкладки трендов; заменена build 2026091385 до production release.

## 0.5.1.3 (build 2026091383)
- Indicator creation is always visible to indicator managers, even when no product/CKP exists.
- Product/CKP link is optional for new indicators.
- Indicator creation and value entry now use indicator-scoped permissions for Administrator, Owner, Manager, Управляющий and Супервайзер.


## 0.5.1.2 — Indicator role permissions

Indicator registry management is available to Moodle site administrators, company owners/managers, and employees holding the active Business Roles `Управляющий` or `Супервайзер` in the selected company. The Business Role grant is scoped only to indicator passports, bindings, norms and values; it does not broaden permissions in other BMS modules.

## 0.5.1.1 — SQL DDL hotfix (build 2026091381)

- Fixed MySQL/MariaDB DDL failure caused by reserved keyword `MAXVALUE`.
- Renamed statnorm columns to `minval` / `maxval` in install and upgrade paths.
- Added idempotent field rename for databases where 0.5.0 schema had already been created.

## 0.5.1 — Информационный центр и история показателей (build 2026091380)

- Добавлен Информационный центр как read-model поверх единого реестра показателей.
- Организационные карточки группируют показатели по верхним подразделениям ОФС и показывают факт, норму, динамику и мини-график.
- Добавлен стратегический обзор по перспективам ССП.
- Карточка показателя получила графики план/факт на 13, 26 и 52 периода.
- Добавлен drill-down к связанным должностям, подразделениям, стратегии и компании.
- Статус «В норме / Отклонение» трактуется только как контроль нормы и не подменяет будущий движок формул управленческих состояний.
- Схема БД не меняется; используются canonical `stat/statval` и versioned `statmeta/statbind/statnorm` из 0.5.0.

## 0.4.10.6 — Краткая и полная форма задачи (build 2026091371)

## 0.4.11.1 — CRUD шаблонов и категорий прямо в библиотеке

- Кнопки добавления шаблона и категории выведены непосредственно на страницу библиотеки.
- Категории редактируются с панели категорий; удаление выполняется архивированием.
- Карточки шаблонов содержат прямые действия редактирования и архивирования.
- Архив категорий и шаблонов доступен с основной страницы и поддерживает восстановление.
- Управление библиотекой разрешено собственнику и менеджеру компании без скрытого режима `manage=1`.
- Схема БД не меняется; используются таблицы библиотеки, добавленные в 0.4.11.

- Новый прогрессивный UX создания и редактирования задач.
- Краткая форма по умолчанию, полная форма по ссылке.
- Группировка участников и дополнительных параметров.
- Desktop split-view с обсуждением справа; responsive stacking на узком экране.
- Schema-neutral upgrade.

## 0.4.10.5 — Компактная карточка задачи (build 2026091370)

- Убрано дублирование строки наблюдателя; выбор и ссылка на профиль собраны в один компактный ряд.
- Источник задачи показывается как тип источника + действие, без повторения длинного названия задачи.
- План/факт времени отображается как «План: N мин · Факт: M мин».
- Период включённого Боевого плана стал кликабельным и показывается компактно вместе с потоком работы.
- Подтверждён порядок «Предъявить результат → Обсуждение задачи».
- Schema-neutral patch.

## 0.4.10.4 — Улучшения карточки задачи и управленческий контекст (build 2026091369)

- Task discussion moved after result submission in the task workspace.
- Planned minutes is rendered as an integer numeric input.
- Added version-pinned management context link for goals, policies, plans, programmes and projects.
- Assignee and observer names link to Moodle user profiles.
- Removed any free-text assignee path from the task form; company employee selector is canonical.

# 0.4.9 — Recurring Tasks & Template Library — 2026-09-13

## 0.4.9.2 — Task Templates to Battle Plan (build 2026091361)

- Added direct Task Library → Battle Plan workflow.
- A template creates one one-off canonical Task only after the user confirms the expected product and execution parameters.
- The task is assigned to the Battle Plan employee and inherits the plan reviewer.
- Template provenance is stored as `sourcekind=template` with stable template UUID/revision.
- Duplicate selection of the same template for the same employee/week is idempotent.
- Recurring task series behavior remains unchanged.
- Schema-neutral upgrade from 0.4.9.1.

- Added company-scoped immutable recurring task definitions and scheduled occurrence generation into the canonical Task Center.
- Added built-in recurring-task methodology library sourced from Google Drive file `1c0kbItTDOZOV5PjzQ8anS5Nd9VTsEkNRZPRYRT0kcS4`; runtime has no Drive dependency.
- Added 94 source templates across Owner, Executive Director, Personnel, Facility, System Administration, Marketing, Sales, Finance, and Procurement/Delivery categories.
- Library templates always create DRAFT definitions; expected product, assignee, cadence confirmation and start date are required before activation.
- Generated occurrences use `sourcekind=recurring`, pin the exact recurring-definition revision and remain ordinary canonical BMS tasks for Task Center/Battle Plan.
- Added hourly idempotent scheduled generator plus manual due-today generation action.
- Added privacy coverage for recurring definitions.
- Added explicit install-package naming to prevent Release Kit ZIPs from being selected in Moodle's plugin installer.

# 0.4.8 — Administrative Cycle — 2026-09-13

- Added customer-facing `administrative_cycle.php` and `administrative_cycle_service` for the management path `Strategy/BSC → Goal → Policy → Plan → Program → Project → Meeting → Decision → Task → Battle Plan → Result`.
- Administrative Cycle planning editor exposes only Goal / Policy / Plan / Program / Project; canonical Meeting Decision and Task are projected instead of duplicated.
- Added immutable `sourcekind/sourceuuid/sourcerevision` pin on Meeting for exact published Administrative Project revisions.
- Added project → participant-visible meetings → decisions → canonical tasks → Battle Plan references → recorded task results projection.
- Preserved private meeting and Battle Plan access boundaries; the cycle is not a privacy bypass.
- Hid the pre-0.4.8 Administrative Scale route from customer navigation while retaining its storage/service as a compatibility layer; legacy Administrative Cascade remains hidden and untouched.
- Additive migration only; no destructive data rewrite. Build `2026091358`.

# 0.4.7 — Meetings & Decisions — 2026-09-13

- Added participant-scoped meetings, agenda, minutes/results and governed decision lifecycle.
- Added accepted decision → canonical Task conversion and reverse traceability.
- Added four additive meeting/decision tables. Build `2026091357`.

# 0.4.6.4 — Workspace Navigation Optimization

- Simplified the BMS App Shell sidebar into Work / Management / More.
- Removed the duplicate business-architecture tree from the daily workspace.
- Added a dedicated Business Architecture Center (`bms_architecture.php`).
- Replaced inventory-first workspace KPI cards with personal daily-work counters: Inbox, Tasks, To review, Learning.
- Added a compact Needs Attention block for overdue work, incoming communication and tasks waiting for review.
- Added quick actions for Task and Message without hardcoding them in KBA Theme.
- Moved process/function/org navigation into the dedicated architecture tree rather than repeating every object in the global sidebar.
- Preserved KBA Theme as optional presentation enhancement; routes, permissions and menu contents remain owned by BMS Core.
- No database schema or business-data migration.

## 0.4.6.1 — 2026-09-13

- Fixed Moodle XMLDB dependency failure in Battle Plan migration.
- Drop legacy `task_uix` before changing `taskuuid` default.
- Recreate provider-aware `reference_uix` after data backfill.
- Migration remains idempotent for retry after a partially failed 0.4.6 upgrade.
- No destructive business-data migration.

## 0.4.6 — 2026-09-13

### Unified Communication & Task Center on App Shell source-of-truth
- Rebased Communication Core and unified Task Provider work on top of BMS App Shell 0.4.5 build `2026091336`.
- Added participant-scoped threads/messages, mentions, notifications, read state and message → canonical Task traceability.
- Added provider projections for canonical BMS tasks, legacy KBACorporate workflow tasks and explicitly bound KBA Element learning work.
- Added Communication Center tabs: Inbox, Messages, Tasks, Discussions and Notifications.
- Battle Plan can reference `(provider, externalid)` without cloning provider-owned work.
- BMS Workspace route model now includes Communication Center and uses unified work counters/tasks.
- Preserved renderer-agnostic App Shell bootstrap and pagelayout-before-`$OUTPUT` ordering from 0.4.5.
- Rebased missing-schema upgrades to builds `2026091337` and `2026091338` so upgrades from installed 0.4.3/0.4.4/0.4.5 execute correctly.
- Database change is additive/idempotent; no destructive business-data migration.

## 0.4.5 — 2026-09-13

### BMS output bootstrap compatibility hotfix
- Removed renderer type dependency from `bms_ui_service`.
- BMS UI service now returns semantic icon descriptors instead of rendered icon HTML.
- `bms_workspace.php` selects `bms`/`standard` pagelayout before the first `$OUTPUT` call.
- Icon rendering is deferred to the page adapter after Moodle output bootstrap is safe.
- Fixes Moodle 4.0 `bootstrap_renderer` errors before BMS App Shell rendering.
- No database, permissions, task, organization or business-logic changes.


## 0.4.4 — 2026-09-13

### BMS App Shell renderer compatibility hotfix
- Fixed fatal type mismatch on `bms_workspace.php` under Moodle 4.0/KBA Theme where `$OUTPUT` can be a `bootstrap_renderer`.
- `bms_ui_service::workspace_model()` and its route builder now accept the common Moodle `renderer_base` contract instead of requiring `core_renderer`.
- No database changes, no business logic changes, no permission changes, no theme changes.

# Changelog

## 0.4.2.2 — Unified Task & Communication Center
- Added `TaskProviderInterface` and providers for canonical BMS tasks, legacy KBACorporate workflow tasks and KBA Element learning work.
- Added `Communication Center` tabs: Inbox, Messages, Tasks, Discussions and Notifications.
- Added explicit company task-source bindings (`local_kbacompany_tasksrc`).
- KBA Element is read-only and opt-in by bound Moodle course; standard completion is projected to task status without copying submissions, grades or evidence.
- Restricted KBA Element source discovery to courses the binding actor may view, including hidden-course capability checks.
- Battle Plan can reference provider-owned work by provider/external id without cloning it into canonical BMS tasks.
- Added manager company-wide unified task projection for BMS/legacy and bound KBA Element work.
- Added restricted read APIs for unified tasks and Communication Center plus privacy coverage and regression tests.
- Build 2026091235; one additive table plus additive Battle Plan reference fields/index migration.

## 0.4.2.1 — Communication Core
- Added participant-scoped direct, group and object-context communication threads.
- Added append-only messages, per-user read state, explicit mentions and separate notification records.
- Added reference-only communication attachments; uncontrolled binary copies are not introduced in the core.
- Added `communications.php` and restricted read API `local_kbacompany_communication_context`.
- Added message → canonical Task conversion with `sourcekind=communication` and source-message traceability.
- Extended centralized permission contract with communication administration while explicitly preventing manager-role read bypass for private content.
- Extended Moodle privacy metadata/export/deletion handling for threads, participants, messages, mentions, notifications, read state and attachment references.
- Added Communication Core regression tests including private-thread isolation, mentions, read state and message-to-task traceability.
- Build 2026091234; seven additive tables; existing Task Center, Battle Plans and legacy execution data are not rewritten.

## 0.4.2 — Task Center & Battle Plan
- Added standalone canonical Task Center, separate from legacy KBA Corporate workflow execution.
- Added manual tasks plus idempotent task generation from exact published Administrative Scale and Position Work Program revisions.
- Every task requires an expected Product/result and preserves source traceability.
- Added assignee/reviewer workflow, priority, deadline, planned/actual minutes, result acceptance, checklist, parent/subtask hierarchy and execution history.
- Added optimistic task revision checks, parent-cycle protection and source-state checks.
- Added weekly Battle Plan as references to canonical tasks, never copied tasks.
- Added Change / Run / Operate / Develop / Unplanned work streams and Position KPI/statistic references.
- Added Battle Plan draft → submitted → active → closed approval lifecycle; closing blocks unfinished work.
- Added Task Center and Battle Plan pages, restricted read APIs, privacy coverage and regression tests.
- Legacy `wfwork`/`execution.php` remains intact for history/backward compatibility; no implicit migration.
- Database 48 → 53 tables, additive only; build 2026091233.

## 0.4.1 — Administrative Scale
- Added canonical versioned Administrative Scale: Goal → Organizational Policy → Plan → Program → Project → Decision/Order → Task.
- Root goals pin exact published BSC Strategic Goal revisions.
- Organizational-policy items pin published controlled policies; decisions may pin published management directives.
- Every child pins the exact published parent revision and publication blocks stale dependencies.
- Added expected result/product, accountable Position, optional CKP/Product, Metric/target and period fields.
- Added full upward traceability in the user workspace and read API.
- Legacy `acobj` Administrative Cascade remains compatibility data and is not migrated automatically.
- Database 47 → 48 tables, additive only; build 2026091232.

## 0.4.0 — Ownership / Strategy / BSC
- Added versioned Ownership Intent with explicit long-term goal.
- Added versioned Strategy linked to Ownership Intent and optional published Business Model.
- Added Balanced Scorecard strategic goals across Finance, Customers, Processes and People/Development.
- Strategic goals reuse canonical BMS statistics, processes and accountable positions instead of duplicating them.
- Added versioned acyclic causal links for the strategic map.
- Added client-facing Strategy workspace, restricted read API, privacy coverage and regression tests.
- Database 43 → 47 tables, additive only; no existing business data is rewritten.

## 0.3.5 — Market & Business Model
- Added versioned Market, Customer Segment, Offering and Business Model objects.
- Added value-proposition and Core-process linkage.
- Added client-facing Market & Business Model workspace and read API.
- Database 39 → 43 tables, additive only.


## 0.3.4 — Position Operating System
- Generated job-description projection from Position, CKP and published canonical Functions.
- Versioned Position Work Program with daily/weekly/monthly/quarterly/yearly/event frequencies.
- Digital Job Folder surfaces current effective controlled documents and source STP documents.
- Position Work Program pins source Function revision and flags outdated source revisions.
- Added read-only Position Operating System API and organization navigation.
- Database expanded additively from 38 to 39 tables; no business data migration.

# v0.3.3 — ISO Document Control

- Added versioned controlled-document model and five management-system document levels.
- Added review/agreement/approval/publication/effectiveness lifecycle with retained review evidence.
- Added generated Master List, revision-specific acknowledgements and immutable Form records.
- Added controlled STP snapshots tied to exact published Process Model revisions and SHA-256 fingerprints.
- Activated `document` in Semantic Core and Company Core API.
- Added `documents.php`, `document_service`, external read API, privacy coverage and regression tests.
- Database grows from 34 to 38 tables through additive upgrade `2026091228`; no business-data migration.

# v0.3.2 — Process → Function → Position

- Добавлена каноническая versioned-модель Function как устойчивой ответственности за продукт, а не копии BPMN Task.
- Реализована трассировка `Process → Activity → Function → Position` с фиксацией source Process Model revision, action и output.
- Если Task исполняется Position, должность предлагается автоматически; OrgUnit/Business Role не превращаются в фиктивную Position.
- Публикация Function требует опубликованного source Process Model, ответственной должности и продукта/результата.
- Добавлен контроль `source_outdated` и явное принятие новой версии операции без скрытого переписывания продукта функции.
- Добавлены `function_service.php`, `functions.php`, API `local_kbacompany_functions_context` и regression tests.
- STP показывает связанный Function для каждой Task либо действие создания Function из операции.
- Semantic Core и OFS поддерживают canonical Functions и прежние `acobj:function` через compatibility path; destructive migration не выполняется.
- Privacy provider учитывает authorship Function revisions.
- Build 2026091227; 34 таблицы; миграция additive.

# v0.3.1 — STP + RACI

- СТП теперь генерируется из единой versioned-модели SIPOC + BPMN и не хранится как независимая копия бизнес-данных.
- Добавлен экран `stp.php` с SIPOC, процедурой, продуктами операций, RACI и связанными показателями/ресурсами/документами/записями/контролями.
- R/Responsible выводится из исполнителя BPMN Task; дополнительные R/A/C/I хранятся в новой version-scoped таблице `local_kbacompany_procraci`.
- Process Model получил типы Resource, Record, Control и привязку process annotation к конкретной Task через `activityuuid`.
- Published Process Model остаётся immutable; новая draft-редакция клонирует RACI вместе с elements/flows, сохраняя стабильные UUID.
- Добавлен restricted read API `local_kbacompany_stp_context`.
- Добавлен HTML export generated STP; controlled document lifecycle / Master List остаются за v0.3.3.
- Добавлены regression tests STP/RACI, company-scope, duplicate guard, immutable published model и clone semantics.
- Build 2026091226; 33 таблицы; миграция additive, существующие business data не переписываются.

# v0.3.0 — SIPOC + BPMN Designer

- Добавлена единая versioned-модель процесса: SIPOC + BPMN как два представления одного источника данных.
- Добавлены таблицы `procmodel`, `procelem`, `procflow`; существующие 29 таблиц и business data не переписываются.
- SIPOC использует канонические Supplier/Customer связи Process Architecture и основной Product/ЦКП процесса; входы/дополнительные выходы хранятся структурировано.
- BPMN subset: Start, End, Task, Gateway, Event, Lane, Sequence Flow, Document/System references.
- Каждое Task требует действие, исполнителя и продукт/результат; проверяется company scope исполнителя и продукта.
- Опубликованная модель неизменяема; новая draft-редакция клонирует published snapshot с сохранением UUID элементов.
- Publication gate проверяет полноту SIPOC, Start/Task/End, flow endpoints и связность BPMN.
- Semantic Core `activity` активирован и отображает только BPMN Tasks; `document` остаётся reserved до v0.3.3.
- Добавлены `process_design.php`, `process_model_service.php`, restricted API `local_kbacompany_process_model_context` и regression tests.
- Privacy provider учитывает авторство Process/Process Model.
- Build 2026091225.

# v0.2.9.2 — Process Architecture

- Добавлена каноническая versioned-модель Process с классификацией Management / Development / Core / Support и уровнями L0/L1/L2.
- Процесс хранит владельца-подразделение, продукт/ЦКП, цель, родителя и append-only revisions со статусами draft/published/archived.
- Добавлены explicit semantic links: поставщик, потребитель результата, участвующее подразделение и показатель процесса.
- `process` стал текущим типом Semantic Core; `activity` и `document` остаются зарезервированными.
- Добавлен клиентский экран `processes.php` и restricted read API `local_kbacompany_process_context`.
- OFS показывает канонические процессы по владельцу и продукту без жёсткого правила «отдел = процесс».
- Публикация дочернего процесса требует опубликованного родителя, владельца и продукта.
- Build 2026091224; новая таблица `local_kbacompany_process`; существующие business data не мигрируются.

# v0.2.9.1 — OFS + CKP

- Добавлен `ofs_service`: продуктово-функциональная проекция ОФС без дублирования canonical objects.
- Реестр ЦКП теперь поддерживает уровень компании (`targettype=company`) наряду с подразделениями и должностями.
- Добавлен клиентский экран `ofs.php`: ЦКП компании, подразделений, продукты функций, связанные статистики и внутренние продуктовые связи.
- Внутренний потребитель хранится явной append-only semantic relation `orgunit consumes product`; поставщик выводится из canonical product target.
- Продукт функции повторно использует существующую связь Function → Result → Product; отдельная копия функции или ЦКП не создаётся.
- Добавлен restricted read API `local_kbacompany_ofs_context`.
- Process links намеренно зарезервированы до v0.2.9.2; фиктивные Process objects не создаются.
- Build 2026091223; 28 таблиц без изменений; upgrade savepoint-only.

# v0.2.9.0 — BMS Semantic Core

- Добавлен `semantic_service` с общей BMS vocabulary и canonical source mapping.
- Добавлена append-only таблица `local_kbacompany_semrel` для явных cross-domain relations.
- Поддержаны relation types: produces, owns, executes, belongs_to, measures, requires, supports, governed_by, derived_from, impacts, supplies, consumes, responsible_for, contributes_to.
- Канонические Company/ЦКП/Goal/OrgUnit/Position/Function/Metric/Policy не дублируются в новой таблице.
- Process/Activity/Document объявлены reserved types и отклоняют запись до релизов их доменных моделей.
- Добавлен restricted read API `local_kbacompany_semantic_context`.
- Добавлены regression tests семантического контракта, canonical projection, create/archive, duplicate и reserved-type guard.
- Build 2026091222; 28 таблиц; миграция additive: создаётся одна пустая relation table, существующие business data не преобразуются.

# v0.2.8.0.1 — Core Stabilization

- Синхронизирован Company Core contract до 0.2.8.0.1; политики/директивы и permission policy включены в machine-readable contract.
- Добавлен централизованный `permission_service`; сервисы организации и управленческого контура больше не содержат собственные role checks.
- Добавлен restricted read API `local_kbacompany_policies_context`.
- Добавлена optimistic conflict protection при применении проектов оргсхемы: новые проекты сохраняют SHA256 fingerprint live-узлов и прямого подчинения.
- Legacy snapshots без fingerprint защищаются сравнением `timemodified` узла/прямого подчинения с моментом создания проекта.
- Добавлены regression tests для permission policy и конфликтов live apply.
- Build 2026091221; схема БД — 27 таблиц без изменений; миграция — savepoint only; business data не изменяются.
- Runtime Moodle/PHPUnit acceptance требуется на стенде; статические проверки выполняются release pipeline.

# v0.2.7.3 — Согласование, применение и PDF оргсхемы

- Сравнение версий узлов, должностей и функций по стабильным кодам.
- Статусы: черновик → согласование → утверждение → применение; возврат на доработку с причиной.
- Передача на согласование блокируется до устранения замечаний по ответственности.
- Явное применение утверждённых узлов без удаления остальных действующих подразделений.
- Прямое подчинение сохраняется отдельно от визуальной иерархии в новой таблице orgreport.
- Должности, штатное расписание, расстановка и назначения при применении не меняются.
- PDF A0–A4: плакат или многостраничный режим, русские шрифты, версия, статус, маркировка черновика и режимы «должности / ФИО / ФИО+фото» по действующей расстановке.
- Build 2026091219; одна новая пустая таблица. Runtime-приёмка впереди.

# v0.2.7.2 — Функции, должности и штатная потребность проекта

- Проектирование функций и их ценного конечного продукта по узлам оргсхемы.
- Проектирование должностей и плановой штатной потребности в FTE.
- Явная ответственная должность функции и отдельный признак руководящей должности.
- Проверки отсутствующих функций, должностей, руководителя и ответственности; контроль нескольких руководителей.
- Сохранение проекта как корпоративного шаблона и создание независимого проекта из него.
- Новые UUID при копировании шаблона с сохранением внутренних ссылок.
- Build 2026091218; схема БД и действующий штат не меняются. Runtime-приёмка впереди.

# v0.2.7.1 — Визуальный редактор проектов оргсхем

- Проекты оргсхем показаны по визуальным уровням в цветных карточках.
- Для каждого узла раздельно редактируются расположение и прямое подчинение.
- Добавлены типовые элементы: руководство, отделение/департамент, отдел, секция, служба и офис.
- Настраиваются код, название, порядок на уровне и цвет узла.
- Миссия и замысел выводятся в шапке проекта с отдельными переключателями видимости.
- Проверяются уникальность кодов, допустимые ссылки и отсутствие циклов в обеих иерархиях.
- Каждое изменение создаёт новую неизменяемую версию и не изменяет действующую оргструктуру.
- Build 2026091217; изменений схемы и данных нет. Runtime-приёмка впереди.

# v0.2.7.0 — Проекты и версии оргсхем

Два стартовых шаблона KBA; копирование подразделений и сохранённых версий в отдельный проект. Миссия, замысел, переименование узлов. Две новые таблицы, build 2026091215; без применения к действующей структуре. Runtime-приёмка впереди. SIPOC закреплён в roadmap v0.4.0.

# v0.2.6 — Потоки продуктов и межфункциональные связи

- Поставщик → продукт → получатель, условия передачи и критерии приёмки.
- Черновик, публикация по актуальным утверждениям функций, архивирование с причиной.
- Снимки опубликованной связи и отметка об изменившемся основании.
- Read-only flows_context; права компании, revision, общий lock, журнал.
- Одна новая таблица flow. Build 2026091214. Стендовая приёмка впереди.
- Фактическая передача продуктов и исполнение BPMN не входят в этот релиз.

# v0.2.5.3 — Повторяющиеся циклы

- Явное создание недельных/месячных черновиков планов и контрольные точки.
- Защита от дублей, перенос дат, отдельная активация и приёмка. Build 2026091213.

# v0.2.5.2 — Отклонения и корректирующие действия

- Количественное отклонение от выбранной нормы из утверждённого пакета функции.
- Точные сравнения, направление показателя, границы и проверка периода наблюдения.
- Гипотеза причины, связи с корректирующими задачами, журнал действий.
- Повторные проверки после приёмки задач; сопоставимость нормативных условий между периодами.
- Закрытие руководителем только после положительной проверки с подтверждением; отмена с причиной.
- Три новые пустые таблицы, manager-scoped read API и Privacy API.
- Build 2026091212. Runtime-приёмка не выполнена.

# v0.2.5.1 — Исполнение административного каскада

- Отдельные явные назначения исполнителей функций с историей.
- Неизменяемые снимки утверждений, проверка изменений зависимостей по SHA256, отзыв утверждений.
- Оперативные планы и задачи с критериями, сроками, исполнителем и отдельным проверяющим.
- Запуск плана; работа, сдача, возврат, повторная сдача и приёмка; явное закрытие плана.
- Доказательства в виде текста и HTTP/HTTPS-ссылки, история попыток и решений.
- Переходы от каскада к связанным работам и от задачи к утверждённому основанию.
- Четыре новые пустые таблицы, read-only execution_context и дополнение Privacy API.
- Build 2026091211. Только статическая проверка; Moodle runtime не проверен.

# v0.2.5.0.1 — Навигация карточки компании

- Шесть ссылок разделов в единой адаптивной сетке с одинаковым оформлением.
- Технические сведения свернуты под навигацией; роли остаются на виду.
- Убран устаревший баннер о релизе, разделявший кнопки.
- Build 2026091210. Схема БД, права и адреса переходов сохранены.

# v0.2.5.0 — Фундамент административного каскада

- Черновики замысла, целей, стратегических планов, функций ОФС, ОКР и норм.
- Связи родитель/потомок и вклад функций в стратегические планы; снятие связей, архивирование.
- Проверки компании, циклов, редакций, уникальных кодов, периодов и точных десятичных границ.
- Связь основного показателя с продуктом ОКР; отдельное хранение проектов норм и план/факт статистики.
- Три новые пустые таблицы, журнал до/после, read-only cascade_context, Privacy API.
- Build 2026091209. Статические проверки выполнены; интеграционные тесты добавлены, не запускались.
- Полный цикл исполнения, утверждение и назначения функций перенесены на v0.2.5.1.

# v0.2.4 — ЦКП и статистики

- Добавлены реестр ЦКП и критерии приёмки с привязкой к подразделению или должности.
- Добавлены статистики, план/факт, периоды, единицы измерения и направление улучшения.
- Ввод чисел и исправлений с неизменяемой историей, обязательной причиной исправления и блокировкой записи.
- Добавлены три пустые таблицы и read-only API products_context.
- Privacy API учитывает авторов и примечания измерений.
- Build 2026091208. Компетенции, обучение, зрелость BMS и штатные назначения не меняются.

# v0.2.3.3 — Staffing Plan & Placement Separation

- Split «Справочник должностей», «Штатное расписание» and «Штатная расстановка» into separate tabs.
- Added versioned plans with draft, approved and archived states.
- Added plan lines for planned FTE, salary range and monthly payroll budget.
- Added individual staffing slots generated only by explicit plan approval.
- Added factual employee placement and retained assignment history; vacancies never create fake users.
- Added read-only API contexts and privacy handling for plan approvals.
- Build raised to `2026091207`; upgrade is additive and performs no business-data migration.

# v0.2.3.2 — Direct Role Assignment Hotfix

- Added «Назначить роль» for employees without business roles.
- Added «Изменить роли» for employees with active roles.
- Opens the existing Corporate Dashboard assignment form with company and employee preselected.
- Action is visible only with role-management capability and full company access.
- Build raised to `2026091206`; no schema, permission or business-data changes.

# v0.2.3.1 — Company Search Hotfix

- Added Russian-first «Поиск контрагента» to «Мои компании».
- Added datalist suggestions and automatic opening after an exact selection.
- Added live card filtering by company name, code and company role.
- Added visible-result counter and empty-result state.
- Build raised to `2026091205`; no schema, permission or business-data changes.

# v0.2.3 — Positions & Staffing

- Added Company Core-owned position definitions and dated employee assignments.
- Added planned staffing, current occupancy and explicit vacancy calculation.
- Added Russian-first position creation and employee assignment interface.
- Added primary, acting and additional assignment types with retained history.
- Added a restricted read-only positions and staffing API operation.
- Added Moodle privacy metadata, export and deletion support for assignment user IDs.
- Preserved the rule that position/course events never change competency assessment.
- Build raised to `2026091204`; upgrade creates empty additive tables only.

# v0.2.2 — Organizing Board Foundation

- Added Company Core-owned branch and hierarchy-node metadata with stable UUIDs.
- Added a Russian-first visual organizing board with seven classic divisions.
- Added hierarchical codes, ordering, colours and multi-branch foundations.
- Added an explicit, permission-checked and idempotent seven-division template command.
- Added a restricted read-only organizing-board API context.
- Preserved source concepts without importing spreadsheet formulas or inventing staffing.
- Build raised to `2026091203`; upgrade creates empty additive tables only.

# v0.2.1 — Business Roles & Assignments

- Added company-scoped business-role and employee-assignment screen.
- Added provider-neutral BusinessRole, EmployeeRoleAssignment and RoleCompetencyRequirement projections.
- Added stable Company Core UUIDs for existing business roles.
- Added active-date filtering for role assignments.
- Added role target competency profiles while excluding BMS-D domain nodes from working requirements.
- Added explicit no-automatic-assessment rules to the contract and UI.
- Added a restricted read-only business-role API operation.
- Build raised to `2026091202`; no schema, assessment or business-data changes.

# v0.2.0 — Organization Core

- Added a company-scoped organisation structure screen.
- Added read-only OrgUnit, Position and EmployeeOrgAssignment projections.
- Added stable UUID identities for existing organisation units.
- Added department, position, employee and unassigned-employee summary cards.
- Added organisation context to the restricted Company Core API.
- Kept existing Corporate Dashboard departments and Moodle profile positions as authoritative sources.
- Missing positions remain unknown; no organisation records are copied or fabricated.
- Build raised to `2026091201`; no schema or destructive data changes.

# v0.1.0.2 — Navbar Visibility Hotfix

- Added the Boost-compatible navbar output callback for «Моя компания».
- Retained the global navigation callback as a theme fallback.
- Restricted the shortcut to signed-in users with `viewowncompany`.
- Build raised to `2026091012`; no schema, permission or business-data changes.

# v0.1.0.1 — Runtime Dependency Detection Hotfix

- Removed blocking `$plugin->dependencies` declaration for `local_kbacorporate`.
- Removed cross-plugin `clonepermissionsfrom` during capability installation.
- Added runtime source detection and a safe unavailable state in «Моя компания».
- Company list returns an empty projection when the compatibility source is absent.
- Build raised to `2026091011`; no schema or business-data changes.

# v0.1.0 — Company Core Architecture Foundation

- Added installable Moodle component `local_kbacompany`.
- Added dependency on `local_kbacorporate` v0.40.0 build `2026091009`.
- Added stable UUID identity registry with concurrency lock and unique indexes.
- Added compatibility adapter for existing company and membership source data.
- Added Company Core service contract `0.1.0` and deterministic schema hash.
- Added multi-company user context and company-scoped role projection.
- Added Russian-first «Моя компания» and company context screens.
- Added disabled-by-default restricted read-only Company Core API.
- Added company-isolation, identity, multi-membership and privacy regression tests.
- Added one additive table; no Corporate Dashboard records are migrated or changed.
# v0.2.7.0.1 — Навигация раздела организации

- Три дублирующих входа с главной страницы объединены в «Организация компании».
- Новый обзор и единые вкладки: действующая оргсхема, подразделения и сотрудники, должности и штат, проекты оргсхем.
- Проекты показываются только пользователям с правом управления организацией.
- Схема БД не менялась. Build 2026091216.
# 0.2.8.0 — Policies and Management Directives

- Adds a company-scoped register for policies and management directives.
- Stores every draft, publication, new-version and withdrawal action as an append-only revision.
- Requires explicit owner, scope, purpose and basis; publication also requires an effective date and approved-document URL.
- Keeps publication separate from execution, competency confirmation and BMS maturity.
- Adds privacy export and anonymisation for revision authors and action reasons.

## 0.4.3 — BMS App Shell Pilot

- Added optional KBA Theme 2.3.25+ App Shell integration on `bms_workspace.php`.
- Added BMS-owned reusable navigation/page model in `bms_ui_service`.
- Added real company/org/task data to the pilot workspace.
- Added normal Moodle fallback when KBA Theme App Shell is not available.
- Added a non-destructive “Open BMS” entry from the existing company page.
- No database changes and no business service semantics changed.


## 0.4.6.3 — Legacy Task Provider PHP 7.3 Hotfix

- Replaced PHP 8 `match` expression in `legacy_workflow_task_provider` with PHP 7.3-compatible `switch`.
- No database or business-data changes.
- Preserves Communication/Task Center and App Shell fixes from 0.4.6.2.

## 0.4.6.6 — Unified Company Workspace Entry

- `company.php` is now a validated compatibility route into `bms_workspace.php`; the duplicate classic company grid is retired.
- company chooser cards and search open the BMS Workspace directly.
- legacy "back to company" links return to the BMS Workspace.
- technical company metadata formerly shown only on the classic page is preserved in a collapsible Company card inside the Workspace.
- the Workspace no longer advertises a "Classic overview" action.
- no database schema change.



## 0.4.6.7 — BMS Page Shell Adapter

- Added shared `bms_page_shell_service`.
- Added lightweight `bms_ui_service::shell_model()`.
- Migrated daily-work, strategy, architecture, organization, process, function, document, policy and execution pages to the unified App Shell when KBA Theme supports it.
- Preserved normal Moodle fallback and business-controller ownership.
- No database schema changes.

## 0.4.6.8 — Business Architecture UX Model

- Reordered Business Architecture by BMS methodology rather than technical route creation order.
- Added eight-step design sequence from ownership/strategy through documents, metrics and improvement.
- Grouped the architecture navigator into Management foundation, Company architecture, Operating model, and Management/regulations.
- Added Position directory as an explicit architecture destination.
- Rebuilt the Business Architecture overview while preserving real process/function/org/position counters.
- No database schema change.


## 0.4.6.9 — Business Architecture Guided Workspace

- Replaced the duplicated architecture overview catalogue with a selected-stage work area.
- Added evidence-based readiness for all eight architecture stages.
- Added automatic recommendation of the first incomplete design stage.
- Added stage/module focus links inside the grouped navigator.
- Added contextual facts and stage actions.
- Kept all readiness evidence derived from canonical BMS objects; no new tables.


## 0.4.6.10 — Legacy Administrative Cascade UI Retirement

The obsolete Administrative Cascade and its execution surface are removed from customer-facing BMS navigation and Business Architecture. The canonical management path is Administrative Scale → programs/projects/decisions/tasks → Battle Plan. Legacy cascade tables/services remain intact for backward compatibility, historical references and a future controlled migration; no business data is deleted.

## 0.4.6.11 — OFS Visual Designer

- Added `ofs_visual_designer_service` and `ofs_designer.php`.
- Added universal `bms_ofs` design template matching the agreed BMS organizational-poster hierarchy.
- Extended versioned design payloads with optional `visualtier`, node `product` and `companyproduct` fields.
- Added draft commands `visualnode` and `visualsettings`; no database schema change.
- Business Architecture Organization/OFS route now opens the visual designer.
- Organization Hub now exposes the visual designer as the primary OFS design tool.
- Added explicit plugin-lib loading to legacy organization subpages to prevent missing organization-tabs callback errors.
- Added responsive/print-safe OFS poster presentation.
- Added regression coverage for the universal visual tier projection and valuable-final-product semantics.


## 0.4.6.12 — OFS Export Engine
- Added `ofs_export_service` with shared OFS layout for SVG, PDF and Draw.io.
- Added `/local/kbacompany/ofs_export.php` download endpoint.
- Added vector PDF sizes A0-A4 and Auto.
- Added Draw.io UUID/revision provenance metadata.
- Added export controls to OFS Visual Designer.
- No database schema changes.

## 0.4.6.13 — Organization Template Library
- Added a built-in, normalized OFS template library for distribution, dentistry, custom construction, meat processing + retail, boat manufacturing, apparel retail, electrical installation services and cafe networks.
- Added `ofs_templates.php` with preview and `Create OFS from template` flow.
- Templates create ordinary company-owned draft OrgDesign projects; BMS remains source of truth.
- Methodology source files in Google Drive remain external read-only references and are not required at runtime.

## 0.4.6.14 — Legacy Organization Template Import
- Added safe parser for compressed and uncompressed diagrams.net / Draw.io `mxfile` sources, including `mxGraphModel`, cells, geometry, edges, hierarchy, style diagnostics and normalized visible text.
- Semantic classification is suggestion-only (`company`, `office`, `orgunit`, `department`, `function`, `position`, `product`, `ckp`, `result`, `label`, `unknown`) with confidence and no color-only business inference.
- Added three-pane import review workbench: source blocks, legacy-layout preview and mapping inspector.
- Import now creates a company-scoped **normalized template draft**, not an OrgDesign project. Publication is explicit: `DRAFT → REVIEWED → AVAILABLE`.
- Added immutable imported-template versions and provenance: filename, optional Drive file ID, source modified timestamp, SHA256, parser/mapping versions, reviewer and import time.
- Only `AVAILABLE` imported templates can create an independent company-owned OrgDesign draft through the existing OFS Template Library.
- Added company-isolated permissions, audit events and Moodle privacy metadata/export/anonymization for imported-template actors and analysis content.
- Preserved the 0.4.6.14 TCPDF/DejaVu Sans Cyrillic SVG→PDF export hotfix in the consolidated build.
- Legacy Google Drive methodology library remains strictly read-only and is not a runtime dependency.


## 0.4.6.15 — Template Normalization & Preview Enhancements
- Refined legacy Draw.io semantic heuristics against the three real source schemes from the methodology archive.
- CKP/product parent suggestion now prefers explicit semantic bands and horizontal column alignment; `ЦКП ОТДЕЛЕНИЯ` targets departments and wide final CKP targets the company root.
- Added compound-block diagnostics for legacy section/service/office boxes that contain embedded roles and responsibilities; no silent auto-splitting is performed.
- Added normalization readiness metrics: business objects, needs-review count, compound blocks and structural product coverage.
- Added review filters, search, semantic preview emphasis and per-cell normalization guidance.
- Enhanced imported-template preview with actual-present tiers, structure levels, positions/functions, warnings and product coverage.
- Parser contract `1.2`, mapping guidance `1.1`; no database schema change and no rewrite of 0.4.6.14 immutable revisions.
- Original Google Drive organization-scheme files remain read-only and unchanged.


## 0.4.7 — Meetings & Decisions (build 2026091357)

- Added participant-scoped Meeting, MeetingParticipant, MeetingAgendaItem and Decision business objects.
- Added `meetings.php` governance workspace under Communication Center.
- Added Discussion → Meeting traceability through `sourcethreaduuid` and an explicit meeting object thread.
- Added agenda items with expected products and owner/status tracking.
- Added decision lifecycle `PROPOSED → ACCEPTED / REJECTED`.
- Added canonical task generation from accepted decisions with `sourcekind=decision` and deep-link traceability back to the meeting.
- Meeting close requires minutes plus resolution of open agenda items and proposed decisions.
- Added privacy metadata/export/deletion handling for meeting and decision user references.
- All schema changes are additive; previous communication/task/template records are preserved.

## 0.4.9.1 — Executive & Finance Task Library Enrichment (build 2026091360)

- Expanded Recurring Task Library from 94 to 169 templates using the supplied Executive Director and Finance Director Excel checklists.
- Preserved exact source weekday sets through additive `weekdaymask` and `monthweek` fields on immutable recurring-task revisions.
- Added first-week-of-month scheduling for source rows explicitly marked in the financial checklist.
- Preserved Executive Director source time and checklist score as template provenance metadata; neither is silently converted into BMS priority or deadline time.
- Consolidated the two Finance Director workbooks into one normalized 45-template source set because task wording and schedule markings are identical; both SHA256 values remain in provenance.
- Excluded three pink "as needed" Executive Director rows from the recurring library because they are event-driven, not recurring.
- Flagged four schedule ambiguities for human review: one Executive Director monthly item with no exact day and three Finance Director rows whose titles say monthly while the green schedule marks a weekday without the month-week marker.
- Existing 0.4.9 template keys remain stable; new templates are appended to Executive and Finance categories.
- Runtime BMS does not depend on the uploaded workbook files.


## 0.4.9.3
- Task assignee can be selected from company employees, including self.
- Task attachments use protected Moodle File API storage.
- Battle Plans support self-approval when owner is the selected reviewer.
- Battle Plans export as real DOCX files.

## 0.4.9.4 — Task Discussion, Safe Delete & Result Evidence Files
- Added participant-scoped inline task discussion backed by Communication Center object threads (`contextkind=task`).
- Added audited soft delete for non-final tasks; deleted tasks are removed from Task Center and Battle Plan references while the task record/history remain for audit.
- Added protected Moodle File API result/evidence area (`task_result`) separate from task-definition attachments.
- Result files can be uploaded while submitting a result or appended later by the assignee/task manager; accepted evidence is not deletable from normal UI.
- Extended pluginfile/privacy handling to cover result evidence files.
- No database schema change; build-only upgrade savepoint 2026091363.
## 0.4.9.5 — Task & Battle Plan Observers
- Added explicit observer role to canonical tasks and weekly Battle Plans.
- Added `Наблюдаю / Watching` views in Task Center and Battle Plan.
- Task observers participate in task discussions without execution/review authority.
- Battle Plan observers have read-only plan access and appear in DOCX export.


## 0.4.10 — Owner LPF & Business Model Canvas 2.0
- Added private versioned `OwnerFrame` for LPF goals and ownership goals with immutable revisions.
- Added explicit approved Owner requirements (`REQ-01..REQ-07`) as the only owner data exposed to corporate architecture.
- Added owner-only **Собственник** workspace with edit, publish, history and archive/delete workflow.
- Upgraded Business Model to BMS Business Model Canvas 2.0 with nine blocks, as-is/DATA/HYP/target separation, criteria, REQ and GAP fields.
- Business-model revisions pin the exact published OwnerFrame revision used as their requirement source.
- Added five target-model validation contours: market, client, economics, resources and owner requirements.
- Added additive DB migration for `local_kbacompany_ownerframe` and BMC source/payload fields; no existing business data is deleted.
- Google Drive methodology sources remain read-only and are not runtime dependencies.

## 0.4.10.1 — Русская терминология интерфейса
- Russian client UI no longer exposes REQ/DATA/HYP/GAP/KPI or Business Model Canvas wording on Owner / Business Model workflows.
- Owner requirement codes remain internal and immutable; UI uses «Требование собственника №N».
- Business Model forms accept requirement numbers and normalize them to internal stable codes.
- Draft/published/archived states are localized instead of rendering raw storage values.


## 0.4.10.2 — Задачи → Боевой план
- В карточке канонической задачи можно выбрать доступный черновик Боевого плана исполнителя и добавить задачу в него.
- Боевой план хранит ссылку на существующую задачу; копия задачи не создаётся.
- Повторное добавление той же задачи в тот же недельный план идемпотентно.
- Уже связанные планы показываются в карточке задачи ссылками.
- Существующий сценарий добавления задач со стороны Боевого плана сохранён.
## 0.4.10.3 — Шаблоны Боевого плана, нормы и состояния (build 2026091368)
- Добавлены versioned `BattlePlanTemplate` и `BattlePlanQuotaSet` с редактированием через новую редакцию, публикацией и архивированием.
- Недельный Боевой план фиксирует точные `template revision` и `quota revision`.
- Шаблон может создавать идемпотентные канонические задачи недели; повторное применение не создаёт дубли.
- Добавлена готовая заготовка недельного плана РОП по предоставленному примеру.
- Наборы норм используют существующие недельные показатели `local_kbacompany_stat`; отдельная параллельная база метрик не создаётся.
- Факт недели записывается как append-only revision в `local_kbacompany_statval`; исправление требует причины.
- Добавлены состояния: Несуществование, Опасность, Чрезвычайное положение, Нормальная деятельность, Изобилие, Могучество, Смена Власти.
- Автоматический расчёт основан на выполнении квоты и динамике; пороги хранятся в редакции набора норм и могут быть изменены методологом. «Смена Власти» задаётся только вручную с комментарием.
- Word-экспорт Боевого плана теперь содержит таблицу метрик: прошлый факт → квота → состояние → текущий факт → выполнение → динамика.
- Схема изменена только additive: две versioned-таблицы и ссылки/override payload в `local_kbacompany_bplan`; старые планы получают пустые ссылки и сохраняют прежнее поведение.


## 0.4.10.7 — Компактная карточка задачи (build 2026091372)
- Редактирование задачи свернуто по умолчанию и раскрывается только по запросу.
- История задачи свернута по умолчанию и показывает количество записей.
- Блок «Работа с задачей» поднят выше файлов результата.
- Быстрое создание задачи перенесено из конца страницы в модальное окно поверх текущего контекста.
- Переключение «Краткая форма / Полная форма» работает внутри окна создания.
- На мобильных окно создания разворачивается на весь экран.
- Изменений схемы БД и внешних сервисов нет.

## 0.4.10.8 — Создание задачи: Боевой план и проект Управленческого цикла (build 2026091373)
- При создании канонической задачи можно сразу выбрать доступный черновик Боевого плана выбранного исполнителя.
- Новая задача добавляется в Боевой план ссылкой; копия задачи не создаётся.
- Перед созданием проверяется, что выбранный Боевой план принадлежит выбранному исполнителю и доступен для редактирования.
- В связи задачи проекты Управленческого цикла обозначены явно как «Проект Управленческого цикла» и сохраняются с точной опубликованной редакцией.
- Схема БД не меняется; добавлены schema-neutral savepoints 2026091372 и 2026091373.


## 0.4.10.9 — Боевой план: читаемый период и прямое добавление из библиотеки

- Исправлен вывод периода Боевого плана: вместо служебных `[[strftimedaymonth]]` отображается локализованный диапазон дат.
- В библиотеке шаблонов задач можно выбрать доступный черновик Боевого плана, подтвердить ожидаемый продукт и добавить задачу напрямую.
- Создаётся одна каноническая задача; Боевой план хранит ссылку без дублирования.

## 0.4.11 — Управление категориями и шаблонами библиотеки задач (build 2026091375)
- Добавлены company-scoped категории и шаблоны библиотеки регулярных задач с immutable revisions.
- Собственник/менеджер может создавать категории, переименовывать их, архивировать и восстанавливать.
- Можно создавать собственные шаблоны задач, редактировать встроенные методологические шаблоны для своей компании, переносить между категориями, архивировать и восстанавливать.
- Встроенная методология не изменяется физически: company edits хранятся как overlays; удаление означает безопасную архивацию.
- Пользовательский шаблон может задавать описание, продукт по умолчанию, периодичность, дни недели, неделю месяца, поток, приоритет и план минут.
- Установка шаблона в регулярную серию и добавление в Боевой план используют company-specific редакцию шаблона.
- Исторические задачи сохраняют source UUID и могут разрешить название даже после архивирования шаблона.
- Добавлены additive tables `local_kbacompany_tasklibcat` и `local_kbacompany_tasklibtpl`; существующие данные не удаляются.

## 0.4.11.2 — Упрощение навигации задач и понятные источники (build 2026091377)
- Убрана вводящая в заблуждение кнопка «Регулярные задачи» из верхней панели Центра задач; регулярные шаблоны доступны через «Библиотека регулярных задач».
- Пользовательское имя источника `KBA Element` заменено на «Обучение» / `Learning`; внутренний provider key `kbaelement` не изменён.
- Схема БД и внешние сервисы не меняются.

## 0.5.0 — Реестр и дерево показателей — build 2026091379

- Добавлен единый экран `Показатели` вместо использования продуктовой страницы как пользовательского центра статистик.
- Канонический показатель остаётся в `local_kbacompany_stat`; история план/факт остаётся append-only в `local_kbacompany_statval`.
- Добавлены versioned passport/binding/norm layers: `statmeta`, `statbind`, `statnorm`.
- Две проекции одного реестра: по ССП/стратегическим целям и по ОФС/подразделениям/должностям.
- Существующие `StrategicGoal.metricuuid` и `Product.targettype/targetuuid` отображаются как read-only compatibility-связи без массовой миграции.
- Нормы отделены от определения показателя и привязаны к конкретному контексту с датами действия.
- Ввод план/факт и история значений сохранены; добавлен график факта.
- Периодичность канонических показателей расширена кварталом и годом.

## 0.5.3 — Состояния показателей и управленческие действия (build 2026091501)
- Добавлен центральный read-model `indicator_condition_service` поверх canonical `stat/statval/statbind/statnorm`.
- Реализованы автоматические состояния Опасность, Чрезвычайное положение, Нормальная деятельность, Изобилие и Могучество по методике состояний.
- Несуществование и Смена Власти оставлены контекстными состояниями и не угадываются по одной статистике.
- Добавлена versioned `local_kbacompany_statpolicy` для явных порогов качественных понятий «круто/очень круто/высокий уровень»; скрытых процентов в новом движке нет.
- Состояние добавлено в Информационный центр, карточку и общий реестр; создана вкладка «Состояния» с управлением политикой и рекомендуемыми формулами действий.
- Автоматическое состояние не сохраняется отдельным фактом; `statval` остаётся append-only источником истины.
- Историческое разрешение норм и политик учитывает архивные tombstone-редакции без стирания прошлых периодов.
- Повторная настройка нормы той же привязки теперь продолжает один versioned norm stream вместо создания нового параллельного UUID; legacy-потоки остаются читаемыми.
- Старый квотный расчёт Боевого плана сохранён только как compatibility adapter для закреплённых редакций и делегирован центральному сервису.
- Управленческие рекомендации не создают параллельные задачи; исполнение должно использовать канонический Task.

## 0.5.4 — Подтверждение состояния → каноническое исполнение (build 2026091502)
- Добавлен append-only governance event `local_kbacompany_stateevt`: подтверждение аналитического состояния или контекстное решение руководителя.
- Автоматическое состояние остаётся read-model и не записывается как второй факт показателя.
- Контекстное переопределение состояния требует комментария руководителя.
- Подтверждённое состояние может создать одну каноническую `local_kbacompany_task` с provenance `sourcekind=condition`.
- Созданную задачу можно сразу добавить ссылкой в доступный черновик Боевого плана; копия Task не создаётся.
- Центр задач показывает источник «Состояние показателя» и обратную ссылку на карточку показателя.
- История подтверждений отображает Task/Battle Plan только при наличии прав просмотра соответствующего объекта.
- Миграция additive; `stat/statval/statbind/statnorm/statpolicy`, Task и Battle Plan остаются каноническими источниками истины своих контуров.

## 0.5.5 — Management Exceptions (build 2026091503)
- Добавлена schema-neutral вкладка «Исключения» в контуре показателей.
- Исключения вычисляются on-read из canonical Indicator/Fact/Norm/Condition/Event/Task без отдельного хранилища.
- Несколько причин внимания агрегируются в одну строку `statbind`.
- Добавлены явные уровни Critical/High/Medium и фильтры по группе причины.
- Учитываются состояние, норма, ухудшающийся тренд, неблагоприятные развороты/экстремумы, критические пробелы данных/политики, подтверждение состояния, отсутствие исполнения и просроченная canonical Task.
- ACL Task Center не обходится: недоступные пользователю задачи не раскрываются.
- Схема БД не меняется; upgrade содержит только savepoint 2026091503.


## 0.5.7 — Управленческое действие из строки показателя Боевого плана (build 2026091505)
- В канонической строке показателя Боевого плана добавлен прямой переход «состояние -> решение -> canonical Task».
- Исторический snapshot пересчитывается сервером на неделю выбранного плана; будущие факты не могут повлиять на старое решение.
- Context/confirm продолжают храниться в immutable `stateevt`; отдельной сущности Action не создано.
- Исполнение создаётся как `local_kbacompany_task(sourcekind=condition)` и добавляется в этот же план существующей ссылкой `bplanitem`.
- Ответственный, reviewer и observer берутся из Battle Plan, а не из формы пользователя.
- Повторная идентичная отправка идемпотентна; soft-deleted Task не воскрешается.
- Схема БД, capabilities, privacy schema и external services не меняются.

## 0.5.8 — Контроль управленческого действия по показателю (build 2026091506)
- Canonical Task рядом с показателем показывает текущий статус, результат и фактический checklist-прогресс.
- Процент прогресса выводится только при наличии checklist; скрытые оценки прогресса по статусу не вводятся.
- На следующей неделе read-model находит решение ровно предыдущей недели и сравнивает факт до/после действия.
- Направление показателя учитывается при оценке изменения: улучшение / ухудшение / без изменения; neutral не получает искусственной управленческой оценки.
- Система явно не делает причинный вывод: сравнение «до/после» является наблюдением, а не доказательством влияния задачи.
- Никакой отдельный action-status/effect объект не создаётся; источник данных остаётся `stateevt + canonical Task + statval`.
- DOCX-экспорт Боевого плана включает краткий контроль задачи и доступное сравнение следующего факта.
- Схема БД, capabilities, privacy schema и external services не меняются.


## 0.5.9 — Управленческий цикл по исключениям (build 2026091507)
- Management Exceptions дополнен schema-neutral read-model управленческого цикла.
- Очередь показывает: требуется ли решение, есть ли canonical Task, просрочена ли она, какой доступный Боевой план несёт действие и что произошло с точным следующим недельным фактом.
- Добавлен фильтр по состоянию управленческого цикла.
- Checklist-прогресс и результат читаются только из canonical Task и только при её ACL-доступности.
- Ухудшение/без изменения после действия становится причиной нового решения только при отсутствии более свежего manager decision.
- Сопоставление фактов до/после не трактуется как доказательство причинности.
- Новые Incident/ActionStatus/Effect таблицы не создаются; schema/access/services/privacy остаются без изменений.

## 0.5.13 — Готовность управленческой недели (build 2026091511)
- Добавлен schema-neutral read-model готовности недельных Боевых планов.
- Жёсткие блокеры зеркалят существующий Battle Plan lifecycle: корректный reviewer и минимум одна задача перед submit/selfapprove.
- Различаются ready-submit, ready-selfapprove, ready-activate, active и closed.
- Пробелы критических показателей/норм выводятся как advisories и не создают новый запрет перехода.
- Добавлен управленческий экран с фильтрами и агрегатами по подразделениям.
- Новый status/readiness registry не создаётся; `install.xml`, ACL, privacy и external services не меняются.


## 0.6.9 — Architecture Stabilization / Pre-0.7 Gate

- Added Company-scoped platform readiness diagnostics before 0.7.x domain work.
- Legacy unmapped Companies remain compatible with a warning.
- Cloud snapshot mode is blocked when no usable entitlement snapshot exists.
- No business schema changes and no automatic entitlement cutover.

## 0.6.9.3 — Learning Contour Entry (build 2026092133)
- Added a company-scoped `Развитие и обучение` workspace in the BMS App Shell.
- Added a `Люди и развитие` navigation group; Employees remain manager-visible and Learning is available to authorised company users.
- Workspace Learning card now links to the new contour and reflects active company-course enrolments when Corporate data is available.
- Corporate catalogue/licence data is read through the KBA Corporate bridge; Moodle Core supplies enrolment/completion.
- Other Moodle courses are intentionally shown separately from company courses.
- No database, ACL, enrolment, licence or competency write changes.

### 0.6.9.3 Learning Contour Entry — release verification refinement
- Manager summary now reports team-scoped employees/training/completion metrics rather than personal counters.
- Added read-only learning warnings and count-level seat/enrolment drift signal; detailed reconciliation remains owned by KBA Corporate.
- Corporate drill-downs are filtered by KBA Corporate capabilities and point to the verified 0.40.1 routes.
- Employee mode now exposes My development, not-started/in-progress/completed states and Continue learning.

## 0.6.9.4 — Owner Intent Guided Workflow (build 2026092134)
- Reworked `owner.php` from a long questionnaire into a SuiteCRM-style record view with 14 guided BMS-AUD-EXM-001 sections.
- Added owner workflow states per section: empty, draft, in review, correction requested, agreed.
- Added append-only, company-scoped owner/consultant feedback events bound to the exact section content hash, so a changed answer automatically returns to draft while unchanged agreements survive later revisions.
- Added explicit consultant capability `local/kbacompany:reviewownerprivate`; it is not granted to manager archetypes by default and does not permit editing owner answers.
- Added comments for both owner and consultant, version history, privacy notice, quality checklist, guided examples and a separate final requirements package.
- Publishing now requires all 14 sections to be agreed; only approved requirements remain exposable to Business Model/strategy.
- Added `local_kbacompany_ownerfb` plus install/upgrade/privacy support; no Cloud/Odoo/Corporate ownership changes.

## 0.6.9.9 — Business Model CRUD Foundation (build 2026092139)
- Converted Business Model Canvas 2.0 from a monolithic textarea workflow to BMS CRUD records while keeping `local_kbacompany_bizmodel` as the canonical root.
- Added `BMC-*` Stable Codes for root models and `BMCI-*` Stable Codes for Canvas items; UUID remains canonical identity.
- Added snapshot-scoped `bmcitem`, pinned `bmcitemreq`, and append-only `bmccomment` storage.
- Separated AS-IS from TARGET and added explicit FACT/DATA/HYP/OPEN/TARGET semantics.
- Added methodology guidance for all nine BMC blocks, evidence/source/test criteria, Position-first accountability and REQ coverage.
- Root revision save/publish/archive clones the Canvas item snapshot inside the same transaction.
- Existing Owner Requirement revision is pinned; it cannot be silently repointed once the Canvas contains records.
- Legacy textarea Canvas is read-only compatibility to avoid two competing write models.
- GAP, change mechanism, KPI, Financial Model and execution engines are not duplicated in this release.

## 0.6.9.10 — Проверка бизнес-модели и реестр разрывов (build 2026092204)
- Добавлена versioned проверка BMC snapshot по пяти контурам: рынок / клиент / экономика / ресурсы / требования собственника.
- Экономика со статусом «Подтверждено» требует опубликованный Financial Scenario, pinned к той же Business Model revision.
- Requirement coverage считается только по TARGET / «Как должно быть» records.
- Добавлен canonical versioned GAP Registry со Stable Code `GAP-*` и точной AS-IS/TARGET item traceability.
- GAP может ссылаться на canonical BMS Indicator как показатель результата; новый KPI engine не создан.
- GAP не создаёт автоматически Project/Program/Task/Administrative Scale.
- Добавлены `local_kbacompany_bmcval`, `local_kbacompany_bmcgap`, `local_kbacompany_bmcgapreq` и privacy support.

## 0.7.0 — People Foundation — build 2026092209
- Added contextual People workspace and reduced flat sidebar duplication.
- Added Company-scoped Moodle Competency Framework governance mapping.
- Added company-scoped competency catalog facade with scope-before-fetch rule.
- Added versioned Role DNA root with UUID/revision and RLD-* stable code foundation.
- Added People-specific manage/private-evidence capabilities and privacy metadata.
- Recruitment, Recognition, 360, Adaptation and Offboarding remain intentionally outside this foundation release.


## 0.7.0.17 — List / Kanban / Analytics View Foundation

- Новый presentation-only `bms_collection_view_service`.
- Единый toolbar: поиск по текущему company-scoped набору + переключатель `Список / Канбан / Аналитика`.
- **Задачи**: list, kanban по lifecycle task status, analytics по открытым / на приёмке / просроченным / выполненным.
- **Разрывы Business Model**: list, kanban `Кандидат → Подтверждён → Архивирован`, analytics по статусам.
- **Стратегические цели ССП**: list, kanban по перспективам ССП, analytics по health и показателям.
- **Программы и Проекты Административной шкалы**: list, kanban по lifecycle, analytics по статусам и stale records.
- Responsive CSS для toolbar, kanban и metric cards.

## Архитектурные границы
- Domain services остаются единственным источником данных и lifecycle.
- Новый service не выполняет SQL и не рассчитывает permissions.
- Company isolation не меняется: фильтрация поиска применяется только к уже company-scoped результатам domain services.
- Theme не получает business routes или field schema.
- Schema / ACL / services / privacy не менялись.

## Не входит
- drag-and-drop смена статусов;
- отдельное хранение dashboard/kanban state;
- BI/pivot engine;
- массовые действия.

## 0.7.1 — Position Competency Profile — build 2026092309
- Added versioned Position Competency Profile on top of the existing Role DNA root.
- Added `local_kbacompany_rolednacomp` for company-scoped competency requirements per Role DNA revision.
- Requirements reference only Moodle competencies from frameworks already authorised for the Company.
- Added target level, priority and required/optional semantics.
- Published profile revisions are immutable; editing a published profile creates a new draft revision and copies the previous requirements.
- Added direct entry from Position Directory to the competency profile.
- Target requirements never mutate employee assessments; course completion remains learning evidence, not competency attainment.

## 0.7.2 — Employee Competency Profile
Build: 2026092310

- Adds an employee competency profile linked to the employee's current canonical Position assignment.
- Compares employee evidence snapshots with the latest published Position Competency Profile.
- Shows target level, current evidence level, GAP and missing evidence without treating missing data as zero competence.
- Stores company-scoped evidence snapshots in `local_kbacompany_empcomp` with source, evidence text/URL and recorder metadata.
- Course completion does not automatically confirm competence. Moodle remains a possible evidence source only.
- Formal assessment workflow, methods, reviewers and attainment decisions remain deferred to 0.7.3 Assessment Engine.
- Position changes recompute comparison against the new published position profile without rewriting employee evidence.


## 0.7.3 — Assessment Engine
- Added formal company-scoped competency assessment sessions and competency-level decisions.
- Added draft → submitted → approved/rejected lifecycle.
- Only approved assessment decisions project to Employee Competency Profile.
- Added assessment privacy metadata/export/deletion handling.
- Added additive XMLDB tables local_kbacompany_assess and local_kbacompany_assessitem.

## 0.7.4 — 360 Assessment
- Multi-rater competency feedback with respondent groups and configurable minimum response threshold.
- Individual responses do not project competency attainment.
- Aggregate transfers to formal Assessment Engine as draft evidence; approval gate remains canonical.

## 0.7.5 — Development Plan — build 2026092313
- Added individual development plans derived from approved-assessment competency gaps.
- Added managed development actions and Moodle/KBA Corporate learning links without duplicating learning execution.
- Added privacy support and additive schema for development plans/actions.

## 0.7.7
- Function Transfer & Authorization with explicit handover, authorization and acceptance.

## 0.7.9 — Values / Culture / Motivation
- Added company-scoped values/culture/motivation registry and employee profile scores.
- Added BMS24-standard Culture workspace under People.
- Added privacy coverage for the new culture tables.

## 0.7.10 — People Architecture Stabilization (2026092319)
- Unified People overview using BMS24 workspace UX.
- Added People-local navigation, KPI summary and attention counters.
- Replaced obsolete planned-area copy with implemented People capabilities.
- No schema, ACL or service endpoint changes.

## 0.8.0 — Management Implementation

- Unified Management workspace over canonical Indicators, Management Exceptions, Tasks, Battle Plans and Management Cycle.
- Added management-local navigation and BMS24-standard workspace header/KPI/attention/gate UI.
- No new storage, task engine, KPI engine or lifecycle.

## 0.8.1 — Management Actions & Decision Loop
- Added schema-neutral decision-loop workspace over canonical management objects.

## 0.8.2 — Management Review & Closure

- Added schema-neutral Management Review workspace over the canonical decision loop.

## 0.8.4 — Recurring Problems & Management Patterns
- Added descriptive recurring-problem patterns over canonical management decision history.
- Added management_patterns.php and local management navigation entry.
- No schema, ACL, services or privacy changes.

## 0.8.6.3 — Coordination (build 2026092404)
- Added company-scoped Battle Plan coordination issues for the same management week.
- Agreement/decline records coordination only and never mutates another Battle Plan or task.
- Added privacy coverage for coordination content and participants.


## 0.8.12 — Management Contour Stabilization Gate — build 2026092411
- Stabilization-only release over the 0.8.x Management contour.
- No new schema, ACL, services, privacy storage contracts or management engine.
- Source-level regression gate covers PHP/XML/language/schema/company-scope/package invariants.
- Live Moodle 4.1.22 upgrade, PHPUnit/Behat and browser/mobile acceptance remain mandatory before production.

## 0.9.1.2 — Odoo Actual Read Staging / Provenance — build 2026092602
- Added read-only staging of one mapped Odoo indicator record field through JSON-2.
- Added deterministic provenance hash and idempotency key plus explicit conflict state against canonical BMS fact history.
- Missing remote actual remains null and is never converted to zero.
- No Odoo write, no background sync and no canonical BMS fact write are enabled.
- Added CLI `odoo_actual_stage.php`; no schema, capability or privacy storage changes.


## 0.9.6 — IDEF0 Foundation — build 2026092903
- Added a read-only IDEF0 context tab to the canonical Process card.
- Reuses existing process/product/model elements for ICOM; no new process tree or storage engine.
- No schema, ACL, privacy, Theme, AI or mutation changes.

## 0.9.7 — IDEF0 ICOM CRUD — build 2026100501
- Added draft-only create/edit/remove controls for IDEF0 Inputs, Controls, Outputs and Mechanisms.
- ICOM writes reuse canonical `procelem` types `input`, `control`, `output`, `resource`; no IDEF0 table or process copy was introduced.
- Published process-model revisions remain immutable; managers must create a draft revision before editing.
- The primary process product remains `process.productuuid` and is displayed in IDEF0 without being duplicated as an editable IDEF0 element.
- No decomposition, inter-process IDEF0 arrows, IDEF0↔SIPOC auto-sync, new links engine, schema or ACL change.

## 0.9.8 — IDEF0 Decomposition — build 2026100502
- Added IDEF0 decomposition as a read/write-neutral projection of the existing canonical Process L0/L1/L2 hierarchy.
- L0 context is shown as A-0 with A0 decomposition; visible L1/L2 children are rendered as calculated A1/A11-style notation in PRC-code order.
- Parent/child navigation and child creation reuse the existing Process CRUD and hierarchy validation; no parallel IDEF0 tree or storage was introduced.
- Reader/manager visibility follows existing Process published/working-revision semantics and every hierarchy query remains Company UUID scoped before fetch.
- No IDEF0 interconnections, ICOM inheritance/balancing, deeper-than-L2 hierarchy, IDEF0↔SIPOC auto-sync, schema, ACL or privacy changes.

## 0.9.9 — IDEF0 Interconnections — build 2026100601
- Added directed IDEF0 interconnections between direct sibling Process functions inside one decomposition.
- Interconnections reuse existing Semantic Core `process -> supplies -> process` relations; no IDEF0 edge table or execution engine is introduced.
- Creation/removal is manager-only, company-scoped, request-receipt protected and guarded by the parent Process revision; removal archives only the semantic relation.
- Reader visibility follows the existing published Process hierarchy; manager visibility follows the existing working hierarchy. Cross-parent, self and duplicate links are rejected.
- Arrow labels use the source Process primary product when present; ICOM, SIPOC and BPMN records/layout are not mutated.
- No schema, ACL, privacy, process-model, BPMN or SIPOC write-contract changes.


## 0.9.10 — IDEF0 ICOM Balancing — build 2026100602
- Added explicit balance diagnostics for sibling IDEF0 `Process -> supplies -> Process` interconnections.
- A handoff is balanced only when the source Process primary Product is referenced by an Input element of the target Process visible model.
- Input elements may now persist the existing canonical `procelem.productuuid` reference; no database schema migration is required.
- Manager balance status uses the target draft model when one exists, otherwise the latest published model; reader status uses only the published model.
- Unbalanced manager flows reuse the existing ICOM Input CRUD with a validated source Product preset. No hidden draft creation or automatic propagation is performed.
- Source-without-product, target-without-input and balanced states are shown explicitly; cross-company records remain excluded at query time.
- SIPOC supplier/customer links, BPMN/procflow/layout, Semantic Core relation identity, schema, ACL and privacy contracts are unchanged.
- Parent-child boundary tunneling/inheritance and automatic IDEF0→SIPOC/BPMN writes remain outside this release.

## 1.1.0-alpha1 (build 2026100615) — BMS Calendar Workspace
- Added read-only BMS Calendar workspace backed by Moodle Core Calendar.
- Company-scoped course filtering prevents mixing Moodle calendar events from unrelated companies.
- Added month grid, upcoming events and BMS sidebar navigation.
- No schema, ACL, privacy, Moodle event or Corporate Learning writes.

## 1.1.0-alpha6 (build 2026100702) — Unified Management Calendar
- Extended the BMS Calendar from Moodle-only events to a federated read-only management calendar.
- Added permission-scoped projections for canonical BMS Tasks, Meetings, Battle Plans and Management Cycle checkpoints.
- Added source filtering and visual source separation while keeping Moodle Calendar CRUD from alpha4/alpha5 unchanged.
- Each source remains canonical in its own domain; the calendar creates no BMS event copy and no new database table.
- Company/task/participant/Battle Plan/management permissions are applied in source queries before rows are fetched.
- Schema, ACL and privacy storage contracts remain unchanged.

## 1.1.0-alpha7 (build 2026100703) — Calendar Planning Views
- Added Month / Week / Day views to the unified BMS24 Calendar.
- Added safe drag/drop rescheduling for authorised manual Moodle events only; server re-validates Company/capability scope and preserves local event time.
- BMS Tasks, Meetings, Battle Plans and Management Checkpoints remain read-only projections and cannot be rescheduled from Calendar.
- Week/day windows reuse permission-scoped projections across month boundaries; no new calendar storage or scheduling engine.
- Schema, ACL and privacy storage contracts remain unchanged.

## 1.1.0-alpha10 (build 2026100706) — Meeting Time Finder
- Added permission-scoped common free-window suggestions for selected company employees.
- Suggestions use visible BMS workload plus only the signed-in user’s own Moodle calendar; other users’ personal Moodle calendars are never queried.
- Added meeting-create prefill for selected time and participants; actual meeting creation remains in canonical Meeting service and requires normal POST validation.
- Fixed the alpha9 invalid-member exception class typo.
- No schema, ACL or privacy storage changes.

## 1.1.0-alpha11 (build 2026100707) — Calendar Today & Reminder Signals
- Added a personal Today block to the main BMS24 workspace using existing permission-scoped Calendar projections.
- Added read-only reminder signals for the next hour, tasks due today and tomorrow's management-cycle checkpoints.
- Added direct navigation to the Calendar Day view.
- No background notifications are sent and no reminder persistence is created; repeated GET/page loads are side-effect free.
- No schema, ACL or privacy storage changes.


## 1.1.0-alpha12 (build 2026101201) — Calendar Notification Engine
- Added a 15-minute scheduled reminder engine over existing Company-scoped BMS projections.
- Added idempotent BMS Communication Center notifications for meetings approaching in about one hour, own tasks due today, tomorrow management-cycle checkpoints and one morning daily-plan summary.
- Reminder identity is folded into the existing unique CommunicationNotification UUID, so repeated cron runs do not create duplicate BMS notifications and no reminder table is introduced.
- Added best-effort Moodle notification delivery after the durable BMS claim; a transport failure does not delete the BMS notification or trigger duplicate retries.
- Other users’ personal Moodle calendars are never queried by the scheduled task; no Task/Meeting/Cycle business object is mutated.
- Added Moodle message provider and scheduled-task declaration; database schema, ACL and privacy table coverage remain unchanged.

## 1.1.0-alpha13 — 2026101202 — Calendar Notification Preferences
- Added Company default and per-user BMS Calendar reminder preferences without a new reminder table.
- Added configurable meeting lead time, morning hour, day digest, task-due and checkpoint toggles.
- Added user-local do-not-disturb intervals, including overnight ranges.
- Cron now resolves effective preferences before idempotent CommunicationNotification claim.
- Added BMS settings page and links to Moodle popup/email notification processor preferences.


## 1.1.0-alpha15 (build 2026101207) — Management Cycle Calendar
- Added manager-only canonical Week / Month / Quarter / Year cycle periods to the BMS Calendar.
- Added a dedicated Management cycles source filter and hierarchy panel for the selected period.
- Reused existing local_kbacompany_mcycle records and existing cycle detail routes; no new storage or lifecycle.
- Checkpoints stay separate calendar projections; project/advisory templates are out of scope.
- Schema, ACL and privacy storage contracts remain unchanged.

## 1.1.0-alpha16 — build2026101208 — Regular Management Calendar
- Added manager-only regular-management panel to Calendar.
- Added weekly acceptance/readiness signals, Battle Plan gaps/blockers, overdue-task and decision counters.
- Added explicit links for period launch, meetings, execution control, readiness and weekly acceptance.
- Added allowed Management Cycle start/close actions as links into canonical cycle workflow.
- No Calendar-side lifecycle writes, no new storage, schema/ACL/privacy unchanged.

## 1.1.0-alpha17 — build 2026101209 — Planning Meeting Workflow
- Added a read-only Calendar workflow projection over canonical Meeting → Agenda → Decision → Task data.
- Calendar now shows upcoming participant-scoped planning meetings with Agenda / Decisions / Tasks progress and attention counters.
- Added deep links from Calendar into the canonical meeting Agenda, Decisions/Tasks and Close sections.
- Selected meeting pages now expose the same workflow strip and readiness-to-close status.
- No new meeting/planning tables, no hidden decisions/tasks, no automatic close/start mutation.
- Company and explicit meeting participation remain the visibility boundary.

## 1.1.0-alpha19 — 2026101211 — Google Calendar Provider
- Added Moodle-core-OAuth2 Google Calendar provider.
- Added per-user connection/settings workspace.
- Added read-only Google event projection to BMS Calendar.
- Added explicit idempotent BMS Meeting → Google Calendar upsert with existing external identity registry.
- No Google credentials, attendee emails, background sync or external-to-BMS writes are stored/performed by local_kbacompany.

## 1.1.0-alpha20 — build 2026101212 — Calendar Analytics
- Added a read-only Analytics view to the unified BMS24 Calendar for the selected month.
- Added meeting time/density, focus-time capacity, overdue task, meeting-heavy day, overlap and regular-management signal metrics.
- Added permission-scoped team workload analytics for authorised managers using only already-visible BMS projections.
- Analytics are calculated at request time and are never stored as employee performance scores.
- Explicit presentation thresholds: workload warning at 75% of the 09:00–18:00 weekday window; meeting-heavy day at 4+ meeting hours or a time overlap.
- No schema, ACL or privacy storage changes.

## 1.1.0-beta1 — build 2026101213 — Feature Freeze & Acceptance Baseline
- Froze the Calendar 1.1 feature set after alpha20 and changed plugin maturity to MATURITY_BETA.
- Added a complete beta acceptance checklist covering Company isolation, Calendar views/CRUD, BMS projections, recurring meetings/events, resources, notifications, Google Calendar and analytics.
- Removed the remaining user-facing technical platform name from the Google OAuth setup message; technical/privacy contracts stay unchanged.
- No functional lifecycle, route or schema expansion relative to alpha20.
- Live Moodle 4.1.22 / PHP 7.3 / production DB / real Google OAuth / cron-delivery / mobile acceptance remain NOT RUN.

2026-10-14 RC27 build2026101458: Employee Work Calendar with explicit company-scoped read/write/comment grants and event discussion; 3 new tables; live NOT RUN.

## 1.1.0-rc28 (build2026101460) — BMS24 Role Access Foundation
Company-scoped access roles, templates, custom roles, assignment by verified Moodle ID, auditable per-cycle grants (view/create/edit), navigation and domain check integration. Separate from existing business roles and Moodle technical roles. Four new tables; no automatic assignments. Company isolation live acceptance NOT RUN. See RELEASE_NOTES_1.1.0-rc28.md / ACCEPTANCE_1.1.0-rc28_RBAC.md.

- 2026101463 RC28 regression: employee work schedule PHPUnit tests; no application logic changes. Live NOT RUN.

- RC28/build2026101464: RBAC granular permissions regression tests (view/edit/revocation/multiple roles), no schema or business logic change. Live NOT RUN.

2026-10-14 RC29.1 build2026101469: read-only commercial offer catalogue v1.1, offering/line-item schema contract and tests. Cloud implementation not included.

- 2026-10-14 build2026101472: Hotfix неверного вызова user_picture::output() на карточке сотрудника и в self-profile: теперь OUTPUT->user_picture(); no schema or ACL changes. Live acceptance NOT RUN.

2026-10-14 build2026101473: BMS24 user photo upload native Moodle storage, no external profile redirect.

2026-10-14 build2026101474: Fixed Management Cycle 2.0 Correction/A3 tab invalidparameter (PARAM_ALPHA truncated a3). No schema/data/ACL changes. Live Moodle NOT RUN.

## RC29 build2026101475 — My Management Cycle
- Added company-scoped personal management-cycle read-only overview and menu entry; existing canonical plan/tasks/calendar; strict owner/curator-only row filter, detail URL only with RBAC view; no data migration.

## RC29 build2026101476
RBAC: enforcement status and effective permissions diagnostics in company roles UI. No schema changes; other objects not yet enforced. Live NOT RUN.

2026-10-14 RC29 build2026101477: Company-wide task:edit and battle_plan:edit grants wired independently to existing manager permissions; no fine-grained ACL yet.

2026-10-14 RC29 build2026101478: department view grant for canonical tasks and battle plans via active organization assignments, scope-safe read SQL and record predicates; no edit/approve extension. Runtime acceptance open.

2026-10-14 RC29 build2026101479: separate canonical task and manual battle-plan company create permissions; scoped create UI, no implicit edit/approve; live acceptance open.

2026-10-14 RC29 build2026101480: department-scoped Battle Plan approval/review server guard and grant toggle; task approval postponed. Live acceptance open.

2026-10-14 RC29 build2026101481: permission battle_plan:create scope=department, manager may create a draft for employees of actual managed org unit, never edit or approve implicitly.

2026-10-14 RC29 build2026101482: scoped department edit of Battle Plan settings and weekly goal (not task mutation or approval).

2026-10-14 RC29 build2026101483: task department scoped edit and result review without execution/closing rights.

2026-10-14 RC29 build2026101484: task attachment/checklist mutation require contributor permission; department-readonly no longer sufficient.

- 2026101490: CKP auto-code, wider entry form and separate read-only Information Centre.

- 2026101492: CKP BMS CRUD-001 pilot: dedicated create, detail and edit; server-side update, stable code preserved.
