# BMS-ARC-FIN-001 — Финансы

v0.1 | 24.11.2026 | ПРОЕКТ К УТВЕРЖДЕНИЮ

## 1. Назначение и границы

BMS-05; MR3-A01. ЦКП: финансово обеспеченная деятельность, контролируемые денежные потоки, исполнение бюджетов и достоверная управленческая отчётность. BMS владеет моделью, ЦФО, планом, лимитами, заявками, согласованием и решениями. ERP/1С/банк — источники первичных операций, платежей, остатков и подтверждённого факта. Не создавать второй бухгалтерский ledger, ERP или платёжный шлюз. MVP не отправляет деньги.

## 2. Существующая база

main 1.1.0-rc29 build2026112301: financial_model.php, financial_statements.php, budgeting.php; financial_model_service, financial_statement_service, budgeting_service, resource_gate_service; finscenario, fincenter, finstmt, finline, finbudget, finbudline, finbudfact, resgate. БДДС — четыре недельных периода; бюджет — save/submit/approve/reject/archive и агрегированный факт. Сохранить UUID, редакции, связи и provenance. Тесты найдены, NOT RUN. Не расширять manageorganization ради финансовой роли.

## 3. Логическая модель

FinancialScenario, FinancialResponsibilityCenter, FinancialStatement, Budget, BudgetLine, BudgetFactSnapshot, ResourceGate — существующие сущности. WeeklyPlan: company_uuid, period_uuid, start/end, timezone, currency, scenario UUID/revision, budget refs, opening balance snapshot, planned receipts/outflows, version/status, council decision reference. Недельный план уточняет БДДС, не создаёт второй бюджет.

SpendRequest: company_uuid, request_uuid, revision, requester/membership, position, ЦФО, purpose, amount/currency, desired date, payee reference, budget UUID/revision/line, evidence, priority/status. RequestAllocation: request revision, line, amount/currency, reservation state. MVP — одна строка и валюта.

FinanceCouncilDecision: ссылка на canonical meeting/decision и exact plan/request revision, approved amount, reason. PaymentScheduleItem: approved request/obligation reference, date, amount/currency, planned account, status, external payment refs. Obligation/Receivable/Payable/Balance — provider fact projections. ActualSnapshot: provider, external ID/revision, company mapping, as_of, import timestamp, amount/currency, kind, provenance, reconciliation state. Имена концептуальные, не DDL.

## 4. Жизненные циклы

Бюджет → недельный план → заявки → лимиты → финансовый совет → календарь → внешний платёж → подтверждённый факт → сверка → решение. План: draft → submitted → approved → active → closed → archived; возврат в новую редакцию.

Заявка: draft → submitted → under_review → approved (резерв) → scheduled → partially_settled/settled → closed; returned/rejected/cancelled с причиной. Изменение суммы, ЦФО, строки или получателя после approve требует новой редакции и согласования. Отмена освобождает незакрытый резерв, не отменяет внешний платёж.

Календарь: planned → confirmed → awaiting_fact → partially_settled/settled; cancelled/rescheduled с историей. Paid только по подтверждённому факту. Ручной факт отдельно маркируется, с evidence и проверкой, не выдаётся за ERP-факт.

## 5. Лимиты, расчёты и сверка

Доступный лимит = утверждённый лимит − подтверждённое потребление − незакрытые резервы. При зачёте факта атомарно уменьшается резерв и учитывается факт без двойного вычитания. Проверка/reserve в одной транзакции, повтор идемпотентен, конкурентные approvals не превышают лимит. Превышение только через явное утверждённое изменение/исключение бюджета; ликвидность проверяется отдельно.

Деньги decimal/fixed precision, ISO currency, утверждённое округление; MVP одна валюта, конвертация только с курсом/датой/источником. Остаток = opening + receipts − outflows. План и факт отдельно, нет данных ≠ ноль. P&L/EBITDA/маржа по опубликованным формулам. Дедупликация факта company/provider/external ID/revision; конфликт payload в quarantine. Частичные факты и остаток; неоднозначность не закрывает заявку. Факт без заявки — отклонение; закрытый период — снимок; поздние факты — корректировка с историей.

## 6. RBAC и безопасность

