# BMOS-A0. Agent Definition, Agent Run, аудит и AI Billing

**Статус: PROPOSED.** Таблицы — проект. Секреты, скрытые рассуждения модели и полные чувствительные промпты по
умолчанию **не хранятся**.

## 1. AgentDefinition (как устроен агент)

Две таблицы: `agentdef` (идентичность определения) и `agentdefrev` (неизменяемые ревизии конфигурации).

`agentdef`: `uuid`, `companyuuid`, `principaluuid` (1:1 с `ai_agent`), `name`, `purpose`, `owneruserid` (= спонсор),
`currentrevision`, `status` (`draft`/`active`/`disabled`/`archived`), `killswitch` (0/1), `revision`.

`agentdefrev` (одна строка — одна утверждённая конфигурация; правка = новая ревизия):

| Поле | Содержание |
|---|---|
| `defuuid`, `revno`, `approvedby`, `approvedat`, `configdigest` | Неизменяемость, кто утвердил |
| `provider`, `modelprofileid`, `modelprofilerevision` | Ссылка на профиль `local_kbaai`, не имя модели в коде |
| `systemprofileid`, `systemprofilerevision` | Версионированный системный профиль (как `bms_profile_registry`) |
| `tools` | Белый список команд API v2, которыми агент вообще может пользоваться (пересечение с грантами) |
| `contextsources` | Разрешённые источники контекста (`task.read` поста, `metrics.read`, …) и поля; ПДн исключены по умолчанию |
| `actions` | Какие команды записи можно предлагать/выполнять (R1–R3) |
| `riskpolicy` | Какие R2 выполнять сразу, какие отправлять на подтверждение |
| `limits` | Токены на запуск, стоимость на запуск, время на запуск (сек), шаги/команды на запуск, запуски в день |
| `credentialref` | Ссылка на секрет провайдера (если не общий брокер) |
| `schedule` | Пусто в A1–A2; в A3 — только расписание, утверждённое человеком |

Активация ревизии — только человеком (`agents.register` и смена ревизии — R4 для агента).

## 2. AgentRun (одно выполнение)

`agentrun`:

| Поле | Содержание |
|---|---|
| `uuid`, `companyuuid`, `principaluuid`, `defuuid`, `defrevno`, `occupancyuuid`, `workuuid` | Что, кто, по какой конфигурации, на каком посту, по какому поручению |
| `trigger` | `manual` (человек), `schedule` (утверждённое), `event` (A4) |
| `initiatoruserid` / `initiatorprincipaluuid` | Кто запустил |
| `idempotencykey` | Уникален в компании; повтор не создаёт второй запуск |
| `correlationid` | Сквозной для команд, вызовов модели, outbox |
| `status` | `queued` → `running` → `succeeded` / `failed` / `escalated` / `cancelled` / `killed` |
| `steps`, `commands`, `modelcalls` | Счётчики против лимитов |
| `tokensin`, `tokensout`, `tokenscached` | Сумма по вызовам |
| `costestimated`, `costconfirmed`, `currency`, `costsource` | См. §6; `costconfirmed` пусто до сверки |
| `evidence` | Ссылки на объекты BMS и хеши входа/выхода, а не тексты |
| `failurecode`, `failuredetail` | Типизированная ошибка (без секретов и ПДн) |
| `escalatedto`, `escalationreason` | Человек, которому передан сбой |
| `startedat`, `finishedat`, `timecreated` | |

`agentrunstep` (неизменяемые шаги): `runuuid`, `stepno`, `kind` (`model_call`/`command`/`approval_request`/
`budget`/`kill_check`), `ref` (UUID команды/аудита/approval), `status`, `inputhash`, `outputhash`, `respondingmodel`,
`latencyms`, `errorcode`, `timecreated`. Уникально `runuuid+stepno`.

## 3. Что хранится и что нет

| Хранится | Не хранится по умолчанию |
|---|---|
| Метаданные, счётчики, ссылки на объекты, хеши, версия профиля и определения, модель-ответчик | Секреты и заголовки авторизации |
| Краткое обоснование предложения (как `rationale` в BMS Assistant) | Скрытые рассуждения модели |
| Итоговый результат, если он стал объектом BMS (комментарий, черновик) — в своём домене | Полный промпт и полный контекст |
| Типизированные ошибки | Полный сырой ответ провайдера |

