# v0.40.2 — SuiteCRM-style Task Record Actions

- Добавлена единая панель действий карточки задачи в стиле SuiteCRM: основное действие «Изменить задачу» и меню «Действия → Удалить задачу».
- Удаление выполняется только POST-запросом с `sesskey` и обязательным подтверждением.
- Реализовано мягкое удаление: запись задачи, история, чек-лист, обсуждение и доказательства сохраняются для аудита и privacy, но задача исключается из рабочих списков, Канбана, аналитики, напоминаний и операционного file access.
- После удаления освобождается активная связка criterion/item + route, поэтому можно создать новую задачу на тот же маршрут без разрушения истории.
- Все операционные выборки фильтруют `deleted = 0` на SQL/repository boundary.
- Используется существующее право управления внедрением компании; `access.php` и `services.php` не менялись.
- Схема: `deleted`, `deletedat`, `deletionkey`, новый индекс `company_deleted_ix`, уникальность активной задачи перенесена на `(itemid, routeid, deletionkey)`.
- Build `2026092111`.

# v0.40.1 — License Provenance & Learning Reconciliation

- Разделены купленные, распределённые и свободные места лицензий и Moodle enrolment state.
- В корпоративной сводке отдельно показаны сотрудники, active enrolments, обучаются и завершили.
- Reconciliation остаётся диагностическим и не меняет enrolments автоматически.
- Сохранён provenance licence package / deal / product row независимо от enrolment.

# v0.40.0 — Production Hardening

- Добавлен обезличенный технический журнал ERP-запросов с HMAC-хешами идентификаторов, длительностью и безопасным кодом результата.
- Payload, исходные внешние ID, персональные данные и тексты исключений в журнал не записываются.
- Все ERP mapping target проверяются на принадлежность заявленному контуру компании.
- Ограничены размер, глубина, число элементов и длина ключей payload для REST и PHP bridge.
- Health Center проверяет все 41 таблицу релиза, company isolation и готовность operational audit.
- Добавлена ежедневная очистка только устаревших технических логов с настраиваемым сроком 30–3650 дней.
- ERP contract `1.3.1`, Public Bridge `1.21`, build `2026091009`.
- Миграция только добавляет `local_kbacorp_erp_log`; бизнес-данные не изменяются.

# v0.35.3 — Competency / Learning / Economic Sync

- Company snapshot дополнен Moodle-owned learning progress и явным competency assessment status.
- Неоценённая компетенция экспортируется как `null` / `unassessed`, а completion не подтверждает уровень.
- Добавлен `learning_catalog_assignment` без enrolment и Learning Plan mutation.
- Добавлен `economic_value`: только unverified ERP values, manual values не перезаписываются.
- Экономический snapshot помечен `correlation_only`, `causality_established=false`.
- ERP contract `1.3.0`, Public Bridge `1.20`, build `2026091008`.
- Схема БД не меняется.

# v0.35.2 — Org Structure & Role Sync

- ERP push поддерживает `department`, `department_membership` и `business_role`.
- Подразделения и company business roles создаются, обновляются и мягко деактивируются идемпотентно.
- Ручные записи и назначения сохраняются; ERP удаляет только собственное department membership.
- Профиль компетенций бизнес-роли остаётся под управлением KBA/Moodle и не меняется ERP.
- Company snapshot дополнен структурой подразделений и ролей без PII.
- ERP contract `1.2.0`, Public Bridge `1.19`, build `2026091007`.
- Схема БД не меняется.

# v0.35.1 — Employee Sync

- Employee push поддерживает существующий `moodle_user_id`, точный поиск по username/email и явное создание account.
- Создание разрешено только при `create_if_missing=true`; пароль не возвращается и требует смены.
- Профиль обновляется только у account, созданного той же ERP mapping.
- `active=false` отключает только ERP-owned employee membership, не удаляя Moodle account, аудит или ИПР.
- Ручные accounts и memberships сохраняются.
- Добавлен `bridge::sync_erp_employee()`, ERP contract `1.1.0`, Public Bridge `1.18`.
- Схема БД не меняется; build `2026091006`.

# v0.35.0 — Stable ERP API Contract

- Зафиксирован стабильный ERP API contract `1.0.0` в major version `1`.
- Добавлены machine-readable правила совместимости, владения данными, ошибок и методологических ограничений.
- Добавлен SHA-256 `schema_hash` для контроля версии схемы коннектором.
- Добавлена read-only функция `local_kbacorporate_erp_status`.
- Public PHP bridge повышен до `1.17` без удаления или изменения старых методов.
- Старые provider token, включая `odoo`, сохранены без переименования.
- Схема БД и существующие данные не меняются; build `2026091005`.

# v0.33.3.1 — Терминология ERP в интерфейсе

## 0.34.1 — Панель экономических показателей аудита

- добавлена графическая экономическая панель компании и аудита;
- KPI-карточки: факт, предыдущий период, план и прогноз;
- линейные и столбиковые диаграммы на стандартном механизме Moodle;
- фильтры, audit context, CSV export and mixed-currency protection;
- схема БД не меняется; build `2026090904`.

- Название конкретного поставщика Odoo заменено в пользовательском интерфейсе на нейтральное «ERP».
- Обновлены API-диагностика, права, источник экономических данных и описания интеграции учебных планов.
- Обновлены описания KBA-шаблонов и учебных планов Moodle; существующие KBA-записи корректируются безопасной текстовой миграцией.
- Технический provider token `odoo`, существующие интеграционные записи и API-контракт не переименовываются.
- Схема базы данных не меняется.

# v0.33.3 — Прогресс внедрения по разделам BMS

- Добавлена сводная аналитика по разделам подтверждённого аудита BMS.
- Разделены показатели зрелости аудита, покрытия действиями, выполнения задач и принятых доказательств.
- Добавлено сравнение средних оценок разделов с предыдущим подтверждённым аудитом того же инструмента.
- Добавлены статусы разделов, риски просрочки и отсутствия ответственных.
- Добавлена детализация разрывов до критериев и связанных действий внедрения.
- Добавлен CSV-экспорт с защитой от формул электронных таблиц.
- Подтверждённые оценки аудита остаются неизменяемыми; результат внедрения фиксируется следующим аудитом.
- Схема базы данных не менялась.

# v0.33.2 — Интеграция действий внедрения с Канбаном

- Канбан использует те же записи действий внедрения и не создаёт дублирующее хранилище.
- Добавлены фильтры по аудиту, ответственному, приоритету, доказательству и срокам.
- Карточки дополнены происхождением из аудита/критерия, разрывом, доказательством и чек-листом.
- Добавлено управляемое изменение ответственного, срока и приоритета с проверкой company scope.
- Добавлены уведомления и отдельные события истории `kanban`.
- Переходы статусов сохранены в lifecycle/evidence workflow без произвольного drag-and-drop.
- В историю задач аддитивно добавлены `eventtype` и `detailsjson`.

