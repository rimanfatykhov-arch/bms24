# Current development checkpoint — 0.9.1 build 2026092600

BMS24 = Management Operating System. Current roadmap authority: BMS-MASTER-001.
0.8.12 management stabilization remains pending live Moodle acceptance; the development
track now proceeds into 0.9.x Integration Platform at the user's request.
0.9.0 first slice: existing external identity registry, human-confirmed company/position/indicator
mappings, tenant isolation, idempotency and close-history. No transport/actual sync yet.
0.9.1 first slice: Odoo adapter pull boundary over confirmed identities and Public API snapshot hashes; no credentials/remote transport/actual ingestion yet.
Next planned product release: 0.9.2 — CRM/provider extension; current KBA Connect must stay compatible. A small 0.9.1.x actual-ingestion slice may precede it only after a verified Odoo write/provenance contract is approved.
Production acceptance is not granted by advancing a development version.

## Historical roadmap below

## Completed UX/Core checkpoint — 0.4.6.4

Workspace Navigation Optimization completed before Meetings & Decisions: simplified sidebar, daily-work dashboard, Needs Attention and dedicated Business Architecture Center. This release changes presentation models only and does not advance the business-domain roadmap.

# KBA Business System Engineering Roadmap

Customer-facing name: **BMS — Business Management System**. Engineering package name remains KBA Business System.

North Star: **BMS is a system for designing, learning, implementing and continuously improving a business.**

KBA Theme is developed on a separate roadmap and provides presentation/App Shell only. BMS Core owns navigation meaning, routes, permissions, business objects and data. Theme integration will be governed by the separately maintained `BMS_UI_CONTRACT` and must not become a business dependency.

## Critical path

Semantic Core → OFS + CKP → Process Architecture → SIPOC + BPMN → STP + RACI →
Process → Function → Position → ISO Document Control → Position Operating System →
Strategy → Administrative Scale → Task / Communication → Meetings / Decisions → Administrative Cycle → Information Center.

Do not bypass this chain for decorative dashboards or AI features.

## Release sequence

