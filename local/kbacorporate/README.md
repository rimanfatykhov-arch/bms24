# KBA Corporate Dashboard

Current prepared release: **0.40.2 — SuiteCRM-style Task Record Actions** (`2026092111`) for Moodle 4.0+.

## v0.40.2 SuiteCRM-style Task Record Actions

- Adds a SuiteCRM-style record action bar to the implementation-task detail page: primary **Edit task** plus **Actions → Delete task**.
- Uses CSRF-protected POST and an explicit destructive-action confirmation for deletion.
- Implements deletion as an auditable soft delete: the task disappears from operational lists, Kanban, analytics, reminders and operational file access while its history, checklist, discussion and evidence remain retained for audit/privacy handling.
- Frees the active criterion/route slot after deletion so an authorised manager can create a replacement task without destroying the previous record.
- Applies `deleted = 0` at repository/SQL boundaries instead of fetch-all/filter-later.
- Reuses the existing company implementation-management permission; no new capability or service is introduced.

## v0.40.1 License Provenance & Learning Reconciliation

- Separates purchased, allocated and free licence seats from Moodle enrolment counts.
- Distinguishes company employees, active Moodle enrolments, learners in progress and completed learners in Corporate UI summaries.
- Keeps reconciliation read-only: it reports drift but does not automatically change enrolments.
- Preserves licence/deal/product provenance independently from enrolment state.

## v0.40.0 Production Hardening

- Adds a privacy-safe ERP operational audit with keyed hashes, duration and safe outcome codes; payloads, raw external IDs, PII and exception messages are not stored.
- Verifies every ERP mapping target against its declared company boundary.
- Bounds payload size, nesting depth, key length and node count for REST and PHP bridge callers.
- Extends the health centre to cover all current plugin tables, mapping isolation and ERP audit readiness.
- Adds configurable technical-log retention and a daily cleanup task that never touches business records.
- Documents backup, upgrade, rollback and post-deployment verification procedures.

## v0.35.3 Competency / Learning / Economic Sync

- Exports assessed competency state with explicit `unassessed` semantics; missing assessment remains `null`.
- Exports Moodle-owned course enrolment and completion state without converting completion into a competency level.
- Synchronises ERP-owned company course-catalog assignments without enrolment or Learning Plan mutation.
- Imports ERP economic values as unverified and never overwrites manual values.
- Exports latest economic context with an explicit correlation-only, non-causal interpretation.

## v0.35.2 Org Structure & Role Sync

- Synchronises company departments, employee placements and company business-role metadata.
- Preserves manually created departments, placements and business roles.
- Soft-deactivates departments and business roles; only ERP-owned department placements may be removed.
- Never changes business-role competency profiles, employee competency levels or Moodle Learning Plans.
- Adds an organisation section to the company snapshot without employee PII.

## v0.35.1 Employee Sync

- Safely links existing Moodle users by ID or exact username/email.
- Creates a user only with explicit `create_if_missing=true` and mandatory identity fields.
- Updates profile data only for accounts created by the same ERP mapping.
- Soft-deactivates and reactivates ERP-owned company membership without deleting or suspending the Moodle account.
- Preserves manual accounts, memberships, roles, learning history, assessments and plans.


## v0.34.0.1 customer BMS implementation access hotfix

- Adds a dedicated company-scoped capability for customer BMS implementation operations.
- Makes the BMS implementation card visible to full corporate managers of their own companies.
- Keeps Framework Manager, global tools and JSON administration under the original high-privilege capability.
- Extends own-company audit, task, evidence, comparison, section-progress and economics access without cross-company leakage.

## v0.33.2 Implementation Kanban integration

- Keeps one implementation-task entity and renders it as a richer five-column Kanban projection.
- Adds company, curator, audit, owner, priority, evidence, due-scope, period and text filters.
- Shows BMS criterion provenance, current/target scores, gap, assignment, deadline, evidence, discussion and checklist progress.
- Allows authorised curators/managers to update owner, deadline and priority without bypassing the evidence workflow.
- Records immutable Kanban metadata events and sends Moodle notifications to affected participants.
- Adds an additive task-history schema migration (`eventtype`, `detailsjson`).
- Keeps status transitions under the existing task lifecycle and versioned-evidence review rules.

## v0.32.3 Individual development plan

- Adds a managed KBA individual-development layer on top of the standard Moodle Learning Plan.
- Adds plan objective, plan owner, review date, development goals, practice, evidence criteria, action mentors and checkpoints.
- Adds explicit evidence submission, review, revision and approval without automatic competency grading.
- Adds an immutable operational history for plans and actions.
- Extends reminders, privacy handling, company deletion and health checks for the new data.
- Uses an additive database migration; production backup and pilot upgrade validation are required.

## v0.32.2.1 Moodle template URL hotfix

- Adds the required `pagecontextid` to Moodle template-competency links.
- Resolves the context from `competency_template.contextid` instead of hard-coding the system context ID.
- Preserves the standard Moodle capability checks and changes no data.

## v0.32.1 assessment history

- Employee-wide, company-scoped assessment journal.
- Accepted-level first-to-latest dynamics inside the active filters.
- Filters by source, status, date range, trend and profile scope.
- CSV export with the same access scope and filters.
- Read-only: no grading, enrolment or plan mutation.

## v0.28.0.1 BMS competency index hotfix

- Removes the explicit competency index that collided with the index automatically created for the foreign key.
- Allows a failed v0.28.0 upgrade to be safely retried without manual database changes.

## v0.28.0 BMS tools and competency links

- Links every BMS implementation criterion to one or more Moodle competencies.
- Stores a target competency level, priority and required flag for each link.
- Includes competency links in immutable BMS tool versions and audit snapshots.
- Shows the linked competencies in the BMS tool, implementation audit and managed plan.
- Keeps earlier audit snapshots unchanged when mappings are subsequently edited.

