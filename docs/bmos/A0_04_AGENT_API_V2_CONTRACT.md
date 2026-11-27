# BMOS-A0. Agent API v2 Contract

**Статус: PROPOSED.** Ни одной функции не создано. Public API v1 (`local_kbacompany_public_v1`, только чтение) не
меняется. Ревизия 2 (25.11.2026): учтены решения Q-01–Q-18 и замечания REV-01, REV-05, REV-07, replay.
Ревизия 3 (27.11.2026): блокеры 1 и 3 проверки Codex — R4 только для нечеловеческих принципалов, состояния повтора
для R3, пары RBAC разделены на действующие и проектные, `task.read` описан семантикой.
Ревизия 4 (27.11.2026): вторая проверка Codex — digest неизменяемого намерения, статусы `task/comment` и `task/create`,
человеческие предикаты чтения, этапы `metrics.read`.

## 1. Имена и сверка с Public API v1

- Public API v1: сервис `local_kbacompany_public_v1`, функции `local_kbacompany_public_<resource>`, envelope
  `api_version / schema_version / resource / company_uuid / workspace_uuid / generated_at / snapshot_hash / data`.
- API v2 сохраняет envelope v1 для чтения и добавляет envelope команд (§3).
- **Логическое имя** команды — `domain.action` (как в ТЗ и #76). **Внешняя функция Moodle** (если команда
  публикуется наружу) — `local_kbacompany_v2_<domain>_<action>` в отдельном выключенном сервисе
  `local_kbacompany_public_v2` (restricted users). Внутренний вызов из Runtime — тот же Command Gateway без токена.
- Каждая команда однозначно отображается в пару RBAC 2.0 `object_type` + `action` (A0_02 §4.2).
- Пути `/api/v2/...` из PR #77 считаются иллюстрацией, а не маршрутами Moodle.

## 2. Транспорт по типу принципала

| Принципал | Как вызывает | Внешняя функция? |
|---|---|---|
| `human` | UI BMS (существующие страницы) или токен Moodle на сервисе v2 | Да, только для команд, разрешённых людям; права — `permission_service` |
| `ai_agent` | Только изнутри `local_bmosruntime`: `agent_command_gateway::execute(PrincipalContext, command, payload)` | **Нет до A4** (решение Q-03); внешние агенты — отдельный ADR |
| `service_integration` | Токен транспортного аккаунта на своём выключенном сервисе (как `local_kbacompany_public_inbound_v1`) | Да, только свои команды |

## 3. Envelope команды

Запрос:

```json
{
  "api_version": "2.0",
  "command": "work.propose",
  "company_uuid": "…",
  "principal_uuid": "…",
  "agent_run_uuid": "…",
  "idempotency_key": "run:<uuid>:step:3",
  "correlation_id": "…",
  "expected_revision": 4,
  "target": {"type": "work", "uuid": "…"},
  "payload": {"kind": "comment_draft", "task_uuid": "…", "body": "…"}
}
```

Ответ успеха:

```json
{
  "api_version": "2.0", "command": "work.propose", "status": "executed",
  "result": {"proposal_uuid": "…", "target_revision": 5},
  "audit_uuid": "…", "idempotent_replay": false
}
```

Другие статусы: `pending_approval` (с `approval_uuid`, с A2; повтор того же ключа возвращает тот же запрос),
`rejected`. Ответы на повтор по состояниям ключа — A0_03 §2.1.

**Неизменяемое намерение.** `command`, `company_uuid`, `principal_uuid`, `target`, `expected_revision` и `payload`
вместе образуют намерение; его digest (A0_03 §2.3) сравнивается при повторе. `agent_run_uuid` и `correlation_id`
— поля доставки и в digest не входят.

Ответ ошибки (машинно-читаемый; текст без внутренних деталей):

```json
{"api_version": "2.0", "status": "rejected", "error": {"code": "stale_revision", "retryable": false}, "audit_uuid": "…"}
```

**Коды ошибок:** `unauthenticated`, `principal_inactive`, `kill_switch`, `company_mismatch`, `sponsor_inactive`,
`no_occupancy`, `function_not_allowed`, `no_grant`, `grant_expired`, `delegation_inactive`, `object_forbidden`,
`invalid_state`, `stale_revision`, `idempotency_conflict`, `risk_forbidden`, `approval_unavailable` (R3 в A1),
`attribution_gate_closed` (запись в существующий объект до ворот W1), `rate_limited` (retryable), `budget_exceeded`,
`validation_failed`, `not_found` (не раскрывает существование чужого объекта — тот же ответ, что `object_forbidden`),
`internal_error` (retryable, без побочного эффекта).

## 4. Каталог команд и риск-классы

