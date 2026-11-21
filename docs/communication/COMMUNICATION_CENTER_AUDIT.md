# Коммуникационный центр BMS24 — аудит фактического состояния

Дата: 15.11.2026 (Москва). Автор: Claude (архитектурный аудит по поручению владельца).
Статус документа: **аудит**. Продуктовый код, схема, CI, production этим документом не меняются.

Метки: **ПРИНЯТО** — решение владельца; **ПРЕДЛОЖЕНО** — проект, требует согласования;
**ПРОВЕРЕНО** — подтверждено кодом или выполненной проверкой (указано какой); **НЕ ПРОВЕРЕНО** — нет доказательства.

## Срез после выпуска #114 (16.11.2026)

Этот раздел добавлен после публикации и не заменяет исходный аудит от 15.11.2026: всё, что ниже раздела, — история.

| Что | Значение | Доказательство |
|---|---|---|
| `main` | `881aac2ef334c4894b9cff2f591b75f675360e3b` | `git rev-parse origin/main`, 16.11.2026 |
| Реализация центра | PR #112 head `5b5d839af4eb682cfb76755799bb320292b0f1e6`, MERGED 16.11.2026 | GitHub metadata #112 |
| Опубликовано | релиз #114 head `153e4bd23f286ff50d0486732a092cb11043606d`, tree `f3bda188…`, ZIP `4bb14c85…`, production 16.11.2026 07:54:37 МСК, MERGED | #114 и [комментарий о публикации](https://github.com/rimanfatykhov-arch/bms24/pull/114#issuecomment-6909069147) |
| Состав #114 | #103 (правка/удаление), #110 (история открытия), #113 (сводка дня), #112 (центр) | описание #114 |

### Статус по уровням доказанности