## v0.27.6.1 responsive workspace menu hotfix

- Replaces the permanent horizontal menu scrollbar with a three-column desktop layout.
- Allows BMS implementation actions to wrap inside their own group without clipping.
- Moves Switch company into the Company group instead of rendering a detached second row.
- Hides the redundant Switch company action on the Corporate dashboard, where the company selector is already visible.
- Stacks the implementation group below personal/company actions on medium screens and uses one column on phones.

## v0.27.6 role-aware workspace navigation

- Places personal student actions first: My competencies and assigned BMS tasks.
- Adds the Corporate dashboard next for verified company or department managers.
- Shows curator students, task board and effectiveness only to assigned curators or authorised administrators.
- Shows clients, audits and BMS tools only to users with implementation-management capability.
- Adds a direct Curator students shortcut to the permanent header for assigned curators.
- Uses the same role-aware navigation on personal competencies, corporate dashboard, implementation tasks, plans, audits and BMS tool editors.
- Keeps server-side capability and company-access checks unchanged; hidden links do not grant access.

## v0.27.5 optimized tariff, request and plan flow

- Presents package selection and the existing commercial request in one clearly labelled section.
- Routes “Package and request” to package selection or request management, never to the plan page.
- Opens the managed plan only after payment and program activation.
- Redirects stale or copied plan links to the correct commercial stage.
- Removes repeated plan actions from the request card and audit/program cards.
- Uses audit/program cards to open the request, select a package, or work with implementation tasks as appropriate.

## v0.27.4 unified BMS implementation navigation

- Replaces the scattered technical links with one compact workspace menu.
- Keeps Clients, Client overview, Audits, BMS tools, Students, Task board and Effectiveness together.
- Highlights the active section and separates workspace actions from company navigation.
- Shows company-specific Audits and BMS tools only when a validated company is open.
- Provides direct Switch company and Corporate dashboard actions.
- Uses a horizontally scrollable menu on narrow screens instead of breaking the page width.

## v0.27.3 BMS tool versioning and safe updates

- Every published tool version stores an immutable JSON snapshot and SHA-256 content hash.
- Tool and criterion edits become a working draft and never rewrite the current published version.
- The version page compares tool settings and added, changed or removed criteria before publication.
- Publication requires a change note and archives the preceding version without deleting it.
- Every new implementation audit is pinned to the exact published tool version and copies its criteria.
- Existing audits remain unchanged when a later tool version is published.

## v0.27.2 BMS tool library and editor

- Search and filter tools by management area, status and global/company scope.
- View tool cards with scale, active criteria, current-company audit count and availability.
- Review criteria grouped by BMS section on the tool page.
- Edit scale, attention threshold, BMS Index method, sections, criteria, maturity descriptions, evidence guidance and weights.
- Global templates are read-only in a company workspace; company tools remain editable.
- Stable tool codes are locked after audit use, and audit creation may start directly from a selected tool.

## v0.27.1.1 audit tool selector hotfix

- The audit form lists active company tools and active global templates.
- A newly selected company receives the bundled Point A template automatically when no audit tool is available.
- An explicit prompt replaces the collapsed empty selector.
- Server validation prevents submitting an unavailable, inactive or cross-company tool.

## v0.27.1 effectiveness drill-down and dynamics

- KPI cards and task counts open the Kanban with matching company, curator, status, overdue and period filters.
- The Kanban supports workflow-status, overdue and reporting-period filters in addition to company, curator and text search.
- A 12-month table visualises tasks created, tasks completed and average completion cycle.
- Company comparison shows the change from the previous to the latest confirmed BMS Index.
- The CSV contains the same BMS Index delta and monthly dynamics.

## v0.27.0 implementation effectiveness analytics

- Management summary for tasks, completion rate, review queue, overdue work, average delivery cycle, and checklist completion.
- Company and curator comparison with the latest confirmed BMS Index.
- Filters by company, curator, task text, and 30/90/365-day period.
- Curators only see their assigned programs; authorised administrators see the complete implementation portfolio.
- CSV export uses the same access scope and active filters.

## v0.26.4 task checklists

- Every authorised task participant can add a control item and toggle its completion state while the task is active.
- The item author, assigned curator, or authorised manager can delete an item.
- Completed and cancelled tasks preserve their checklist as a locked record.
- Checklist progress is visible in the task workspace, on Kanban cards, and in “My BMS tasks”.
- Checklist actor data participates in Moodle privacy export and anonymisation.

## v0.26.3 implementation task board

- Tasks are grouped into planned, in-progress, review, completed and cancelled columns.
- Cards show the company, assigned employee, priority, deadline, overdue marker and unread-message count.
- Curators see only programs assigned to them; authorised administrators may view all curator programs.
- Search, company filtering and administrator curator filtering are performed server-side.
- Each card opens the controlled task page; the board does not bypass result, evidence or permission checks.
- The layout wraps responsively instead of creating page-level horizontal scrolling.

## v0.26.2 deadline control and reminders

- A daily scheduled task finds active implementation tasks approaching their deadline.
- The assigned employee receives one advance Moodle/email reminder.
- When a task becomes overdue, the employee, program curator and authorised company managers are notified.
- Delivery state prevents duplicate reminders; changing the deadline resets the reminder cycle.
- The administrator may enable reminders and set the advance window from 1 to 30 days.
- “My BMS tasks” includes linked upcoming-deadline and overdue queues.

## v0.26.1 task results and evidence

- An employee must describe the completed result before submitting a task for review.
- A result may include an external link and up to ten supporting files.
- The result card is visible to the assigned employee, program curator and authorised company manager.
- Supporting files use the existing protected Moodle file area and the same task-access boundary.
- Status history and notifications from v0.26.0 remain unchanged.

## v0.26.0 managed task lifecycle