Классы: **R0** — чтение; **R1** — черновик/предложение в собственных таблицах BMOS, без изменения канонических
данных; **R2** — обратимая запись в собственной зоне агента, видимая людям (свой рабочий элемент, свой отчёт,
прямой комментарий); **R3** — изменение чужой работы; **R4** — для нечеловеческих принципалов запрещено (A0_03 §4);
для людей R4 означает «только human»: такие команды проверяются существующими человеческими правами
(`permission_service::can_manage_organization` и предикатами сервисов), а не цепочкой A0_03 §2.

**Решения, которые меняют каталог (Q-09, REV-01, REV-05):**
- В A1 агенту разрешены только R0, R1 и R2 над **собственными** объектами (`work.complete`). R3 в A1 отклоняется
  `approval_unavailable` без исполнения и без побочных эффектов. Approval-поток — A2.
- Комментарии агента сначала только предложения (`work.propose` с `kind=comment_draft`); человек публикует их сам
  от своего имени или отклоняет. Прямой `task.comment` (R2) включается только после ворот атрибуции W1
  (A0_06 §5.1) **и** отдельного решения владельца.
- Собственный результат агента (`work.complete`, свой отчёт) не меняет каноническую задачу: ни статус, ни поля, ни
  `assigneeid`.

Колонка **Пара** — `object_type/action` и её статус в коде (A0_02 §4.2): **К** применяется для людей, **С** есть в
словаре, но не применяется, **П** проектная. `task/comment` и `task/create` — **К**: `task_service` проверяет их
через `access_role_service::can` (company scope). Колонка **Проверка** — чем проверяется команда сейчас; для
человеческого вызова указан действующий предикат.

