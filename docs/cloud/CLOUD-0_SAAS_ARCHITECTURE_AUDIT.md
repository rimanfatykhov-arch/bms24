# CLOUD-0 — SaaS Architecture & Audit

**Статус: PROPOSED / на согласование владельца и независимую проверку.** Только аудит и проект. Документ не разрешает CLOUD-1, DDL, DNS, provisioning, подключение платных сервисов, merge, TEST/staging/production или deployment.

| Поле | Значение |
| --- | --- |
| Линия | BMS-10 Cloud / SaaS (P0), поручение [#204](https://github.com/rimanfatykhov-arch/bms24/issues/204), реестр [#198](https://github.com/rimanfatykhov-arch/bms24/issues/198) |
| Исполнитель | Claude, отдельная сессия «CLOUD-0 — SaaS Architecture & Audit» |
| Дата среза | 28.11.2026 |
| Baseline | `main` = `0884643590541fdd9b75b1414fd93d6120ded017`, tree `a576c5976485b95acd8666bca93824c459ea8e76` |
| Версия плагина на baseline | `local_kbacompany` `1.1.0-rc29`, `$plugin->version = 2026112301` |
| Изменённые пути | `docs/cloud/CLOUD-0_SAAS_ARCHITECTURE_AUDIT.md`, `docs/cloud/cloud0-evidence-inventory.json` (только новые документы) |
| Влияние на БД / ACL / privacy / интеграции | Нет. Код, схема, языковые строки, CI и настройки не изменяются |

Обозначения состояния: **EXISTING** — есть в `main` на baseline (указан путь); **PROPOSED** — описано в контрактах или PR, не реализовано; **UNKNOWN** — источник недоступен или доказательства нет; **NOT RUN** — проверка не выполнялась.

---

## 1. Итоги для владельца

1. **Дубля Cloud Control Plane нет, но в Moodle уже есть его клиентская сторона.** В `local_kbacompany` есть проекция Workspace (`workspace_mapping_service`, таблица `local_kbacompany_cloudmap`), кеш entitlement snapshot (`entitlement_snapshot_service`, `local_kbacompany_entsnap`), resolver (`entitlement_service`), контракт тарифного каталога (`bms_tariff_catalog_service`) и закрытый web service `local_kbacompany_cloud_v1` (по умолчанию выключен). Отдельного сервиса Cloud, репозитория Cloud, PostgreSQL, Product Registry, Subscription Registry и корпоративного Odoo **в доступных источниках нет**. CLOUD-1 нужно строить как внешний Control Plane, который пишет в уже существующие проекции. Второй реестр компаний или вторую проекцию в Moodle создавать нельзя.
2. **Текущим компаниям ничего не грозит.** `entitlement_service::has()` в `main` не вызывается ни в одном guard, а `explain()` используется только для диагностики (`platform_boundary_service`). Компании без проекции и компании в режиме `legacy_compatibility` получают `allowed = true` (`LEGACY_COMPATIBILITY`). Применение snapshot само режим не переключает. Эту политику предлагается закрепить (раздел 6).
3. **Главное открытое решение — что считать «двумя изолированными пространствами» для S3/G3.** Контракты Drive (CLOUD-001 §6, REG-001 §12), MR3-D10 и `docs/12` исходят из общего Moodle. Открытый PROPOSED [ADR-SAAS-001 в PR #95](https://github.com/rimanfatykhov-arch/bms24/pull/95) предлагает отдельный экземпляр Moodle на tenant. В общем Moodle нет восстановления одной компании (CLOUD-001 §18 откладывает его на будущее), поэтому критерий G3 «независимое восстановление» на общей модели сейчас недостижим. Варианты и рекомендация — в разделе 5.
4. **Найдены пять расхождений между кодом и контрактами.** Их нужно согласовать до CLOUD-2 (раздел 4.3). Самое важное: каталог тарифов использует коды `management.*` и `bms.*`, а snapshot принимает только `mgmt.*` (как и ENT-001). Если включить enforcement без согласования кодов, существующие функции закроются.
5. **Первый PR (CLOUD-1a) предлагается без runtime:** машиночитаемые контракты Registry и Entitlements в репозитории, offline-валидатор, синтетические фикстуры A/B и CI-проверка. Без DDL, без сервиса, без изменения `local/` (раздел 9).

---

## 2. Проверенные источники

| Источник | Версия / SHA | Результат |
| --- | --- | --- |
| `AGENTS.md`, `docs/12_CLOUD_ERP_BOUNDARIES.md`, `docs/deploy/BMS24_RELEASE_CONVEYOR_V1.md`, [#63](https://github.com/rimanfatykhov-arch/bms24/issues/63), #198, #204 | `main` 0884643 | Прочитано |
| [BMS-ARC-CLOUD-001](https://docs.google.com/document/d/1fGSKD4tDUzzjumYwxAgAZl3u3hZD78ebNAPLTo7zqZc/edit) v0.1 PROPOSED | Google Drive, прочитано 28.11.2026 | Прочитано полностью |
| [BMS-ARC-REG-001](https://docs.google.com/document/d/1mC6aEAWzMnBuE2ovZpfumG-T5LHj2Y8XDcZadQpaLsk/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [BMS-ARC-REG-002](https://docs.google.com/document/d/12DvSqybN58QFcxWFziJfmwCCENZW9Fo5zQ7f_7rIYig/edit) v0.1 PROPOSED | то же | Текст прочитан. Пакет схем (YAML/JSON/Mermaid/PNG/ZIP) и SHA-256 из §18 **NOT RUN**: файлы не скачивались, контрольные суммы не сверены |
| [BMS-ARC-ENT-001](https://docs.google.com/document/d/1D6BBA1_12rkRiXu2sM6y5A6gJQR_ggUCWHe1ujg1hx4/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [BMS-ARC-MIG-001](https://docs.google.com/document/d/19-a39u5U4yI73svjBVyQTafAxWtmTxbNe47D-peIThQ/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [RELEASE ROADMAP 3.1](https://docs.google.com/document/d/1pHuELJWEXaynqZqI0uzm8brzoLnq2lNnW6u9CCPY85s/edit) | изменён 28.11.2026 07:38 UTC | Прочитано (RR31-D01–D07, CLOUD-0…5, G0–G5) |
| MASTER ROADMAP 3.0 (зеркало в [PR #184](https://github.com/rimanfatykhov-arch/bms24/pull/184), `docs/product/MASTER_ROADMAP_3.md`) | head `96fb84a464caccdaeb1be0d00dc63ac11dc24f69` | Прочитаны MR3-D10–D19, D64–D79, S0–S6, BMS-10. Сам Google-документ MR3 не открывался: использовано зеркало |
| [PR #95](https://github.com/rimanfatykhov-arch/bms24/pull/95): `ADR-SAAS-001`, `SAAS_MVP.md` | head `9e80ee55dd27718171d266a017a217f7f231409a` | Прочитано, это возможный дубль/конфликт (раздел 3) |
| [PR #97](https://github.com/rimanfatykhov-arch/bms24/pull/97) | head `696b5b356bc5d9ac1b1a9ce8d586338856dae43a` | Изучен только список файлов; содержимое детально не проверялось |
| Репозиторий `bms24-context` (приватный) | HEAD `a4d3ef1f`, 19.11.2026 | Поиск по дереву: единственный Cloud-материал — `sessions/2026-11-14-saas-plan-pr95.md` и записи в `DECISIONS.md` о PR #95/#97 |
| Другие репозитории владельца (`biznes-akademiya-marketing`, `sites`, `ba-chats`, `kzn.mba`) | — | По названиям и описаниям к Cloud не относятся. Содержимое **NOT RUN** |
| BMS-ARC-DOM-001, BMS-PRD-EDT-001, BMS-ARC-INT-001, BMS-ARC-ERP-001, ADR Odoo Hosting | — | **UNKNOWN**: на них ссылаются контракты, но ссылок на документы нет |
| Корпоративный Odoo BMS24, хостинг Cloud, DNS `*.bms24.online`, production inventory | — | **UNKNOWN**: доступа нет, серверные проверки запрещены поручением |

---

## 3. Проверка дублей

| Кандидат | Где | Вывод |
| --- | --- | --- |
| Проекция Workspace в Moodle | `workspace_mapping_service` 0.6.8, `local_kbacompany_cloudmap` | EXISTING. Это *проекция* (`moodle_role = projection_cache`), а не Registry. CLOUD-1 должен в неё писать, а не создавать вторую |
| Entitlement runtime | `entitlement_service` 0.6.7, `entitlement_snapshot_service` 0.6.7, `local_kbacompany_entsnap` | EXISTING. Совпадает с ENT-001 §13–16, §51 этап 1. В Cloud нужен только resolver/выдача snapshot; второй resolver в Moodle не нужен |
| Тарифный каталог | `bms_tariff_catalog_service` 1.1, `tariff_catalog.php` | EXISTING, только для чтения, константы в коде. Пересекается с будущим Product Registry (CLOUD-2): после CLOUD-2 его нужно превратить в проекцию, иначе будет два каталога |
| Реестр компаний | `company_registry_service`, `local_kbacompany_company` | EXISTING, канонический владелец BMS Company. Cloud не создаёт копию, только `WorkspaceCompanyLink` по UUID |
| Внешние идентичности | `identity_registry`, `local_kbacompany_extmap` | EXISTING. Это provider-neutral mapping компаний и пользователей (Bitrix24, Odoo-клиента). Его нельзя путать с CloudCustomer ↔ коммерческий Odoo |
| Odoo | `odoo_adapter_service` 0.9.1.3, `odoo_json2_transport`, `odoo_actual_*` | EXISTING, но это **ERP клиента** (импорт факта), а не корпоративный коммерческий Odoo (MR3-D70). Корпоративного Odoo-коннектора нет |
| Лицензии Academy | `local/kbacorporate/classes/local/licenses.php`, `local/bitrixsync/.../license_*` | EXISTING, владелец — KBA Corporate (ENT-001 §22). Cloud не копирует учёт мест |
| SaaS MVP / изоляция | PR #95 (`ADR-SAAS-001`, `SAAS_MVP.md`), PR #97 | PROPOSED, не смержены. Расходятся с контрактами Drive по модели изоляции (раздел 5). Этот документ их **не заменяет**: он даёт сравнение для решения владельца и предлагает согласовать #95 и CLOUD-0 одним решением |
| Сервис Cloud / Control Plane | — | Отсутствует (UNKNOWN вне доступных репозиториев). Дубля нет |

---

## 4. Evidence inventory: что есть в `main`

Машиночитаемая версия — [`cloud0-evidence-inventory.json`](cloud0-evidence-inventory.json).

### 4.1 Компоненты

| Компонент | Путь | Контракт | Состояние | Тесты |
| --- | --- | --- | --- | --- |
| Workspace projection | `local/kbacompany/classes/local/workspace_mapping_service.php` | 0.6.8, projection schema 1.0 | EXISTING. 1:1 Company ↔ Workspace, монотонная `projection_revision`, привязка неизменна, без provider ID | `tests/workspace_mapping_service_test.php` (3 теста) |
| Entitlement resolver | `local/kbacompany/classes/local/entitlement_service.php` | 0.6.7 | EXISTING. Режимы `legacy_compatibility` / `cloud_snapshot` / `suspended`. Порядок проверки: entitlement → company scope → capability → business rule | через тесты snapshot и `platform_boundary_service_test.php` |
| Snapshot cache | `local/kbacompany/classes/local/entitlement_snapshot_service.php` | 0.6.7, schema 1.0 | EXISTING. SHA-256 hash, last-known-good, grace 86400 с, принимает только `mgmt.*`, fail-closed после grace | `tests/entitlement_snapshot_service_test.php` (5) |
| Tariff catalog | `local/kbacompany/classes/local/bms_tariff_catalog_service.php`, `tariff_catalog.php` | 1.1 | EXISTING, только для чтения. Цен, лимитов и enforcement нет | `tests/bms_commercial_catalog_contract_test.php` (2) |
| Cloud API | `local/kbacompany/classes/external/cloud_api.php`, `db/services.php` | `local_kbacompany_cloud_v1` | EXISTING. 6 функций (3 read, 2 write, contract), `restrictedusers=1`, `enabled=0`, capability `local/kbacompany:cloudsync` (system, RISK_CONFIG, без archetypes) | NOT RUN (отдельного теста API нет) |
| Platform boundary | `local/kbacompany/classes/local/platform_boundary_service.php` | 0.6.9 | EXISTING. Владельцы и запрещённые зависимости, в т. ч. `cloud_registry_tables_inside_bms_business_model` | `tests/platform_boundary_service_test.php` |
| Таблицы | `db/install.xml`: `local_kbacompany_cloudmap`, `local_kbacompany_entsnap`; `db/upgrade.php` (шаги около строк 2808, 2869, 2903) | — | EXISTING. `cloudmap`: уникальные `companyuuid` **и** `workspaceuuid` | — |
| Изоляция компаний | `docs/04_SECURITY_COMPANY_ISOLATION.md`, `tests/pr48_tenant_isolation_test.php` | — | EXISTING на уровне Company UUID в общем Moodle | 2 теста |

### 4.2 Среды и домены

| Объект | Доказательство | Состояние |
| --- | --- | --- |
| Production BMS | `edu.kzn.mba` (73 упоминания в репозитории; MR3: build2026112301 по отчёту PR #165) | EXISTING по документам. Текущий inventory **UNKNOWN** / NOT RUN |
| Test | `test.bms24.online` (3 упоминания) | EXISTING по документам, не проверялся |
| Stage41 | `stage41.bms24.online` (PR серии 1892–1897) | Историческое, текущее состояние UNKNOWN |
| `app.` / `cloud.` / `academy.bms24.online` | MR3-D64–D68, CLOUD-001 §14 | PROPOSED; DNS **UNKNOWN**; DNS не создавать |
| Корпоративный Odoo | MR3-D70, CLOUD-001 §3 | **UNKNOWN**: экземпляр, версия, хостинг и API не подтверждены |

### 4.3 Расхождения кода и контрактов

| # | Расхождение | Где | Риск | Предложение |
| --- | --- | --- | --- | --- |
| D1 | Коды функций: каталог — `management.tasks` и др., лимиты `bms.*`; snapshot и ENT-001 — `mgmt.*`, `limit.*` | `bms_tariff_catalog_service::PRODUCTS/LIMITS` и `entitlement_snapshot_service` (стр. 150, 244) | Высокий при включении `cloud_snapshot`: коды каталога snapshot не примет, функции закроются | Канон — `mgmt.*` / `limit.*` по ENT-001. Каталог исправить отдельным малым PR после решения владельца (OD-6) |
| D2 | Коды продуктов: `management/hire/academy/erp` против `mgmt/academy/cloud/erp/int/api` в REG-001 §10. `hire` в контрактах Drive нет | каталог против REG-001 | Средний | Утвердить единый список application codes в CLOUD-1a; `hire` — решение владельца |
| D3 | Статусы Workspace: `pending/active/degraded/suspended/closed/error` против `draft/provisioning/active/degraded/suspended/closing/closed` | `workspace_mapping_service::validate_status` против REG-002 §14 | Средний | В CLOUD-1a — явная таблица соответствия Registry → проекция, без изменения кода Moodle |
| D4 | Мутации проекции и snapshot не пишут audit event (есть только `createdby/modifiedby`, у snapshot — история строк) | `workspace_mapping_service::bind`, `entitlement_snapshot_service::apply` | Средний: REG-001 §34 и ENT-001 §36 требуют append-only аудит | Канонический аудит ведёт Cloud. В Moodle — Moodle event на apply (будущий PR, вне CLOUD-1) |
| D5 | Кардинальность 1:1 (уникальный `companyuuid`) против many-company в REG-001 §7 | `install.xml` cloudmap | Низкий для беты (v0.1 default — одна primary Company) | Не менять до решения по холдингам (MR3-D79) |
| D6 | Контракты Drive расходятся между собой: состояние ERP `Not Provisioned` (REG-001 §60) отсутствует в enum REG-002; статусы ERP entitlement в MIG-001 §6 (`Requested…Closed`) отличаются от ENT-001 §5 (`Pending…Revoked`); подпись snapshot (ENT-001 §13) против hash в коде | Drive | Низкий / средний | Отметить в v0.2 контрактов: commercial state ≠ operational state (ENT-001 §26). Локальный `not_provisioned` оставить |

---

## 5. Shared и isolated: сравнение

| Критерий | A. Общий Moodle, изоляция по Company UUID | B. Отдельный Moodle на tenant (ADR-SAAS-001, PR #95) | H. Гибрид: A по умолчанию, B для повышенных требований |
| --- | --- | --- | --- |
| Соответствие решениям | CLOUD-001 §6, REG-001 §12, MR3-D10, `docs/12` | Противоречит CLOUD-001 §6 и MR3-D10 без отдельного решения | MR3-D10 + MR3-D11 («выделенные среды при повышенных требованиях») |
| Готовность кода | Высокая: Company scope, ACL, проекции и snapshot уже есть | Код BMS тот же, но нужны автоматизация экземпляров, конфигурации, cron и обновлений — сейчас её нет | Как A, плюс B позже |
| Граница безопасности | Логическая. Ошибка scope затрагивает соседей. Нужен аудит глобальных сущностей Moodle: пользователи, файлы, сессии, кеш, cron, поиск, экспорт | БД, файлы и учётные данные раздельны. Общий хост и Control Plane остаются общей зоной | Выбирается для каждого Workspace |
| Независимое восстановление одной компании | **Нет**: backup на уровне платформы, restore одной компании не реализован (CLOUD-001 §18) | Да, это штатный restore экземпляра | A — нет, B — да |
| Независимые версии и rollout | Нет: одна версия на всех. Канал — feature rollout (CLOUD-001 §12) | Да: staged rollout по экземплярам | Частично |
| Стоимость и эксплуатация | Ниже | Выше: N экземпляров, обновлений и проверок restore | Средняя |
| Release Conveyor (один ZIP) | Подходит как есть | Тот же ZIP, но N установок и N квитанций | Подходит |
| Существующие клиенты | Без изменений | Перенос из общей установки — отдельный workstream (не разрешён) | Без изменений |
| Модель Registry | `ApplicationInstance` shared, `workspace_uuid = null` | `ApplicationInstance` на Workspace | REG-001 поддерживает обе (`shared_or_isolated`) |

**Рекомендация (PROPOSED):** H.
- Registry и Entitlements проектировать с поддержкой обоих режимов. REG-001 и REG-002 это уже позволяют.
- Закрытую бету запускать на A, если владелец принимает такую трактовку G3: две компании в общем Moodle изолированы, есть отрицательные cross-tenant проверки, а «независимое восстановление» — это восстановление платформы плюс проверенный экспорт компании.
- Если владелец требует восстанавливать пространство A, не затрагивая B, то на A это сейчас невозможно. Тогда бета требует либо B хотя бы для одного пилота, либо отдельной реализации company-level export/restore (это большая работа, MR3 её не планирует).

Это решение OD-1, его принимает владелец. Оно не меняет текущий Moodle.

---

## 6. Политика для legacy-компаний (entitlements)

Основание: ENT-001 §51–53, текущий код `entitlement_service`.

1. **L1 — по умолчанию ничего не меняется.** Компания без проекции, или с `entitlement_mode = legacy_compatibility`, получает `allowed = true`, `reason_code = LEGACY_COMPATIBILITY`. CLOUD-1 и CLOUD-2 это поведение не меняют.
2. **L2 — snapshot не включает enforcement.** Применение snapshot сохраняет его, но режим не переключает (правило `snapshot_apply_does_not_enable_cloud_enforcement_automatically`).
3. **L3 — grandfather bundle.** До перевода любой существующей компании в `cloud_snapshot` в каталоге утверждается bundle `bms_legacy_compat`. Он включает все `mgmt.*` коды, которые фактически доступны сегодня. Для каждой компании сравнивается «доступно сейчас» и «будет доступно по snapshot»; при любом отличии — HOLD.
4. **L4 — переключение по одной компании.** Режим меняется только проекцией с более высокой `projection_revision`, для одной компании, с записью в аудите Cloud, по решению владельца. Массовое переключение запрещено.
5. **L5 — откат.** Новая проекция с `entitlement_mode = legacy_compatibility` и следующей ревизией. Данные и история не удаляются (ENT-001 §34–35).
6. **L6 — новые компании беты** создаются сразу через edition bundle. Старые компании переводятся в коммерческие подписки отдельной контролируемой миграцией после беты.
7. **L7 — enforcement-guards** (navigation → read → write) вводятся отдельными PR. Каждый guard — по capability class (ENT-001 §12), с тестами legacy/cloud/suspended и без изменения ACL.

---

## 7. Матрица владельцев данных

| Сущность | Канонический владелец | Система | Роль Cloud | Роль Moodle (`local_kbacompany`) | Состояние |
| --- | --- | --- | --- | --- | --- |
| Коммерческий клиент, сделка, заказ, счёт, подписка | Корпоративный Odoo BMS24 (MR3-D70) | Odoo | `CloudCustomer` и `SubscriptionReference` — только ссылки | Нет | Odoo UNKNOWN, Cloud PROPOSED |
| Product / bundle / entitlement catalog | Product/Cloud architecture (ENT-001 §55) | Cloud | Владелец (CLOUD-2) | Проекция для чтения (`bms_tariff_catalog_service`, сейчас константы) | Moodle EXISTING, Cloud PROPOSED |
| Workspace, ApplicationInstance, Environment, Domain, ProvisioningJob, AuditEvent Cloud | BMS Cloud Control Plane | Cloud (PostgreSQL, MR3-D13) | Владелец | Проекция `cloudmap`: статус, режим, ERP state, health | Moodle EXISTING, Cloud PROPOSED |
| Назначенные entitlements и snapshot | Cloud / Entitlement Service | Cloud | Владелец, выдаёт snapshot | Last-known-good кеш `entsnap`, resolver | Moodle EXISTING |
| BMS Company, memberships, OFS, должности, стратегия, задачи и т. д. | `local_kbacompany` | Moodle | Только `company_uuid` в `WorkspaceCompanyLink` | Владелец | EXISTING |
| Пользователь | Moodle User (CLOUD-001 §15) | Moodle | Только административные ссылки | Владелец | EXISTING |
| Права пользователя (RBAC) | Приложение (Moodle capability + BMS RBAC) | Moodle | Нет | Владелец | EXISTING |
| Feature flags | Приложение, в разрезе компании | Moodle | Нет (ENT-001: flag ≠ entitlement) | Владелец (`bms24_shell_mode`, `*_enabled`) | EXISTING |
| Места и зачисления Academy | KBA Corporate | Moodle | `academy.*` entitlement и лимит | `local_kbacorporate` | EXISTING |
| Операционный факт ERP клиента | Odoo клиента | Odoo | `ApplicationInstance` / `IntegrationConnection` | Импорт факта через `odoo_adapter_service` и `extmap` | Адаптер EXISTING, provisioning PROPOSED |
| Секреты интеграций | Secrets manager (UNKNOWN) | — | Только `credential_reference` | Секреты не хранит | Механизм UNKNOWN |

---

## 8. Предлагаемая физическая архитектура (без DDL)

```
Корпоративный Odoo (commerce master, UNKNOWN)
   │  идемпотентные коммерческие события (customer/order/subscription approved)
   ▼
BMS Cloud Control Plane — отдельный сервис, модульный монолит (MR3-D12/13)
   ├─ registry      : Customer, Workspace, WorkspaceCompanyLink, ApplicationInstance, IntegrationConnection
   ├─ catalog       : Product, Bundle, EntitlementDefinition (versioned)
   ├─ entitlements  : WorkspaceEntitlement, resolver, explain, snapshot issuer
   ├─ provisioning  : ProvisioningJob (idempotency key, state machine), operator checklist
   ├─ audit         : AuditEvent append-only
   └─ PostgreSQL (своя БД; не БД Moodle)
   │  restricted service user, web service local_kbacompany_cloud_v1 (EXISTING, disabled)
   ▼
Общий Moodle — BMS Management + Academy (shared ApplicationInstance)
   ├─ local_kbacompany_cloudmap  (проекция Workspace)
   └─ local_kbacompany_entsnap   (last-known-good snapshot)
```

Принципы:
- P1. Cloud не читает и не пишет таблицы Moodle напрямую — только через `local_kbacompany_cloud_v1` (`external_consumers_reading_moodle_or_bms_tables_directly` запрещено в `platform_boundary_service`).
- P2. Moodle не обращается к Cloud синхронно в page request (ENT-001 §44): обмен идёт push-проекциями и snapshot.
- P3. Создание BMS Company для нового Workspace — только через сервис BMS (REG-001 §39), идемпотентно по коммерческому событию.
- P4. Секреты Registry не хранит (только ссылки). Выбор secrets manager — OD-5.
- P5. Для isolated-режима (вариант B) `ApplicationInstance` получает `workspace_uuid`, а provisioning описывается отдельным контрактом. В бету это не входит без решения OD-1.

Не решено и **не выбирается в CLOUD-0**: язык и framework сервиса, хостинг, расположение кода (отдельный репозиторий или каталог `cloud/` в `bms24`), конвейер выпуска Cloud (Release Conveyor v1 описывает Moodle ZIP), DNS и TLS.

---

## 9. План CLOUD-1…CLOUD-5 и первый PR

| Срез | Содержание | Gate до старта | Gate S/G |
| --- | --- | --- | --- |
| **CLOUD-1a** (предлагаемый первый PR) | Контракты в репозитории + offline-валидатор + синтетические фикстуры A/B. Без runtime, без DDL | Утверждение CLOUD-0 владельцем и независимый review | — |
| CLOUD-1b | Скелет Control Plane: модули registry и audit, миграции PostgreSQL только в одноразовой CI-БД, синтетические данные | OD-2/OD-3 (стек, расположение кода, конвейер) | — |
| CLOUD-2 | Product/Subscription Registry, catalog, resolver, snapshot issuer, quotas; legacy bundle (L3); каталог Moodle как проекция (D1/D2) | CLOUD-1b PASS, OD-6 | Часть S3 |
| CLOUD-3 | Операторский provisioning (идемпотентное activation/suspension/offboarding), monitoring, два изолированных пространства по OD-1, backup/restore, отрицательные cross-tenant проверки | OD-1, OD-4, разрешение на среду | S3 / G3 |
| CLOUD-4 | Закрытая платная бета: оператор, оплата по счёту (MR3-D75/76) | S0–S3 PASS, коммерческое GO (G4) | S5 |
| CLOUD-5 | Self-service, онлайн-оплата, масштабирование | Результаты беты (G5) | S6 |

### 9.1 CLOUD-1a — предлагаемый scope

Предлагаемые новые пути (уточняются при утверждении OD-3):
- `cloud/contracts/registry/v0.1/registry_mvp.yaml` и `.json` — импорт пакета REG-002 после сверки SHA-256 из §18, плюс таблица соответствия статусов Registry → `cloudmap` (D3).
- `cloud/contracts/registry/v0.1/exchange.schema.json` — JSON Schema обмена (REG-002 §16).
- `cloud/contracts/entitlements/v0.1/catalog.yaml` — EntitlementDefinition, bundles `bms_start` → `bms_professional` → `bms_erp`, `bms_academy`, `bms_legacy_compat`; без цен.
- `cloud/contracts/projection/workspace_projection_1.0.schema.json` и `entitlement_snapshot_1.0.schema.json` — формализация **существующих** payload Moodle (без изменения кода Moodle).
- `cloud/fixtures/synthetic/{workspace_a,workspace_b}.json` — только синтетические UUID.
- `tools/cloud/validate_contracts.py` и `tools/cloud/test_validate_contracts.py`.
- Шаг CI в существующем workflow (Dev AI / static) — по согласованию с Codex.

Критерии приёмки:
1. Валидатор проверяет инварианты REG-002 §15 и §62 и catalog QA ENT-001 §49: уникальность кодов, namespace, зависимости без циклов, наследование bundles без циклов, у provisioning-кодов есть handler, у deprecated-кодов есть migration metadata.
2. Валидатор проверяет, что коды catalog `mgmt.*` принимаются правилами `entitlement_snapshot_service`, и **фиксирует** расхождение D1 с текущим `bms_tariff_catalog_service` как известное отклонение (не исправляет).
3. Фикстуры A/B: одинаковые локальные ID в A и B не дают пересечения, повторное событие не создаёт дубль, у закрытого Workspace нет нового job.
4. В `local/`, `theme/`, `db/` ничего не меняется; Moodle ZIP побайтно идентичен baseline (кроме служебных файлов сборки, если они есть).
5. Нет реальных клиентов, доменов, ключей и персональных данных.
6. `python scripts/qa_local.py`, `python tools/check_reconcile_1879.py`, `git diff --check` — PASS; новые тесты PASS.

Зависимости: утверждение CLOUD-0; скачивание пакета REG-002 из Drive и сверка SHA-256; согласование шага CI с Codex. Номер версии плагина не нужен: код Moodle не меняется.

Оценка ресурсов (без сроков): CLOUD-1a — один PR небольшого объёма (контракты, около 300–600 строк валидатора и тестов), один исполнитель плюс независимый reviewer. CLOUD-1b–CLOUD-3 — по 2–4 PR на срез; CLOUD-3 требует серверного оператора и решения по среде. Даты и бюджет не оцениваются, пока нет решений OD-1–OD-5.

---

## 10. Матрица рисков

| ID | Риск | Вероятность | Влияние | Снижение | Gate |
| --- | --- | --- | --- | --- | --- |
| R1 | Ошибка company scope в общем Moodle раскрывает данные соседнего Workspace | Средняя | Критическое | Аудит глобальных сущностей Moodle; отрицательные A/B тесты URL/API/files/cache/cron/export; без fetch-then-filter | G1, G3 |
| R2 | Включение `cloud_snapshot` закрывает существующие функции (D1, неполный bundle) | Высокая без L3 | Высокое | L1–L5; сравнение «до/после» по компании; одна компания за раз | CLOUD-2 |
| R3 | Требование независимого восстановления невыполнимо на общем Moodle | Высокая | Высокое для G3 | Решение OD-1 до CLOUD-3 | G3 |
| R4 | Два каталога или два реестра (Moodle-константы против Cloud) | Средняя | Среднее | Каталог Moodle — только проекция с `catalog_version`; правило «одна сущность — один owner» (MR3-D17) | CLOUD-2 |
| R5 | Повтор коммерческого события создаёт дубль Company, Workspace или ERP | Средняя | Высокое | Idempotency key (REG-001 §24, ENT-001 §39); уникальные ключи REG-002; тесты в фикстурах | CLOUD-1a / 3 |
| R6 | Корпоративный Odoo не готов или его нет | UNKNOWN | Высокое для автоматизации | В бете — операторский ввод `SubscriptionReference` с аудитом (MR3-D76) | CLOUD-4 |
| R7 | Секреты в Registry или в коде | Низкая | Критическое | Только `credential_reference`; secrets manager — OD-5; проверка в валидаторе | CLOUD-1b+ |
| R8 | Учётная запись web service `cloud_v1` с широкими правами | Средняя | Высокое | Restricted user, только `cloudsync`, токен по IP, ротация; сервис включается только решением владельца | CLOUD-3 |
| R9 | Moodle запрашивает Cloud синхронно и падает вместе с ним | Низкая | Среднее | P2, last-known-good, grace | CLOUD-2 |
| R10 | Нет аудита мутаций проекции в Moodle (D4) | Высокая | Среднее | Канонический аудит в Cloud; Moodle event — отдельный PR | CLOUD-2 |
| R11 | Конфликт CLOUD-0 с PR #95 / #184 и двойная документация | Средняя | Среднее | Решение владельца OD-7; этот PR не правит `docs/00`, `ROADMAP` и `AGENTS.md` | Review |
| R12 | Миграция isolated (B) для действующих установок без плана | Низкая (запрещено) | Высокое | Отдельный workstream; не входит в бету | — |

---

## 11. Открытые решения (владелец)

| ID | Решение | Варианты | Рекомендация | Ответственный |
| --- | --- | --- | --- | --- |
| OD-1 | Модель изоляции для беты и трактовка G3 «два изолированных пространства / независимое восстановление» | A / B / H (раздел 5) | H; бета на A, если владелец принимает восстановление на уровне платформы плюс экспорт компании | Владелец |
| OD-2 | Стек Control Plane | Например PHP (переиспользование), Python, TypeScript | Не выбирать в CLOUD-0; критерии — REG-002 §20 | Владелец + архитектор |
| OD-3 | Расположение кода Cloud и конвейер выпуска | Отдельный репозиторий / `cloud/` в `bms24` | `cloud/` в `bms24` для CLOUD-1a (одни контракты, один CI); выделение — позже | Владелец |
| OD-4 | Хостинг Cloud, PostgreSQL, среда для CLOUD-3 | — | Не выбирать без отдельного разрешения | Владелец |
| OD-5 | Secrets manager | — | Отдельный ADR до CLOUD-1b | Владелец + Codex |
| OD-6 | Канон кодов entitlement и продуктов (D1/D2), судьба `hire` | `mgmt.*` по ENT-001 / `management.*` | `mgmt.*`, `limit.*`, application codes по REG-001 | Владелец |
| OD-7 | Связь с PR #95 (ADR-SAAS-001, SAAS_MVP) и #184 | Объединить / оставить #95 как альтернативу B / закрыть | Сохранить ADR-SAAS-001 как описание варианта B; итог зафиксировать одним ADR после OD-1 | Владелец, координатор #198 |
| OD-8 | Есть ли корпоративный Odoo и протокол billing → Cloud | — | Для беты — ручной `SubscriptionReference` с аудитом | Владелец |
| OD-9 | Независимый reviewer CLOUD-0 | — | Назначает владелец (RR31) | Владелец |

---

## 12. Проверки

| Проверка | Результат |
| --- | --- |
| Сверка `main` (`git fetch`, HEAD = origin/main 0884643) | RUN, PASS |
| Чтение пяти контрактов Drive | RUN, PASS |
| Поиск дублей (код, open PR, репозитории владельца) | RUN; результат — в разделе 3 |
| `python scripts/qa_local.py`, `python tools/check_reconcile_1879.py`, `git diff --check` | Результат — в описании PR |
| Сверка SHA-256 пакета REG-002 | NOT RUN |
| Moodle PHPUnit, реальная установка или upgrade, cron, security acceptance | NOT RUN (docs-only) |
| Серверный inventory, DNS, корпоративный Odoo | NOT RUN, запрещено поручением / нет доступа |

## 13. Следующие действия

1. Владелец: решения OD-1, OD-6, OD-7, OD-9; назначение независимого reviewer.
2. Reviewer: проверить этот документ по exact SHA PR и источникам раздела 2.
3. После утверждения — отдельное поручение на CLOUD-1a и запись в #198. До этого CLOUD-1 не начинается.
