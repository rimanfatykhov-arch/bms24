# BMS-ARC-DOC-001 — Канцелярия

v0.1 | 24.11.2026 | ПРОЕКТ К УТВЕРЖДЕНИЮ

## 1. Назначение и границы

BMS-12; MR3-A01. ЦКП: зарегистрированные, согласованные, доведённые до исполнителей и проконтролированные документы с доказуемой историей движения. Канцелярия владеет регистрацией и движением корреспонденции; ISO Document Control — нормативными документами и редакциями; Administrative Scale/policy — директивами; Meetings — протоколами и решениями; Task Center — задачами и приёмкой; Communication Center — обсуждениями; ERP/CRM — коммерческим фактом. Связи вместо дублирования мастеров.

## 2. Существующая основа

main, local_kbacompany 1.1.0-rc29 build2026112301: documents.php, document_service.php, doc/docreview/docack, policy, stable_code_service. Есть редакции, согласование, публикация, активация, архив, ознакомление, контролируемые формы, защищённая выдача файлов. Тесты обнаружены, NOT RUN. Общие полномочия управления требуют предметного RBAC.

## 3. Логическая модель

OfficeRecord: company_uuid, record_uuid, direction (incoming/outgoing/internal), kind, subject, correspondent reference, external number/date, registration number/date, responsible position, confidentiality, status, current revision, timestamps.

OfficeRevision: exact revision, file/content reference, hash, author; входящий оригинал неизменяем, исправления метаданных аудируются. Attachment: защищённая Moodle file reference и hash. Controlled document связывается по doc UUID+revision без копии файла.

RegistrationSeries: company, series, period, prefix, counter; атомарная уникальность company+series+period+number, номера отменённых записей не переиспользуются; stable code не равен регистрационному номеру.

Resolution: UUID, record/revision, issuer, position/employee, expected result, due_at, task_uuid. ApprovalRoute/Step: versioned template, snapshot approvers, sequence/parallel group, decision/time/reason. DispatchEvidence: recipient, channel, timestamp, receipt. CaseFile: classification, retention policy, legal hold. ContractLink: ссылка на канонический договор ERP/CRM либо отдельно утверждённый governance object. Имена концептуальные, не DDL.

## 4. Жизненные циклы

Входящий: received → registered → under_review → resolved → in_execution → closed → archived; закрытие без поручения с причиной. Исходящий: draft → review → approved → registered → dispatched → closed → archived. Внутренний: draft → review → approved → registered → communicated → in_execution (если нужно) → closed → archived. returned/rejected → новая редакция черновика; cancelled с причиной.

Согласование последовательное или параллельное (все обязательные шаги); изменение содержания требует повторного review; самосогласование запрещено по умолчанию. Регистрация ≠ утверждение, ознакомление ≠ исполнение, отправка ≠ доставка. Резолюция создаёт canonical Task идемпотентно по resolution_uuid; статус исполнения — проекция задачи; закрытие требует завершения поручений или явного разрешённого исключения.

## 5. RBAC и безопасность

Регистратор: регистрация; автор: черновики; согласующий: назначенный шаг; утверждающий: делегированные полномочия; исполнитель: поручение и разрешённый документ; архивариус: дела; руководитель: scope; аудитор: read-only; администратор: назначения, не автоматическое чтение. Раздельные read/create/register/resolve/review/approve/dispatch/ack/archive/export, company/object/participant/department scope, deny by default. Название должности не выдаёт право.

Проверять entitlement, membership, ACL, lifecycle, expected revision в сервисе; POST+sesskey, API scoped identity; запрет утечек через поиск, счётчики, уведомления, файлы, экспорт. Аудит actor/principal, command, object, revision, time, outcome. Отзыв прав прекращает выдачу файла. ЭП и сроки хранения — отдельное решение.

## 6. API, события и интерфейс

Проект команд office.create/register/submitReview/decideReview/approve/resolve/dispatch/close/archive; query list/detail/history. Envelope: company_uuid, object_uuid, expected_revision, idempotency_key, correlation_id; actor из auth. Конфликт версии или повтор ключа с другим payload отклоняется. Outbox after commit: office.registered, office.resolution.created, office.approved, office.dispatched, office.closed; дедупликация подписчиков.

BMS CRUD Overview, Входящие, Исходящие, Внутренние, Приказы, Договоры, Архив; ListView/DetailView/Create/Edit, карточка с реквизитами, редакциями, файлами, согласованием, задачами и историей. App Shell принадлежит local_kbacompany; переиспользовать уведомления и коммуникации, внешнему каналу только разрешённая ссылка.

## 7. Рабочие пакеты и приёмка

DOC-W0 аудит → W1 контракт/RBAC → W2 workspace → W3 регистрация → W4 резолюции/согласование → W5 приказы/протоколы/ознакомление → W6 договоры/дела/архив → W7 аналитика/ЭП/ИИ. Первый срез: входящий реестр, файл, атомарный номер, ACL, журнал.