- **0.2.8.0.1 — Core Stabilization** ✅: contract synchronization, centralized permissions, optimistic org-design apply, tests and release documentation.
- **0.2.9.0 — BMS Semantic Core** ✅: Product/CKP, Goal, OrgUnit, Position, Function, Process, Activity, Metric, Document reference and explicit relations.
- **0.2.9.1 — OFS + CKP** ✅: company/department/unit/function result hierarchy, internal customer/supplier and linked metrics/processes.
- **0.2.9.2 — Process Architecture** ✅: Management, Development, Core and Support process map with L0/L1/L2 hierarchy.
- **0.3.0 — SIPOC + BPMN Designer** ✅: process passport plus controlled BPMN subset; every activity has executor and output/product.
- **0.3.1 — STP + RACI** ✅: STP generated from the process model, responsibilities, resources, KPI, records and control points.
- **0.3.2 — Process → Function → Position** ✅: function/product/actions traceability and position assignment.
- **0.3.3 — ISO Document Control** ✅: controlled documents, Master List, revisions, approvals, acknowledgements, records/evidence.
- **0.3.4 — Position Operating System** ✅: generated job description, Position Work Program and Digital Job Folder.
- **0.3.5 — Market & Business Model** ✅: market, customer segments, value proposition, portfolio and business-model links.
- **0.4.0 — Ownership / Strategy / BSC** ✅: ownership intent, long-term goal, strategy, BSC and strategic map.
- **0.4.1 — Administrative Scale** ✅: Goal → Policy → Plan → Program → Project → Decision/Order → Task with pinned revisions and full traceability.
- **0.4.2 — Task Center & Battle Plan** ✅: canonical execution tasks plus weekly Change / Run / Operate / Develop / Unplanned projection.
- **0.4.2.1 / 0.4.2.2 — Communication prototypes**: development branches later rebased into the App Shell line; not the deployment source of truth.
- **0.4.3 — BMS App Shell Pilot** ✅: BMS-owned navigation model with optional KBA Theme App Shell and standard Moodle fallback.
- **0.4.4 — Renderer Compatibility Hotfix** ✅: broadened renderer compatibility.
- **0.4.5 — Output Bootstrap Compatibility Hotfix** ✅: renderer-agnostic UI service and pagelayout-before-`$OUTPUT` ordering.
- **0.4.6 — Unified Communication & Task Center** ✅: Communication Core + BMS/legacy/KBA Element Task Providers + Battle Plan provider references integrated on the App Shell source-of-truth.
- **0.4.6.1 — Migration Dependency Hotfix** ✅: safe retry of Battle Plan provider migration on Moodle 4.0 after XMLDB dependency error.
- **0.4.7 — Meetings & Decisions** ✅: agenda → data/context → discussion → formal decision → tasks → control.
- **0.4.8 — Administrative Cycle** ✅: Strategy/BSC → Goal → Policy → Plan → Program → Project → Meeting → Decision → Task → Battle Plan → Result with canonical Decision/Task projection.
- **0.4.9 — Recurring Tasks & Template Library** ✅: recurring definitions → scheduled canonical task occurrences, plus a 94-template methodology library copied as company-scoped drafts.
- **0.5.0 — Indicator Registry & Trees** ✅: one canonical indicator registry with strategy/organization projections, versioned passports, bindings and norms.
- **0.5.1 — Information Center & History** ✅: organization/strategy overview, plan/fact charts, 13/26/52-period history and business-context drill-down.
- **0.5.2 — Trends & Events — delivered**: schema-neutral trend read-model, direction-aware assessment and derived reversal/extrema/norm-transition events.
- **0.5.3 — Conditions → Actions — delivered**: binding-scoped condition read-model, explicit versioned interpretation policy and recommended management formulas; canonical Task remains the execution object.
- **0.5.4 — Condition confirmation → execution**: manager confirmation/context events → canonical Task → Battle Plan reference, without copying the indicator or task.
- **0.5.5 — Management Exceptions** ✅: schema-neutral attention queue over indicator analytics, manager decisions and canonical Task execution.
- **0.5.6 — Battle Plan Indicator Header** ✅: employee → position → position indicators → effective norms → previous/current fact → condition → trend, without copying Indicator or Task.
- **0.5.7 — Battle Plan Indicator Governance**: confirm/contextualize condition and create/link canonical management Task directly from the Battle Plan indicator row, reusing the v0.5.4 execution bridge.
- **0.6.0 — Financial Model**: scenarios and financial-responsibility centers linked to OFS.
- **0.6.1 — BDR / BDDS / Forecast Balance — implemented (build 2026091521)**: versioned planned P&L budget, four-week cash-flow budget and forecast balance. Payment-calendar execution remains deferred.
- **0.6.2 — Budgeting — implemented (build 2026091522)**: goal/program/project/org-unit budgets, limits, approval lifecycle and aggregate plan/fact snapshots.
- **0.6.3 — Resource Gate — implemented (build 2026091523)**: money, people, competencies, time, technology, information and infrastructure feasibility before launch.
- **0.6.4 — Platform Boundary & Company Isolation — implemented (build 2026092125)**: Company UUID boundary, Workspace projection and Entitlement foundation.
- **0.6.5 — BMS Public API Foundation — implemented (build 2026092126)**: stable provider-neutral read API for Cloud / Integration Hub / Odoo consumers.
- **0.6.6 — Provider-Neutral Outbox / Integration Contract — implemented (build 2026092127)**: durable canonical event outbox while preserving KBA Connect command compatibility.
- **0.7.0 — Competency Architecture**: Process → Function → Competency; behavioral indicators.
- **0.7.1 — Role / Position Competency Profile**: required competency levels by role/position.
- **0.7.2 — Employee Competency Profile**: required vs actual with client term «Зона развития».
- **0.7.3 — Assessment Engine**: evidence from self/manager/test/practice/Moodle/360/certification/mentor/AI/business result.
- **0.7.4 — 360 Assessment**: campaigns, respondent matrix, anonymity, weights, self-vs-others and results.
- **0.7.5 — Development Plan**: development zones → target level → activities → deadline.
- **0.7.6 — BMS Learning / Moodle — implemented (build 2026092314)**: learning activities and KBA Element integrated behind BMS UX; course completion ≠ competency achievement.
- **0.7.7 — Function Transfer & Authorization — implemented (build 2026092315)**: delegation, supervised practice, explicit authorization and recipient acceptance without automatic Moodle ACL or staffing mutation.
- **0.7.8 — Reassessment & Competency Progress**: longitudinal before → development → after comparison.
- **0.7.9 — Values / Culture / Motivation**: philosophy, values, cultural behaviors, motivation and responsibility.
- **0.8.0 — Implementation Management**: target vs actual and implementation projects/tracks.
- **0.8.1 — Impact Analysis**: changed object → affected objects/people/documents/training/deployment.
- **0.8.2 — Change Deployment**: publish → acknowledge → learn → practice → deploy → evidence → accept.
- **0.8.3 — Audit / CAPA**: audit → finding → nonconformity → root cause → corrective action → verification.
- **0.8.4 — Improvement Management**: idea/problem/deviation/feedback/risk → improvement case → change proposal → new version.
- **0.8.5 — BMS Maturity**: seven evidence-based maturity levels by BMS discipline.
- **0.8.6 — Company Health**: evidence-backed health/critical-zone view and growth priorities.
- **0.9.0 — Integration Platform**: provider-neutral company/employee/position/finance/KPI/CRM/ERP/learning contracts.
- **0.9.1 — ERP / Odoo**: operational actuals while BMS remains owner of target management model.
- **0.9.2 — CRM / Bitrix24**: leads/deals/contacts/payments/client and enrolment integration.
- **0.9.3 — Bitrix24 Tasks & Communications Provider**: optional external work/communication source in the unified BMS center.
- **0.9.4 — Multi-company / Holding**: legal entities, shared standards/templates, benchmarking and holding permissions.
- **0.9.5 — Leadership & Consultant Workspace**: owner, CEO, manager and KBA consultant workspaces/portfolio.
- **0.9.6 — BMS 1.0 Release Candidate**: hardening, security, performance, migration and full E2E acceptance.
- **1.0.0 — BMS**: Design → Learn → Implement → Improve → Redesign.