# v0.33.1 — Управление доказательствами внедрения

- Добавлены неизменяемые версии доказательств для действий внедрения.
- Добавлены отправка, независимая проверка, возврат на доработку и повторная отправка.
- Добавлены файлы, внешняя ссылка, история версий и журнал решений.
- Принятие завершает задачу; возврат переводит её обратно в работу.
- Старые доказательства сохранены в read-only legacy-режиме.
- Добавлены две аддитивные таблицы, privacy support, cleanup и Health Center checks.

# v0.32.3 — Индивидуальный план развития сотрудника

- Добавлены общая цель ИПР, ответственный за план и дата контрольного пересмотра.
- Добавлены цели действий, практические задания, критерии доказательства, наставники и контрольные точки.
- Добавлены отправка доказательства, возврат на доработку и явное принятие результата другим пользователем.
- Добавлен статус «Ожидает проверки» и запрет ручного завершения действий с обязательным доказательством.
- Добавлена неизменяемая история изменений ИПР.
- Расширены напоминания, privacy provider, удаление компании/пользователя и Health Center.
- Добавлена аддитивная миграция схемы и backfill существующих планов.

# v0.32.1 — История оценок компетенций сотрудника

- Добавлен единый журнал оценок сотрудника по всем видимым компетенциям компании.
- Добавлены фильтры по источнику, статусу, периоду, динамике и составу профиля.
- Добавлена first-to-latest динамика подтверждённых уровней и CSV-экспорт.
- Pending, rejected и missing остаются видимыми, но не изменяют динамику.
- Схема базы данных не менялась.

# v0.31.6 — Предпросмотр учебного плана по разрывам компетенций

- Добавлен выбор подтверждённых разрывов перед формированием учебного плана.
- Неоценённые и ожидающие подтверждения компетенции видимы, но не включаются автоматически.
- Добавлены этапы 1/2/3 и рекомендуемые сроки 30/60/90 дней.
- Добавлены предупреждения об обязательных компетенциях без курса.
- Сохранены существующие сроки, курсы и пункты вне текущего перечня разрывов.
- Схема базы данных не менялась.

# v0.31.5 — Employee GAP Calculation

- Added a dedicated employee GAP report based on assigned business roles.
- Kept confirmed Moodle competency grades as the authoritative current level.
- Separated mandatory, supporting, critical, unassessed and pending-confirmation requirements.
- Added company-scoped course coverage, filters and next-action links.
- No database schema or destructive data changes.

# v0.31.2.2 — Selective Role Profile Migration

- Replaced the exposed all-domain BMS-Dxx expansion workflow with selective child competency migration.
- Per-competency target level, priority and mandatory settings.
- Existing concrete requirements are preserved.
- Explicit, guarded domain removal.
- No database schema changes.

## 0.31.2.1 — Role Profile & GAP Matrix Integrity — 2026-09-06
- Blocked BMS-Dxx grouping nodes as terminal business-role requirements.
- Added explicit role-profile integrity preview and safe domain expansion into direct child competencies.
- Excluded legacy BMS-Dxx requirements from GAP, matrix and Moodle Learning Plan role-template synchronisation.
- Prevented domain nodes from propagating through global role templates.
- Fixed missing `Competency` and `Preview` UI strings.

## 0.31.2 — Automatic GAP Learning Plan Builder — 2026-09-06
- Added Preview → Apply generation of KBA development plans from current role competency GAPs.
- Synchronised resulting competency membership into standard Moodle Learning Plans.
- Kept plan refresh non-destructive and idempotent.


## 0.31.1 — Moodle Learning Plan Integration
- Standard Moodle Learning Plans/Templates are now the primary plan/template competency layer.
- Added non-destructive links from KBA business roles and development plans to Moodle core.
- Added company migration/synchronisation UI and standard Moodle plan links.
- KBA retains GAP, target level, priority, courses, BMS and Odoo extension metadata.
# CHANGELOG

## 0.28.0.1 — BMS competency index hotfix — 2026-09-04

- Removed the duplicate `competency_ix` definition that collided with the `competency_fk` index in Moodle XMLDB.
- Kept the composite unique criterion/competency index and all v0.28.0 functionality unchanged.
- Added a no-schema savepoint so failed v0.28.0 upgrades can retry the corrected table creation safely.

## 0.28.0 — BMS tools and competency links — 2026-09-04

- Added criterion-to-competency mappings with target level, priority and required state.
- Added a searchable Moodle competency selector inside the BMS tool editor workflow.
- Added linked-competency badges to tool criteria and immutable competency details to audit items.
- Included mappings in tool version comparison, publication hashes and new audit snapshots.
- Displayed version-pinned competency requirements on implementation audits and plans.

## 0.27.6.1 — Responsive workspace menu hotfix — 2026-09-04

- Removed the desktop horizontal scrollbar from the shared workspace menu.
- Added a three-column desktop grid with wrapped BMS implementation actions.
- Moved Switch company into the Company group and suppressed it on the dashboard with its own selector.
- Added medium-screen and phone-specific stacking rules.

## 0.27.6 — Role-aware workspace navigation — 2026-09-04

- Rebuilt the shared workspace menu into Personal, Company and BMS implementation groups.
- Placed student and corporate-manager actions before curator and administrator controls.
- Added exact task-owner, corporate-manager, curator and implementation-manager visibility checks.
- Added the shared menu to competency, corporate dashboard, request, plan, task, audit and BMS tool pages.
- Added a permanent Curator students shortcut for assigned curators.
- Reordered permanent header and navigation-drawer entries by role progression.

## 0.27.5 — Optimized tariff, request and plan flow — 2026-09-04

- Consolidated package selection and request tracking into one commercial-stage panel.
- Corrected the “Package and request” cycle step to open the package/request workflow.
- Restricted the plan route to paid programs in an eligible delivery status.
- Redirected unavailable plan URLs to package selection, request status or administrator request management.
- Removed duplicate plan buttons and changed program cards to context-aware request, package or task actions.

## 0.27.4 — Unified BMS implementation navigation — 2026-09-04

- Replaced scattered implementation links with a single workspace navigation panel.
- Added active-section highlighting for clients, audits, tools, students, task board and effectiveness.
- Added company-aware links to audits, BMS tools, company switcher and corporate dashboard.
- Reused the menu across the implementation home, curator workspace, task board, analytics, audits and tool library.
- Added a responsive horizontally scrollable layout for narrow screens.