- Employees receive a “My BMS tasks” navigation entry when tasks are assigned to them.
- The employee starts work and submits the result for review from the task page.
- The curator or authorised manager accepts the result or returns it for revision with a comment.
- Every transition is stored in the status history and sent to participants through Moodle notifications.
- Task queues show status, company, deadline, last activity and overdue state.

## v0.25.3.1 live implementation company search

- The client search field suggests every company available to the current administrator.
- Suggestions include the current implementation state.
- Visible company cards are filtered immediately while typing.
- Selecting an exact suggestion submits the server search automatically, including companies on another page.

## v0.25.3 live curator student search

- The search field suggests assigned students while the curator types.
- Each suggestion includes the student's company as supporting context.
- The task list is filtered immediately in the browser without waiting for form submission.
- The Apply button remains available for combined text and task-status filtering.

## v0.25.2.2 task links, dates and message cleanup

- The task title is now the single task-opening link; the separate action button was removed.
- Activity and message times use the consistent `DD.MM.YYYY · HH:MM` format.
- Single- and multiple-encoded editor HTML is normalised before safe rendering.
- Curator message previews strip all HTML markup.

## v0.25.2.1 curator counters and rich-text hotfix

- Fixed the Moodle `html_writer::link()` argument error on the curator workspace.
- New editor messages are always stored as HTML.
- Messages affected by the v0.25.2 plain-format default are detected and rendered correctly without data migration.

## v0.25.2 curator task controls and rich discussion

- Every student row includes an explicit “Open task” button.
- Summary counters link to all, unread and overdue task queues.
- Status counters link to planned, in-progress, review, completed and cancelled task queues.
- Task discussions use the standard Moodle editor with formatting and emoji support.
- Existing plain-text messages remain compatible through the new message-format field default.

## v0.25.1.1 curator navigation and notifications

- The “Curator students” entry is visible to assigned curators and implementation administrators.
- A second direct entry is shown inside the BMS implementation area.
- Administrators without a personal curator assignment see assigned students across all curators.
- Bell notifications include company, student, task, message author and a direct discussion link.

## v0.25.1 curator workspace

- Curators get a permanent navigation entry when at least one implementation programme is assigned.
- Search and task-status filters, unread counters, overdue counters and direct discussion links are included.
- Opening a task marks its discussion as read for the current user.

## v0.25.0 implementation task workspace

- Every implementation task has a stable direct URL and a dedicated detail page.
- Task titles and actions in the task register open the new workspace.
- The assigned employee and authorised company managers can exchange timestamped discussion messages.
- Owners, company managers and the program curator receive Moodle/email alerts about new messages.
- Discussion remains working correspondence; official completion evidence and assessment stay separate.

## v0.24.2 request approval workflow

- Administrators can write a customer-facing response while updating commercial and delivery statuses.
- The requester receives a Moodle/email notification whenever either status changes.
- The request card displays the KBA response and the latest status-change date.
- Dedicated status metadata separates workflow changes from ordinary contact edits.

## v0.24.1 implementation request notifications

- The customer receives a Moodle notification confirming the company, package and submission date.
- Implementation administrators receive a separate new-request alert with a direct client-workspace link.
- Notifications use Moodle popup/email preferences and never prevent a request from being saved if delivery fails.
- The customer request card displays its submission date.

### v0.24.0.1 active client context

- A prominent “Current client” banner appears in the implementation workspace and its audit, plan, task, economic, request and program pages.
- Multi-company administrators can confirm the selected company before editing data or sending a request.

## v0.24.0 BMS implementation request card

- Package selection opens a dedicated request form instead of immediately creating a program.
- The customer confirms a contact person, email and phone and can add a comment for the implementation team.
- Submitted details are stored with the implementation program and included in the Bitrix24 deal description.
- The customer workspace shows the saved request card, while the administrator can edit its contact data.
- The filtered client CSV includes the request contact fields.

## v0.23.1 Direct BMS entry and package selection

- «Внедрение BMS» is displayed beside «Мои компетенции» and the corporate dashboard when one company context is known.
- The implementation workspace displays editable package cards immediately after its summary.
- A package request is linked to the latest baseline audit and cannot duplicate an existing request for that audit.
- If Point A does not exist yet, the workspace explains the dependency and opens audit creation.

### v0.23.1.1 navigation hotfix

- The top-level “BMS implementation” shortcut remains visible for administrators and managers with several companies.
- When no company is selected, a tenant-safe company chooser is shown instead of hiding the shortcut or selecting an arbitrary company.

## v0.23.2 BMS client search and filters

- Administrators can search the implementation company chooser by client name.
- Filters isolate companies with no audit, audit without a request, an existing request/program, or an active program.
- Every company card displays its operational state plus audit and program counts.

## v0.23.3 BMS implementation client worklist

- Adds sorting by activity, company name and implementation state plus 12/24/48 item pagination.
- Client cards show latest audit and BMS Index, selected package, commercial stage, curator and latest activity.
- Contextual actions open the workspace, create Point A, offer a package or manage the program.
- Program administrators can assign a curator in the program editor.
- CSV exports the complete filtered worklist with operational columns.

### v0.23.3.1 company switching hotfix

- Multi-company administrators always return to the client registry from the permanent “BMS implementation” shortcut.
- A visible “Select another company” action is available inside every company implementation workspace.
- Single-company managers retain direct access without an unnecessary chooser.

## v0.23.0 BMS implementation workspace

- One customer section combines implementation audits, BMS Index, commercial requests, programs, plans and tasks.
- The corporate dashboard now opens the workspace instead of a bare audit table.
- The implementation journey is presented as four guided stages: Point A, package/request, plan/tasks and Point B.
- Audit/program cards show the correct next action for the current state.
- Editable packages are rendered as customer-facing commercial cards.
- All operational implementation pages link back to the workspace.

## v0.22.2.2 Responsible-person fallback