## Product milestones

1. Company → OFS → CKP → Process → SIPOC → BPMN → STP → Functions → Positions → Job Description → Work Program → Job Folder.
2. Strategic Goal → Administrative Scale → Task → Communication/Decision → Battle Plan → Product → Statistic → Trend → Condition → Management Decision.
3. Position → Competencies → Employee → Assessment/360 → Development Plan → Learning/KBA Element → Practice → Authorization → Reassessment.
4. Process change → Impact Analysis → new STP → affected people → acknowledgement/training → deployment → new trend → effectiveness validation.

- **0.4.6.7 — BMS Page Shell Adapter** ✅: internal BMS business pages share the same sidebar/topbar App Shell while retaining normal Moodle fallback.

- **0.4.6.8 — Business Architecture UX Model** ✅: grouped methodology navigator and ordered design flow from strategy/business model through OFS, processes, functions, positions, documents, metrics and improvements.

- **0.4.6.9 — Business Architecture Guided Workspace** ✅: selected design stage, evidence-based readiness, contextual actions and next recommended step; duplicated architecture overview removed.


## 0.4.6.10 — Legacy Administrative Cascade UI Retirement

The obsolete Administrative Cascade and its execution surface are removed from customer-facing BMS navigation and Business Architecture. The canonical management path is Administrative Scale → programs/projects/decisions/tasks → Battle Plan. Legacy cascade tables/services remain intact for backward compatibility, historical references and a future controlled migration; no business data is deleted.