## 0.27.3 — BMS tool versioning and safe updates — 2026-09-04

- Added immutable published versions for BMS tools and their criteria.
- Added a working-draft flag after tool or criterion changes.
- Added version comparison, required publication notes, publisher, timestamp and SHA-256 content hash.
- Pinned each new implementation audit to the current published version snapshot.
- Preserved existing audits and archived versions when subsequent versions are published.
- Added visible version and draft badges to the tool library, tool page and audit pages.
- Added PHPUnit coverage for pre-publication and post-publication audit snapshots.

## 0.27.2 — BMS tool library and editor — 2026-09-04

- Rebuilt the company BMS tool library as a searchable, filterable card catalogue.
- Added explicit global-template and company-tool scope with read-only protection for global templates.
- Added tool metrics for active criteria, sections, current-company audits, scale and status.
- Extended the tool editor with scale maximum, attention threshold and BMS Index calculation method.
- Extended the criterion editor with section code/name and minimum/maximum maturity descriptions.
- Added duplicate-code, threshold and positive-weight validation.
- Locked the stable tool code after the first audit while keeping future-audit settings editable.
- Added direct creation of an audit with the selected tool prefilled.

## 0.27.1.1 — Audit tool selector hotfix — 2026-09-04

- Restored the BMS tool selector on the implementation-audit creation form.
- Added company-owned and global active tools to the selectable list while excluding tools owned by other companies.
- Automatically installs the bundled Point A template when a company has no available audit tool.
- Added a visible placeholder and server-side validation instead of rendering an empty collapsed selector.

## 0.27.1 — Effectiveness drill-down and dynamics — 2026-09-04

- Made effectiveness KPI cards and company comparison counts open the matching Kanban task selection.
- Added Kanban filters by workflow status, overdue scope, company, curator and reporting period.
- Added a 12-month trend for created tasks, completed tasks and average completion cycle.
- Added the change between the two latest confirmed BMS Index measurements for each company.
- Extended the filtered CSV with BMS Index change and monthly dynamics.

## 0.27.0 — Implementation effectiveness analytics — 2026-09-04

- Added a scoped management dashboard for BMS implementation task effectiveness.
- Added filters by company, curator and 30/90/365-day period plus task/company/curator search.
- Added delivery, review, overdue, average-cycle and checklist-completion indicators.
- Added company/curator comparison rows with the latest confirmed BMS Index.
- Added formula-safe semicolon CSV export that respects the same access scope and filters.
- Added direct navigation from BMS implementation, curator workspace and task board.

## 0.26.4 — Implementation task checklists — 2026-09-04

- Added shared checklist items inside each BMS implementation task.
- Allowed authorised task participants to add items and mark them complete; deletion remains limited to the item author, curator, or authorised manager.
- Added checklist progress to the task workspace, curator Kanban cards, and employee task cards.
- Locked checklist mutations after a task is completed or cancelled.
- Added Moodle privacy metadata, export, anonymisation, and company-deletion handling for checklist actors and content.
- Corrected remaining Moodle 4.0 task-filter links that passed CSS classes as strings instead of attribute arrays.

## 0.26.3.1 — Implementation task board hotfix — 2026-09-04

- Fixed the Kanban and personal task-title links for Moodle 4.0: `html_writer::link()` now receives an attribute array.
- Prevented the runtime exception shown when a task card is rendered.

## 0.26.3 — Implementation task board — 2026-09-04

- Added a responsive five-stage Kanban-style task board for curators and implementation administrators.
- Added task cards with company, employee, priority, deadline, overdue state and unread-message count.
- Added server-side search plus company and curator filters.
- Added direct board links from the curator workspace and BMS implementation area.
- Kept all status changes inside the controlled task page instead of allowing unsafe drag-and-drop transitions.

## 0.26.2 — Deadline control and reminders — 2026-09-04

- Added a daily scheduled deadline check for active BMS implementation tasks.
- Added one advance reminder to the task owner and one overdue escalation to the owner, curator and authorised managers.
- Added duplicate-delivery protection tied to the task deadline and reminder type.
- Added configurable enablement and a 1–30 day reminder window.
- Added linked “Upcoming deadlines” and “Overdue” queues to “My BMS tasks”.

## 0.26.1 — Task results and evidence — 2026-09-04

- Added employee submission of a required result description, an optional result URL and up to ten supporting files.
- Made submission move the task to review only after its result is stored.
- Added a result card visible to the assigned employee, curator and authorised manager.
- Extended task-file delivery to authorised task participants while preserving company boundaries.
- Added the result URL to privacy metadata and user-data export.

## 0.26.0 — Managed task lifecycle — 2026-09-04

- Added controlled task transitions: planned → in progress → review → completed or returned for revision.
- Added “My BMS tasks” for assigned employees with status filters, deadlines and overdue indicators.
- Added a permanent status history and decision comments to every implementation task.
- Added Moodle bell/email notifications for status changes with direct task links.
- Added privacy metadata and deletion handling for task transition records.

## 0.25.3.1 — Live implementation company search — 2026-09-04

- Added selectable company suggestions with implementation-state labels.
- Added immediate filtering of visible client cards.
- Added automatic full-register lookup when an exact company suggestion is selected.

## 0.25.3 — Live curator student search — 2026-09-04

- Added selectable student suggestions with company labels.
- Added immediate client-side filtering by student, company, task and message preview.
- Added a live empty-result state while preserving server-side combined filtering.

## 0.25.2.2 — Task links, dates and message cleanup — 2026-09-04

- Moved the task link to the task title and removed the redundant button.
- Standardised task and discussion timestamps.
- Added repeated entity decoding for legacy editor messages and clean plain-text previews.

## 0.25.2.1 — Curator counters and rich-text hotfix — 2026-09-04

- Fixed the linked summary-card attribute type error.
- Forced editor messages to use HTML format.
- Added backward-compatible rendering for editor HTML previously marked as plain text.

## 0.25.2 — Curator task controls and rich discussion — 2026-09-04

- Added an explicit task-opening action to each curator student row.
- Made summary and status counters navigate to filtered task queues.
- Replaced the plain discussion textarea with the standard Moodle rich-text editor.
- Added stored message format while preserving existing plain-text discussion records.

## 0.25.1.1 — Curator navigation and notifications — 2026-09-04

- Made the curator workspace visible to both assigned curators and implementation administrators.
- Added a direct workspace link inside the implementation registry and company implementation page.
- Added administrator overview mode for all assigned curators.
- Enriched Moodle bell notifications with company and student context.

## 0.25.1 — Curator workspace — 2026-09-04

- Added a Bitrix24-style list of assigned students, tasks and recent messages.
- Added scoped curator access to task discussions across assigned programmes.
- Added per-user read state, unread counters, search, status filtering and overdue summary.