Инициатор: свои заявки; руководитель ЦФО: свой scope; экономист: план и сверка; финансовый директор: лимиты и согласование; совет/CEO: решения по политике; казначей: календарь и сверка; бухгалтер/provider: подтверждённый факт; аудитор read-only; администратор: назначения. Раздельные read/request/submit/approve/reserve/schedule/import/reconcile/close/export. Самосогласование запрещено по умолчанию, исключение — явная политика и аудит. Пороговые суммы конфигурируются.

Company/object/ЦФО scope и entitlement отдельно от ACL; POST+sesskey, scoped API, audit. Нет утечек финансовых сумм в поиске, отчётах, уведомлениях, AI; секреты вне логов.

## 7. API, события и UI

Проект команд finance.plan.create/submit/approve/close; finance.request.create/submit/approve/reject/cancel; finance.schedule.create/reschedule; finance.actual.ingest/reconcile; queries budgetAvailability/planFact/cashForecast. Envelope company_uuid, object_uuid, expected_revision, idempotency_key, correlation_id, auth-derived actor. Approval+reserve атомарно; outbox after commit: finance.request.approved, finance.reserve.changed, finance.schedule.updated, finance.actual.reconciled, finance.period.closed.

UI: Обзор, Финансовая модель, ЦФО/Бюджеты, Недельный план, Заявки, Финансовый совет, Казначейство/календарь, План-факт, Отчётность. Карточка заявки: цель, ЦФО, сумма, лимит с датой, согласования, документы, календарь, факты, история. Dashboard показывает свежесть и источник, отдельно прогноз. Переиспользовать задачи, совещания, документы, уведомления.

## 8. Пакеты и приёмка

FIN-W0 аудит → W1 ownership/RBAC/лимиты → W2 workspace → W3 недельный план → W4 заявки → W5 совет/резерв → W6 календарь → W7 факт/сверка → W8 отчётность → W9 AI. Первый срез: обзор существующих объектов без новой БД; затем недельный план и заявки после контракта.

Acceptance: бюджет → заявка → решение → резерв → календарь → частичный/полный факт → закрытие; конкурентные approvals не превышают лимит; повтор команды/факта не удваивает сумму; отмена освобождает остаток; корректировка сохраняет историю; чужой ЦФО/company/export запрещён; отсутствие данных не ноль; сверка календаря и БДДС; запрет неавторизованного approve/import. PHPUnit/browser, TEST DDL/recovery, независимая приёмка и GO по Release Conveyor. NOT RUN.

## 9. Открытые решения

Граница ручного факта и ERP, статьи плана, день совета, матрица порогов, формулы P&L/EBITDA, валюта, источники факта, холдинги, exact DDL/API, миграция и RPO/RTO. AI только предлагает, не утверждает бюджет/платёж, без прямого SQL. FIN-01–28 сохраняются в Roadmap.

## Источники

MASTER ROADMAP 3.0: https://docs.google.com/document/d/1HlfPe-8CygWFL8j-zrx4rKOPD0alCMTxKhIM6KqutdM/edit

Canonical Ownership Matrix: https://docs.google.com/document/d/1fVCcYqgrfVsI-Y6NOqQDP66aivBuZHeTXptcASRfaJ8/edit

Код: https://github.com/rimanfatykhov-arch/bms24/blob/main/local/kbacompany/classes/local/budgeting_service.php

Техническое приложение BMS-TECH-FIN-001 v0.1 — проект

24.11.2026. FIN-W1: техническое проектирование; не разрешение на DDL или production. Базовый срез аудита build2026112301; до реализации сверить актуальный main, AGENTS.md, открытые PR и schema.

1. ERD и физические кандидаты XMLDB

Существующие finscenario, fincenter, finstmt, finline, finbudget, finbudline, finbudfact, resgate остаются владельцами. Ниже предлагаемые суффиксы local_kbacompany_, не существующие таблицы. PK id bigint, companyuuid/UUID char(36), revision/lockversion int, timestamps UTC bigint. Деньги XMLDB number(19,4) как кандидат, decimal string в API, арифметика fixed precision без float, scale и диапазон проверить относительно текущих finbudline/finbudfact. Валюта ISO char(3), MVP одна валюта, округление по валюте. Не переписывать существующие суммы молча.

finweek: uuid, periodstart/periodend (local date YYYY-MM-DD), timezone, currency, scenariouuid/scenariorev, statementuuid/statementrev, openingfactref nullable, currentrev, status, lockversion. UNIQUE(companyuuid,uuid); INDEX(companyuuid,periodstart,status). Проверка непересечения активных периодов под lock company+plan series.