## 0.4.6.11 — OFS Visual Designer

- universal BMS OFS visual form;
- versioned visual tiers inside organization design revisions;
- node/department product and company Valuable Final Product projection;
- editable poster view backed by the canonical design model;
- universal `bms_ofs` starter template;
- next: shared export engine for SVG/PDF/Draw.io, then normalized industry template library.


## 0.4.6.12 — OFS Export Engine

- one OFS visual layout shared by SVG / PDF / Draw.io;
- vector SVG download;
- PDF A0-A4 and Auto sizing from SVG;
- editable diagrams.net / Draw.io export;
- BMS UUID + revision metadata embedded in Draw.io;
- BMS remains source of truth;
- next: normalized industry OFS template library, then controlled legacy Draw.io template import.

- **0.4.6.13 Organization Template Library** — built-in normalized industry catalogue, preview and create-from-template flow. Original Drive source files remain unchanged.
- Next: controlled legacy template import/mapping and normalized library enrichment from the original Draw.io/PDF methodology archive.

- **0.4.6.14 Legacy Organization Template Import** ✅ — parse legacy `.drawio` copies into a full analysis graph, review semantic mapping, persist normalized template drafts with provenance, promote `DRAFT → REVIEWED → AVAILABLE`, then create independent OrgDesign drafts from the Template Library. Originals remain unchanged.
- **0.4.6.15 Template Normalization & Preview Enhancements** ✅ — real-source CKP/column heuristics, compound-block normalization guidance, review filters/search, readiness metrics and actual-tier imported-template preview.
- Next functional release: **0.4.7 Meetings & Decisions** — agenda → context/data → discussion → formal decision → tasks → control, without turning messages into decisions automatically.

### Completed: v0.4.7 — Meetings & Decisions

- participant-scoped meetings;
- agenda and expected products;
- meeting discussion context;
- governed decisions;
- accepted decision → canonical task;
- bidirectional traceability to Task Center.

Delivered after v0.5.3 condition analytics: **0.5.4 — Condition confirmation → canonical Task → Battle Plan**.

### Completed: v0.4.8 — Administrative Cycle

- customer-facing management chain replaces the old scale-first navigation;
- Goal/Policy/Plan/Program/Project remain revision-pinned planning objects;
- published Project → exact project-scoped Meeting;
- Meeting → canonical Decision → canonical Task;
- Task → Battle Plan reference → recorded Result;
- old Administrative Scale decision/task definitions and legacy Cascade data remain compatibility-only and are not destructively migrated.

Delivered functional release: **0.5.4 — Condition confirmation → canonical Task → Battle Plan**.

## Delivered in 0.4.10
- Private Owner LPF & ownership-goals workspace.
- Approved Owner requirements boundary (`REQ`) into corporate architecture.
- Full BMS Business Model Canvas 2.0 in Business Architecture.
- Revision pinning OwnerFrame → Business Model and archive-preserving delete.

Next business-architecture enhancement: formal GAP/change traceability from BMC into the existing Administrative Cycle / Improvement contour without auto-creating projects from every gap.

## 0.5.0 — Реестр и дерево показателей — реализовано

- Один канонический реестр показателей (`local_kbacompany_stat`) и единая append-only история план/факт.
- Дерево по стратегии: ССП → перспектива → стратегическая цель → показатель.
- Дерево по организации: ОФС → подразделение → должность → показатель.
- Версионный паспорт источника/методики показателя.
- Версионные связи показателя с объектами стратегии и организации.
- Версионные нормы/квоты с периодом действия.
- Карточка показателя с вводом данных, историей и графиком.
- 0.5.1 — Информационный центр и расширенная история/графики — реализовано.

## 0.5.1 — Информационный центр и история показателей — реализовано