## 0.25.0 — Implementation task workspace — 2026-09-04

- Added a stable direct-link detail page for every implementation task.
- Added a chronological, non-graded task discussion between the assigned employee and managers.
- Added participant notifications with direct links to the task.
- Linked task names in the register and returned to the task page after editing.
- Added privacy metadata, export and deletion handling for discussion messages.

## 0.24.2 — Request approval workflow — 2026-09-04

- Added a customer-facing administrator response to implementation requests.
- Recorded who changed request statuses and when.
- Notified the requester when commercial or delivery status changes.
- Displayed the KBA response and last status update in the customer request card.

## 0.24.1 — Implementation request notifications — 2026-09-04

- Added a Moodle message provider for BMS implementation requests.
- Sent an immediate confirmation to the requesting customer.
- Alerted authorised implementation administrators about each new request.
- Added the request submission date to the customer-facing request card.
- Kept notification delivery failures isolated from request persistence.

## 0.24.0.1 — Active client context — 2026-09-04

- Added a prominent active-client banner to the BMS implementation workspace.
- Reused the banner across request, audit, assessment, plan, task, economic and program-management pages.
- Kept the existing company switcher and dashboard navigation unchanged.

## 0.24.0 — BMS implementation request card — 2026-09-04

- Added a dedicated confirmation form between package selection and program creation.
- Stored request contact name, email, phone and customer comment with the implementation program.
- Added the request details to the customer workspace and administrator program editor.
- Added request contacts to the administrator CSV export.
- Enriched automatically created Bitrix24 deals with customer request details.

## 0.21.3.5 — Assessment form clarity — 2026-09-03

- Fixed the unresolved `[[comment]]` label on BMS criterion assessment pages.
- Replaced evidence jargon with clear supporting-document and verification labels.
- Added guidance for maturity scoring and clarified that a file or external link is sufficient.

## 0.21.3.4 — Company profile details — 2026-09-03

- Added the company primary business activity (niche) and founding year.
- Displayed the profile details in the company dashboard and company register.
- Added both fields to the stable ERP company push/snapshot contract.

## 0.21.3.3 — Economic audit indicators — 2026-09-03

- Added a verified company economic snapshot to every editable BMS implementation audit.
- Added revenue, margin profit, operating profit, net profit, tax breakdown, payroll and workforce indicators.
- Added automatic profitability, tax burden, payroll share and per-employee calculations.
- Added supporting files and Point A / Point B comparison with the preceding confirmed snapshot.
- Kept economic performance separate from the BMS Maturity Index.

## 0.21.3.2 — Evidence form runtime fix — 2026-09-03

- Explicitly loaded Moodle filelib on criterion-assessment and implementation-task evidence pages.
- Fixed the runtime exception raised by `file_get_submitted_draft_itemid()`.

## 0.21.3.1 — Corporate dashboard audit navigation — 2026-09-03

- Moved BMS implementation audits and competency audits to positions one and two.
- Restored the KBA green visual identity for the company hero and neutral dashboard cards.
- Added stronger green emphasis to both audit entry points.

## 0.21.3 — Managed implementation plan — 2026-09-03

- Converted audit gaps and development routes into persistent implementation tasks.
- Added owners, dates, workflow statuses, expected/actual results and evidence files.
- Added status, owner and overdue filters with safe company lifecycle handling.

## 0.21.2 — Gaps and implementation routes — 2026-09-02

- Added target scores and immutable audit target snapshots.
- Added many-to-many development routes to BMS tools and company Moodle courses.
- Added an automatic implementation plan and prior-audit comparison.
- Backfilled Point A targets to level 7.

## 0.21.1 — Point A audit template — 2026-09-02
- Added one-click installation of the `BMS-AUD-CHK-001` Point A template per company.
- Imported 15 BMS areas and 137 criteria from the approved workbook without demo answers.
- Added configurable audit scales, area snapshots and 0/maximum endpoint descriptions.
- Added `Needs data` and `Not applicable` assessment states.
- Added equal-area Point A calculation, assessment coverage and below-7 attention counts.
- Preserved the existing 0–5 verified-evidence calculation for ordinary BMS tools.

## 0.21.0 — BMS implementation audits and BMS Index — 2026-09-02
- Added a company-scoped BMS management-tool library and weighted implementation criteria.
- Added baseline, progress, final and monitoring audits with evidence files and optional links.
- Added maturity scoring 0–5, Evidence Coverage and a weighted BMS Index.
- Required criteria must have a score and verified evidence before confirmation.
- Confirmed audits are immutable official index snapshots.
- Added a dedicated corporate-dashboard card without mixing tool audits with employee competency audits.

## 0.20.0.1 — Corporate dashboard navigation cleanup — 2026-09-02
- Removed the redundant `Мои компетенции` card from the corporate customer dashboard.
- Preserved the standalone employee competency page and permanent navbar link.
- No database schema or Public API changes.

## 0.20.0 — Stable Odoo/BMS ERP exchange API — 2026-09-02

- Added the disabled-by-default `KBA Corporate ERP API v1` Moodle External Service.
- Added idempotent provider-neutral pushes for companies, employees and business-role assignments.
- Added paged company snapshots with roles, departments, competency levels and development-plan progress.
- Added explicit data ownership, pagination, payload and privacy rules in the machine-readable contract.
- Added a dedicated service-account capability with no automatic archetype grants.
- Preserved all Public API 1.x and Bitrix24/KBA Connect methods; additive API release is now 1.16.
- Prevented ERP events from removing pre-existing manual business-role assignments.

## 0.19.3.8 — Role-aware competency navigation — 2026-09-02

- Fixed the missing return navigation on employee competency profiles.
- Corporate managers return to the selected company dashboard.
- Employees without corporate-dashboard access return to their personal Moodle dashboard.
- Added a safe fallback when no single company context is selected.

## 0.19.3.7 — Consistent company navigation — 2026-09-02

- Added one reusable, capability-aware company-dashboard navigation component.
- Added `← Back to dashboard` to members, courses, departments, competency, audit, role-template and license screens.
- Preserved the selected company ID in every dashboard return URL.
- Kept existing parent-page links on detail and edit screens.
- Left global administration, integration, health and version pages unchanged because they have no single company context.

## 0.19.3.6 — Business role navigation — 2026-09-02

- Added navigation above and below the business-role catalog.
- Added return to the selected company's corporate dashboard.
- Added a competency-map shortcut when the viewer has competency access.
- Preserved company context and capability checks in every link.
- Confined the wide role catalog to a local responsive scroll container.

## 0.19.3.5 — Company directory filters — 2026-09-02