- Pipeline, stage and user-directory failures are isolated from one another.
- A webhook without user-directory permission falls back to its own Bitrix24 user via `user.current`.
- Full responsible-person selection remains available when the webhook has the Users permission.

## v0.22.2.1 Bitrix24 REST compatibility

- Encodes nested `order`, `filter` and `fields` parameters using Bitrix24 bracket notation.
- Fixes “Parameter order must be array” while loading stages.
- Applies the same correction to responsible-user lookup and deal creation.

## v0.22.2 Bitrix24 selectors

- A protected administrator page loads deal pipelines from Bitrix24.
- Stages are loaded for the selected pipeline, including the default pipeline.
- Active Bitrix24 users can be selected as the responsible person.
- New CRM deals receive `CATEGORY_ID`, `STAGE_ID` and `ASSIGNED_BY_ID`.
- Previously saved values survive a temporary Bitrix24 connection failure.

## v0.22.1 Editable packages and Bitrix24

- Administrators edit package name, service contents, price, currency, duration, order and availability.
- Companies select an active package when requesting a managed implementation program.
- Moodle can create a CRM deal automatically in a configured Bitrix24 pipeline and stage.
- A failed CRM call never discards the Moodle request; administrators can retry manually.
- The webhook is stored only in Moodle configuration.

## v0.22.0 Commercial implementation programs

- A company can request a managed implementation program from a completed or open BMS audit.
- Audit results remain available, while the detailed plan and tasks are commercially gated.
- KBA administrators select BMS Start, Support or Transformation, record the Bitrix24 deal and activate delivery.
- Paid active programs allow task creation; paused and completed programs remain readable.

## v0.21.3.5 Assessment form

- Correct comment label instead of `[[comment]]`.
- Clear supporting-file, external-document and verification terminology.
- Help text for selecting maturity levels and attaching supporting materials.

## v0.21.3.4 Company profile

- Store the primary business activity (niche) and founding year once in the company card.
- Show these details in the company dashboard and company register.
- Exchange both optional fields through the ERP API company contract.

## v0.21.3.3 Economic indicators

- Store a period, currency, data source and verification status with each audit snapshot.
- Capture revenue, margin profit, operating profit, net profit, taxes, payroll, employee count and average salary.
- Calculate profitability, tax burden, payroll share, revenue per employee and operating profit per employee.
- Compare Point A and Point B while keeping performance metrics separate from the BMS Maturity Index.

## v0.21.3.1 Corporate dashboard

- BMS implementation audits appear first and competency audits second.
- Primary audit entry points and neutral navigation cards use green KBA accents.

## v0.21.3 Managed implementation plan

- Create persistent tasks from audit gaps and configured development routes.
- Assign a company member, dates, priority and expected result.
- Track planned, in-progress, review, completed and cancelled states.
- Attach completion evidence and filter the task register by owner, status or overdue state.

## v0.21.2 Gaps and implementation routes

- Criteria define their target maturity score; every audit stores an immutable target snapshot.
- Point A uses target level 7 on its 0–9 scale.
- Each criterion can recommend one or more BMS tools and company Moodle courses.
- The implementation plan is generated automatically from unassessed criteria and gaps.
- Repeated audits are compared with the preceding confirmed audit.

## v0.21.1 Point A audit template

- One-click company installation of `BMS-AUD-CHK-001` from the supplied workbook methodology.
- 15 BMS areas and 137 clean criteria without demonstration answers.
- Native 0–9 scale, `Needs data` and `Not applicable` states.
- Area summaries, scores below 7 and assessment coverage.
- BMS Index normalised to 0–100 while preserving equal weighting of assessed areas.
- Audit snapshots retain the scale, threshold, area names and endpoint descriptions used at creation time.

## v0.35.1 ERP Employee Sync

- Links existing Moodle accounts by Moodle user ID or exact username/email.
- Creates a Moodle account only with explicit `create_if_missing=true` and complete validated identity fields.
- Updates profile data only for accounts created by the same ERP mapping.
- Soft-deactivates only an ERP-owned company employee relation; Moodle accounts and history are preserved.
- Preserves manual accounts and manual company memberships.
- Adds `bridge::sync_erp_employee()` and raises the public bridge to `1.18`.

See `API_CONTRACT_v0.35.1.md` and `ERP_API_V1.md`.

## v0.35.0 Stable ERP API Contract

- Freezes ERP API contract major version 1 and contract release `1.0.0`.
- Adds machine-readable compatibility rules and a SHA-256 schema fingerprint.
- Adds read-only readiness function `local_kbacorporate_erp_status`.
- Keeps existing provider tokens, mappings and v1 operations backwards compatible.
- Publishes data authority, privacy, error and BMS methodology safeguards.
- Public PHP bridge release at the time was `1.17`; all methods remain supported by current API `1.21`.

See `API_CONTRACT_v0.35.0.md` and `ERP_API_V1.md`.

## v0.21.0 BMS implementation audits

- Company-scoped BMS tool library and weighted audit criteria.
- Baseline, progress, final and monitoring implementation audits.
- Criterion maturity score from 0 to 5, with a distinct `Not assessed` state.
- Evidence files stored through the Moodle File API plus optional external links.
- Required evidence verification before audit confirmation.
- Weighted BMS Index and Evidence Coverage; confirmation freezes the official result.
- Separate dashboard entry; implementation audits do not alter employee competency grades.

## v0.20.0.1 corporate dashboard cleanup

- The duplicate `Мои компетенции` card is no longer shown in the corporate customer dashboard.
- The standalone `mycompetencies.php` page and permanent navbar entry remain available.
- Corporate competency navigation now focuses on the company map, roles, audits, development plans and effectiveness.

## v0.20.0 Odoo/BMS Digital ERP exchange