finweekrev: weekuuid, revision, authorid, createdat, decisionref nullable; UNIQUE(companyuuid,weekuuid,revision). finweekline: weekuuid/revision, lineuuid, budgetuuid/budgetrev/linecode, direction, planneddate, amount, currency, source_ref. UNIQUE(companyuuid,weekuuid,revision,lineuuid). Недельный план ссылается на БДДС, не второй бюджет.

finreq: uuid, requesterid, positionuuid, centeruuid, currentrev, status, lockversion, timecreated/timemodified. UNIQUE(companyuuid,uuid); INDEX(companyuuid,centeruuid,status). finreqrev: requestuuid, revision, purpose text, amount, currency, desired_date, payeeref, budgetuuid, budgetrev, linecode, evidence refs, priority, authorid, timecreated. UNIQUE(companyuuid,requestuuid,revision). Точная утверждённая версия immutable; изменения через новую редакцию и новый approval.

finreserve: uuid, requestuuid, requestrev, budgetuuid, budgetrev, linecode, amount, outstanding, currency, status, lockversion. UNIQUE(companyuuid,requestuuid,requestrev) в MVP; INDEX(companyuuid,budgetuuid,budgetrev,linecode,status). Резерв не является фактом. История reserve/release/consume append-only в существующем audit или согласованной журналируемой модели.

finpayplan: uuid, requestuuid/rev, reserveuuid, due_date, plannedamount, currency, accountref nullable, status, lockversion; INDEX(companyuuid,due_date,status). Не содержит банковского платёжного поручения. MVP один активный schedule на резерв; частичное исполнение через reconciliation.

finactual: uuid, provider, externalid, sourcerev, sourcehash, asof, importedat, amount, currency, kind, provenance, qualitystatus. UNIQUE(companyuuid,provider,externalid,sourcerev); текущий эффективный факт определяется correction/supersedes chain, не суммой всех revisions. finreconcile: uuid, actualuuid, scheduleuuid, allocatedamount, currency, decisionactor, createdat, reversesuuid nullable. UNIQUE(companyuuid,uuid); сумма активных аллокаций не превышает подтверждённый факт и незакрытый schedule, проверка атомарная. Не создавать второй master finbudfact: выбрать один источник потребления и deterministic projection; ADR до W7.

ERD: FinancialScenario 1:N WeeklyPlan; WeeklyPlan 1:N WeeklyRevision 1:N WeeklyLine; BudgetRevision 1:N BudgetLine; BudgetLine 1:N RequestRevision; SpendRequest 1:N RequestRevision; approved RequestRevision 0..1 Reservation (MVP); Reservation 0..1 active Schedule; Actual N:M Schedule через Reconciliation. CouncilDecision — ссылка на canonical meeting/decision. Документы — protected references. Все связи company-scoped.

2. RBAC

Operation keys finance.read, plan.edit/submit/approve/close, request.create/edit/submit/approve/reject/cancel, reserve.manage (service-only), schedule.manage, actual.ingest, reconcile, export, audit.read. Инициатор — свои заявки; руководитель ЦФО — read/review в scope; экономист — план/сверка в scope; CFO — approve в рамках делегированного лимита; CEO/совет — решения по политике; казначей — schedule/reconcile; бухгалтер/provider — ingest (service principal отдельный); аудитор — read/history; security admin — назначения без автоматического read. Самосогласование default deny. Entitlement ≠ ACL. Все запросы проверяют company + ЦФО + object + lifecycle, включая list/count/file/export. No broad manageorganization grant.

3. API v1 — внутренние service commands

Envelope company_uuid, object_uuid, expected_lock_version, idempotency_key, correlation_id, payload, actor auth-derived. Response uuid, revision, lock_version, status, result. Errors VALIDATION, FORBIDDEN/NOT_FOUND, STALE_VERSION, INVALID_TRANSITION, INSUFFICIENT_LIMIT, CURRENCY_MISMATCH, IDEMPOTENCY_CONFLICT, AMBIGUOUS_FACT, TEMPORARY_CONFLICT. HTTP mapping only if adapter approved. Replay checks ACL and payload hash; no public endpoint in first slice.

