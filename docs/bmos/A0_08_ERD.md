# BMOS-A0. ERD

**Статус: PROPOSED.** Ревизия 2 (25.11.2026): добавлены `wpdeleg`, `proposal`, `cmdidem`, `logtable/logid` в `wpattr`. Существующие таблицы — как в `main` 62fdc49 (повторно сверено с 323f948: схема не менялась). Новые помечены `(P)` и **не утверждены как DDL**.
Все таблицы — с префиксом `local_kbacompany_`, кроме `local_kbaai_*`.

```mermaid
erDiagram
  company ||--o{ member : "uuid"
  company ||--o{ position : "companyuuid"
  position ||--o{ posassign : "positionuuid (люди)"
  position ||--o{ poscover : "positionuuid (замещение людей)"
  position ||--o{ task : "dutypositionuuid"
  member }o--|| moodle_user : "userid"
  task ||--o{ tasklog : "taskuuid"

  company ||--o{ wprincipal_P : "companyuuid"
  wprincipal_P ||--o{ wpaudit_P : "principaluuid"
  wprincipal_P ||--o{ wpgrant_P : "principaluuid"
  wprincipal_P }o--o| moodle_user : "userid (только human) / transportuserid (только service_integration)"
  wprincipal_P }o--|| moodle_user : "sponsoruserid"
  position ||--o{ posoccup_P : "positionuuid (нечеловеческие)"
  wprincipal_P ||--o{ posoccup_P : "principaluuid (ai_agent)"
  posoccup_P ||--o{ posoccuplog_P : "occupancyuuid"
  wpgrant_P }o--o| posoccup_P : "occupancyuuid"
  wprincipal_P ||--o{ wpdeleg_P : "principaluuid (помощник)"
  wpdeleg_P }o--|| moodle_user : "delegatoruserid (руководитель)"
  wpgrant_P }o--o| wpdeleg_P : "delegationuuid"

  wprincipal_P ||--|| agentdef_P : "principaluuid"
  agentdef_P ||--o{ agentdefrev_P : "defuuid"
  agentdefrev_P }o--|| kbaai_modelprofile_P : "modelprofileid+revision"
  agentdef_P ||--o{ agentrun_P : "defuuid"
  agentrun_P ||--o{ agentrunstep_P : "runuuid"
  posoccup_P ||--o{ work_P : "occupancyuuid"
  work_P }o--o| task : "taskuuid"
  agentrun_P }o--o| work_P : "workuuid"
  work_P ||--o{ proposal_P : "workuuid"
  wprincipal_P ||--o{ cmdidem_P : "principaluuid"

  agentrun_P ||--o{ approvalreq_P : "agentrunuuid"
  approvalreq_P }o--|| task : "targetuuid (пример)"

  company ||--o{ aibudget_P : "companyuuid"
  aibudget_P ||--o{ aireserve_P : "budgetuuid"
  agentrun_P ||--o{ aireserve_P : "runuuid"
  agentrun_P ||--o{ aiusage_P : "runuuid"

  wprincipal_P ||--o{ wpattr_P : "actorprincipaluuid"
  wpattr_P }o--o| eventout : "correlationid"
  wpattr_P }o--o| tasklog : "logtable+logid (после W1)"
```

## Ключи и уникальность (проект)

| Таблица (P) | Уникальные ключи | Индексы с компанией первой |
|---|---|---|
| `wprincipal` | `uuid`; `companyuuid+userid` для `human` (через частичное правило в сервисе — Moodle не поддерживает partial index) | `companyuuid, principaltype, status` |
| `wpaudit` | `principaluuid+revision` | `companyuuid, timecreated` |
| `wpgrant` | `uuid` | `companyuuid, principaluuid, status` |
| `wpdeleg` | `uuid` | `companyuuid, principaluuid, status`; `companyuuid, delegatoruserid, status` |
| `posoccup` | `uuid` | `companyuuid, positionuuid, status`; `companyuuid, principaluuid, status` |
| `posoccuplog` | `occupancyuuid+revision` | |
| `agentdef` | `uuid`; `principaluuid` | `companyuuid, status` |
| `agentdefrev` | `defuuid+revno` | |
| `agentrun` | `uuid`; `companyuuid+idempotencykey` | `companyuuid, principaluuid, status`; `companyuuid, timecreated` |
| `agentrunstep` | `runuuid+stepno` | |
| `work` | `uuid`; `companyuuid+idempotencykey` | `companyuuid, principaluuid, status` |
| `proposal` | `uuid` | `companyuuid, workuuid`; `companyuuid, status` |
| `approvalreq` | `uuid`; `companyuuid+requesterprincipaluuid+idempotencykey`; `executionaudituuid` (уникален, если заполнен) | `companyuuid, status, approveruserid`; `status, expiresat` |
| `aibudget` | `uuid` | `companyuuid, scope, scopeuuid` |
| `aireserve` | `uuid`; `runuuid+stepno+attemptno+budgetuuid` | `status, expiresat` (включая `uncertain`) |
| `aiusage` | `uuid`; `runuuid+stepno+attemptno` | `companyuuid, timecreated`; `companyuuid, status` |
| `wpattr` | `uuid`; `logtable+logid` (если заполнено) | `companyuuid, objecttype, objectuuid`; `companyuuid, actorprincipaluuid, timecreated` |
| `cmdidem` (ключи идемпотентности команд: digest, `status` `pending_approval`/`executed`/`failed`/`rejected`/`expired`/`cancelled`, `approvaluuid`, сохранённый результат, `expiresat` ≥ max(30 дней, горизонт повтора, срок approval)) | `companyuuid+principaluuid+idempotencykey` | `expiresat`; `approvaluuid` |
| `local_kbaai_modelprofile` | `id+revision` | |

Все выборки — с `companyuuid` в условии SQL (AGENTS.md: не фильтровать в PHP после выборки всех компаний).

## Связь с ADR-0012 (#179)

`local_kbacompany_inbconn` (#179) в отдельном срезе A1-12 (после приёмки контура MAX) получает ссылку на `wprincipal(service_integration)`; `principaluserid` →
`wprincipal.transportuserid`. Поведение импорта не меняется. Подробно — ADR-0013 §6 и A0_10 §3.