- Adds disabled-by-default Moodle External Service `KBA Corporate ERP API v1`.
- Uses a dedicated service account and `local/kbacorporate:useerpapi` capability.
- Supports idempotent company, employee and business-role-assignment pushes.
- Uses provider-neutral external mappings; `odoo`, `bms_erp` and future providers do not change the domain model.
- Exposes paged company snapshots with roles, departments, confirmed competency state and development-plan progress.
- Does not export employee names, email addresses or evidence text.
- Preserves manually assigned access and business roles; ERP can deactivate only assignments it owns.
- Public PHP bridge release at the time was `1.16`; all earlier methods remain supported by current API `1.21`.

See `ERP_API_V1.md` for setup, payloads and response rules.

## v0.17.4 Business Role Template Library

- Uses centrally maintained global roles as the KBA template library.
- Creates an independent company role with a complete copy of the template competency profile.
- Records the source template and applied template version.
- Keeps the existing role constructor for company-specific names, descriptions and competency requirements.
- Detects newer template versions in the company role profile.
- Applies updates non-destructively: template-controlled requirements are refreshed, new requirements are added, manual overrides and company-only competencies remain unchanged.
- Does not silently remove requirements or alter employee role assignments.

See `RELEASE_NOTES_0.17.4.md` for the workflow and upgrade details.

## v0.17.3 Automated Role-based Audit

- Creates an audit from the employee's active business roles in one selected company.
- Snapshots each required competency, target level, priority, required flag and contributing roles.
- Automatically collects the confirmed Moodle user-competency level, competency evidence and company-course competency results.
- Accepts an existing confirmed Moodle level immediately; other grade-bearing evidence stays preliminary until reviewed.
- Uses an explicit **Needs assessment** state when evidence is absent, never an invented level 0.
- Supports bulk confirmation of preliminary proposals through the Moodle Competencies API.
- Removes the primary manual Evidence URL field; assessor comments are stored as structured audit evidence.
- Learning Plans remain optional learning sources and do not define role audit scope.

See `RELEASE_NOTES_0.17.3.md` for upgrade and acceptance details.

## v0.17.2 Competency Audit

Corporate managers can create a company-scoped employee audit and record preliminary assessments by self-assessment, test, interview, practical task, expert or AI. Nothing changes the authoritative level until a reviewer explicitly confirms it. Confirmation uses Moodle core Competency API; Learning Plans are optional and are not used to define business-role targets.

See `RELEASE_NOTES_0.17.2.md` for permissions, upgrade and acceptance details.

## v0.17.1.2 Business Role Management UI

- Adds a company-scoped catalog of business roles and read-only visibility of global roles.
- Adds role profiles with Moodle competency, target level, priority and required fields.
- Assigns several business roles to one employee using additive MERGE semantics.
- Deactivates one explicit employee-role assignment without deleting assignment history or changing other roles.
- Links role management from Moodle administration, company management, employee membership and employee profile pages.
- Enforces system capability, full-company access and server-side company scope on every management page.

## v0.17.1.1 Moodle 4.0 XMLDB hotfix

- Removed the redundant role-competency index that collides with its foreign key in Moodle 4.0 XMLDB.
- Shortened external mapping system/entity tokens so their unique composite index stays within the Moodle/MySQL 333-character limit.
- Uses a higher build and an idempotent migration path so a failed v0.17.1 attempt can be resumed through Moodle's plugin installer without uninstalling the plugin.

## v0.17.1 My Competencies role targets and course recommendations

- Resolves exactly one company context before applying business roles; roles from different companies are never merged.
- Shows authoritative current level, role target, gap, priority/required markers and an independent attention signal.
- Separates `Не оценено` from the real stored level `0`.
- Treats course-scoped grades as provisional evidence (`Требует подтверждения`), not as the current competency level.
- Recommends company-assigned courses mapped to the competency, including enrolment, completion and progress state.
- Shows the latest assessment/confirmation and uses the term `Аудит` only for explicitly marked audit evidence.
- Extends the public bridge contract to API 1.15 and requires explicit or uniquely resolvable company scope for target profiles.
- Covers business-role assignments and user external mappings in lifecycle deletion and Moodle Privacy API operations.

## v0.17.0 Role Competency Profile foundation

- Adds canonical KBA business roles (`BMS-ROLE-*`) separate from Moodle roles and corporate access flags.
- Adds role competency requirements: business role → Moodle/BMS competency → target level, priority and required flag.
- Adds employee business-role assignments with company scope and source metadata.
- Adds generic external-system mapping for Bitrix24 now and future ERP/HR connectors.
- Adds target-profile resolver: multiple active roles are merged by competency, using the highest target level.
- `Мои компетенции` can now include role-required competencies even before the employee is enrolled in a course that develops them.
- Public internal bridge API is extended to 1.14 with business-role catalog, assignment and employee target-profile methods.
- Moodle competency result/evidence tables remain the source of current competency state; role audits keep a dated evidence snapshot for traceability.

## Current competency baseline

- `mycompetencies.php` shows competencies from the learner's enrolled courses and, when present, Learning Plans.
- Current competency/evidence data remains owned by Moodle core; Corporate Dashboard stores only the evidence snapshot attached to an audit.
- BMS pilot target levels are displayed for documented pilot competencies.
- User-facing terms include **AI Оценка**, **Проверка**, and **Разрыв до цели**.
- Competency cards include clickable source-course links, status filters, course filter, text search, and collapse/expand controls.
- Evidence can move a competency to **В развитии**, but explicit proficiency remains required for **Подтверждено**.

## What this MVP does