- Added exact company selection to the company administration directory.
- Added active/inactive status filtering.
- Added combined search by company name, code and Bitrix24 ID.
- Added result counts, filter reset and filtered empty state.
- Confined the wide company table to a responsive local scroll container.

## 0.19.3.4 — Development plans UI — 2026-09-02

- Redesigned the IPR list with a dedicated hero, company context and creation panel.
- Added total, draft, pending, active and completed plan summary cards.
- Added semantic plan-status badges and responsive table containment.
- Corrected the broken `[[progress]]` table heading.
- Restricted listed plans and summary counts to employees visible to the current manager.
- Added corporate-dashboard return navigation above and below the list.

## 0.19.3.3 — Development plan navigation — 2026-09-02

- Added return navigation above and below every individual development plan.
- Managers return to the company IPR list; employees return to their competency profile.
- Added a corporate dashboard shortcut when the viewer has dashboard access.
- Preserved company context in every navigation link.

## 0.19.3.2 — Development effectiveness UI — 2026-09-02

- Added a dedicated employee-development hero and removed the duplicated company label.
- Combined department and report controls into one visual filter panel.
- Added semantic colour accents to all eight effectiveness KPIs.
- Grouped role, department and before/after analytics into distinct report sections.
- Confined wide tables to their own responsive containers to prevent page-level horizontal scrolling.

## 0.19.3.1 — Development dashboard navigation — 2026-09-02

- Added employee development effectiveness directly to the corporate dashboard.
- The card respects company, department and employee visibility scope.
- Added distinct restrained colour accents for competencies, role profiles, audits, IPR and effectiveness.
- Preserved the existing three-column responsive dashboard layout.

## 0.19.3 — Before and after development dynamics — 2026-09-02

- Added monthly before/after dynamics for confirmed follow-up competency audits.
- Uses the immutable development-plan baseline rather than today's Moodle level.
- Added 3, 6, 12 month and all-time period filters.
- Separates newly assessed competencies from comparable before/after pairs.
- Added average before, average after, growth, improvement and target-reached metrics to the page and CSV.

## 0.19.2 — Role and department development analysis — 2026-09-02

- Added development-effectiveness breakdowns by current business role and department.
- Each group shows plans, active/completed counts, progress, overdue actions and confirmed outcomes.
- Explicitly labels employees without a role or department.
- Includes both breakdowns in the filtered CSV export.

## 0.19.1 — Development analytics filters and export — 2026-09-02

- Added department, plan-status and employee/title search filters to development effectiveness.
- Recalculates all summary metrics from the filtered plan set.
- Added a scoped CSV export protected by the report-export capability.
- Applied spreadsheet-formula injection protection to exported text.

## 0.19.0 — Development effectiveness analytics — 2026-09-02

- Added a company-level development-effectiveness page linked from IPR management.
- Shows plan states, action completion, overdue actions and follow-up audit coverage.
- Aggregates confirmed level improvements, reached role targets and assessment coverage.
- Applies existing company and department-manager employee visibility rules.

## 0.18.9 — Measured development outcomes — 2026-09-02

- Added a measured outcome section to completed individual development plans.
- Compares the level captured before the plan with the current authoritative Moodle level.
- Shows assessment coverage, improved levels, reached targets and remaining confirmed gaps.
- Keeps unassessed competencies separate and never treats course completion as a level increase.

## 0.18.8 — Post-development competency audit — 2026-09-02

- Added one-click follow-up audit creation from a completed individual development plan.
- Limits reassessment to competencies actually included in that plan.
- Reuses Moodle competency levels, evidence and corporate course competency results.
- Links each plan to one audit and reopens the existing audit instead of creating duplicates.
- Deleting the audit safely clears the plan link without changing confirmed Moodle levels.

## 0.18.7 — Development plan reminders — 2026-09-02

- Added daily Moodle notifications for active IPR actions due within seven days or overdue.
- Sends one plan digest to the employee and only authorised company or department managers.
- Added a per-plan daily delivery guard to prevent duplicate reminders.
- Added a direct plan link and deadline list to popup and email notifications.

## 0.18.6 — Course completion synchronisation — 2026-09-02

- Added hourly synchronisation of active development-plan actions with Moodle course completion.
- Added an on-demand manager action for synchronising one active plan.
- Preserved Moodle's recorded completion time in the development action.
- Automatically completes the plan when its final outstanding action is closed.
- Course completion still does not promote a competency level without confirmed assessment evidence.

## 0.18.5 — Development plan monitoring — 2026-09-02

- Added plan progress cards for completed, in-progress and overdue actions.
- Added a visual progress bar and a 14-day upcoming-deadline indicator.
- Marked overdue actions directly in the plan and included overdue counts in the company plan list.
- Kept completed actions out of overdue totals and added deterministic regression coverage.

## 0.18.4.1 — Development plan upgrade hotfix — 2026-09-02

- Removed the unnecessary `status` default DDL change that conflicts with existing status indexes.
- Partial v0.18.4 upgrades resume safely because every new workflow field is checked before creation.
- New plans still explicitly store the `draft` status; existing active plans remain unchanged.

## 0.18.4 — Development plan agreement — 2026-09-02

- New plans start as editable drafts.
- Managers submit drafts to the employee for agreement.
- Employees can accept a plan or return it for changes with a required comment.
- Accepted plans become active; existing active plans remain compatible.
- Managers can cancel a plan or return pending, active, completed or cancelled plans to draft.
- Privacy metadata, user discovery and anonymisation cover the new workflow fields.

## 0.18.3 — Managed development plans — 2026-09-02

- Managers can edit the plan title and target date.
- Managers can change each action’s company course, due date and status.
- Missing business-role competencies can be added manually to the plan.
- Plan actions can be removed with confirmation without altering Moodle evidence.
- Employees retain the limited ability to update their own action status.

## 0.18.2.3 — Competency map search — 2026-09-02

- Added a visible “Search” label to the competency-map text search.
- Widened the field so its employee/competency/role/department hint is readable.
- Renamed the submit action from “Apply” to “Find”.
- Removed desktop horizontal scrolling from the competency map by using a fixed, wrapping column layout.

## 0.18.2.2 — Competency map filters — 2026-09-02

- Renamed the gap status to “Confirmed gap” and kept unassessed requirements separate.
- Added automatic application for role, framework and status selectors.
- Added active-filter feedback and a reset action.
- Added regression coverage for gap, unassessed and attention filtering.

## 0.18.2.1 — Upgrade-order hotfix — 2026-09-02

- Removed premature `assign_capability()` for the newly introduced development-plan capability.
- Added build `2026090214` so failed and partial v0.18.2 upgrades can resume safely.