Проверки: две компании, чужой UUID/file/export/search запрещены; параллельная регистрация без дубля; повтор команды не создаёт запись; stale revision отвергается; неавторизованный approve/resolve запрещён; повтор резолюции не создаёт вторую задачу; отзыв доступа; история сохраняется. DDL требует TEST и восстановления. Release Conveyor v1, независимая приёмка, отдельный GO. NOT RUN.

## 8. Открытые решения

Виды и серии номеров, обязательные этапы, замещение, конфиденциальность, сроки хранения, ЭДО/ЭП, договоры без ERP, точные DDL/API. AI только предлагает, не утверждает и не отправляет автономно. DOC-01–19 сохраняются в Roadmap.

## Источники

MASTER ROADMAP 3.0: https://docs.google.com/document/d/1HlfPe-8CygWFL8j-zrx4rKOPD0alCMTxKhIM6KqutdM/edit

Canonical Ownership Matrix: https://docs.google.com/document/d/1fVCcYqgrfVsI-Y6NOqQDP66aivBuZHeTXptcASRfaJ8/edit

Код: https://github.com/rimanfatykhov-arch/bms24/blob/main/local/kbacompany/classes/local/document_service.php

Техническое приложение BMS-TECH-DOC-001 v0.1 — проект

24.11.2026. DOC-W1: техническое проектирование; не разрешение на DDL или production. Базовый срез аудита build2026112301; перед кодом сверить актуальный main, AGENTS.md, открытые PR и schema.

1. ERD и физические кандидаты XMLDB

Все имена ниже — предлагаемые суффиксы local_kbacompany_, не существующие таблицы. PK id bigint; companyuuid char(36) NOT NULL; UUID char(36); UTC epoch bigint; revision/lockversion int; текст по XMLDB; поля и индексы проверить на поддерживаемой СУБД и ограничениях Moodle. Не добавлять физические FK к чужим доменам без проверки их lifecycle; ссылки валидируются в сервисе.

offrec: uuid, direction char(12), kind char(32), subject char(255), status char(24), currentrev int, lockversion int, seriesuuid nullable, regperiod char(8) nullable, regseq bigint nullable, regnumber char(80) nullable, registeredat bigint nullable, ownerpositionuuid, confidentiality char(20), createdby, timecreated, timemodified. UNIQUE(companyuuid,uuid); INDEX(companyuuid,status,timemodified); UNIQUE(companyuuid,seriesuuid,regperiod,regseq) для зарегистрированных записей (проверить nullable semantics).

offrev: recorduuid, revision, externalnumber char(100), externaldate nullable, correspondentref, summary text, contenthash char(64), createdby, timecreated. UNIQUE(companyuuid,recorduuid,revision). File API context/itemid = offrev.id; оригинал фиксируется при регистрации; отдельная metadata correction audit, без перезаписи оригинала.

offseries: uuid, code char(32), period char(8), prefix char(32), nextseq bigint, lockversion. UNIQUE(companyuuid,code,period); UNIQUE(companyuuid,uuid). Серия MVP IN, календарный год в timezone компании; предлагаемый формат IN-YYYY-000001. Не переиспользовать номера отменённых документов.

offlink: recorduuid, revision, relationtype, targettype, targetuuid, targetrevision nullable; UNIQUE(companyuuid,recorduuid,revision,relationtype,targettype,targetuuid,targetrevision) с явным non-null sentinel для неверсированных целей. Ссылки на doc/policy/meeting/task вместо дублей.

offres: uuid, recorduuid, revision, issuerid, positionuuid, assigneeid, expectedresult text, dueat, taskuuid nullable, state, lockversion. UNIQUE(companyuuid,uuid); INDEX(companyuuid,recorduuid); идемпотентное создание canonical task по resolution UUID. DOC-W4.

offroute/offstep: routeuuid, recorduuid, revision, templateversion, groupno, reviewerid, required, decision, decidedat, reason; уникальный шаг (company,route,group,reviewer). Решения append-only; повторный review — новый route. DOC-W4; перед созданием проверить reuse docreview.

offdispatch/offcase: доказательство отправки (recipient, channel, externalref, dispatchedat, receipt hash), номенклатура/retention/hold — отложены W6. Общие audit/idempotency/outbox сначала переиспользовать; если нет подходящего механизма — отдельный ADR.

Связи ERD: Company 1:N OfficeRecord; OfficeRecord 1:N OfficeRevision; RegistrationSeries 1:N зарегистрированных OfficeRecord; OfficeRevision 1:N File и OfficeLink; OfficeRevision 1:N Resolution; Resolution 0..1 Task; OfficeRevision 1:N ApprovalRoute; ApprovalRoute 1:N ApprovalStep. Все связи company-scoped, cross-company target запрещён.

2. RBAC — минимальная матрица