- Организационные карточки по верхним подразделениям ОФС.
- Стратегический обзор по перспективам ССП.
- Последний факт, действующая норма, динамика и мини-график.
- Детальные графики план/факт на 13, 26 и 52 периода.
- Drill-down из показателя в должность, подразделение, стратегию и компанию.
- 0.5.3 — состояния показателей и рекомендуемые управленческие действия — реализовано.
- 0.5.4 — подтверждение состояния менеджером и перевод решения в каноническую задачу/Боевой план без копирования — реализовано.


## 0.5.4 — Подтверждение состояния → канонический Task → Боевой план — реализовано

- Автоматическое состояние остаётся read-model; вычисленный результат не сохраняется вторым фактом.
- Подтверждение/контекст руководителя фиксируется immutable governance event с точной ссылкой на показатель, binding revision и период.
- Контекстное состояние требует комментария и позволяет корректно фиксировать Несуществование/Смену Власти без статистического суррогата.
- Исполнение создаётся только как canonical Task с provenance на событие состояния.
- Боевой план получает ссылку на Task через существующий bplanitem; копия задачи не создаётся.
- История подтверждений не обходит ACL Task Center/Battle Plan.

## 0.5.5 — Management Exceptions — реализовано

- Одна строка очереди на текущую business-привязку канонического показателя.
- Причины внимания агрегируются из факта/нормы/тренда/событий/состояния/подтверждения/Task.
- Нет отдельной сущности Incident/Exception и нет записи аналитики в statval.
- Task ACL сохраняется; скрытая задача не раскрывается через экран показателей.
- Скрытых процентных/временных порогов нет.

## 0.5.6 — Показатели должности в Боевом плане — реализовано

- верх Боевого плана строится из канонических `stat/statbind/statnorm/statval/statpolicy/stateevt`;
- факт текущей недели дописывается в append-only `statval`;
- прошлый факт, норма, состояние и тренд рассчитываются на дату выбранного плана;
- связанная canonical Task отображается без обхода ACL;
- legacy набор квот остаётся compatibility-only.

## 0.5.7 — Управленческое действие из строки показателя Боевого плана — реализовано

- действие раскрывается прямо в ячейке состояния канонического показателя;
- сервер повторно строит исторический snapshot выбранной недели и не доверяет периоду из браузера;
- подтверждение/контекст фиксируется существующим immutable `stateevt`;
- исполнение создаётся только как canonical Task `sourcekind=condition`;
- исполнитель/проверяющий/наблюдатель берутся из самого Боевого плана;
- canonical Task автоматически добавляется ссылкой в тот же черновик Боевого плана;
- повторная идентичная отправка идемпотентна; удалённый Task не воскрешается;
- схема БД, права и external services не меняются.

## 0.5.8 — Контроль управленческого действия по показателю — реализовано

- строка показателя показывает статус связанной canonical Task и её результат без копирования исполнения;
- checklist-прогресс рассчитывается только из реальных пунктов `taskcheck`; если checklist отсутствует, процент не придумывается;
- в следующем недельном Боевом плане подхватывается управленческое решение ровно предыдущей недели;
- факты `до → после` сравниваются из append-only `statval` с учётом направления показателя;
- сравнение не выдаётся за доказательство причинности управленческого действия;
- отдельной таблицы action-status/effect нет, релиз schema-neutral.

Следующий функциональный релиз: **0.5.9 — Управленческий цикл по исключениям** — связать Management Exceptions с активным Боевым планом и существующей canonical Task так, чтобы руководитель из очереди исключений видел: где уже есть действие, что просрочено, что дало улучшение/ухудшение на следующей неделе и где требуется новое решение, без создания второго контура инцидентов.

## 0.5.9 — Управленческий цикл по исключениям — реализовано