- Creates corporate companies.
- Links Moodle users to a company as employees or corporate managers.
- Automatically assigns a restricted `KBA Corporate Manager` role to corporate managers.
- Assigns Moodle courses (optionally a Moodle group) to a company.
- Shows a company dashboard with employee count, active learners, completions, average activity-completion progress and average course grade.
- Shows employee-by-course status, progress, grade and last activity.
- Shows BigBlueButton webinar attendance, factual participant duration, presence coverage, first join/lateness and Summary engagement metrics.
- Shows a corporate certificate/document registry from `mod_customcert`, with secure download of already-issued employee PDFs.
- Exports the visible company report to CSV, including webinar attendance columns.
- Enforces company isolation on the server side for dashboard, employee card and export.
- Reads custom user profile field `position` when it exists.
- Stores `bitrixcompanyid` and exposes a public bridge API for optional BMS Connect integration.
- Supports company departments and department-scoped managers.
- Provides a report centre with CSV, XLSX and executive PDF exports.
- Provides a dynamic **Requires attention** control centre and scheduled manager digests.
- Integrates with **KBA Gamification** through its public internal API: company XP, levels, achievements and employee leaderboards with department-aware access scope.

## Installation

1. Copy folder `kbacorporate` to `moodle/local/kbacorporate` (the ZIP supplied contains the plugin folder contents; when installing via Moodle use the ZIP directly).
2. Go to Site administration -> Notifications and complete installation.
3. Go to Site administration -> Plugins -> Local plugins -> KBA Corporate Dashboard -> Companies.
4. Create a company.
5. Add employees and at least one corporate manager.
6. Assign one or more courses to the company.
7. The corporate manager opens `/local/kbacorporate/`.

## Progress calculation

Progress is calculated from activities for which Moodle activity completion is enabled. If a course does not use completion tracking, progress is displayed as `—`. Course completion uses Moodle's course completion API. The grade shown is the course total converted to a percentage.

## Group mapping

If a company-course mapping specifies a Moodle group, only employees who are members of that group are included for that mapping. If no group is specified, all company employees are evaluated for the course.

## BigBlueButton Analytics 2.0 (v0.7.0)

The plugin reads the standard Moodle 4.0 `mod_bigbluebuttonbn` tables in read-only mode. Factual participant time is summed from `Summary` log JSON (`data.duration`, seconds), matching Moodle's own BBB attendance-completion rule. v0.7.0 also reads the Summary `data.engagement` counters used by Moodle completion rules (`chats`, `talks`, `raisehand`, `poll_votes`, `emojis`).

The earliest Moodle BBB `Join` log is used as the first-join indicator. Lateness and presence coverage are calculated only when the activity opening/closing interval is no longer than the configured single-webinar maximum (12 hours by default), so a multi-day access window is never presented as meeting duration. Logout/early-leave data is displayed/exported only when a compatible `Logout` record exists. Group-specific mappings continue to filter BBB meeting logs by the mapped Moodle group.

## Custom Certificate registry (v0.8.0)

When `mod_customcert` is installed, Corporate Dashboard reads its `customcert_issues` registry in read-only mode. The company manager can filter issued/not-issued rows by employee and course, see issue dates/codes, export the registry to CSV and download an **already-issued** PDF through a Corporate Dashboard proxy. The proxy re-validates company access, employee membership, company-course assignment and optional Moodle-group scope before delegating PDF rendering to Custom Certificate.

The adapter does **not** create or issue certificates. A `Not issued` row simply means no `customcert_issues` record exists for that employee/certificate activity; eligibility remains controlled by Moodle activity restrictions and `mod_customcert`.

The PDF adapter supports both the Moodle 4.0-era `\mod_customcert\template::generate_pdf()` API and newer service-based Custom Certificate releases when those APIs are present.

## Planned adapters

Future versions can add:

- batch ZIP download for issued certificates;
- visual trend charts and SLA dashboards;
- signed outbound webhooks and delta feeds for approved integrations.

## Upgrade from 0.1.0 to 0.1.1

Do not uninstall v0.1.0. Replace/update the plugin with v0.1.1 and run the standard Moodle upgrade. The upgrade step repairs the Bitrix24 company field name automatically and preserves existing values.

## Learning time (v0.4.0)
The **Learning time** view automates the logic of KBA's former weekly employee-hours workbook. A company can have a daily learning quota (default 1.5 hours per employee) and configured learning weekdays. The report shows company plan vs actual by day, cumulative plan/fact, average hours per employee, employee totals, and course totals.

BigBlueButton presence duration is taken from BBB Summary records. Normal asynchronous Moodle activity does not have an authoritative time-on-task timer; KBA Corporate Dashboard estimates active time by grouping standard-log interactions into sessions and excluding long inactivity gaps. The thresholds are configurable in the plugin administration settings and the UI clearly distinguishes estimated Moodle time from webinar duration.

## BMS Connect public bridge (v0.4.2)

`local_kbacorporate` remains an independent plugin. BMS Connect may optionally call:

```php
\local_kbacorporate\local\bridge::sync_member(
    $userid,
    $bitrixcompanyid,
    $companytitle,
    $bitrixcontactid
);
```

The bridge finds or creates the company by `bitrixcompanyid`, updates its title when Bitrix24 changed it, and idempotently ensures an `employee` relation in `local_kbacorp_member`. It does not enrol users, calculate progress, read grades, analyse BigBlueButton, or build reports. Those responsibilities remain separated between BMS Connect and KBA Corporate Dashboard.

The current v0.4.2 schema does not contain a Bitrix24 Contact ID column on company members. The optional `$bitrixcontactid` argument is accepted for API compatibility but is not persisted.



## Unified BMS integration (v0.4.3)

Version 0.4.3 combines the public BMS Connect bridge from the integration build with the legacy `/local/kbacorporate/manage.php` compatibility endpoint. BMS Connect can detect `\local_kbacorporate\local\bridge`, while older management links continue to redirect safely to `companies.php` after capability checks.

## Public API v1.1 (v0.4.5)

External/optional Moodle plugins must integrate through `\\local_kbacorporate\\local\\bridge` and must not read/write `local_kbacorp_*` tables directly.