Предлагаемые operation keys (не Moodle capabilities): office.read, create, edit, register, resolve, review, approve, dispatch, close, archive, export, file.read. Регистратор: read/create/edit/register в назначенном scope; автор: read/create/edit собственных черновиков; согласующий: read/file.read/review только назначенного шага; утверждающий: read/approve/resolve в делегированном scope; исполнитель: read/file.read по назначению, исполнение через Task; архивариус: read/archive в scope; аудитор: read/history, export только отдельным grant; security admin: назначение ролей, без автоматического read. Руководитель: только явно выданные operation/scope. Deny by default, confidentiality gate поверх grant. Никакого расширения manageorganization. Revocation применяется на каждом запросе.

3. API v1 — внутренние service commands, публичный endpoint не открывать

Общий envelope: company_uuid, object_uuid (кроме create), expected_lock_version, idempotency_key UUID, correlation_id UUID, payload; actor из auth. Ответ: object_uuid, revision, lock_version, status, result; ошибки VALIDATION, FORBIDDEN/NOT_FOUND (не раскрывать чужой объект), STALE_VERSION, INVALID_TRANSITION, IDEMPOTENCY_CONFLICT, TEMPORARY_CONFLICT. HTTP mapping при отдельном REST adapter: 400/403 или 404/409/422/503. Idempotency scope company+principal+command+key, hash нормализованного payload; replay возвращает тот же результат только после повторной проверки прав.

office.create: direction=incoming, kind, subject, correspondent_ref, external_number/date, draftfile refs; возвращает received, uuid. office.register: record UUID, series UUID, expected version; require received, file/evidence validated, atomic number+state+audit, returns registered/regnumber. office.resolve: record/revision, assignee/position, expected_result, due_at; require registered/under_review, create canonical task or durable retry via outbox; no second task. office.submitReview/decideReview/approve: exact revision+route+step+decision+reason; W4. office.dispatch/close/archive: evidence or reason, enforce transition; W5+. Queries list/detail/history/file: authorized scope, stable cursor, bounded page (default 25 max 100), no unfiltered totals. Event envelope event_uuid, company_uuid, object_uuid, revision, type, occurred_at, correlation_id; no sensitive body; consumers deduplicate.

4. Миграция и тестовый gate

DOC-W2: schema-neutral navigation/workspace, existing document projection only; feature off by default, no fake counts. DOC-W3: additive XMLDB tables offrec/offrev/offseries plus only required indexes; version chosen from current main at implementation; install.xml + upgrade.php + savepoint + privacy provider + file ACL. Do not backfill ISO records automatically. No destructive rollback: disable feature, keep data; DDL recovery verified using TEST backup, maintenance/quiesce writers, restore DB+files+code together. DB lock via Moodle lock factory and delegated transaction; acquire same lock for series, timeout fail closed; test with concurrent connections, not sequential imitation. Test clean install, upgrade, duplicate register/replay, conflict, cancellation, stale version, cross-company list/detail/file/export, revoked access, no leak counts, file privacy, RU/EN, mobile.

5. ТЗ Claude / Codex

Claude: DOC-W2 first, one branch/PR; reuse App Shell, BMS CRUD and canonical ISO services; document inventory and routes, no DDL, no production. After review and explicit W3 gate, implement incoming register with minimal schema and tests. Codex: inspect main/open PR, ownership and ACL, review diff and CI; test on TEST with two companies; record exact SHA, immutable ZIP, migration/recovery evidence if W3; no merge/deploy without owner GO. DoD: source paths, tests executed vs NOT RUN, screenshots, acceptance matrix, release notes, risk, rollback and handoff. No test run claimed by this design.

Поручение разработки W2 — DOC-W2

Поручение Claude: реализовать schema-neutral рабочее пространство Канцелярия. Обзор только реально существующих доступных документов, политик/директив, протоколов, поручений и связанных объектов, если подтверждены канонические маршруты и ACL. Переиспользовать document_service и BMS App Shell; не выдавать ISO-документы за зарегистрированную корреспонденцию. Новые реестры входящих/исходящих, нумерация, резолюции, договоры и DDL остаются W3+. Быстрое меню, реальные ссылки, пустые состояния, RU/EN, desktop/mobile. Не показывать неработающие кнопки или фиктивные счётчики.

Общий gate: проверить AGENTS.md, актуальный main SHA, PR/Issues, владельца ветки; использовать существующий механизм feature flags либо согласовать безопасный default-off способ без DDL. Проверка server-side company/object ACL до чтения и агрегации, запрет утечек через меню, счётчики, прямой URL и вложения. PHP/JS/RU-EN/регрессия, два синтетических company contexts и browser TEST; явно PASS/FAIL/NOT RUN. Документация, changed paths, CI, exact SHA, handoff. Не выполнять merge, установку или production без соответствующего отдельного разрешения. Статус: ТЗ подготовлено; код не проверен, тесты NOT RUN.