Режим диагностики с полным текстом — только по отдельной политике: включает человек, срок ограничен, доступ —
спонсору и администратору, ПДн маскируются (Q-13).

## 4. Отказы и эскалация

- Ошибка с `retryable=true`: повтор с тем же ключом, ограниченное число раз, экспоненциальная задержка.
- Иначе — запуск `failed`; если есть незавершённая работа — `escalated` на спонсора/руководителя поста, задача в
  очереди сбоев. Побочных бизнес-изменений от провалившегося шага нет (команда атомарна).
- Превышение любого лимита — `failed` с кодом лимита, резерв освобождается.

## 5. Аудит и атрибуция

- **Не меняем** существующие журналы (`tasklog`, `dreportlog`, `commrev`, `aclaudit`, …) и их схемы.
- Новая неизменяемая таблица атрибуции `wpattr`: `uuid`, `companyuuid`, `objecttype`, `objectuuid`, `action`,
  `actorprincipaluuid`, `actorprincipaltype`, `onbehalfofuserid`, `sponsoruserid`, `occupancyuuid`, `grantuuid`,
  `agentrunuuid`, `approvaluuid`, `correlationid`, `idempotencykey`, `result` (`executed`/`rejected`/`pending`),
  `errorcode`, `timecreated`.
- Для существующих журналов с NOT NULL `actorid` действие агента пишется с `actorid = 0` **и** обязательной строкой
  `wpattr`; UI показывает «ИИ-агент <имя>», а не «система». Это изменение отображения — проверить все места, где
  `actorid` превращается в имя (вопрос совместимости, задача A1-08).
- Отказы пишутся в отдельной транзакции после отката (как deny-audit ADR-0012).
- **Outbox envelope 1.2** (добавочные поля): `actor_principal_type`, `actor_principal_uuid`, `on_behalf_of_user_id`,
  `agent_run_uuid`, `approval_uuid`. Envelope 1.1 и `syncout` не меняются.

## 6. AI Billing

**Бюджеты** (`aibudget`): `uuid`, `companyuuid`, `scope` (`company` · `agent` · `position` · `model` · `run`),
`scopeuuid`/`modelprofileid`, `period` (`day`/`month`; для `run` — на запуск), `unit` (`tokens`/`rub`), `limit`,
`softwarn` (процент), `hard` (1 — блок), `revision`, `setby`.

**Резервы** (`aireserve`): `uuid`, `runuuid`, `stepno`, `budgetuuid`, `amount` (оценка максимума: лимит токенов шага
× цена), `status` (`reserved` → `committed` / `released` / `expired`), `expiresat`.
- Резерв ставится **до** вызова модели во всех применимых бюджетах сразу, в одной транзакции под lock компании.
  Хотя бы один бюджет не вмещает — `budget_denied`, вызова нет.
- После ответа: фактическая оценка фиксируется (`committed`), остаток освобождается. Сбой без ответа — `released`.
  Зависшие резервы истекают по `expiresat` (уборка — без вызова модели).

**Журнал расхода** (`aiusage`): `uuid`, `companyuuid`, `runuuid`, `stepno`, `attemptno`, `principaluuid`,
`positionuuid`, `modelprofileid`, `requestedmodel`, `respondingmodel`, `providerrequestid`, `tokensin`, `tokensout`,
`tokenscached`, `costestimated`, `pricesnapshotid`, `costconfirmed`, `confirmedat`, `confirmsource`, `status`
(`estimated` → `confirmed` / `disputed`). **Уникально** `runuuid+stepno+attemptno` — повтор регистрации того же
вызова не создаёт вторую запись (идемпотентность).

**Оценка ≠ списание.**
- `costestimated` = токены × версия прайса (`pricesnapshotid`, вводит администратор; цены не в коде).
- `costconfirmed` заполняется только сверкой с данными провайдера: у Timeweb нет API стоимости, поэтому источник —
  CSV-выгрузка логов или UI «Активность», загружаемые оператором; сопоставление по `providerrequestid`/времени/ключу.
  Расхождение → `disputed`, не перезапись.
- Отчёты компании показывают обе суммы и пометку «оценка».
- Лимит ключа Timeweb (422) — внешний предохранитель, а не учёт BMS.

**Перепродажа и тарифы клиентам** (платные операции, MR3-D60) — вне A0; это решение SaaS/коммерции (Q-16).