| Пункт | Принято владельцем | Реализовано (код `main`) | Проверено | Показано владельцу | Опубликовано |
|---|---|---|---|---|---|
| Одно обсуждение задачи в карточке и центре | да, «пункты 1–5» 15.11 | `task_service::discussion_context`, `communication_service::VIEWER_READ_KINDS = ['task']` | PHPUnit `communication_center_cleanup_test::test_task_viewer_reads_the_same_discussion_without_joining`; BROWSER 64/64 (#114) | dev-просмотр перед #114 | да, #114 |
| Чтение обсуждения и файлов без автоматического участия | да | чтение для `contextkind='task'` с проверкой доступа к задаче на каждом чтении | `test_task_discussion_is_closed_to_other_company_and_users_without_task_access`, `test_viewer_read_is_limited_to_task_discussions` | да | да |
| События задачи в ленте; открытие — в «Истории» | да | `communication_service::discussion_events`; `task_open_service` (#110) | `test_task_events_are_inline_but_are_not_messages`; `task_open_history_test`; BROWSER task_open 7/7 | да | да |
| Ответ с цитатой и упоминания | да | `send_message*(…, $replytouuid)`, `inline_mentions` | `test_reply_quotes_original_in_same_thread_and_hides_withdrawn_text`, `test_inline_mentions_resolve_only_active_participants` | да | да |
| Без дублей личных диалогов | да | поиск активного `direct`-треда пары в `create_thread` | `test_direct_conversation_is_reused_for_the_same_pair` | да | да |
| Участники и владелец группы | да | `leave_thread`, `remove_participant`, роль `owner`, передача владения | `test_group_member_leaves_owner_removes_and_ownership_passes`, `test_leave_and_remove_reject_direct_and_object_threads` | да | да |
| Фильтры и переход к задаче | да | `communication_center_service::TABS` (`unread`, `messages`, `discussions`, `mentions`, `meetings`, `notifications`) | BROWSER центр 8/8 (#112/#114) | да | да |
| Правка/удаление своих сообщений, несколько вложений | да (#103, #102) | `change_message`, `change_discussion_message`, `send_message_with_attachments` | BROWSER message_actions 7/7, multiattachments | да | да |

Пункты закрыты только в объёме, перечисленном выше. Проверка «показано владельцу» опирается на запись оператора
в #114 и контексте, а не на собственный просмотр автора документа.

### Что по-прежнему НЕ реализовано (подтверждено кодом `main`)

- Тип объекта `battleplan` отсутствует в `communication_service::CONTEXT_KINDS` и в `communication_object_service::describe`.
- ИИ-участники, адаптеры MAX/Telegram, производители `eventout` для коммуникаций — нет.
- #88 (контракт MAX inbound) и #77 (ADR-0007 принципалов ИИ) — документы, не реализация.

## 0. Точка проверки

| Что | Значение | Источник |
|---|---|---|
| `main` | `d35350954146867f80877bcea4729c31477d16d3` (merge PR #102) | `git log origin/main`, 15.11.2026 |
| Плагин в `main` | `local_kbacompany` `1.1.0-rc29`, `version = 2026111100` | `local/kbacompany/version.php` |
| Опубликовано в production | код PR #103 `dfd90535ea90d13918fce0c2707cbd5946c406f2` (правка/удаление своих сообщений в обсуждении задачи), **PR не merged** | GitHub metadata PR #103 (Draft, OPEN); контекстный репозиторий `bms24-context` (DECISIONS) |
| Разница #103 ↔ main | +`task_service::change_discussion_message`, действия `discussionedit`/`discussionwithdraw` в `tasks.php`, UI в `task_ui_service`, браузерный сценарий `tools/ci/browser/message_actions.py`. Схема и `version.php` не меняются | `git diff d353509 dfd9053` |

Вывод по #103: серверная логика правки/отзыва (`communication_service::change_message`) уже есть в `main`;
#103 добавляет к ней поверхность карточки задачи. Опубликованное поведение учитывается как текущее.

### Связанные PR / Issue (GitHub metadata на 15.11.2026)

| № | Название (кратко) | Состояние | Head | Отношение к этой работе |
|---|---|---|---|---|
| Issue #75 | RC33–RC34: корпоративная лента, MAX-бот, ежедневные отчёты | OPEN | — | Исходный продуктовый запрос по MAX |
| PR #77 | BMOS Agent Platform, ADR-0007 AI Control Layer + Timeweb AI Gateway | Draft, OPEN | `7dd475f` | **Только документ**. Принципалы `human`/`ai_agent`/`service_integration`, спонсор-человек, повторная авторизация команд ИИ. Этот план на него опирается и не дублирует |
| PR #88 | MAX → корпоративная лента: inbound contract | Draft, OPEN | `d9daaed` | **Только документ**. Подробный контракт входящего MAX → лента; используется как источник, не как реализация |
| PR #89 | BMOS SIPOC handoff contract review | Draft, OPEN | `bc14926` | Смежное (агенты/должности), не коммуникации |
| PR #95 | Единый план BMS24 до SaaS | OPEN (не Draft), не merged | `9e80ee5` | Вводит `docs/ROADMAP.md` (D02 коммуникации/MAX, D03 исполнитель-ИИ). В `main` отсутствует |
| PR #97 | Backlog Codex/Claude, границы MAX/BMOS/SaaS | Draft, OPEN | `696b5b3` | Решения MAX trust/attribution вынесены владельцу |
| PR #103 | Правка/удаление сообщений в обсуждении задачи | Draft, OPEN, **опубликован** | `dfd9053` | Учитывается как текущее поведение |
| Issue #106 / PR #107 | Сводка дня (Codex) | OPEN / Draft | `8e503f5` | Не трогать; общие файлы `task_service`, `task_ui_service`, `tasks.php`, lang |
| PR #108 | Быстрый маршрут предпросмотра на dev-Moodle | Draft, OPEN | `9267546` | Не принят; будущие проверки этого плана на него не опираются |
| PR #109 | История открытий задачи | CLOSED (заменён #110) | `43c528d` | — |
| PR #110 | История открытий задачи (`tasklog action='opened'`) | Draft, OPEN, команда «Опубликуй» передана оператору | `43c528d` | Не трогать. Открытие ≠ прочтение |

## 1. Таблица «Возможность → что есть → доказательство → чего не хватает»

| Возможность | Что есть | Доказательство | Чего не хватает |
|---|---|---|---|
| Единый сервис сообщений | `communication_service` (contract `bms_communication_core` 0.6.0): треды `direct`/`group`/`object`, участники, сообщения, прочтение, упоминания, уведомления, вложения | `classes/local/communication_service.php:13–51` (main `d353509`) | Нет. **Второй сервис не нужен** |
| Хранение | 8 таблиц: `commthread`, `commpart`, `commmsg`, `commrev`, `commread`, `commmention`, `commnotif`, `commattach`; все с `companyuuid` | `db/install.xml:2008–2165` | Нет полей происхождения/канала/внешнего ID у сообщения; нет типа участника (человек/ИИ) |
| Личный диалог | `threadtype=direct`, ровно один собеседник, сотрудник компании | `communication_service.php:193–231` | Код не ищет существующий диалог той же пары: дедупликация есть только для `object` (`:236`). Поведение UI — НЕ ПРОВЕРЕНО |
| Группа | `threadtype=group`, заголовок обязателен, добавляет только `owner` | `communication_service.php:226, 312–347` | Нет выхода участника/удаления участника владельцем, нет смены владельца |
| Обсуждение объекта | `threadtype=object` + `contextkind/contextuuid/contextrevision`; один активный тред на объект (`get_records ... 'id ASC', 0, 1` под company-lock) | `communication_service.php:236–278` | Уникальность обеспечена блокировкой, а не уникальным индексом |
| Виды объектов | `company, task, project, process, document, goal, statistic, kbaelement, improvement, meeting` | `CONTEXT_KINDS`, `communication_object_service::describe` | **Нет `battleplan`**, приказов, дневного отчёта. `statistic` проверяет только компанию, без доменного ACL — НЕ ПРОВЕРЕНО, требуется ли он |
| Обсуждение задачи = обсуждение из центра | Карточка задачи использует тот же тред (`task_service::discussion_thread` ищет `object/task/<uuid>`), сообщения через `communication_service::context` | `task_service.php:208–215, 1212–1278` | Пользователь с правом просмотра задачи, но не участник, видит `restricted=true` и пустую ленту до первого сообщения |
| Участники обсуждения задачи | Создатель, исполнитель, проверяющий, наблюдатель; синхронизация при изменении задачи, отключение утративших доступ | `task_service.php:246–286` (`discussion_participants`, `sync_discussion`) | Руководитель/коллега с доступом присоединяется только отправкой. Нет явной «подписки» без сообщения |
| Право писать в обсуждение задачи | `can_post_discussion`: участие в задаче, `approve` по подразделению или `task/comment` | `task_service.php:461–466` | Чтение ≠ запись — корректно; для ИИ нужна отдельная модель |
| Права на каждом входе | `company_context_service::context` + активное участие + повторная проверка объекта (`require_thread_context`) при чтении/записи/файле | `communication_service.php:127–141, 434–441, 616–627`; `lib.php:119–129` | Нет. Это базовый инвариант для каналов и ИИ |
| Непрочитанные | `commread.lastmessageid` на участника; счётчики в `communication_center_service` и `communication_live_service` | `communication_service.php:572–583`; `communication_center_service.php:66–125` | Курсор по `id`, не по времени; нет «прочитано» по сообщению — и не нужно для внешних каналов |
| Уведомления | `commnotif` отдельно от сообщений (`notification_is_not_message`); идемпотентные системные уведомления | `communication_service.php:143–191` | Нет доставки во внешний канал (только внутри BMS24) |
| Живое обновление | `communication_live_poll.php` — read-only JSON-отпечаток, без содержимого | `communication_live_poll.php`, `communication_live_service.php` | Поллинг, не push; приемлемо |
| Вложения | Защищённые файлы Moodle File API (`communication_attachment`, itemid=id сообщения) + ссылки `document/url/external`; атомарная мультизагрузка с сообщением | `communication_service.php:361–432, 597–663`; PR #102 | Нет ограничений типов/антивируса сверх Moodle; нет происхождения внешнего файла |
| Правка/отзыв | Только автор, активный участник, проверка объекта, lock + transaction, хэш версии (оптимистичная блокировка), исходный текст в `commrev`, отзыв = пустой текст + `withdrawn`, снятие упоминаний/уведомлений; сообщения, ставшие задачей/решением, неизменяемы | `communication_service.php:500–570`; #103 `task_service::change_discussion_message` | Нет `timemodified` у сообщения (признак «Изменено» вычисляется по `commrev`) |
| Сообщение → задача / решение | Трассируемое создание задачи/решения из сообщения, сообщение остаётся сообщением | `communication_service.php:665–739` | — |
| Аудит | `commrev` (правки), `tasklog` (`discussion_post`), `aclaudit` для ACL | `task_service.php:1257–1258` | Нет аудита входящих/исходящих событий канала и действий ИИ |
| Приватность | Privacy provider описывает comm-таблицы | `classes/privacy/provider.php:348+` | Новые поля/таблицы потребуют обновления провайдера |
| Outbox | `local_kbacompany_eventout` (provider-neutral, at-least-once, idempotency key) и legacy `syncout` | `integration_event_service.php:13–60` | **Ни один продуктовый путь не публикует события коммуникаций**; вызовы `publish` только в тестах |
| Внешние идентичности | `local_kbacompany_extmap` (provider/entitykind/localref/externalid/namespace) | `db/install.xml:2536+`; `integration_service.php` | Нет привязки «аккаунт мессенджера ↔ сотрудник» с подтверждением владения |
| MAX / Telegram | Только документы (#88 контракт входящего MAX → лента; #97 решения) | PR #88 `d9daaed`, PR #97 `696b5b3` | **Кода адаптеров нет**, ботов, токенов, webhook нет |
| ИИ-участники | `bms_ai_bridge_service` — помощник для полей CRUD (explain/draft/review), без участия в обсуждениях | `bms_ai_bridge_service.php:7–10` | Нет сущности «ИИ-терминал», нет участника-ИИ, нет авторства ИИ в сообщениях |
| Мобильный UI | Браузерные сценарии #102/#103 включают ширину 320 px для обсуждения задачи | `tools/ci/browser/message_actions.py` (в #103) | Центр целиком на мобильной ширине — НЕ ПРОВЕРЕНО этим аудитом |
| Открытие задачи | `tasklog action='opened'` (PR #110, не опубликован на момент записи) | PR #110 | Это **не** прочтение сообщений и не ознакомление |

## 2. Разделение по степени доказанности

**Подтверждено кодом (`main` d353509, чтение исходников в этом аудите):** всё из колонки «Что есть» выше,
включая единый тред обсуждения задачи, повторную проверку доступа к объекту, правку/отзыв с аудитом, защищённые файлы,
отсутствие производителей `eventout` для коммуникаций, отсутствие `battleplan` в `CONTEXT_KINDS`, отсутствие адаптеров MAX/Telegram и ИИ-участников.

**Подтверждено выполненной проверкой (чужими прогонами, не этим аудитом):** опубликованный #103 прошёл CI BROWSER на свежем
Moodle 4.1.22 (MariaDB/Percona) по `contract.message_action_checks` и production-публикацию с owner smoke — согласно
`docs/releases/TASK_DISCUSSION_MESSAGE_ACTIONS.md` в #103 и записи оператора в `bms24-context`. PHPUnit
`communication_service_test`, `communication_center_service_test`, `communication_live_service_test` существуют; их результат на `main`
этим аудитом **не перезапускался**.

**Только описано в документах:** контракт MAX inbound (#88), решения trust/attribution (#97), D02/D03 (#95), предложение
коммуникационного центра в `bms24-context/proposals/2026-11-15-communication-center.md`.

**Не проверено:** поведение центра на мобильной ширине целиком; повторное создание личного диалога; производительность
поллинга при большом числе тредов в production; доменный ACL для `statistic`; реальные лимиты MAX/Telegram на пилоте.

## 3. Найденные проблемы текущего центра (кандидаты в «этап 0»)

1. **P1. Читатель задачи не видит обсуждение.** Пользователь, которому `can_view_row` разрешает задачу, но который не в `commpart`,
   получает `restricted=true` и пустую ленту (`task_service.php:1223–1226`). Это противоречит цели «один разговор, видимый из карточки и центра».
   Решение требует выбора владельца (см. вопросы): видимость по праву на объект или только по участию.
2. **P2. Нет `battleplan` как объекта обсуждения.** Добавление вида требует ACL-дескриптора в `communication_object_service`.
3. **P2. Расхождение лимитов текста:** 12 000 символов в `task_service::post_discussion_message` и 20 000 в `communication_service`.
4. **P3. Уникальность обсуждения объекта** держится на блокировке, без уникального индекса (индекс `context_ix` не уникален). Для каналов
   с повторами событий нужна защита на уровне данных или идемпотентный ключ.
5. **P3. Личный диалог** для той же пары код не дедуплицирует (проверено чтением кода; в UI не воспроизводилось) — дубли размывают историю и маршрутизацию ответов из каналов.