- Management Exceptions связан с `stateevt -> canonical Task -> Battle Plan -> next-week statval`;
- одна строка исключения показывает текущий статус управленческого цикла без отдельного Incident/ActionStatus объекта;
- доступные Боевые планы текущей недели показываются для position-bound показателей;
- результат и checklist-прогресс задачи читаются только из canonical Task под её ACL;
- ухудшение или отсутствие изменения следующего факта требует нового решения только при отсутствии более свежего решения руководителя;
- релиз schema-neutral.

Следующий функциональный релиз: **0.5.10 — Управленческий обзор Боевых планов** — агрегировать по руководителю/подразделению активные Боевые планы, критические показатели, открытые/просроченные canonical Tasks и требующие решения исключения, сохраняя единственные источники истины.

## 0.5.10 — Управленческий обзор Боевых планов — реализовано

- активные Боевые планы выбранной недели агрегируются без нового summary-storage;
- фильтры по подразделению (`position -> orgnode`) и руководителю/проверяющему;
- сводки по подразделениям и руководителям;
- критические position-bound показатели и Management Exceptions выводятся рядом с планом;
- открытые/просроченные canonical Tasks считаются только под существующим Task ACL;
- закрытые планы не входят в оперативный обзор;
- релиз schema-neutral.

## 0.5.11 — Покрытие Боевых планов и управленческие пробелы — реализовано

- фактические назначения на выбранную неделю сопоставляются с одним Боевым планом на `employee + position + week`;
- историческое покрытие использует `effectivefrom/effectiveto`;
- выявляются назначения без Боевого плана;
- активные должности без критических position-bound показателей;
- критические привязки без действующей на неделю нормы;
- пробелы агрегируются по подразделениям;
- отдельный реестр контроля не создаётся; релиз schema-neutral.

Следующий функциональный релиз: **0.5.12 — Запуск управленческой недели** — из экрана покрытия явно и идемпотентно создавать недостающие Боевые планы для выбранных назначений, с выбором проверяющего/наблюдателя и без автоматического создания планов без команды руководителя.

## 0.5.12 — Запуск управленческой недели — реализовано

- из покрытия явно выбираются назначения без недельного Боевого плана;
- проверяющий обязателен, наблюдатель выбирается опционально;
- историческое назначение повторно проверяется по `effectivefrom/effectiveto`;
- повторный запуск идемпотентно использует существующий `employee + position + week` план;
- наличие назначения без команды руководителя не создаёт план автоматически;
- новый план создаётся только как canonical `bplan` в статусе `draft`;
- отдельного реестра запуска недели и новой схемы нет.

Следующий функциональный релиз: **0.5.13 — Готовность управленческой недели** — показать созданные черновики, отсутствующих проверяющих/обязательные настройки и готовность каждого плана к отправке/активации, используя существующий lifecycle Боевого плана без нового status-registry.

## 0.5.13 — Готовность управленческой недели — реализовано

- readiness вычисляется из существующего `bplan + bplanitem + reviewer + position indicators`;
- жёсткие блокеры полностью повторяют существующий lifecycle и не расширяют правила переходов;
- черновики различаются на blocked / ready-submit / ready-selfapprove;
- submitted-планы показываются как ready-activate при корректном reviewer;
- пробелы критических показателей и норм являются предупреждениями, а не новыми блокерами;
- readiness агрегируется по подразделениям без отдельного status-registry;
- релиз schema-neutral.

## 0.5.14 — Массовый переход управленческой недели — включено в 0.5.15

- из экрана готовности явно выбираются планы для submit/approve;
- каждый план проходит существующий `battle_plan_service::transition()` отдельно;
- прямого bulk UPDATE статусов и фоновой автоактивации нет;
- повторная отправка идемпотентно пропускает уже прошедшие переходы.

## 0.5.15 — Stabilization / Weekly Management Acceptance — реализовано