## 0.18.2 — Individual Development Plans — 2026-09-02

- Added stored employee development plans and competency development actions.
- Added automatic generation from unassessed role requirements and confirmed gaps.
- Added first available company course recommendation and a default 90-day target date.
- Added employee self-view and manager workflow statuses.
- Added company/member lifecycle cleanup, Privacy metadata/export/delete coverage and Health Center checks.

## 0.18.1 — Competency Map Analytics — 2026-09-02

- Added assessment-coverage and target-readiness indicators.
- Added server-rendered pagination with 50 target requirements per page.
- Added a filtered CSV export protected by both competency-view and report-export capabilities.
- Applied spreadsheet-formula injection protection to exported text fields.

## 0.18.0.2 — Unified Page Headings — 2026-09-02

- Removed the duplicate Moodle context heading from every Corporate Dashboard page.
- Preserved each page's own contextual heading, company name and section subheadings.
- Added an explicit primary title to Health Center, the only page that previously relied solely on Moodle's context heading.

## 0.18.0.1 — Competency Map UI Hotfix — 2026-09-02

- Fixed the untranslated `[[competency]]` table heading.
- Added safe horizontal scrolling for the wide competency matrix.
- Made employee-profile and audit actions compact and readable.

## 0.18.0 — Company Competency Map — 2026-09-02

- Added the company-scoped employee × competency management map.
- Reused the canonical My Competencies calculation for current level, role target and gap.
- Added company, department, business-role, framework, status and text filters.
- Added links to the employee competency profile and competency audit creation.
- Kept “not assessed” distinct from an explicit level 0.
- Enforced company and department-manager visibility rules.

## 0.17.9 — Template Version History — 2026-09-02

- Added immutable role and competency-profile snapshots for global template versions.
- Added version author, creation date and optional change comment.
- Added a version-history page with added, changed and removed competency comparison.
- Added a company-role update preview showing additions, template changes, protected manual overrides and retained requirements.
- Added migration backfill, Privacy API coverage, Health Center checks and regression tests.

## 0.17.8 — Cross-company Template Rollout — 2026-09-02

- Added a site-administrator view of every company role derived from one global template.
- Shows company, applied version, available version, role state and update requirement.
- Allows an individually confirmed non-destructive update for one company role.
- Added regression coverage for cross-company rollout status.

## 0.17.7 — Template Update Control — 2026-09-02

- Added applied-version and update-status columns to the role-template library.
- Added a direct confirmed update action for outdated company roles.
- Preserved manual competency overrides, company-only requirements and employee assignments.

## 0.17.6 — Company Role to Global Template — 2026-09-02

- Added one-click creation of a global template from an existing company role.
- Copied the complete competency profile while preserving the source role and assignments.
- Added duplicate global-code protection and regression coverage.

## 0.17.5 — Audit History Management — 2026-09-02

- Added audit-title editing with validation and company access checks.
- Added confirmed, transactional audit deletion including assessment and evidence children.
- Preserved competency levels already confirmed in Moodle when an audit record is deleted.

## 0.17.4.2 — Competency Management Navigation — 2026-09-02

- Added company-scoped links from My competencies to business roles, the template library and audits.
- Restricted each link by company access and the corresponding Moodle capability.

## 0.17.4.1 — Global Template Form Fix — 2026-09-02

- Preserved `scope=global` in the business-role form action and submitted form data.
- Returned cancelled global-template forms to the template library.

## 0.17.4 — Business Role Template Library — 2026-09-02

- Added a company-scoped template library page backed by centrally managed global roles.
- Added one-click creation of independent company roles with copied competency requirements.
- Added source-template, template-version and applied-version tracking.
- Added non-destructive template updates that preserve manual overrides and company-only requirements.
- Added template creation/editing for site administrators and update notices in company role profiles.
- Added regression coverage for cloning, idempotence, version updates and manual-override preservation.

## 0.17.3 — Automated Role-based Audit — 2026-09-02

- Added one-click audit composition from active business-role competency profiles.
- Snapshotted role targets, priorities, required flags and contributing roles per audit item.
- Collected current Moodle competency grades, core competency evidence and corporate course competency results.
- Added the explicit `missing` / **Needs assessment** state instead of treating absent evidence as level 0.
- Added structured audit evidence storage and bulk confirmation of preliminary proposals.
- Removed the primary manual Evidence URL input and extended lifecycle and Privacy API coverage.

## 0.17.2.1 — Business-role Query Hotfix — 2026-09-01

- Replaced the duplicated `:now` SQL placeholder with two Moodle 4.0-compatible named parameters.
- Fixed `Expected 4, got 3` when opening **My competencies** for an employee with active business roles.
- Added regression coverage for active user role lookup.

## 0.17.2 — Competency Audit — 2026-09-01

- Added company-scoped employee audit sessions and competency assessment history.
- Added preliminary self, test, interview, practical, expert and AI assessment methods.
- Added explicit confirmation/rejection; only confirmation grades through Moodle core Competency API.
- Integrated pending and confirmed audits with **My competencies**.
- Added Privacy API and lifecycle cleanup for audit data.
- Added capability `local/kbacorporate:managecompetencyaudits`; Public API remains 1.15.

## 0.17.1.2 — Business Role Management UI — 2026-09-01
- Added company-scoped business-role catalog and role editing.
- Added role competency profiles with target level, critical/core/additional priority and required marker.
- Added additive multi-role employee assignments and explicit single-assignment deactivation with retained history.
- Kept global role definitions read-only for corporate managers while allowing their use inside authorised companies.
- Added navigation from Moodle administration, company list, membership list, dashboard and employee card.
- Added server-side full-company scope checks and management-service regression tests.

## 0.17.1.1 — Moodle 4.0 XMLDB Hotfix — 2026-09-01
- Raised the plugin build to `2026090101` so Moodle can install the corrected full package over the failed `2026090100` attempt.
- Removed the redundant `competency_ix` index that collided with the `competency_fk` foreign key.
- Reduced external mapping `system` and `entitytype` tokens to 30 characters, keeping `external_uix` within the 333-character Moodle/MySQL limit.
- Kept the four-table migration idempotent so a partially completed upgrade can resume without uninstalling the plugin or deleting data.

