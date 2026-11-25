# BMOS-A0. Аудит существующих компонентов

**Статус: PROPOSED (архитектурный этап A0).** Исполнитель: Claude. Дата: 24.11.2026.
База: `origin/main` `62fdc4934b8b8a87fb8c7f21a52c56b64229e836`, `local_kbacompany` `2026112301` / `1.1.0-rc29`,
`local_kbaai` `2026101516` / `0.9.5`. Повторная сверка 25.11.2026 с `main` `323f948c6cc670c34722f516118ca7426fd604f7`: добавился только #173 (сводка отчётов, ADR-0011), схема, права и сервисы не менялись. Это чтение исходников, а не runtime-проверка. Платформа агентов **не реализована**.

## 1. Что изучено и что нет

| Источник | Статус |
|---|---|
| Код `main` (сервисы ниже, `db/install.xml`, `db/services.php`, `db/tasks.php`, `db/access.php` обоих плагинов) | Прочитан по исходникам |
| `PUBLIC_API_0.6.5.md`, `PROVIDER_NEUTRAL_OUTBOX_0.6.6.md`, `docs/10_AI_ASSISTANT_CONTRACT.md`, `docs/11_RBAC_ACCESS_MODEL.md`, `docs/18_BMOS_AI_AGENTS_FUTURE.md`, `docs/deploy/BMS24_RELEASE_CONVEYOR_V1.md` | Прочитаны |
| ADR-0006, ADR-0010, ADR-0011 (в `main` с #173); ADR-0012 (PR #179); ADR-0007 (PR #77) | Прочитаны |
| PR #77 (`BMOS_AI_CONTROL_LAYER.md`), #88 (контракт MAX), #89 (SIPOC review), #97 (согласование MAX/BMOS/SaaS) | Прочитаны с веток PR |
| Issue #76, #84, #75 | Прочитаны (тело issue; комментариев в #76 нет) |
| MASTER ROADMAP 3.0 (Drive), Master Context v1.5.43 (Drive), BMS-ARC-AI-001 (Drive), Canonical Ownership Matrix v0.2 (Drive) | Прочитаны через Google Drive (разделы про ИИ, BMOS, владение) |
| Timeweb AI Gateway: `/docs/ai-gateway`, `/manage`, `/logs`, `/usage-statistics`, `/fallback` | Прочитаны 24.11.2026, только документация, ключей нет |
| Отдельная страница прайса Timeweb, API готовых агентов Timeweb, условия обработки данных | **Не изучены** |
| RBAC 2.0 спецификация на Google Drive («BMS24 — RBAC 2.0 — роли доступа, матрица объектов и границы разрешений — RC28») | Прочитана 25.11.2026 по ссылке владельца; сверка — A0_02 §4.2 (REV-08). В ревизии 1 не была найдена |
| Исходный список 22 тестов задания | Получен в решении владельца 25.11.2026; трассировка — A0_09 §1 (REV-09) |

## 2. Сущности: владелец, таблицы, идентификаторы, lifecycle, ACL, точки расширения, риски

Все таблицы — `local_kbacompany_*`, кроме отмеченных.

### 2.1 Компания и членство

- **Владелец:** BMS Management (`company_service`, `company_context_service`, `membership_access_service`, ADR-0006).
- **Таблицы:** `company` (`uuid`), `member` (`uuid`, `companyuuid`, `userid`, флаги ролей, `effectivefrom/to`, `active`), `revoke` (явный отзыв, ADR-0006).
- **Lifecycle:** членство историческое (`effectivefrom/to`), отзыв — явный и приоритетный, повторное открытие только с аудитом.
- **ACL:** `company_context_service::context(companyuuid, int userid)` → `company_service::company_context` → `membership_access_service::require_access`. Граница компании **жёстко человеческая**: параметр — Moodle `userid`.
- **Точка расширения:** новый `principal_context_service::context(companyuuid, principaluuid)` рядом, а не вместо. Human-путь не меняется.
- **Риск совместимости:** любой сервис, который принимает `int $userid`, не может обслужить агента без новой точки входа. Передавать `userid` спонсора — это impersonation (запрещено).

### 2.2 Должность (Position), назначения, штатное расписание

- **Владелец:** `position_service`, `staffing_service`, `position_operating_service`.
- **Таблицы:** `position` (`uuid`, `companyuuid`, `orgnodeuuid`, `ismanager`, `plannedcount`, `active`); `posassign` (`companyuuid`, `positionuuid`, `slotuuid`, `userid`, `assignmenttype`, `assignmentfte`, `effectivefrom/to`, `active`); `staffplan` / `staffline` / `staffslot` (`uuid`); `function` и `poswork` (`uuid` + `revision`, функции и работы поста).
- **Lifecycle:** позиция active/inactive; назначение `active` + даты; штатный план draft → approved.
- **ACL:** `permission_service::can_manage_organization` (owner/manager/hr + capability `manageorganization`).
- **Расхождение с Ownership Matrix v0.2:** матрица называет canonical ID назначения «assignment UUID», но у `posassign` **нет `uuid`, `revision`, `approvedby`**. Ключ — числовой `id`. Это подтверждает вывод PR #97.
- **Точка расширения:** отдельная таблица занятости для нечеловеческих принципалов (см. A0_02), ссылающаяся на `position.uuid`. `posassign` остаётся реестром людей.
- **Риск:** записывать агента в `posassign.userid` нельзя: поле — Moodle user, оно попадает в штатное расписание, `effective_executor` и отчёты.

### 2.3 Position Coverage (замещение)

- **Владелец:** `position_coverage_service` (+ `battle_plan_coverage_service`).
- **Таблицы:** `poscover` (`uuid`, `positionuuid`, `primaryid`, `delegateid`, `scope`, `startdate/enddate`, `status`, `revision`, `approvedby/at`), `poscovaudit` (уникально по `coverageuuid+revision`).
- **Семантика:** `effective_executor(company, position, primaryid, at)` возвращает `delegateid` только при approved coverage, активном primary-назначении в `posassign` и активной позиции. `task_service::effective_assignee` применяет это только к открытым задачам с `dutypositionuuid`.
- **Точка расширения:** нет. A0 предлагает **не** допускать агента в `poscover` (ни primary, ни delegate).
- **Риск:** если агент станет delegate, к нему уйдёт открытая работа человека и права исполнителя — нарушение инварианта AGENTS.md.

### 2.4 Задачи

- **Владелец:** `task_service` (2854 строки), `recurring_task_service`, `task/generate_recurring_tasks`.
- **Таблицы:** `task` (`uuid`, `assigneeid`, `dutypositionuuid`, `creatorid`, `reviewerid`, `observerid`, `status`, `revision`, контекст и источник), `tasklog` (`actorid` int, `action`, `details`), `taskcheck`, `taskrec`, `tasksrc`.
- **Lifecycle:** статусы задачи с переходами через `transition`; оптимистичная `revision`; идемпотентность повторяющихся экземпляров не зависит от исполнителя.
- **ACL:** `can_view_task`, `can_manage`, `can_post_discussion_for`; права на основе `userid`.
- **Точка расширения:** узкие principal-aware команды (`task.read`, `task.comment`, `task.propose_update`) поверх существующих проверок, плюс отдельная атрибуция действия агента (A0_06 §5).
- **Риск:** `tasklog.actorid` — NOT NULL int. Действие агента в существующем журнале нечем подписать без `0`/служебного значения. Предложение: отдельная таблица атрибуции, существующие журналы не меняются.

### 2.5 Коммуникации

- **Владелец:** `communication_service`, `communication_object_service`; в стеке #178 — `communication_write_gate`.
- **Таблицы:** `commthread`, `commpart`, `commmsg` (`authorid` int), `commrev`, `commread`, `commmention`, `commnotif`, `commattach`.
- **ACL:** участник ветки + object ACL; в #178 общий lock-протокол записи.
- **Связь с ADR-0012 (#179):** MAX-импорт пишет сообщение как сопоставленный человек, транспортный аккаунт записан отдельно (dual-actor audit).
- **Риск:** у сообщения нет поля для нечеловеческого автора. A0 запрещает агенту публиковать сообщения в A1–A3 от имени человека; комментарий агента — только через отдельную атрибуцию и видимую метку «ИИ».

### 2.6 Ежедневные отчёты

- **Владелец:** `daily_report_service`, `daily_report_store_service`, `daily_report_review_service`, `daily_report_reviewer_service`, `daily_report_feed_service`, `daily_report_ui_service` (ADR-0009, ADR-0010; ADR-0011 в #173).
- **Таблицы:** `dreport` (уникально `companyuuid+userid+reportday`), `dreportver` (неизменяемые версии, `requestkey`, `contenthash`, `revision`), `dreportrev` (решение проверяющего, уникально `versionuuid+requestkey`), `dreportlink`, `dreportlog`, `dreportcc`, `dreportset`.
- **Lifecycle:** draft → submitted → accepted/returned; версии неизменяемы; идемпотентная отправка.
- **ACL:** автор — Moodle user; проверяющий — по оргсхеме (ADR-0010); руководитель видит отчёты всей компании.
- **Риск:** уникальный ключ по `userid` не допускает отчёт агента без миграции ключа или отдельной сущности (вопрос Q-08). Отчёты опубликованы на production (#168), поэтому любая схема в `dreport*` — режим B конвейера.

### 2.7 RBAC 2.0

- **Владелец:** `permission_service` (политики owner/manager/hr), `acl*`: `aclrole`, `aclgrant` (`objecttype`, `actionname`, `scope`), `aclassign` (`roleid`, **`userid`**), `aclaudit`.
- **Правила:** site-admin bypass, обязательный контекст компании, capability Moodle + роль компании, нет role-check внутри сервисов.
- **Точка расширения:** гранты принципала (A0_02 §4) — отдельно от `aclassign`. Агент **не** получает роли компании и capability Moodle.
- **Риск:** `aclassign.userid` — только люди. Переиспользование таблицы для агентов смешало бы человеческие и машинные права.

### 2.8 Интеграции, outbox, Public API

- **Владелец:** `integration_service` (`syncout`, legacy KBA Connect), `integration_event_service` (`eventout`, envelope 1.1), `public_api_service` + `classes/external/public_api.php`.
- **Public API v1:** сервис `local_kbacompany_public_v1`, выключен, restricted users, **только чтение**: `public_contract/company/employees/organization/positions/indicators/budgets`. Транспорт — токен Moodle, привязанный к Moodle user.
- **Outbox:** `eventout` (`eventuuid`, `idempotencykey` уникален, `correlationid`, `sourcecommanduuid`, at-least-once). **В envelope нет полей актора.**
- **`extmap`:** namespaced mapping внешних ID (`provider`, `namespacekey`, `externalid`, `mappinguuid`).
- **Точки расширения:** envelope 1.2 с `actor_principal_type/uuid`, `on_behalf_of_user_id`, `agent_run_uuid` (добавочные поля, совместимо); API v2 — отдельный выключенный сервис.
- **Риск:** токен Moodle всегда означает Moodle user. Для `ai_agent` нужен другой путь (A0_04 §2).

### 2.9 Фоновые задачи

- `local_kbacompany`: `generate_recurring_tasks`, `send_calendar_reminders`, `send_battle_plan_decision_reminders` (scheduled).
- `local_kbaai`: `scan_submissions` (scheduled, каждую минуту), adhoc `assess_submission`, `reply_as_supervisor`.
- **Риск:** автономный агентный цикл в cron — это «неконтролируемое выполнение». A0 запрещает автономные процессы до A3 и требует kill switch, бюджет и лимит частоты до первого запуска.

### 2.10 `local_kbaai` (KBA AI Assessor и BMS Assistant)

- **Provider abstraction:** `provider\provider_interface` с методами `get_id`, `get_capabilities`, `generate(assessment_request)`, `analyse_attachments`, `generate_supervisor_reply`, `test_connection`. Интерфейс **построен вокруг сценариев** (оценка, супервайзер), а не вокруг модели.
- **Фабрика:** `provider_factory::create` знает только `mock` и `yandex` (жёсткий список); `bms_assistant_service::status()` тоже проверяет `['mock','yandex']`. BMS Assistant вызывает `generate_bms_assistance` через `method_exists`.
- **Профили:** `bms_profile_registry` (версия 0.3, операции explain/interview/draft/review/evidence_check, `write_policy=proposal_only`, JSON-схема, fail-closed нормализация).
- **Мост в BMS:** `local_kbacompany\local\bms_ai_bridge_service` (контракт 0.3): контекст компании, белые списки доменов/полей, proposal-only.
- **Секреты:** ключ Yandex читается из `$CFG->kbaai_yandex_apikey` **или** из `config_plugins` (`admin_setting_configpasswordunmask`). Второй вариант хранит секрет в БД и в её бэкапах.
- **Хранение:** `local_kbaai_assessment.inputsnapshot` и `resultjson` хранят вход и выход оценки — это допустимо для Assessor, но для агентов A0 по умолчанию запрещает хранить полные промпты.
- **Вывод:** `local_kbaai` — единственный provider engine. Переиспользовать фабрику и адаптеры, добавить к ним модельный интерфейс (A0_05). Сценарные интерфейсы Assessor и Assistant не переписывать (MR3-D50, BMS-ARC-AI-001 §1.4).

### 2.11 MAX и ADR-0012 (PR #178 → #179, не смержены)

- `service_integration` = выделенный живой Moodle-аккаунт на компанию и провайдера (`local_kbacompany_inbconn`), capability `local/kbacompany:importinbound` не выдана никому, сервис `local_kbacompany_public_inbound_v1` выключен.
- Принципал — транспорт, не автор; автор — подтверждённый человек; audit пишет `principaluserid` и `actoruserid`.
- ADR-0012 прямо запрещает считать себя прецедентом для общей модели. A0 предлагает, как его поглотить (ADR-0013 §6).

## 3. Что переиспользовать и что разрабатывать

| Переиспользовать без изменений | Расширить (A1+) | Разработать (A1+, PROPOSED) |
|---|---|---|
| Position, posassign, staffing, coverage, task, dreport* как есть | `local_kbaai`: модельный интерфейс, ModelRouter, адаптер Timeweb рядом с текущими | WorkPrincipal, гранты, ссылки на секреты |
| `company_context_service` для людей | Envelope outbox 1.2 (поля актора) | PositionOccupancy для нечеловеческих принципалов |
| `eventout` (доставка, идемпотентность) | `bms_ai_bridge_service` как образец контракта `contract()/status()` | AgentDefinition (+ревизии), AgentRun, ApprovalRequest |
| `bms_profile_registry` (proposal-only, JSON-схема, fail-closed) | Public API: новый выключенный сервис v2 | AI-биллинг: бюджеты, резервы, журнал расхода |
| ADR-0006 отзыв, `communication_write_gate` (#178) | — | Agent Runtime (отдельный компонент, MR3-D51) |

## 4. Найденные противоречия

См. `A0_01_ARCHITECTURE.md` §7 — таблица противоречий и предлагаемых решений.
