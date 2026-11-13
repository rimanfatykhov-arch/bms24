# BMOS: независимый review контрактов межпостовой передачи

Статус: **PROPOSED / BMOS-A0**, 13.11.2026. Agent 4, [Issue #84](https://github.com/rimanfatykhov-arch/bms24/issues/84), основание — [#76](https://github.com/rimanfatykhov-arch/bms24/issues/76). Это проект контракта, не реализованная Agent Platform и не разрешение на deployment.

Проверенная база `main`: **4e5c7f31797f7788bb317916f913094d64b5c26e**; `local/kbacompany/version.php`: `2026111100`, release `1.1.0-rc29`, requires `2022041900`. Review [PR #77](https://github.com/rimanfatykhov-arch/bms24/pull/77) выполнен по **7dd475f11cce0c2215823d9be2361f8476284af5** (открытый Draft). Номер сборки в старых вводных документах не заменяет фактический version.php. Production и фактическая схема работающей БД не исследовались: ниже проверка исходников XMLDB и служб, а не заявление о состоянии deployment.

## 1. Решение и границы

Функциональный пост остаётся существующим Position. Его может занимать человек, ИИ-агент или гибридная команда. Hybrid — режим совместной работы нескольких типизированных исполнителей, **не новый тип личности**. У каждого действия один проверенный фактический WorkPrincipal; у результата один ответственный пост и явно назначенный человек, отвечающий за агентное исполнение.

Принять направление PR77, но до runtime устранить пробелы: связать контракт с реальными ревизиями SIPOC, отделить штатные назначения от агентных допусков, формализовать согласование и однократность бизнес-эффекта. Не создавать второй Position, Staffing, BPMN, документный архив или интеграционный outbox. Не записывать principal UUID/agent ID в `userid`, `assigneeid`, `creatorid`, `reviewerid`, `approvedby` и другие человеческие поля.

Timeweb AI Gateway — первый возможный адаптер ModelProvider. Он не назначает исполнителей, не принимает результаты, не выдаёт BMS-права. В этом review нет проверки его текущих возможностей, цен или договоров; это отдельный provider contract check перед интеграцией. MAX transport, сообщения и работа Agent 5 вне scope.

## 2. Сверка с кодом: существует и предлагается

Все ссылки в таблице закреплены за проверенным SHA; имена таблиц приведены без Moodle-префикса.

| Область и точный источник | Реальные данные и entry points | Архитектурный вывод |
|---|---|---|
| [Position](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/position_service.php#L118), [XMLDB](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/db/install.xml#L161) | `local_kbacompany_position`: `uuid, companyuuid, branchuuid, orgnodeuuid, purpose, ismanager, plannedcount, active`; `create_position`, `directory_record`, `directory_save`, `assign_employee` | Пост не содержит всю модель ЦКП/метрик/руководителя в одной строке. Объединять связанные канонические объекты в read model; не изобретать manager/CKP columns как существующие. |
| [Staffing](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/staffing_service.php#L645), [назначения](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/db/install.xml#L231) | `posassign`: `id, companyuuid, positionuuid, slotuuid, userid, assignmenttype, assignmentfte, effectivefrom, effectiveto, active`; `staffplan/staffline/staffslot`; `approve_plan`, `save_slot_assignment`, `end_slot_assignment` | Уже есть интервалы, первичные назначения, лимит FTE и блокировка slot. `posassign` не имеет UUID/revision; часть изменений обновляет строку. Не объявлять её immutable и не дублировать человеческую занятость в новой таблице. |
| [Coverage](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/position_coverage_service.php#L257) | `poscover`: `positionuuid, primaryid, delegateid, scope, startdate, enddate, status, revision, approvedby`; `poscovaudit`; `request`, `transition`, `effective_executor` | Это временное человеческое замещение. Resolver проверяет approved, срок, активный пост и primary `posassign`; возвращает **int user ID**. Не использовать для агента или передачи approval authority. |
| [Process](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/process_service.php#L324), [SIPOC/BPMN](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/process_model_service.php#L7) | `process(uuid, revision, companyuuid, ownerorgunituuid, productuuid, status)`; `procmodel(id, processuuid, revision, processrevision, changeversion, status)`; `procelem(modelid, uuid, elementtype, executortype, executoruuid, activityuuid, productuuid, outputtext)`; `procflow`, `procraci`, `semrel` | Supplier/customer — semantic relations **orgunit ↔ process**, не готовые маршруты post A → post B. Executor допускает `orgunit/position/role`. BPMN XML — layout над моделью; это не execution engine. |
| [Публикация модели](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/process_model_service.php#L956) | `ensure_draft`, `save_element`, `save_flow`, `save_raci`, `publish`; draft optimistic `changeversion`, published immutable; `procelem` unique `(modelid, uuid)` | Одного process UUID или step UUID недостаточно. Нужны process revision **и** model revision. Публикация проверяет структуру/достижимость, но не machine-readable вход/выход, агентный ACL и runtime acceptance. |
| [Функции](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/db/install.xml#L969) | `function`: `uuid, revision, positionuuid, productuuid, sourceprocessuuid, sourceactivityuuid, sourcemodelrevision`; `poswork`: ссылки на `functionuuid/functionrevision` | Переиспользовать связь функции, поста, продукта и исходной activity. Не выводить её только по подписи lane или названию должности. |
| [Существующий ProductFlow](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/flow_service.php#L5) | `local_kbacompany_flow`: `uuid, companyuuid, fromuuid, touuid, productuuid, criteria, handoff, revision, snapshot, status`; `command(save/publish/archive)` | Уже есть **дизайн** передачи продукта. Endpoints относятся к `acobj` kind=function и `wfapprove` snapshots, а не к Position UUID и не напрямую к `local_kbacompany_function`. Сопоставлять явно; нельзя создать второй такой каталог или считать `active` фактом принятия результата. |
| [Task](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/task_service.php#L1068) | `task`: `uuid, companyuuid, assigneeid, dutypositionuuid, creatorid, reviewerid, result, revision, sourcekind/sourceuuid/sourcerevision/periodkey`; `create`, `effective_assignee`, `transition`, `tasklog` | Task остаётся канонической человеческой задачей. `submit` без reviewer может сразу дать `done`; это **не** handoff acceptance. История/рецензент/attachments не переходят агенту по occupancy. |
| [Документы](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/document_service.php#L19), [file route](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/lib.php#L100) | `document_service`: released immutable content/files, `source_preview`, `create_from_stp`, review/approve/publish, `file_for_download`; `document_revision` route; отдельные `task_attachment/task_result` | OutputArtifact — типизированная ссылка на результат, не копия document lifecycle. Переиспользовать immutable document revision и её ACL; обычное изменяемое вложение задачи не считать sealed artifact. |
| [Public API](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/external/public_api.php#L159), [service](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/public_api_service.php#L18) | `company/employees/organization/positions/indicators/budgets` snapshots; external functions use Moodle actor and `viewowncompany`; v1 read-only, service registration in `db/services.php` | Нет agent-auth или handoff write endpoint. `indicators_snapshot` — определения/связи, не гарантированный API фактических значений метрик. |
| [Outbox](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/integration_event_service.php#L45), [envelope](https://github.com/rimanfatykhov-arch/bms24/blob/4e5c7f31797f7788bb317916f913094d64b5c26e/local/kbacompany/classes/local/platform_event_envelope.php#L36) | `eventout`: unique `eventuuid/idempotencykey`, envelope, status pending/error/done, attempts; `publish`, `pending_events`, complete/fail/retry; envelope 1.1 | Переиспользовать. Ключ включает event type/company/entity/revision/**correlation и serialized payload**. Это не клиентский command receipt и не downstream exactly-once. `pending_events` — внутренняя глобальная очередь, не tenant inbox API. |

Поиск WorkPrincipal/PositionOccupancy/OutputArtifact/ProcessHandoff в `classes` и `db` не нашёл реализации. Новые runtime-концепты ниже не следует выдавать за существующие таблицы. Планируемые ограничения требуют отдельной XMLDB/upgrade-проверки на MariaDB и Percona.

## 3. Principal и occupancy без дублирования Staffing

**WorkPrincipal** (новая identity/grant boundary): `principal_uuid`, `company_uuid`, `type=human|ai_agent|service_integration`, `status=active|suspended|revoked`, `auth_epoch`, `revision`. Для human обязательна ссылка `moodle_user_id`, уникальная в компании; для остальных она NULL. Для ai_agent обязательны `agent_definition_ref` и `accountable_user_id` существующего активного члена компании. Credentials reference хранится отдельно от публичного DTO. Principal UUID не удостоверяет запрос: actor выводится сервером из токена/сессии; клиент не выбирает actor type или sponsor.

Один Moodle user может иметь principal в разных компаниях; non-human credentials/grants не переходят между компаниями. Sponsor — ответственность, **не** impersonation и не автоматический approver. Service integration не становится occupant автоматически; для MVP его роль — доставить событие, не выполнить/принять работу поста.

**PositionOccupancy** — объединённый read/authorization model:

- Human source of truth — `posassign`; adapter выдаёт tagged ref `human_assignment:<id>` только внутри company boundary. Не создавать вторую редактируемую запись назначения. Совпадение `userid`, post, сроков и company проверяется при каждом действии.
- Новый non-human occupancy grant: `occupancy_uuid, company_uuid, position_uuid, principal_uuid, revision, valid_from, valid_until, function_refs[], action_scopes[], capacity_policy, accountable_user_id, approved_by_user_id, approved_at, status`. Только авторизованный human назначает/отзывает. Одна revision неизменяема; смена scope/срока создаёт следующую с ожидаемой предыдущей revision.
- Human `posassign` не версионирован: в evidence сохранять fingerprint значимых полей и снимок полномочий; это обнаруживает drift, но не заменяет будущую полную историю Staffing. Перепроверять живой row, membership и coverage. Не обещать восстановление всей прежней occupancy только из текущей строки.
- Для нового grant время UTC, полуоткрытый интервал `[from, until)`; nullable until = бессрочно по policy. Legacy `effectiveto` включителен, `0` означает открытый конец; adapter сохраняет эту семантику (не менять существующие даты/UTC-day coverage).
- Hybrid вычисляется по активным human + agent grants. Работа назначается конкретному principal и occupancy revision; broadcast всем occupant запрещён. Пересекающиеся grants допустимы только при явном распределении функций/операций. Agent capacity — лимиты работы/вызовов, не штатный FTE и не зарплатный budget.
- Human vacancy и machine coverage показываются отдельно: агент не закрывает штатную вакансию в `staffslot`. Замещение через `poscover` не создаёт agent grant, file ACL или право приёмки.

## 4. Контракт утверждённого маршрута

Предлагаемый **ProcessStepContract** — executable overlay над существующей опубликованной моделью, а не второй BPMN editor. Идентичность: `(company_uuid, contract_uuid, contract_revision)`, immutable после human approval. Минимальные поля:

| Поля | Обязательное правило |
|---|---|
| `source_model`, `target_model` | Каждое: `process_uuid, process_revision, model_revision, activity_uuid`. Resolver находит ровно один published `procmodel` и activity в нём; draft `changeversion` не runtime revision. Для MVP оба endpoints в одном model. |
| `output_element_uuid`, `input_element_uuid` | Существующие output/input `procelem` в соответствующих model; проверка `activityuuid`, продукта и согласованных связей. Отсутствующая явная связь блокирует approval, не угадывается LLM. |
| `from_position_uuid`, `to_position_uuid`, `function_refs` | Активные посты той же компании; подтверждённая привязка activity/function к постам. Для role/orgunit executor human явно фиксирует post binding в overlay. |
| `design_flow_ref` | Опциональная ссылка на существующий ProductFlow UUID/revision/snapshot hash и явное mapping его acobj endpoints. Требуется при использовании такого дизайна; равенство UUID разных видов объектов не mapping. |
| `output_schema`, `input_schema` | Immutable schema IDs, versions, hashes; producer output должен удовлетворять **обеим** схемам. MVP без автоматических преобразований; преобразование позднее — новый auditable artifact. |
| `criteria[]` | Stable criterion ID/version, kind structural/deterministic/human, severity blocking/advisory, parameters, evidence requirements; без исполняемого кода/SQL/произвольных URL из модели. |
| `data_policy`, `acceptance_policy` | Allowlist полей/типов файлов, classification, retention reference, receiver scopes, named human acceptance authority, separation-of-duty rule. |
| `sla`, `advance_policy` | Absolute expiry при создании handoff, retry cap, escalation human, allowlisted next action, one transition key. Gateway conditions — явно выбранное human правило; MVP один линейный edge без fork/join/loops. |
| `approved_by_user_id`, `approved_at`, `content_hash`, `status` | Approval связан с hash всей revision; изменение схемы, маршрута или критериев требует нового approval. |

`published` process model необходим, но недостаточен для `approved` contract. Нельзя автоматически превращать старые SIPOC descriptions/criteria text в executable policy. Существующие `flow.criteria/handoff` переиспользуются как human-readable основание; machine criteria overlay дополняет их.

Изменение design/model не переключает текущий process run на latest. Run фиксирует revisions при старте. Старый approved контракт разрешён до explicit retirement; `suspended/revoked` немедленно блокирует новые передачи/advance даже для закреплённого run. Migration run — отдельное human решение с mapping входов и новым audit; никогда silent rebase.

## 5. OutputArtifact и ProcessHandoff

**OutputArtifact revision**: `(company_uuid, artifact_uuid, artifact_revision)`, `schema_ref/version/hash`, `payload_ref`, `content_sha256`, `media_type`, `byte_length`, `classification`, `producer_principal_uuid`, `producer_occupancy_ref/revision_or_fingerprint`, `producer_position_uuid`, `accountable_user_id`, `process_run_uuid`, source model tuple, optional `task_uuid/task_revision`, optional `agent_run_uuid`, `created_at`, `supersedes_revision`.

Mutable draft отделён от sealed revisions. При submit сервер сохраняет immutable payload/manifest, вычисляет SHA-256 **фактических сохранённых bytes**, проверяет schema и фиксирует версию validator. JSON: сохранять конкретные UTF-8 bytes, запрещать duplicate keys/non-finite numbers; hash вычисляется по bytes, не по повторной сериализации клиентом. Файлы: manifest с hash/size/MIME каждого объекта, без публичных bearer URL. Ссылка на released document revision допустима после проверки её неизменяемости и ACL; mutable task result требует отдельного sealed snapshot с provenance. Нельзя автоматически копировать защищённое вложение в более доступный storage.

Проверяемые источники, AI interpretation и неизвестные данные — отдельные поля. Model output не становится фактом по факту schema PASS. Исправление создаёт новую artifact revision и новую submission; старый результат и решение сохраняются. Hash — контроль целостности, не ACL и не доказательство истинности.

**ProcessHandoff**: `handoff_uuid, company_uuid, revision`, `process_run_uuid`, `contract_uuid/contract_revision`, `artifact_uuid/artifact_revision/content_sha256`, endpoint model tuples и posts, `submitted_by_principal_uuid`, producer occupancy snapshot, `recipient_binding`, `state`, `created_at, expires_at`, `correlation_id`, `submission_key`, `gate_evidence_ref`, `approval_ref`, `decision_actor`, `decision_reason`, `supersedes_handoff_uuid`. Pin artifact revision, не только artifact UUID. Decision actor выводится из auth.

`recipient_binding` содержит target post + approved resolver policy; конкретный получатель проверяется при чтении и принятии. Смена occupant после submit допускает нового полномочного получателя только после повторного ACL и записи нового binding revision; producer attribution остаётся прежним. Потеря получателем доступа оставляет work blocked/escalated, не выдаёт полномочия преемнику автоматически.

Новый **ProcessRun/step execution receipt** — минимальный runtime ledger, не ещё один планировщик задач. Run закрепляет contract/model revisions и business period; каждый transition имеет unique `(company, run, contract_revision, step_instance, transition_key)`. Повтор цикла в будущем потребует нового step_instance; не использовать current assignee или task revision как ключ recurring occurrence.

### Проектный command envelope (не существующий API)

```json
{
  "command": "handoff.accept",
  "company_uuid": "11111111-1111-4111-8111-111111111111",
  "handoff_uuid": "22222222-2222-4222-8222-222222222222",
  "expected_revision": 4,
  "idempotency_key": "accept-22222222-v4-01",
  "correlation_id": "pilot-2026-11-13",
  "artifact_revision": 1,
  "contract_revision": 1,
  "decision": {"reason": "Источники и полнота проверены"}
}
```

`accept` доступен только назначенному human acceptor MVP. Request не принимает произвольные `principal/approved_by/next_task_assignee` как authority. Ответ: `command_receipt_uuid, handoff_uuid, revision, state, event_id, advance_status`. Body hash receipt охватывает command + tenant + object + expected revision + payload; correlation — трассировка, не разрешение. Idempotency key ограничен 100 ASCII символами; receipt unique `(company, principal, command, key)`. Повтор с тем же body возвращает прежний результат **после текущей авторизации**; другой body → `IDEMPOTENCY_CONFLICT`. Новое решение требует нового key.

Предлагаемые ошибки: `NOT_FOUND` (включая скрытый чужой объект), `FORBIDDEN/REVOKED`, `REVISION_CONFLICT`, `CONTRACT_STALE`, `SCHEMA_INVALID`, `QUALITY_FAILED`, `APPROVAL_REQUIRED`, `EXPIRED`, `IDEMPOTENCY_CONFLICT`, `TEMPORARY_UNAVAILABLE`. Ни ошибка, ни conflict не создают бизнес-эффекта. HTTP mapping 404/403/409/422/503 — только для будущего facade; Moodle external adapter должен дать эквивалентные typed errors, не заявлять эти URL уже существующими.

## 6. Состояния, однократность и восстановление

Artifact gate и delivery status не смешивать с решением получателя. Предлагаемый handoff lifecycle:

| Из → в | Кто и preconditions | Атомарный результат |
|---|---|---|
| absent → submitted | Producer с output.submit; approved contract; valid occupancy; immutable artifact sealed | Handoff revision 1 + audit + command receipt; никакого next task |
| submitted → validated | Internal validator, все blocking deterministic checks PASS, inputs доступны по policy | Evidence содержит artifact/schema/contract hashes, checker revision и результаты критериев |
| submitted → returned | Blocking quality/schema failure после допустимой подачи | Reason/criterion IDs, без delivery; malformed request отклоняется до создания handoff |
| validated → delivered | Authorized inbox projection, актуальный ACL, контракт не revoked, срок не истёк | Outbox notification; delivered означает доступность входа, не acceptance |
| delivered → needs_human_approval | MVP всегда; разрешённая подача на human gate | ApprovalRequest привязан к handoff revision + artifact hash + contract revision + exact proposed action |
| needs_human_approval → accepted | Human acceptor с актуальным scope, не producer; обязательные критерии PASS; approval не истёк | Decision, audit, command receipt, advance intent и outbox в одной DB transaction |
| delivered/needs_human_approval → returned/rejected | Authorized human; reason обязателен | Returned допускает исправление через **новую** submission; rejected окончателен для этой передачи |
| nonterminal → expired/cancelled | Deadline worker либо human owner; recheck в lock | Terminal audit, uncommitted advance запрещён |

`accepted/returned/rejected/expired/cancelled` terminal. Технический сбой доставки хранится отдельно как retry metadata; он не превращает accepted обратно в submitted. Повторный submit исправленного результата создаёт новый handoff с `supersedes_handoff_uuid`; до этого предыдущая незавершённая submission отменяется под lock. Для одного run/step/edge не допускаются две активные submissions.

Approval не переносится на новую artifact/contract/action revision. Self-approval запрещено: human producer не принимает собственный результат; agent никогда не approve. Human sponsor может принять агентный результат только при отдельном accept grant и если сам не указан producer/coauthor этой revision. Отсутствие независимого acceptor → escalation, не autoaccept. RACI A, `ismanager` и Coverage не являются достаточным grant.

**Transaction protocol:** lock tenant/run-step/handoff в фиксированном порядке → перечитать auth epoch/grants, срок, revisions, receipt → deterministic checks → domain transition + immutable audit + receipt + `integration_event_service::publish` → commit. Ошибка audit/outbox откатывает всё. Не выполнять provider/network calls внутри DB transaction. Для revoke применяется тот же authorization fence/lock (или CAS epoch) и повторная проверка непосредственно перед commit; запрос, линеаризованный после revoke, не может пройти со старым grant.

At-least-once worker получает reference-only event. Consumer хранит unique `(consumer_id, company_uuid, event_id)` receipt и отдельный business transition receipt. Оба нужны: один бизнес-переход может прийти разными событиями. `publish` сейчас делает read-before-insert; unique collision надо корректно обработать вне aborted transaction/повторить весь command с новым чтением, не считать concurrent publish уже доказанно безопасным.

Envelope 1.1 сохранить; actor/provenance добавлять versioned metadata/reference в payload, не менять старый idempotency алгоритм для всех событий. Использовать стабильные correlation и сохранённую сериализацию payload на retry. В eventout только reference/hash/status, без prompt, файла или частных данных. Не использовать `syncout` KBA Connect для BMOS.

Acceptance не гарантирует completed advance. Worker с узким service grant повторно проверяет human approval, occupancy/ACL, contract status и validity. При отказе — `advance_status=blocked` и human escalation, accepted evidence остаётся. Для MVP next task создаёт **реальный авторизованный human** через `task_service::create`; adapter атомарно сохраняет transition receipt + task UUID и вызывает канонический сервис в согласованном lock/transaction порядке. Не вызывать его с agent ID или sponsor ID из фонового процесса. Автоматический non-human task command требует отдельного typed-authority расширения сервиса и review.

Crash до commit → ничего; после commit до ACK → replay receipt; после создания задачи до ACK → тот же task UUID. Результат — однократный эффект при доказанных locks/constraints, **не** обещание exactly-once transport. Retry: только transient failure, exponential backoff+jitter, максимум 5 попыток и не позже expires_at; затем human queue. Revoked/forbidden/schema/stale не retry без нового решения. Provider timeout не означает, что повторная inference бесплатна или идемпотентна; новый attempt записывается, бизнес submission key остаётся стабильным.

## 7. Авторизация, файлы и контроль руководителя

Каждый read/write/download/export/worker command проверяет company **в запросе к хранилищу**, authenticated principal status/epoch, membership либо agent grant, действующий post/occupancy, function/action scope, object/field/file ACL и policy. Для human дополнительно Moodle capability и канонические BMS domain permissions. Для non-human отдельный bounded authentication adapter; v1 Moodle token не превращать в agent token и не выдавать site-admin пользователя.

Передача не расширяет source ACL. Для receiver предъявляется только разрешённая проекция; если required input содержит недоступное поле, передача блокируется или producer создаёт явно разрешённый обезличенный artifact. Нельзя вырезать обязательные поля и пометить incomplete projection accepted. Каждый download снова проверяет source object + artifact + receiver grant; hash/file ID и знание URL не право доступа. Existing human `pluginfile` остаётся human route; будущий agent download facade обязан сохранить эквивалентный tenant/object check. Revoke останавливает новые reads, но не может стереть уже законно полученные bytes; это учитывается в минимизации и retention.

Руководитель видит company/post inbox, pinned versions, producer/sponsor, AI provenance, критерии PASS/FAIL, diff новой submission, expiry, retry и blocked advance. Действия: accept, return с критериями, reject, cancel незавершённую передачу, suspend agent/contract, запросить переназначение. Никакого «override ACL» или изменения accepted payload. Отзыв принятого бизнес-решения — новая компенсирующая запись и impact review для уже запущенных downstream tasks; история не удаляется. Просмотр руководителя тоже ограничен object/file policy.

Отдельный append-only BMOS audit связывает company, actor type/principal, accountable human, occupancy evidence, contract/artifact hashes, before/after revision, command/event/correlation, auth policy revision, decision/reason и outcome. `tasklog/aclog/poscovaudit` сохраняются; их человеческий actorid не переопределяется. AgentRun — provenance, eventout — доставка; ни один не заменяет audit. Записи deny содержат минимум метаданных без раскрытия чужих objects. Доступ только аудиторам в tenant scope; deletion/retention/legal hold отдельной policy. Транзакционная append-only модель не равна криптографической tamper-proof гарантии: независимое архивирование/подпись требует отдельной реализации.

## 8. Тестовая матрица (план, НЕ результаты PASS)

Общая fixture: компании A/B, два поста A1/A2, human producer/acceptor/restricted manager, agent X и integration Y; два approved contract revisions, private file, истёкший grant. Каждому deny сопоставить позитивный контроль. На deny проверять отсутствие task/result/permission/outbox/receipt мутаций, кроме минимального security audit.

| ID | Сценарий | Ожидаемый результат |
|---|---|---|
| I1 | Human + agent + hybrid на одном посту | Раздельные actors; human assignment/FTE без изменений; agent не учитывается как employee |
| I2 | Agent UUID/int передан как assigneeid; spoof sponsor/principal | Reject, нет fake user и impersonation; существующий human task create проходит |
| I3 | Задача без dutypositionuuid, активный coverage | Личная задача не делегируется; исходный assignee/history/attachments неизменны |
| I4 | Human coverage действует/истёк, reviewer права | Effective human executor корректен; approval и file права не наследуются |
| A1 | Компания B угадывает artifact/handoff/file/event UUID A | Query boundary deny для API/UI/export/download; без existence leak |
| A2 | Same company, нет scope/нет occupancy/неверный post | Deny, даже при Moodle capability или RACI A; allow для явного grant |
| A3 | Revocation principal/token/membership/occupancy/tool/contract после enqueue | Worker/read/download/accept deny; old receipt не раскрывает данные; queued advance blocked |
| A4 | Revoke одновременно с accept и истечение на границе времени | Fence определяет один порядок; post-revoke commit невозможен; legacy inclusive и новые half-open даты тестируются отдельно |
| A5 | Private attachment, доступ к task есть у producer, у receiver нет | No transfer/leak; разрешённая отдельная sanitized projection проходит |
| A6 | Prompt injection в payload/файле/model output | Данные не меняют grants, contract, next action или approver |
| V1 | Draft model, неверная activity/model revision, orgunit без post binding | Contract approval/submit deny; опубликованная точно связанная модель проходит |
| V2 | Новый model опубликован во время run | Run сохраняет pinned version; explicit revoked contract блокирует дальнейшее действие |
| V3 | Несовместимые input/output schema; отсутствующий criterion evidence | No delivered/accepted; advisory failure виден, blocking failure возвращён |
| V4 | Bytes/hash изменены, document superseded, mutable task file | Hash mismatch deny; pinned immutable bytes разрешены только при живом ACL, не latest lookup |
| S1 | Все разрешённые переходы и все остальные пары state/action | Разрешённые дают +1 revision и audit; остальные deny без эффекта |
| S2 | Agent accept, producer self-approval, sponsor без accept grant | Deny; независимый human acceptor проходит |
| S3 | Approval expired или artifact/contract/action изменён | Старый approval непригоден; новая submission требует нового решения |
| S4 | Return → исправление → accept | Новая immutable artifact revision + handoff; старые decision/reason сохранены |
| C1 | Два accept с одной expected_revision; accept против cancel/expiry | Один winner, другой conflict; один audit transition и advance intent |
| C2 | Replay command тот же key/body; key с другим body; другая company | Тот же receipt; conflict; независимый tenant namespace без утечки |
| C3 | Duplicate/out-of-order events, разный event_id одного transition | Consumer dedup и business receipt дают ровно один next task |
| C4 | Crash до/после commit, после task create до ACK; outbox/audit insert error | Rollback либо replay того же результата, без orphan artifact/дубля task |
| C5 | Concurrent eventout unique collision, correlation/payload retry drift | Без unhandled partial commit; сохранённый envelope replay; drift обнаружен |
| O1 | Provider unavailable/timeout/budget/kill switch | Ограниченные попытки, сохранённый draft, human escalation; нет acceptance или business side effect |
| O2 | Accepted, затем grant отозван до next action | Accepted evidence сохраняется, advance blocked; возобновление только новым authorized решением |
| O3 | Manager UI/read/audit, raw prompt/file/private data | Tenant-scoped минимальные проекции; secrets/сырые prompts отсутствуют |
| M1 | Install/upgrade/rollback flags на MariaDB и Percona | XMLDB/index parity, constraints/locks работают; legacy rows/counts/IDs не изменены |

Переиспользовать regression suites: `process_model_service_test.php`, `process_service_test.php`, `position_coverage_service_test.php`, `position_task_coverage_test.php`, `position_coverage_handover_regression_test.php`, `battle_plan_coverage_service_test.php`, `public_api_service_test.php` в `local/kbacompany/tests/`. Добавить runtime contract/ACL/concurrency tests в будущих implementation PR. Проверять реальные HTTP/file requests и DB races, не ограничиваться unit mocks или happy-path UI.

## 9. Первый рабочий сценарий и slices

**Pilot:** один существующий пост A «аналитик» готовит черновик сводки операционных метрик; пост B с human acceptor получает его как вход проверки и создаёт одну задачу проверки/уточнения. Названия иллюстративны, реальные post UUID выбирает company manager. Нет внешней публикации, финансовых проводок или изменения прав.

Input: только синтетический или явно разрешённый immutable набор `metric_uuid, period_start/end, unit, value, source_ref, observed_at`. Public API v1 не обещает facts feed: сначала fixture, затем отдельный разрешённый facts-read adapter. Output `metrics-review.v1`: те же source refs/period/units, агрегаты, явно маркированный AI summary, anomalies, missing_data; неизвестное значение NULL с причиной, не выдуманное число. Приёмка: все required metrics покрыты или явно missing; units/period совпадают; суммы пересчитаны детерминированно; ссылки доступны; human проверил интерпретацию. Model score не критерий достоверности.

| Slice | Изменения будущего PR | Exit gate |
|---|---|---|
| 0 — A0 decisions | Утвердить identity/auth, human occupancy adapter, source mapping ProductFlow↔SIPOC, exact pilot schema/criteria, независимого acceptor | Закрыты вопросы ниже; согласованный contract example, без runtime |
| 1 — principal foundation | Tenant principal registry, scoped agent grants, occupancy read model, revoke fence, supervisor read view; feature flag default off | I1–I4/A1–A4, legacy API/Staffing regressions; миграции отдельно проверены |
| 2 — deterministic handoff | Contract overlay + sealed artifact references + handoff/audit/receipts, fake producer, human gate, existing outbox adapter | V/S/C matrix на двух DB; ни одного live LLM call |
| 3 — first end-to-end | A draft → A2 inbox → human accept/return → human canonical task create with transition receipt; история/файлы/UI | Один task после retries; rejected cases без эффекта; HTTP/browser/file negative tests |
| 4 — supervised model | Provider-neutral runtime + Timeweb adapter после отдельного provider approval; synthetic then approved minimal context; бюджеты/kill switch | O1–O3, independent security review, tracing без secrets, owner acceptance |
| 5 — дальнейшие возможности | Typed non-human canonical commands, daily-report integration, fanout/join, loops, policies | Новые ADR/tests; не расширять MVP скрыто |

BMOS-A1/A2 нумерация в ADR77 и основном плане различается; до планирования release закрепить единый dependency order, а не обещать готовность по номеру RC. Для первой доставки достаточно slices 1–3 с fake agent; реальный model — slice 4. DailyReport не реализовывать вторым сущностным контуром в этом review: впоследствии он может ссылаться на принятую artifact revision.

Rollout только отдельным разрешённым релизом: additive migrations, feature flag per company off, zero automatic backfill of human assignments, read-only projection сверить со Staffing. Откат — запрет новых commands/provider calls и остановка advance, сохранение evidence/receipts; destructive down-migration не применять к принятой истории. Ни один slice не требует изменения Core/theme или текущего production этим PR.

## 10. Замечания к PR77 и открытые решения

Точные точки review на SHA `7dd475f11cce0c2215823d9be2361f8476284af5`:

1. `docs/architecture/BMOS_AI_CONTROL_LAYER.md:32–33`: PositionOccupancy нельзя вводить как второй источник human staffing. Нужна проекция `posassign` + отдельно non-human grants; `process revision/step UUID` недостаточно без `procmodel.revision` и scoped element UUID. См. §§2–4.
2. Тот же файл `:43`: supplier/customer текущего SIPOC — orgunit semantic relations, ProductFlow уже имеет approved-function design snapshots. Требуется явное overlay mapping; утверждённый SIPOC ещё не содержит автоматический post-to-post runtime contract.
3. Тот же файл `:46–48`: atomic publish и at-least-once не дают однократную следующую задачу. Нужны command + consumer + business transition receipts и reauthorization; `task_service::create` принимает human actor. См. §6.
4. Тот же файл `:53`: линейный перечень состояний не определяет допустимые возвраты, competing accept/cancel, expiry и approval invalidation; применить таблицу переходов §6.
5. `docs/adr/ADR-0007-bmos-ai-control-layer-timeweb-provider-PROPOSED.md:54–57` против `docs/architecture/BMOS_AI_CONTROL_LAYER.md:85–88`: A1/A2 называют разные поставки. Нужен единый порядок prerequisites; provider adapter не означает готовность identity/ACL/handoff.

Открытые решения до implementation:

- Выбрать bounded non-human auth boundary и token ownership: отдельный adapter/broker с principal grants, не маскировка Moodle user. Как atomically синхронизировать revoke epoch между worker и Moodle storage?
- Утвердить exact mapping существующих acobj ProductFlow functions и canonical process functions; неподтверждённые mappings блокируют исполнение. Нужен ли пилоту ProductFlow ref, или достаточно существующей SIPOC-модели с явно утверждённым overlay?
- Подтвердить acceptor policy для компании: кто назначает human accept grant, кто замещает отсутствующего acceptor без Coverage inheritance, separation of duty для малых команд.
- Выбрать sealed JSON storage и retention, переиспользуя released document revisions где возможно; определить privacy export/deletion и legal hold для provenance/audit.
- До автоматического next task решить typed-authority extension канонической службы, lock order и атомарность receipt; MVP использует human command, без sponsor impersonation.
- Согласовать реальные метрики/источники и SLA пилота, unit/period semantics, лимиты schema/files; не переносить assumptions v1 snapshots на фактические значения.

Это блокеры будущего runtime, не препятствия созданию данного независимого documentation PR. Исходники других агентов и документы PR77 не изменяются.

## 11. Проверки этого review

Выполнена статическая сверка таблиц/полей, ключевых entry points, существующих тестов и diff PR77 на закреплённых SHA. Полная тестовая матрица выше — план будущей реализации, не выполненные тесты BMOS. Результаты локальных repository gates фиксируются в описании Draft PR после запуска. Moodle PHPUnit, DB upgrade/races, HTTP/browser, live provider и production acceptance здесь **NOT RUN**. Изменение — только этот документ; действующие ACL, DB, services, Core/theme и deployments не меняются.