- единая schema-neutral контрольная точка недельного управленческого цикла перед финансовым контуром;
- hard gates: покрытие планами, readiness/lifecycle, отсутствие draft/submitted хвостов, duplicate weekly plan key и orphan canonical references;
- critical KPI/norm gaps, pending management decisions и overdue canonical Tasks выводятся как управленческое внимание, не как новая state machine;
- серверный Battle Plan transition выровнен с readiness: reviewer повторно валидируются при submit/approve/selfapprove;
- acceptance не хранится в БД и автоматически пересчитывается из канонических источников;
- после 0.5.15 следующий крупный функциональный контур — **0.6.0 Financial Model**.

**0.6.0 — Financial Model — реализовано (build 2026091520):** versioned сценарии и центры финансовой ответственности, связанные с canonical BMS Company/ОФС. Следующий функциональный релиз — **0.6.1 — БДР / БДДС / прогнозный баланс**.
## 0.5.16 — BMS Company & Employee Sync — реализовано

- новая Bitrix Company попадает в BMS только по явному признаку «Клиент BMS = Да»;
- Bitrix Company ID является стабильным внешним ключом, название не используется как identity;
- canonical Employee = Moodle User + исторический BMS membership;
- Bitrix Contact ID связывается с Moodle User через provider-neutral external mapping;
- ручное создание сотрудника в BMS создаёт durable outbox-команду для KBA Connect;
- завершение outbound employee sync допускается только после Bitrix Contact + KBA Corporate membership reconciliation;
- существующие `local_kbacorporate` members остаются видимыми через read-only compatibility projection;
- Corporate Learning roles не становятся автоматически BMS management roles;
- Deal → Product → Course → Enrolment не меняется;
- миграция additive, история и прежние company UUID сохраняются.

Financial Model foundation реализован в **0.6.0 build 2026091520**, БДР / БДДС / прогнозный баланс — в **0.6.1 build 2026091521**, Budgeting — в **0.6.2 build 2026091522**, Resource Gate — в **0.6.3 build 2026091523**. Следующий шаг — **0.7.0 — Competency Architecture**.


## v0.5.17 — Employee CRM UX — implemented (final build 2026091519)

CRM-style employee list, membership-aware employee record, canonical Moodle profile editing, durable outbound reconciliation and confirmation-only soft removal from company. No new Person registry, no physical person deletion, no KBA Corporate ownership leakage and no schema change. Financial Model foundation is delivered in v0.6.0 build 2026091520 and planned financial statements in v0.6.1 build 2026091521; next roadmap step is v0.6.2 Budgeting.


## v0.6.0 — Financial Model foundation — implemented (build 2026091520)

- canonical Financial Scenario: versioned assumptions, horizon, currency, optional published Strategy/Business Model revision references;
- canonical Financial Responsibility Center: versioned responsibility scope linked to Company / OFS org unit / Position / Product, with optional responsible Position and Indicator;
- no duplicate organizational tree and no accounting/ERP fact copy;
- no BDR, BDDS, forecast balance or budget-line storage in this release;
- delivered next in **v0.6.1 — BDR / BDDS / Forecast Balance**; following step: **v0.6.2 — Budgeting**.


## v0.6.1 — BDR / BDDS / Forecast Balance — implemented (build 2026091521)

- versioned planned BDR pinned to published Financial Scenario / optional responsibility centre;
- versioned four-week BDDS with automatic opening/closing cash roll-forward and visible cash-gap signal;
- versioned forecast balance with publish-time balance equality validation;
- calculated rows remain derived, not editable duplicate facts;
- no actual accounting/bank/ERP facts and no payment execution;
- delivered next as **v0.6.2 — Budgeting build 2026091522**; following step: **v0.6.3 — Resource Gate**.


## v0.6.2 — Budgeting — implemented (build 2026091522)