Public API v1 exposes:

- `bridge::contract()` — machine-readable API contract/version;
- `bridge::status()` — read-only health/schema/count diagnostics;
- `bridge::get_company_by_bitrix_id()` — safe company lookup;
- `bridge::sync_company()` — idempotent company create/update by Bitrix24 Company ID;
- `bridge::sync_employee()` — canonical employee sync;
- `bridge::sync_member()` — backwards-compatible alias used by older BMS Connect builds;
- `bridge::remove_employee()` — remove only the employee/company relation;
- `bridge::assign_course()` — idempotently map a Moodle course/group to the company without enrolling users.

At v0.4.5 the API major was `1` and additive release was `1.1`. API v1 remains backwards compatible; v0.12.0 exposes additive release `1.9`. A future incompatible contract must use a new major API version.

Public API v1.1 additionally exposes read-only CRM snapshots:

- `bridge::get_company_snapshot($bitrixcompanyid)` — one company plus courses explicitly assigned in Corporate Dashboard;
- `bridge::list_company_snapshots($aftercompanyid, $limit)` — bounded cursor batch for scheduled BMS/CRM publishing.

The snapshot catalogue uses `local_kbacorp_course` as the source of truth. Employee Moodle enrolments are intentionally not used to infer which courses the corporate client purchased. A course is marked active in the snapshot only when both its corporate mapping is active and the Moodle course is visible.

Administrator diagnostics are available at `/local/kbacorporate/api.php` and from the plugin administration tree under **Public API / diagnostics**.


## BMS full synchronisation contract (v0.5.0)

Public API release `1.2` adds the operations needed for a complete idempotent BMS Connect corporate flow without direct SQL access to Corporate Dashboard tables.

- `bridge::sync_training_assignment(...)` atomically ensures company + employee relation + corporate course/group mapping in one company-scoped lock. It deliberately does **not** enrol the Moodle user in the course; BMS Connect remains responsible for enrolment and group membership.
- `bridge::sync_company_courses(...)` synchronises a bounded company course catalogue and can soft-disable mappings that are no longer present in the source list.
- `bridge::unassign_course(...)` soft-disables one course/group mapping instead of deleting it, allowing a later webhook to reactivate the same row idempotently.
- Company snapshots now also expose `employeecount` and `managercount`.
- `bridge::status()` exposes active/inactive course mapping counts and a `writesafe` flag. `writesafe=false` indicates an incomplete schema or duplicate non-empty Bitrix24 Company IDs.

Recommended BMS Deal path:

```text
Bitrix24 Deal / Contact / Company
        ↓
BMS creates or updates Moodle user
        ↓
BMS enrols user and assigns Moodle group
        ↓
bridge::sync_training_assignment()
        ↓
KBA company + employee + purchased course mapping
```

All new v1.2 methods are additive to Public API v1; existing v1.0/v1.1 calls remain supported. No database schema changes are introduced in plugin v0.5.0.


## Learning Time Analytics 2.0 (v0.6.0)

The learning-time workspace now supports **current week**, **current month**, **current quarter**, and a bounded **custom period**. Named periods end on the current day so future planned weekdays do not reduce plan completion.

The report contains:

- company plan, actual learning time, percentage and variance;
- employees below plan;
- weekly plan-vs-actual dynamics;
- employee-level Moodle estimate, BigBlueButton factual duration, total, variance and plan status;
- course-level actual time and share of all learning time;
- separate CSV exports for employees, weeks, courses and days.

Public API v1 remains backwards compatible and the additive release is now **1.3**. `bridge::get_training_time_snapshot()` exposes the same plan/fact methodology to trusted local integrations such as BMS Connect. Moodle asynchronous time remains explicitly marked as an estimate; BBB Summary duration is treated as factual presence time when available.

## Public API v1.4 (v0.7.0)

`bridge::get_webinar_analytics_snapshot($bitrixcompanyid)` exposes a read-only company webinar summary and per-employee aggregates for BMS/CRM publishing. It reports the data-source methods explicitly so integrations can distinguish factual BBB Summary duration/engagement from Moodle Join-log timing. Public API major version remains `1` and existing v1 methods remain backwards compatible.

### Report Center (v0.9.0)
Corporate managers with `local/kbacorporate:exportreport` can open `/local/kbacorporate/reports.php` and select a week, month, quarter or custom period. The centre provides an executive PDF, a multi-sheet XLSX workbook and existing detailed CSV exports. The XLSX workbook contains Summary, Employees, Progress, Learning time, Webinars and Certificates sheets.

### Departments (v0.10.0)

Corporate companies can now be split into departments or business units. An employee may belong to more than one department. A department manager receives the `KBA Department Manager` system role, but data visibility is additionally constrained by explicit department-manager mappings stored by Corporate Dashboard. The role alone never grants company-wide data access.

Administrators manage departments from **Companies → Departments**. Corporate users see a read-only **Departments** section and can filter the dashboard and reports by their available departments.



## Upgrade to v0.11.0

Install v0.11.0 over v0.10.1 and complete the standard Moodle upgrade from **Site administration → Notifications**. The upgrade adds only three company-level scheduling fields: `alertsenabled`, `alertfrequency` and `lastalertsent`. Calculated learning-control signals are not copied into a persistent alerts table. Notifications are disabled for existing companies until an administrator enables them explicitly.

## Learning control and notifications (v0.11.0)

The **Requires attention** workspace calculates current risk signals without copying them into a separate alerts table. Signals disappear automatically when the underlying learning issue is resolved. The control engine checks:

- company course assigned but the employee is not enrolled;
- course not started within the configured number of days after enrolment;
- prolonged inactivity;
- low average progress or course grade;
- learning time below the company plan;
- low attendance for completed BigBlueButton webinars;
- completed courses without an issued Custom Certificate.