## 0.17.1 — My Competencies Role Targets and Course Recommendations — 2026-09-01
- Enforced one-company context for business roles, target profiles and cross-user competency views.
- Fixed global role catalog scope and prevented company-role aggregation when `companyid=0`.
- Kept `competency_usercomp.grade` authoritative; course results are now provisional evidence only.
- Added `Not assessed`, `Outside target profile`, attention, latest-assessment and company-context states.
- Added company-scoped recommended courses with enrolment/completion/progress state.
- Kept own competency profiles available to every authenticated learner; cross-user views remain capability- and company-scoped.
- Read user-facing level names from the configured Moodle competency scale, with KBA labels only as a fallback.
- Excluded hidden or otherwise unavailable courses from learner recommendations.
- Reserved `local/kbacorporate:managerolecompetencies` for the later role-profile management UI.
- Removed the redundant `competency_ix` XMLDB index that collided with the `competency_fk` foreign key on Moodle 4.0 upgrades.
- Reduced external-system and entity-type tokens to 30 characters so the `external_uix` composite index stays within the Moodle/MySQL 333-character limit.
- Extended Public API to 1.15 with explicit-or-unique company resolution.
- Added business-role/external-mapping lifecycle cleanup, Privacy API coverage and metadata safeguards.
- Added PHPUnit coverage for role isolation, target aggregation, status semantics, recommendations, privacy and lifecycle behavior.

## 0.17.0 — Role Competency Profile Foundation — 2026-08-25
- Added separate KBA business-role domain (`BMS-ROLE-*`).
- Added role → competency → target-level requirements with priority and required flag.
- Added employee business-role assignments and universal external-system mapping.
- Added target-profile resolver with multi-role maximum-target aggregation.
- Added role-required competencies to `Мои компетенции` and `Требуется для роли` source chips.
- Extended public internal bridge API to 1.14.
- Added four database tables via tested in-place upgrade path.

## 0.16.10 — Documentation Baseline — 2026-08-24
- Normalized README, CHANGELOG and RELEASE_MANIFEST to the current 0.16.x line.
- Updated embedded AI Knowledge Base with 0.16.x architecture/development context.
- No functional or database changes.

## 0.16.9 — Course Filter, Search and Collapse — 2026-08-24
- Added course filter and competency search by code, name and description.
- Added per-card collapse/expand and global collapse/expand controls.
- Status filter preserves course and search context.

## 0.16.8 — Competency Status Filters — 2026-08-24
- Added quick filters for All, Confirmed, Developing, Not started and Needs confirmation.
- Added counts and filtered empty state.

## 0.16.7 — Russian Gap Label — 2026-08-24
- Replaced user-facing `GAP` with `Разрыв до цели`.

## 0.16.6 — Course Links and BMS UI Accents — 2026-08-24
- Added clickable source-course links to competency cards.
- Added BMS/KBA-aligned visual accents for summary cards, levels and source chips.

## 0.16.5 — Competency Evidence Progress — 2026-08-24
- Evidence now drives `В развитии` even before a final competency grade is set.
- Reads course-scoped competency results from `competency_usercompcourse`.
- User-facing evidence categories: Подтверждение обучения / Проверка / AI Оценка.
- Explicit proficiency remains required for confirmed competency.

## 0.16.4 — BMS Competency Profile UI — 2026-08-24
- Updated copy to `Ваш профиль компетенций BMS...` and `Профиль компетенций`.

## 0.16.3 — Course Competencies — 2026-08-24
- `Мои компетенции` now aggregates competencies from active enrolled courses.
- Learning Plans remain an optional additional source.
- Duplicate competencies are merged by competency ID.

## 0.16.2 — Moodle 4.0 DB Fix — 2026-08-24
- Corrected competency framework field usage to `competencyframeworkid` for Moodle 4.0 schema.

## 0.16.1 — Competency Navigation Fix — 2026-08-24
- Own competency profile no longer depends on a newly introduced capability.
- Added visible navigation entry for `Мои компетенции`.

## 0.16.0 — Competency Development Stage 1 — 2026-08-24
- Added learner-facing `Мои компетенции`.
- Added cross-user competency view for authorized corporate roles.
- Added current/target level, gap/status and evidence presentation.
- Uses Moodle core competency data as system of record.

## 0.14.6 — Employee Roles MERGE Public API — 2026-08-18
- Added Public API 1.13 method `merge_employee_roles()`.
- Added additive `department_manager` and `hr` business roles.
- Role synchronization is MERGE-only.

## 0.14.5 — Integration Health Center — 2026-08-18
- Added Health Center, history log, daily health task and Public API health status.

## 0.14.4 — KBA Connect Pairing — 2026-08-18
- Added secure one-time pairing and connection diagnostics.

## 0.14.3 — Bitrix24 Corporate Roles — 2026-08-18
- Added desired role schema provisioning through KBA Connect.

## 0.14.2.1 — Membership / BBB Hotfix — 2026-08-18
- Merged duplicate company memberships and fixed BBB group-log compatibility.
## 0.22.0 — Commercial BMS implementation programs — 2026-09-03

- Added customer requests and administrator activation for paid implementation programs.
- Added BMS Start, Support and Transformation packages and Bitrix24 deal reference.
- Kept audit results open while gating detailed plans and managed tasks.
## 0.22.1 — Editable packages and Bitrix24 sales funnel — 2026-09-03

- Replaced hard-coded packages with an editable commercial catalogue.
- Added package selection to customer program requests.
- Added configurable Bitrix24 pipeline/stage and automatic or manual deal creation.
## 0.22.2 — Bitrix24 pipeline, stage and owner selector — 2026-09-03

- Replaced manual pipeline and stage identifiers with live Bitrix24 selectors.
- Added selection of the responsible Bitrix24 user for implementation-program deals.
- Added safe preservation of saved selections when CRM reference loading fails.
## 0.22.2.1 — Bitrix24 REST array encoding fix — 2026-09-03

- Fixed nested REST parameter transfer through Moodle curl.
- Corrected stage lists, user filters and deal field submission.
## 0.22.2.2 — Bitrix24 responsible-person fallback — 2026-09-03

- Prevented a denied `user.get` call from blocking pipelines and stages.
- Added webhook-owner fallback and actionable permission guidance.
## 0.23.0 — BMS implementation customer workspace — 2026-09-03

- Added a dedicated card-based implementation section for corporate customers.
- Combined audit, BMS Index, request, active plan, tasks and follow-up audit navigation.
- Added commercial package cards and consistent return navigation.

## 0.23.1 — Direct BMS navigation and package selection — 2026-09-03

- Added «Внедрение BMS» to the permanent top navigation beside the competency profile.
- Added direct editable tariff cards to the implementation workspace.
- Kept tariff requests tenant-scoped and bound to the latest baseline audit.
- Replaced duplicate ordering with the selected tariff and current request/program status.

## 0.23.1.1 — Multi-company BMS navigation hotfix — 2026-09-03

- Kept the top-level “BMS implementation” shortcut visible for multi-company administrators.
- Added an explicit company chooser when the current page has no company context.
- Preserved direct opening when one company or a validated `companyid` is known.