| Команда | Кто может | Риск для агента | Пара | Проверка | Этап | Примечание |
|---|---|---|---|---|---|---|
| `positions.list` | human, ai_agent, service_integration | R0 | `org_structure/view` (С) | human — как Public API v1; принципал — грант | A1 | `public_api_service::positions_snapshot`; без ПДн людей |
| `occupancy.read` | human, ai_agent (свой пост) | R0 | `org_structure/view` (С) | human — членство в компании `company_context_service::context` (как `position_service::positions_context`); принципал — грант + `position` | A1 | Новое `OccupancyView` |
| `occupancy.assign` | **только human** (два лица для `mode=agent`) | R4 | `org_structure/edit` (С) | `can_manage_organization` | A1 | Назначает агента на пост |
| `occupancy.revoke` | **только human** | R4 | `org_structure/edit` (С) | `can_manage_organization` | A1 | Немедленное действие |
| `agents.register` | **только human** | R4 | `agent/create` (П) | `can_manage_organization` | A1 | Принципал `draft` + определение |
| `agents.disable` | **только human** (спонсор или менеджер) | R4 | `agent/edit` (П) | спонсор или `can_manage_organization` | A1 | Kill switch уровня агента |
| `agents.grants.read` | human; ai_agent — только свои | R0 | `agent/view` (П) | human — `can_manage_organization` или спонсор; принципал — `own` | A1 | |
| `work.list` | ai_agent (свои), human | R0 | `task/view` (К) | принципал — `own`; human — поручивший или руководитель поста | A1 | Новое `work` |
| `work.assign` | **только human** | R4 | `task/edit` (К) | права человека на задачу поста | A1 | Поручить агенту работу по задаче поста |
| `work.propose` | ai_agent | R1 | `task/comment` (К) | грант + `own`/`position` | A1 | Предложение: текст, черновик комментария, ссылки |
| `work.complete` | ai_agent | R2 (own) | `task/edit` (К) | грант + `own` | A1 | Сдаёт свой рабочий элемент; задача не меняется |
| `work.accept` / `work.return` | **только human** (поручивший или руководитель поста) | R4 | `task/approve` (К) | поручивший или руководитель поста | A1 | Приёмка результата |
| `task.read` | ai_agent (`position`, `delegated_team`), human | R0 | `task/view` (К) | human — `task_service::can_view_task`; принципал — грант + `principal_object_acl` (A0_02 §4.3) | A1 (`position`), A3 (`delegated_team`) | Семантика для принципала: компания, пост задачи в scope, задача не личная; `task_service::can_view_task` для принципала не вызывается |
| `task.comment` | ai_agent | R2 | `task/comment` (К) | грант + `principal_object_acl` | После W1 и решения владельца | `task_service::post_discussion_message` + атрибуция; метка «ИИ», без упоминаний |
| `task.propose_update` | ai_agent | R3 | `task/edit` (К) | грант + `principal_object_acl`, затем approval | A2 (в A1 — `approval_unavailable`) | Через approval |
| `task.create_draft` | ai_agent | R1 | `task/create` (К) | грант + `position` | A1 (черновик) / A2 (превращение в задачу через approval) | Канонической задачи нет до утверждения |
| `metrics.read` | ai_agent, human | R0 | `indicator/view` (С) | human — `indicator_registry_service::context` (как `public_api_service::indicators_snapshot`); принципал — грант + `position` (A1) или `delegated_team` (A3) | A1 (`position`), A3 (`delegated_team`) | `indicators_snapshot`, `stat*`; только метрики поста или делегированных постов |
| `daily_report.read` | ai_agent (свои; команды — только `delegated_team`), human | R0 | `daily_report/view` (П) | human — `daily_report_review_service::can_read_report` / `can_read_all` (ADR-0009/0010); принципал — грант + `own` (A2) или `delegated_team` (A3) | A2 (свои), A3 (команда) | Права спонсора не используются (Q-11) |
| `daily_report.create` / `daily_report.submit` | ai_agent (свой отчёт) | R2 (own) | `daily_report/create` (П) | грант + `own` | A2 | Отдельное хранение (Q-08); приёмка только человеком |
| `daily_report.accept` / `daily_report.return` | **только human** | R4 | `daily_report/approve` (П) | правила проверки отчётов ADR-0009/0010 | A2 | Для отчётов агентов; human-отчёты — как сейчас |
| `agent_runs.read` | спонсор, руководитель поста, менеджер; ai_agent — свои | R0 | `agent/view` (П) | human — спонсор, руководитель поста или `can_manage_organization`; принципал — `own` | A1 | Без промптов |
| `feed.post` (из #76) | — | R4 до отдельного решения | `communication/create` (С) | — | — | Внешняя/массовая публикация |

Проектные пары (`agent/*`, `daily_report/*`) добавляются в словарь `access_role_service` отдельным решением вместе
с первой командой, которая их использует. До этого человеческие команды проверяются только колонкой «Проверка».

## 5. Work item (поручение агенту)

`work`: `uuid`, `companyuuid`, `principaluuid` (агент), `occupancyuuid`, `taskuuid` (задача поста, необязательно),
`functionuuid`, `assignedby` (человек), `reviewerid` (человек), `kind` (`draft_report`, `collect_metrics`,
`comment_draft`, …), `status` (`assigned` → `in_progress` → `submitted` → `accepted`/`returned`/`cancelled`/`failed`),
`revision`, `idempotencykey`. Задача и её `assigneeid` не меняются; рабочий элемент — отдельный слой атрибуции
(#76 §A). Предложения агента (`proposal`) хранятся отдельно и ссылаются на рабочий элемент.

## 6. Человек, сервис и агент не равны

- Одна и та же команда проверяет права по типу: человек — `permission_service`; агент — грант + занятость (+
  делегирование); сервис — грант + привязка канала. Нет пути, где агент «наследует» права спонсора или сервис —
  права сопоставленного человека.
- Команды, помеченные «только human», для других типов не зарегистрированы вообще (не «запрещены проверкой», а
  отсутствуют в реестре команд типа).

## 7. Пример (A1): агент предлагает комментарий к задаче поста

1. Runtime: `task.read` (R0) → задача поста.
2. Модель предлагает текст. Runtime: `work.propose` (`kind=comment_draft`, `expected_revision` рабочего элемента,
   ключ `run:R:step:2`).
3. Gateway: шаги 1–15 (A0_03 §2) → запись предложения в собственную таблицу BMOS, атрибуция, outbox
   `bmos.proposal.created` (envelope 1.2). Задача и её обсуждение не меняются.
4. Руководитель видит предложение с меткой «ИИ» и публикует его от своего имени или отклоняет.
5. Повтор того же ключа (даже если рабочий элемент уже сменил ревизию) → тот же `proposal_uuid`,
   `idempotent_replay=true`, если текущие права ещё действуют; после отзыва — отказ.
6. Тот же ключ с другим текстом → `idempotency_conflict`.

## 8. Rate, budget, idempotency — общие правила

- Ключ идемпотентности обязателен для всех команд, кроме R0; хранится с digest неизменяемого намерения (A0_03 §2.3) и сохранённым результатом.
- **Срок хранения (решение Q-15):** не меньше 30 дней **и** не меньше горизонта повтора запуска (максимальное время,
  в течение которого Runtime или approval может повторить команду). Удаление ключа раньше горизонта запрещено.
- Сохранённый результат возвращается только после повторной проверки текущих прав и отзыва (A0_03 §2, шаги 1–8).
- Rate limit — на грант, принципал, компанию; превышение — `rate_limited`, без побочных эффектов.
- Бюджет проверяют только команды, вызывающие модель, и сам Runtime перед вызовом модели.
