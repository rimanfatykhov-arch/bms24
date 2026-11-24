# BMOS-A0. Agent API v2 Contract

**Статус: PROPOSED.** Ни одной функции не создано. Public API v1 (`local_kbacompany_public_v1`, только чтение) не
меняется.

## 1. Имена и сверка с Public API v1

- Public API v1: сервис `local_kbacompany_public_v1`, функции `local_kbacompany_public_<resource>`, envelope
  `api_version / schema_version / resource / company_uuid / workspace_uuid / generated_at / snapshot_hash / data`.
- API v2 сохраняет envelope v1 для чтения и добавляет envelope команд (§3).
- **Логическое имя** команды — `domain.action` (как в ТЗ и #76). **Внешняя функция Moodle** (если команда
  публикуется наружу) — `local_kbacompany_v2_<domain>_<action>` в отдельном выключенном сервисе
  `local_kbacompany_public_v2` (restricted users). Внутренний вызов из Runtime — тот же Command Gateway без токена.
- Пути `/api/v2/...` из PR #77 считаются иллюстрацией, а не маршрутами Moodle.

## 2. Транспорт по типу принципала

| Принципал | Как вызывает | Внешняя функция? |
|---|---|---|
| `human` | UI BMS (существующие страницы) или токен Moodle на сервисе v2 | Да, только для команд, которые разрешены людям; права — `permission_service` |
| `ai_agent` | Только изнутри Agent Runtime: `agent_command_gateway::execute(PrincipalContext, command, payload)` | **Нет** в A1–A3 (Q-03) |
| `service_integration` | Токен транспортного аккаунта на своём выключенном сервисе (как `local_kbacompany_public_inbound_v1`) | Да, только свои команды |

## 3. Envelope команды

Запрос:

```json
{
  "api_version": "2.0",
  "command": "task.comment",
  "company_uuid": "…",
  "principal_uuid": "…",
  "agent_run_uuid": "…",
  "idempotency_key": "run:<uuid>:step:3",
  "correlation_id": "…",
  "expected_revision": 12,
  "target": {"type": "task", "uuid": "…"},
  "payload": {"body": "…"}
}
```

Ответ успеха:

```json
{
  "api_version": "2.0", "command": "task.comment", "status": "executed",
  "result": {"comment_uuid": "…", "target_revision": 13},
  "audit_uuid": "…", "idempotent_replay": false
}
```

Другие статусы: `pending_approval` (с `approval_uuid`), `rejected`.

Ответ ошибки (машинно-читаемый; текст без внутренних деталей):

```json
{"api_version": "2.0", "status": "rejected", "error": {"code": "stale_revision", "retryable": false}, "audit_uuid": "…"}
```

**Коды ошибок:** `unauthenticated`, `principal_inactive`, `kill_switch`, `company_mismatch`, `sponsor_inactive`,
`no_occupancy`, `function_not_allowed`, `no_grant`, `grant_expired`, `object_forbidden`, `invalid_state`,
`stale_revision`, `idempotency_conflict`, `risk_forbidden`, `rate_limited` (retryable), `budget_exceeded`,
`validation_failed`, `not_found` (не раскрывает существование чужого объекта — тот же ответ, что `object_forbidden`),
`internal_error` (retryable, без побочного эффекта).

## 4. Каталог команд и риск-классы

Классы: **R0** — чтение; **R1** — черновик/предложение без изменения канонических данных; **R2** — обратимая
запись в своей зоне (свой комментарий, свой отчёт); **R3** — изменение чужой работы, только через `ApprovalRequest`;
**R4** — запрещено агенту (A0_03 §4).