- versioned budgets for published Administrative Scale goal/program/project revisions and active canonical OFS org units;
- exact published Financial Scenario revision is mandatory; published Financial Responsibility Centre revision is optional;
- immutable lines per budget revision with plan amount, spend limit, responsible Position, optional Indicator and source provenance;
- explicit submit / approve / reject lifecycle; approved revision remains effective until a later amendment is approved;
- aggregate management fact snapshots drive plan/fact variance without storing raw accounting entries, bank transactions or payment execution;
- next: **v0.6.3 — Resource Gate** (money, people, competencies, time, technology, information and infrastructure feasibility before launch).


## v0.6.3 — Resource Gate — implemented (build 2026091523)

- versioned readiness gates for published goals/programs/projects;
- mandatory coverage of money, people, competencies, time, technology, information and infrastructure;
- references approved Budget, canonical Position/Staffing, read-only Business Role competency requirements and controlled documents;
- Ready requires each required check to pass or be explicitly waived with evidence;
- blocked re-assessment revokes effective Ready while draft/submitted amendments preserve the previous Ready until a new decision;
- no resource master-data duplication and no operational/ERP execution.

Next: **v0.7.0 — Competency Architecture**.

### RC28 / RBAC 2.0 first slice (2026101460)
- Implemented: separate BMS access role registry and UI, company-scoped grants, assignments, audit; management cycles view/create/edit permissions with existing compatibility fallback.
- Next: live two-company permission acceptance and finer own/team/department scopes. **All other BMS object permissions are NOT yet enforceable through RBAC 2.0**; integrate each canonical service one at a time, then broaden matrix.
- 1.1.0 FINAL still NOT READY; RC28 needs migration/regression acceptance.


---

## Proposed release stream after RC30 — BMOS Agent Platform (owner decision 2026-11-13)

**Planning proposal only:** future RC31–RC42 numbers are targets for backlog planning, not shipped release claims. The deployed RC30 candidate and production remain outside this documentation change.

| Planned stage | Scope / exit gate |
|---|---|
| RC31 | Release Pipeline 1.0 — small changes through feature PR → CI → isolated staging → versioned ZIP → owner-approved production → smoke/rollback |
| RC32 / BMOS-A0 | BMS UI/CRUD stabilization and design of typed human/agent principals, functional-post occupancy, SIPOC output/handoff contract, API v2 ADR, provider-neutral model router |
| RC33 / BMOS-A1 | Communication center, corporate feed and MAX integration via approved API/events, minimal agent registry, permissioned SIPOC handoff foundation |
| RC34 / BMOS-A2 | Daily reports of humans and AI agents, human manager acceptance, audit, idempotent inter-post output delivery and approval workflow |
| BMOS-A3 (dedicated release after RC34) | First supervised AI functional post via Timeweb Cloud AI Gateway adapter; bounded read of metrics → draft report → another post / human supervisor handoff; no unauthorized autonomous writes |
| BMOS-A4 (future) | Multi-agent coordination, controlled schedules/delegation, tenant cost budgets, workload metrics, hardening |
| RC35–RC42 / 1.0 | Continue management operations, organizational architecture, metrics/finance, competencies, commercial subscriptions, integrations, SaaS infrastructure, pilot companies, public product launch; sequence is subject to acceptance and owner prioritization |

**Architecture:** [BMOS AI Control Layer + SIPOC Handoff](../../docs/architecture/BMOS_AI_CONTROL_LAYER.md), [ADR-0007 proposed](../../docs/adr/ADR-0007-bmos-ai-control-layer-timeweb-provider-PROPOSED.md), [BMOS agent issue #76](https://github.com/rimanfatykhov-arch/bms24/issues/76), [communication and daily reports #75](https://github.com/rimanfatykhov-arch/bms24/issues/75).

**Invariants:** Functional Position is independent of its human/AI/hybrid occupant; validated SIPOC output becomes an authorized input to the following post; Timeweb is a ModelProvider, **BMOS owns authorizations and execution**. Do not redefine `task.assigneeid` (Moodle user), overwrite `dutypositionuuid`, bypass canonical company UUID RBAC or treat model output as an executable instruction. Each future feature requires independent acceptance and approval.
