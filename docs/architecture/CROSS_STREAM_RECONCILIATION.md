# Согласование MAX / BMOS / SaaS

14.11.2026, МСК. База `f34ab6ff0d2f67a56972e99c23d096ed77a9873c`. Документ — слой согласования, не ещё одна копия архитектуры. Технические инварианты взяты из текущего кода/AGENTS; новые продуктовые и архитектурные выборы ниже **PROPOSED**.

## Источники вне main

- [#77, исходная архитектура](https://github.com/rimanfatykhov-arch/bms24/tree/7dd475f11cce0c2215823d9be2361f8476284af5/docs).
- [#88, MAX contract](https://github.com/rimanfatykhov-arch/bms24/blob/d9daaed7733fe0a25a33609c886613c2fa6f2745/docs/architecture/RC33_MAX_FEED_INBOUND_CONTRACT.md).
- [#89, source-grounded SIPOC review](https://github.com/rimanfatykhov-arch/bms24/blob/bc14926a8713f6a6d2ff801e0e37c7b60fe779f2/docs/architecture/BMOS_SIPOC_HANDOFF_CONTRACT_REVIEW.md).
- [#95, архитектура](https://github.com/rimanfatykhov-arch/bms24/blob/9e80ee55dd27718171d266a017a217f7f231409a/docs/architecture/BMS24_ARCHITECTURE.md), [MVP](https://github.com/rimanfatykhov-arch/bms24/blob/9e80ee55dd27718171d266a017a217f7f231409a/docs/product/SAAS_MVP.md), [tenant ADR PROPOSED](https://github.com/rimanfatykhov-arch/bms24/blob/9e80ee55dd27718171d266a017a217f7f231409a/docs/adr/ADR-SAAS-001-tenant-isolation-PROPOSED.md).

## Канонический словарь и владельцы

| Сущность | Владелец / правило |
|---|---|
| Tenant | Коммерческая/изоляционная граница Cloud; не синоним Company UUID. Кардинальность tenant/company пока не утверждена |
| Company UUID | Каноническая область BMS; сохраняется при смене поставщика и переходе Start→ERP. ACL проверяются даже при отдельных экземплярах |
| Workspace | Cloud runtime binding; BMS хранит ограниченную projection с monotonic revision. Нельзя молча перепривязать workspace к другой компании |
| Human | Moodle user внутри установки + company membership. Числовой userid не глобальная SaaS identity; предлагаемая явная связь principal UUID/workspace/userid требует решения |
| ai_agent / service_integration | Предлагаемые типы principal с явными scope, сроком, отзывом и аудитом. Hybrid — режим поста, не общий всесильный пользователь |
| Должность / occupancy | Existing position UUID и human posassign остаются каноническими. У posassign нет immutable UUID/revision контракта; agent occupancy — отдельное расширение, не новый human staffing |
| Задача / исполнитель / принимающий | `task.assigneeid` — исходный Moodle user; `dutypositionuuid` — ответственность поста. Effective executor вычисляется approved coverage. Reviewer/acceptance — отдельное право |
| Process / model / element | Process UUID + published model revision; element UUID идентифицируется вместе с model. Нельзя маршрутизировать по одному element UUID между ревизиями |
| ProductFlow / SIPOC | Существующий flow — модель проектирования; supplier/customer semantic relation не автоматическая очередь исполнения |
| OutputArtifact / ProcessHandoff | Предлагаемое исполнение: версия, provenance/hash и ссылка на канонические файлы; явное принятие/отказ. Не вторая библиотека документов и не копия ProductFlow |
| MAX mapping / receipt | BMS-owned scoped связь внешнего отправителя и human; durable source namespace, event id + digest, конфликт повторного payload. Ротация ключа не создаёт новое пространство идентичности |
| BMS / Moodle / Corporate | BMS — управление и domain rights; Moodle — auth, File API, lifecycle; Corporate — обучение. Moodle Core не меняется |
| Cloud / ERP / provider | Cloud — provisioning, subscriptions, env/release/backup metadata; отдельный control plane. ERP — операционные факты. Timeweb/AI/MAX IDs — mapping, не канонические ключи |

Основания в main: [границы](../01_ARCHITECTURE_BOUNDARIES.md), [Cloud/ERP](../12_CLOUD_ERP_BOUNDARIES.md), [company isolation](../04_SECURITY_COMPANY_ISOLATION.md), [workspace mapping](../../local/kbacompany/CLOUD_WORKSPACE_MAPPING_0.6.8.md), [task service](../../local/kbacompany/classes/local/task_service.php), [coverage](../../local/kbacompany/classes/local/position_coverage_service.php), [flow service](../../local/kbacompany/classes/local/flow_service.php), [schema](../../local/kbacompany/db/install.xml). Это проверка контрактов по исходникам, не доказательство runtime.

## Противоречия и предлагаемое разрешение

| Источники / риск | Разрешение | Статус |
|---|---|---|
| #77 расширяет post/principal; #89 показывает существующий human staffing | Повторно использовать position/posassign/coverage; не записывать agent UUID в assigneeid | Сохранён существующий инвариант; agent schema PROPOSED |
| #77 handoff vs #89 ProductFlow | Разделить design graph и execution events; pin model revision, ссылаться на existing flow | Техническое согласование; новый runtime не реализован |
| #89 task done vs acceptance | Submit без reviewer может завершить задачу; не считать это принятием межпроцессного результата | Явный отдельный acceptance contract PROPOSED |
| #88 mapped human vs #77 machine principal | Хранить фактического integration executor и заявленного human автора отдельно; webhook secret не доказывает подпись человека | Инвариант аудита; выбор доверия владельцем |
| #95 tenant=workspace/company как MVP simplification | Не вводить равенство в canonical keys. One-company ограничение лишь коммерческая гипотеза | PROPOSED, existing multi-company сохраняется |
| #95 отдельный instance vs текущие company ACL | Изоляция экземпляром дополняет ACL; проверить фоновые задания, File API, sessions/cache и single-tenant restore | PROPOSED, миграция не согласована |
| #95 перенос MAX/AI после MVP vs #75/#76 | Направления остаются в продукте; только объём первой версии требует выбора | Не принято переносить весь объём автоматически |
| #88 revoke vs open sessions/background jobs | Проверять текущие права при каждом command/доставке; общий порядок блокировок с отзывом; committed audit отказа отдельно от rollback | Требование к будущей реализации, не заявление о готовности |
| #88 edit/delete/retry vs immutable history | Durable tombstone/control hold до create, digest mismatch conflict, запрет resurrection; не удалять аудит/историю | Контракт fake-provider; live policy отдельно |
| #95 retention/export/delete vs tenant suspension | Suspension подписки не равно membership revoke; export scoped, deletion по отдельной политике включая backup lifecycle | Сроки и правовые условия не утверждены |

UI/API/интеграции должны вызывать один канонический доменный command с company/object checks, текущими полномочиями, revision/transaction/idempotency и аудитом. Read-only API v1 не расширять скрытым machine write. Смена исполнителя не передаёт personal tasks, защищённые файлы, private calendar, RBAC или review rights.

## Решения владельца: варианты и рекомендация

| Решение | Варианты | Рекомендация / что блокирует |
|---|---|---|
| Изоляция SaaS | A: shared app/DB + ACL; B: отдельные instance/DB/files на shared infra; C: dedicated infra | B для измеряемого синтетического пилота, не production миграции. Проверить стоимость, shared-host blast radius и restore; Cloud PostgreSQL отдельно |
| Tenant/company | Одна компания в тарифе; несколько компаний/holding | Не ограничивать идентичность схемой 1:1; первую коммерческую комплектацию подтвердить отдельно |
| Identity | Workspace-scoped human binding; глобальный SSO сразу | Scoped binding сейчас; глобальный SSO отдельным ADR. Запрещено объединять одинаковые Moodle userid между установками |
| MVP/MAX/AI | Core daily-loop + fake adapters; полный live MAX/AI в первом платном выпуске | Core + gated fake adapters для первой проверяемой ступени; не считать отсрочку live утверждённой |
| MAX авторство | Доверенный adapter с dual attribution; подтверждение каждой публикации человеком | До live выбрать модель явно; для пилота без настоящих пользователей fake-provider. Adapter compromise остаётся угрозой при первом варианте |
| Handoff | Human acceptance; ограниченная автоматическая acceptance | Human по умолчанию, авто только отдельной policy, доказанными scope/revoke и аудитом |
| Коммерция/данные | Один тариф/manual billing или набор тарифов; сроки retention/export/deletion и RPO/RTO | Не фиксировать цены/сроки без владельца. Предложенные #88 числа не SLA; backup и restore обязательны при любом тарифе |

До решения спорного пункта разрешены документация и изолированное воспроизведение, но не изменение бизнес-правил. [Реестр и последовательность](../coordination/TWO_EXECUTOR_BACKLOG.md) определяют исполнителей; ветки авторов не редактируются.

## Последствия выбора и точные остановки реализации

Повторная база 14.11.2026: main f34ab6f, upstream #77/#88/#89/#95 остаются закреплёнными выше; #96 меняет CI, не канонические доменные сущности. Исправления CI не утверждают продуктовые решения.

| Решение выше | Последствия рекомендуемого варианта | До решения блокируется |
|---|---|---|
| Изоляция B | Отдельные DB credentials, файлы, caches, sessions и queues на tenant; общий host остаётся общей точкой отказа; нужны измерения стоимости | Production provisioning и миграция существующих клиентов; синтетический прототип разрешён отдельно |
| Tenant/company без физического 1:1 | Сохраняет holdings/multi-company; billing cardinality не меняет ACL | Коммерческий onboarding и тарифные ограничения |
| Scoped identity binding | Один человек может иметь разные локальные userid; обязательна проверка workspace+company+principal на каждом command | Agent/service write API и связывание аккаунтов между установками |
| Human acceptance | Замещение должности не передаёт reviewer rights; agent может подготовить результат, но не сам утвердить его | Автоматическая приёмка и передача исполнения downstream |
| MAX trust model | Trusted adapter может подделать атрибуцию mapped human при компрометации; подтверждение человеком снижает риск, но добавляет шаг | Настоящий MAX bot, реальные авторы/получатели и live публикация |
| MVP daily-loop + fake adapters | Ускоряет проверку управления; не выполняет обещание live MAX/AI в первой версии | Обещание состава первой платной версии и перенос live-направлений |
| Retention/export/delete/RPO/RTO | Backup включает code+DB+files; suspension не удаляет данные; export scoped/audited; erasure учитывает backups | Production SLA, необратимое удаление и коммерческая эксплуатация |

Рекомендации не являются согласием владельца. Default deny и сохранность истории действуют независимо от будущего тарифа.

### Карточка операционных решений для пилота (PROPOSED)

| Выбор | Конкретные альтернативы | Рекомендация и последствия |
|---|---|---|
| Экспорт | Операторский scoped export с аудитом; self-service asynchronous export | Операторский в первом пилоте, отдельный queue/API позже. В обоих вариантах повторная проверка прав при формировании и скачивании, TTL ссылки; отзыв доступа отменяет выдачу |
| Удаление | Ручная согласованная очередь с hold; автоматическое удаление по сроку | Ручная очередь до утверждения retention. Subscription suspension не запускает удаление; нужна отдельная политика backup expiry и доказательство выполнения |
| Эксплуатационная цель | S0: только синтетический пилот без SLA; S1: предложенные RPO24ч/RTO8ч; S2: предложенные RPO1ч/RTO4ч | Сейчас S0; S1/S2 требуют отдельного выбора владельца, измерения полного restore на репрезентативном объёме и проверки доступности backup. Цифры — варианты, не обещание и не принятый SLA |
| История/retention | Единая политика tenant; разные сроки по классам данных с legal hold | Политики по классам (domain history, messages, audit, backup) до irreversible erase. Числа7/30 из MAX proposal не переносить на все данные SaaS |
| Первый live состав | Core loop без live integrations; Core+MAX; Core+MAX+AI | Сначала проверяемый core и fake adapters. Выбор первого платного состава остаётся владельцу; направления MAX/AI не исключаются и их перенос не считается принятым |

Доказательство restore в PR98 относится к синтетическому CSS-кандидату и конкретным СУБД. Оно не подтверждает S1/S2, коммерческую многопользовательскую нагрузку, production backup или изоляцию SaaS tenants.