finance.plan.create: period, timezone, currency, scenario/statement exact revisions, lines; submit/approve/close require exact revision, council reference where required. finance.request.create: center, purpose, amount decimal string, currency, desired_date, payee_ref, budget UUID/rev/line, evidence; submit validates active budget and position. finance.request.approve: approved exact revision, decisionref, expected version; one transaction lock logical budget line across revision changes, revalidate current approved limit, compute consumption and reserves, reserve and commit approval/audit/outbox; deny overspend. finance.request.reject/cancel: reason, release only outstanding reserve. finance.schedule.create/reschedule: approved reserve, date, amount, reason; no bank execution. finance.actual.ingest: provider ID, external ID/revision, amount/currency, as_of, provenance/hash; provider mapping and auth, dedup or quarantine. finance.actual.reconcile: fact + schedule + allocation, lock both resources in deterministic order, atomically update reserve consumption, no double count. Query budgetAvailability returns approved limit, confirmed consumption, outstanding reservations, available, currency, as_of; planFact/cashForecast distinguish missing data from zero. Events after commit: finance.request.approved, finance.reserve.changed, finance.schedule.updated, finance.actual.reconciled, finance.period.closed; no sensitive payload.

4. Concurrency, migration, acceptance

Invariant available = effective approved limit − effective confirmed consumption − outstanding reserves. Example limit 100000, fact 20000, reserve 30000 => 50000 available; partial payment 10000 on existing reserve changes fact to 30000 and reserve to 20000, available remains 50000. Reject a concurrent second 30000 approval if only 20000 remains. New budget revision must carry/resolve active reserves and consumption under same logical line lock, never reset availability. If line identity changes, explicit mapping or block activation. Reconciliation correction reverses prior allocation with audit; never sum superseded external revisions. Decimal comparison and overflow tests.

FIN-W2 first: schema-neutral workspace using existing financial services, ACL-filtered projections; no invented actuals or new business entities. FIN-W3 additive finweek/finweekrev/finweekline after schema gate; FIN-W4/5 request+reserve only after budget revision/locking ADR; W6 schedule; W7 fact/reconcile with provider sandbox. XMLDB install/upgrade/savepoint, privacy provider, feature flag default off, no destructive backfill. TEST backup/restore DB+files+code, quiesce writers before DDL. Tests: two companies/ЦФО, permissions and revoked access, stale approval, simultaneous approvals with independent connections, same key replay, different payload conflict, partial settlement, cancellation, correction, new budget revision, wrong currency, missing fact, external duplicate, unauthorized import, export leakage, RU/EN/mobile. Tests NOT RUN in design.

5. ТЗ Claude / Codex

Claude: FIN-W2 first; reuse canonical financial_model/statement/budgeting/resource_gate services; one task/branch/PR, no DDL; UI with Overview/Model/ЦФО/Budgets, freshness and missing data. After separate gate design FIN-W3 and W4/5, not all at once. Codex: independent review ACL, current schema, financial invariants, CI, exact SHA, TEST acceptance, immutable ZIP and recovery receipt where DDL. No merge/deploy without owner GO. DoD: code paths, explicit tests PASS/FAIL/NOT RUN, browser screenshots, acceptance matrix, release notes, risk, rollback, handoff. No live payment execution.

Поручение разработки W2 — FIN-W2

Поручение Claude: реализовать schema-neutral рабочее пространство Финансы. Обзор, финансовая модель, ЦФО, БДР, БДДС, прогнозный баланс, бюджеты, Resource Gate — через существующие сервисы и маршруты. План и подтверждённый факт раздельно, валюта/период/источник/свежесть при наличии, отсутствие данных не равно нулю. Недельный план, заявки, резервы, казначейство, платежи и новые таблицы остаются W3+. Не расширять финансовый доступ или показывать агрегаты вне ACL.

Общий gate: проверить AGENTS.md, актуальный main SHA, PR/Issues, владельца ветки; использовать существующий механизм feature flags либо согласовать безопасный default-off способ без DDL. Проверка server-side company/object ACL до чтения и агрегации, запрет утечек через меню, счётчики, прямой URL и вложения. PHP/JS/RU-EN/регрессия, два синтетических company contexts и browser TEST; явно PASS/FAIL/NOT RUN. Документация, changed paths, CI, exact SHA, handoff. Не выполнять merge, установку или production без соответствующего отдельного разрешения. Статус: ТЗ подготовлено; код не проверен, тесты NOT RUN.