| Команда | Кто может | Риск для агента | Основа в коде | Примечание |
|---|---|---|---|---|
| `positions.list` | human, ai_agent, service_integration | R0 | `public_api_service::positions_snapshot`, `position_service` | Только активные посты компании; без ПДн людей |
| `occupancy.read` | human, ai_agent (свой пост) | R0 | новое `OccupancyView` | |
| `occupancy.assign` | **только human** (`can_manage_organization`) | R4 | новое | Назначает агента на пост |
| `occupancy.revoke` | **только human** | R4 | новое | Немедленное действие |
| `agents.register` | **только human** | R4 | новое | Создаёт принципал `draft` + определение |
| `agents.disable` | **только human** (спонсор или менеджер) | R4 | новое | Kill switch уровня агента |
| `agents.grants.read` | human; ai_agent — только свои | R0 | новое | |
| `work.list` | ai_agent (свои), human | R0 | новое `work` | |
| `work.assign` | **только human** | R4 | новое | Поручить агенту работу по задаче поста |
| `work.propose` | ai_agent | R1 | новое | Предложение результата: текст, ссылки, вложение-черновик |
| `work.complete` | ai_agent | R2 | новое | Отмечает свой рабочий элемент сданным на проверку; задача не меняется |
| `work.accept` / `work.return` | **только human** (поручивший или руководитель поста) | R4 | новое | Приёмка результата |
| `task.read` | ai_agent (scope `position`), human | R0 | `task_service::can_view_task`, `row_dto` | Только задачи с `dutypositionuuid` поста занятости |
| `task.comment` | ai_agent | R2 | `task_service::post_discussion_message` + атрибуция | Метка «ИИ», упоминания отключены |
| `task.propose_update` | ai_agent | R3 | `task_service::save/transition` через approval | Изменение статуса/срока/описания только после утверждения человеком |
| `task.create_draft` | ai_agent | R1 | новое | Черновик, канонической задачи нет до утверждения |
| `metrics.read` | ai_agent, human | R0 | `public_api_service::indicators_snapshot`, `stat*` | Только метрики поста/компании по гранту |
| `daily_report.read` | ai_agent (свои), human | R0 | `daily_report_*` | Агент не читает отчёты людей без отдельного гранта (Q-11) |
| `daily_report.create` / `daily_report.submit` | ai_agent (свой отчёт) | R2 | A2, см. Q-08 | Приёмка только человеком |
| `daily_report.accept` / `daily_report.return` | **только human** | R4 | `daily_report_review_service::decide` | |
| `agent_runs.read` | спонсор, руководитель поста, менеджер; ai_agent — свои | R0 | новое `agentrun` | Без промптов |
| `feed.post` (из #76) | — | R4 до отдельного решения | — | Внешняя/массовая публикация |

## 5. Work item (поручение агенту)

`work`: `uuid`, `companyuuid`, `principaluuid` (агент), `occupancyuuid`, `taskuuid` (задача поста, необязательно),
`functionuuid`, `assignedby` (человек), `reviewerid` (человек), `kind` (`draft_report`, `collect_metrics`,
`prepare_comment`, …), `status` (`assigned` → `in_progress` → `submitted` → `accepted`/`returned`/`cancelled`/`failed`),
`revision`, `idempotencykey`. Задача и её `assigneeid` не меняются; рабочий элемент — отдельный слой атрибуции (#76 §A).

## 6. Человек, сервис и агент не равны

- Одна и та же команда проверяет права по типу: человек — `permission_service`; агент — грант + занятость; сервис —
  грант + привязка канала. Нет пути, где агент «наследует» права спонсора или сервис — права сопоставленного человека.
- Команды, помеченные «только human», для других типов не зарегистрированы вообще (не «запрещены проверкой», а
  отсутствуют в реестре команд типа).

## 7. Пример: агент комментирует задачу поста

1. Runtime: `task.read` (R0) → задача поста, `revision=12`.
2. Модель предлагает текст. Runtime: `task.comment` c `expected_revision=12`, ключ `run:R:step:2`.
3. Gateway: шаги 1–14 (A0_03 §2) → запись комментария через канонический сервис, атрибуция, outbox
   `communication.message.created` с полями актора (envelope 1.2).
4. Повтор того же ключа → тот же `comment_uuid`, `idempotent_replay=true`.
5. Тот же ключ с другим текстом → `idempotency_conflict`.

## 8. Rate, budget, idempotency — общие правила

- Ключ идемпотентности обязателен для всех команд, кроме R0; хранится с digest payload; срок хранения ≥ срок
  повтора запуска (Q-15).
- Rate limit — на грант, принципал, компанию; превышение — `rate_limited`, без побочных эффектов.
- Бюджет проверяют только команды, вызывающие модель, и сам Runtime перед вызовом модели.