## 0.23.2 — BMS client search and status filters — 2026-09-03

- Added server-side client-name search to the multi-company implementation chooser.
- Added workflow filters for missing audits, missing requests, existing programs and active programs.
- Added status badges and audit/program counters to company cards.
- Kept all aggregate queries restricted to companies visible to the current administrator.

## 0.23.3 — BMS implementation client worklist — 2026-09-04

- Added sorting and pagination to the multi-company implementation registry.
- Added latest audit, BMS Index, package, commercial stage, curator and activity metadata.
- Added state-aware actions for Point A, package offers and program management.
- Added curator assignment to implementation program administration.
- Added a tenant-scoped CSV export of the complete filtered client registry.

## 0.23.3.1 — Multi-company switching hotfix — 2026-09-04

- Stopped the permanent BMS shortcut from retaining the current `companyid` for multi-company administrators.
- Added an explicit return to the client registry inside each implementation workspace.
- Preserved direct navigation for users with exactly one available company.

## 0.30.2.1 — 2026-09-06
- Fixed misleading «Необходимо заполнить» badge for mandatory BMS criteria.
- Added dedicated RU/EN label «Обязательный критерий» / «Mandatory criterion».

## 0.30.3 — Mapping Cleanup & Integrity
- Added mapping integrity diagnostics and controlled BMS-Dxx cleanup.
- Added 137/137 working-mapping coverage indicator.
- Prevented BMS-Dxx domain nodes from being used as terminal criterion competencies.
- Added duplicate and target-level integrity checks.

## 0.31.0 — 2026-09-06
- Added consolidated Role ↔ Competency ↔ Learning Plan ↔ Course Matrix.
- Added role/employee filters, target/current/GAP view, company course coverage and learning-plan status.
- Reused existing role, Moodle competency, development-plan and course-competency domains; no schema migration.

## 0.31.2 — Automatic GAP Learning Plan Builder
- Added GAP preview → Apply workflow for Moodle Learning Plans.
- Added non-destructive draft refresh and idempotent plan/item handling.
- Protected pending/active plans from automatic membership changes.

## 0.31.2.3 — Role Profile Levels & Priorities
- Added bulk role-profile editor for target levels, priority and mandatory flags.
- Added mandatory/supporting/critical summary cards.
- Bulk update is non-destructive and preserves role competency membership.
- Global role templates increment version once per bulk save.

## 0.31.2.4 — 2026-09-06
- Fixed global business-role edit context collision between role `companyid=0` and return company navigation context.
- Added explicit `returncompanyid` contract for business_role_edit.php and its callers.

## 0.31.3 — Role Competency Course Coverage
- Added company-scoped role competency course coverage UI.
- Uses Moodle core course ↔ competency mappings as the authoritative relation.
- Adds mappings idempotently and does not remove existing relations.
- Exposes coverage metrics for all and mandatory role competencies.

## 0.31.4 — Course Coverage Integrity — 2026-09-08
- Added non-destructive integrity diagnostics for Role → Competency → Course coverage.
- Added separate mandatory/supporting uncovered competency lists.
- Added active company courses without Moodle competency relations.
- Added duplicate and out-of-catalogue mapping detection.
- Added role readiness status before GAP and Moodle Learning Plan generation.

## 0.31.5.1 — Russian UI terminology — 2026-09-08
- Replaced user-facing “Learning Plan” with «учебный план» in the Russian interface.
- Replaced user-facing “GAP” with «разрыв компетенций» / «разрыв до цели».
- Kept technical class names, URLs, database fields and API identifiers unchanged for backward compatibility.


## 0.31.7 — Создание учебного плана Moodle — 2026-09-08
- Added explicit final confirmation before creating or updating a study plan.
- Revalidated selected confirmed competency gaps on the server before writes.
- Provisioned Moodle role templates and the employee learning plan idempotently.
- Added owner and competency-composition verification with partial-success diagnostics.
- Kept course enrolment outside the learning-plan provisioning transaction.

## 0.31.8 — Прогресс учебного плана и повторная оценка — 2026-09-08
- Added a dedicated study-plan progress page with live Moodle course state.
- Added action, course, assessment and target-achievement metrics.
- Added overdue and reassessment diagnostics and filters.
- Added controlled course-completion synchronisation and reassessment handoff.
- Preserved the rule that course completion does not automatically grade a competency.

## 0.32.0 — Профиль компетенций сотрудника — 2026-09-08
- Added a company-scoped unified employee competency profile.
- Added assessment-source attribution and per-competency history.
- Combined role targets, confirmed Moodle levels, gaps, courses and study-plan context without duplicating grades.
- Added multi-company relevance filtering and read-only history de-duplication.
- No database schema changes.

## 0.32.2 — 2026-09-08

- Добавлен управляемый workflow повторной оценки после завершённого плана развития.
- Исходный уровень и цель фиксируются в versioned snapshot пункта аудита.
- Старые уровни/evidence до контрольной даты не подтверждаются как новый результат.
- Новые evidence могут создать только ожидающее подтверждения предложение.
- Добавлены сравнение до/после/цель, оставшийся разрыв, подтверждение/отклонение и следующий цикл развития.
- Схема БД не изменена; build `2026090807`.

## 0.32.2.1 — Исправление ссылки на шаблон учебного плана Moodle — 2026-09-08

- Добавлен обязательный `pagecontextid` в ссылку на `tool_lp/templatecompetencies.php`.
- Контекст определяется по записи шаблона Moodle, без жёсткого `pagecontextid=1`.
- Добавлены регрессионные тесты URL-контракта.
- Схема и данные не изменены; build `2026090808`.

## 0.33.0 — Критерий аудита → действие по внедрению — 2026-09-08

- Added preview-first bulk action creation from confirmed BMS audit gaps.
- Added owner, priority, expected-result and 30/60/90-day deadline defaults.
- Added direct criterion-level create/open action links in confirmed audits.
- Reused existing managed implementation tasks, lifecycle, evidence and discussion.
- Prevented duplicate or silent overwrite of existing actions.
- Kept unassessed and achieved criteria outside action generation.
- No database schema changes; build `2026090810`.

## 0.33.4 — Реестр экономических показателей — 2026-09-09

- Added a stable global/company economic indicator catalogue.
- Added periodic actual, plan and forecast values with manual, ERP, import and API sources.
- Added optional same-company BMS audit linkage and immutable before/after change history.
- Added filtered CSV export and spreadsheet formula-injection protection.
- Added additive database tables for definitions, values and history.
- Backfilled and continuously synchronised legacy audit economic snapshots without deleting them.
- Added company-scoped permissions, privacy coverage and an internal idempotent ERP-ready upsert contract.