Administrators configure global thresholds in the plugin settings. Notification delivery is enabled separately for each company and can run daily or weekly. Digests are sent through the Moodle notification system to corporate managers and department managers. Each recipient receives only the employees allowed by the existing company/department access rules. An administrator can also run a company digest manually from the control centre.

The scheduled task runs daily at 07:15 and sends only companies that are due. Empty successful checks are marked as completed for the configured interval so cron does not repeatedly recalculate the same company. The administrator diagnostics page reports companies with notifications enabled and Public API availability.

Public API v1.8 adds:

```php
\local_kbacorporate\local\bridge::get_attention_snapshot(
    $bitrixcompanyid,
    'month'
);
```

The method returns the calculated summary, thresholds, per-employee risk aggregation, individual signals and notification status for an optional BMS/CRM integration.

## Corporate licences and seats (v0.12.0)

Version 0.12.0 adds a commercial-contract layer without moving Bitrix24 logic into Corporate Dashboard. `local_bitrixsync` / BMS Connect remains the only component that communicates with Bitrix24; it passes normalised contract data through `\local_kbacorporate\local\bridge`.

Each licence package stores:

- company, Moodle course and optional Moodle group;
- purchased seats (`0` means an unlimited compatibility package);
- validity start/end dates;
- source (`manual`, `bitrix`, or internal `legacy` migration);
- Bitrix24 Deal ID and Product Row ID;
- active/inactive/cancelled contract state.

Seat use is explicit in `local_kbacorp_license_user`. Moodle enrolment remains the responsibility of BMS Connect or a Moodle administrator; enrolment alone does not normally consume a seat. The v0.12 upgrade performs one compatibility backfill for existing company-course mappings and currently enrolled corporate employees.

Business rules:

1. Repeating the same BMS licence or employee call is idempotent.
2. One employee has at most one active seat across packages for the same company/course/group.
3. An unused seat may be released before the employee starts training.
4. Started or completed training retains the used seat and contract history.
5. Expired, inactive and cancelled packages stay in the register but do not inflate current company capacity KPIs.
6. Once a real commercial package exists, legacy unlimited compatibility data cannot bypass its quantity or validity limits.
7. Package deactivation is soft; allocations and external references are retained.

### Public API v1.9 for BMS Connect

```php
use local_kbacorporate\local\bridge;

$contract = bridge::sync_course_license(
    $bitrixcompanyid,
    $companytitle,
    $courseid,
    $groupid,
    $seats,
    $datefrom,
    $dateto,
    $dealid,
    $productrowid
);

$allocation = bridge::assign_license_user(
    $userid,
    $bitrixcompanyid,
    $courseid,
    $groupid,
    $dealid,
    $productrowid
);

$snapshot = bridge::get_license_snapshot($bitrixcompanyid);
```

The receiving side is complete in v0.12.0. A separate BMS Connect release must call these methods after it has processed the Bitrix24 deal/product and completed Moodle enrolment. Corporate Dashboard does not call Bitrix24 directly.

`bridge::sync_training_assignment()` remains available for older BMS builds. It first commits the company, employee and corporate course mapping, then attempts a seat allocation. A full/expired licence is returned in the nested `license` result and does not roll back successful Moodle/BMS processing.

### Upgrade from v0.11.0

Install the ZIP over the existing plugin and complete **Site administration → Notifications**. Do not uninstall the old plugin. The upgrade creates:

- `local_kbacorp_license`;
- `local_kbacorp_license_user`.

Every existing corporate course/group mapping receives one unlimited `legacy` package. Existing corporate employees who are currently enrolled in the matching Moodle course (and matching group, when configured) receive a compatibility allocation. Administrators can then replace the legacy package with a finite manual or Bitrix24 contract; active compatibility allocations are transferred to the first real package and any excess is shown as overbooking rather than silently discarded.

After upgrade, purge Moodle caches and verify **KBA Corporate Dashboard → Public API / diagnostics** reports API `1.9`, licence schema available and no duplicate Bitrix24 licence references.



## KBA Gamification integration (v0.13.0)

Corporate Dashboard treats `local_kbagamification` as an optional independent plugin. No Gamification tables are queried directly. Runtime data is read only through `\local_kbagamification\api`.

The integration provides:

- total and average XP for the currently visible company/department cohort;
- achievement totals and level distribution;
- configurable employee XP leaderboard;
- XP, current level and achievement count in the employee list;
- full awarded-achievement history in the employee card;
- optional display of KBA Coins, disabled by default;
- graceful fallback when KBA Gamification is absent, disabled or temporarily unavailable.

Corporate Dashboard remains the source of truth for company and department access. Only the already-authorised employee Moodle IDs are passed to KBA Gamification. Gamification remains the source of truth for XP, Coins, levels and achievements.

Administrator settings are under **Site administration → Plugins → Local plugins → KBA Corporate Dashboard → Settings**. The **Version information** page also reports the detected KBA Gamification build and API availability.

## v0.16.0 — Competency Development

Первый этап компетентностного контура добавляет read-only страницу «Мои компетенции» (`mycompetencies.php`). Источник истины — Moodle core competencies и learning plans. Для пилота BMS Начало отображаются уровни 0–3, целевой уровень, GAP и evidence. AI evidence в интерфейсе называется «AI Оценка».

## v0.32.2 — Повторная оценка после плана развития

После завершения плана развития уполномоченный пользователь открывает предпросмотр, создаёт повторную оценку, собирает новые доказательства и явно подтверждает новый уровень. Система фиксирует исходный уровень из плана, не подменяет отсутствие оценки нулём, не принимает завершение курса за подтверждение компетенции и рассчитывает результат «до / после / цель / оставшийся разрыв».

Точки входа:

- `competency_reassessment_start.php?planid=<id>` — проверка до создания;
- `competency_reassessment.php?id=<auditid>` — рабочее пространство оценки и подтверждения.
