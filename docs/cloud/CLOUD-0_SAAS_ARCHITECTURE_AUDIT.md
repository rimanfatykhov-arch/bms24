# CLOUD-0 — SaaS Architecture & Audit

**Статус: PROPOSED, ревизия 2.1 (закрытие handoff #204, замечания Codex 7237057682).** Ожидает независимой проверки Codex (OD-9) по exact head. Только аудит и проект. Документ не разрешает CLOUD-1a, DDL, DNS, provisioning, подключение платных сервисов, оплату, merge, TEST/staging/production или deployment.

| Поле | Значение |
| --- | --- |
| Линия | BMS-10 Cloud / SaaS (P0), поручение [#204](https://github.com/rimanfatykhov-arch/bms24/issues/204), реестр [#198](https://github.com/rimanfatykhov-arch/bms24/issues/198), PR [#207](https://github.com/rimanfatykhov-arch/bms24/pull/207) |
| Исполнитель / reviewer | Claude (ownership #207) / Codex (OD-9, не пушит в ветку автора) |
| Решение владельца | [PR #207, комментарий 7236311964](https://github.com/rimanfatykhov-arch/bms24/pull/207#issuecomment-7236311964), 28.11.2026 |
| Baseline ревизии 2 | `main` = `74e00c4a63cbd5d37759e604753ebaa4b4fc7947`, tree `de454b208e0bac07ff960f80ec1de0da87418253`; `local_kbacompany` `1.1.0-rc29`, `$plugin->version = 2026112400` |
| Baseline ревизии 1 | `main` = `0884643590541fdd9b75b1414fd93d6120ded017`, `2026112301` (head #207 `4575a1e4…`) |
| Изменения main между ревизиями | 36 файлов (R5a #172: календарь отчётности, privacy provider, 3 новые таблицы). Аудированные файлы (`workspace_mapping_service`, `entitlement_service`, `entitlement_snapshot_service`, `bms_tariff_catalog_service`, `platform_boundary_service`, `company_registry_service`, `identity_registry`, `odoo_adapter_service`, `cloud_api`, `db/services.php`, `db/access.php`, `tariff_catalog.php`, определения `cloudmap`/`entsnap`) не изменились — проверено `git diff 0884643 74e00c4` |
| Изменённые пути PR | `docs/cloud/CLOUD-0_SAAS_ARCHITECTURE_AUDIT.md`, `docs/cloud/cloud0-evidence-inventory.json`, `docs/adr/ADR-SAAS-002-hybrid-cloud-isolation-PROPOSED.md` (только документы) |
| Влияние на БД / ACL / privacy / интеграции | Нет. Код, схема, lang, CI и настройки не изменяются |

Обозначения состояния: **EXISTING** — есть в `main` на baseline (указан путь); **PROPOSED** — описано в контрактах или PR, не реализовано; **ACCEPTED** — решение владельца; **UNKNOWN** — источник недоступен или доказательства нет; **NOT RUN** — проверка не выполнялась; **HOLD** — gate закрыт до доказательства.

---

## 1. Итоги для владельца

1. **Дубля Cloud Control Plane нет, но в Moodle уже есть его клиентская сторона.** В `local_kbacompany` есть проекция Workspace (`workspace_mapping_service`, таблица `local_kbacompany_cloudmap`), кеш entitlement snapshot (`entitlement_snapshot_service`, `local_kbacompany_entsnap`), resolver (`entitlement_service`), контракт тарифного каталога (`bms_tariff_catalog_service`) и закрытый web service `local_kbacompany_cloud_v1` (по умолчанию выключен). Отдельного сервиса Cloud, PostgreSQL, Product/Subscription Registry и корпоративного Odoo **в доступных источниках нет**. CLOUD-1 строится как внешний Control Plane, пишущий в существующие проекции; второй реестр компаний или вторая проекция в Moodle запрещены.
2. **Текущим компаниям ничего не грозит.** `entitlement_service::has()` в `main` не вызывается ни в одном guard; `explain()` используется только диагностикой (`platform_boundary_service`). Без проекции и в режиме `legacy_compatibility` — `allowed = true` (`LEGACY_COMPATIBILITY`); snapshot режим не переключает. Политика L1–L7 (раздел 6) сверена с кодом.
3. **Изоляция — гибрид (OD-1 ACCEPTED).** Действующий общий Moodle сохраняется, существующие shared-компании не мигрируют, архитектура предусматривает отдельные изолированные пространства. **G3 остаётся HOLD**: восстановление общей платформы плюс экспорт компании **не** считается независимым восстановлением. Нужно доказать восстановление одного пространства без воздействия на другое (критерий — раздел 5.2). Для беты нужны изолированные пространства либо доказанное company-level восстановление; механизм и среда — отдельное решение.
4. **Коды каталога (OD-6 ACCEPTED):** канон `mgmt.*` / `limit.*` по ENT-001. Mapping текущих `management.*` / `bms.*` зафиксирован в разделе 6.1 только как документ; runtime, доступы и legacy enforcement не меняются. Судьба Hire открыта.
5. **CLOUD-1a** размещается в `cloud/` репозитория `bms24` (OD-3 ACCEPTED), ТЗ утверждено, но **HOLD до CLOUD-0 PASS и отдельного GO владельца**. CI — только по согласованию с Codex.

---

## 2. Проверенные источники

| Источник | Версия / SHA | Результат |
| --- | --- | --- |
| `AGENTS.md`, `docs/12_CLOUD_ERP_BOUNDARIES.md`, `docs/deploy/BMS24_RELEASE_CONVEYOR_V1.md`, [#63](https://github.com/rimanfatykhov-arch/bms24/issues/63), #198, #204 | `main` 0884643, сверено на 74e00c4 | Прочитано |
| [BMS-ARC-CLOUD-001](https://docs.google.com/document/d/1fGSKD4tDUzzjumYwxAgAZl3u3hZD78ebNAPLTo7zqZc/edit) v0.1 PROPOSED | Google Drive, 28.11.2026 | Прочитано полностью |
| [BMS-ARC-REG-001](https://docs.google.com/document/d/1mC6aEAWzMnBuE2ovZpfumG-T5LHj2Y8XDcZadQpaLsk/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [BMS-ARC-REG-002](https://docs.google.com/document/d/12DvSqybN58QFcxWFziJfmwCCENZW9Fo5zQ7f_7rIYig/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [REG-002 Schema Package v0.1.zip](https://drive.google.com/file/d/1y0CAa4nHhnSRxMRJHSIpWACRdC8C2cX6/view) | Drive id `1y0CAa4nHhnSRxMRJHSIpWACRdC8C2cX6`, 151 666 байт, `modifiedTime` 2026-09-20T04:17:24Z | **SHA-256 ZIP `093fa2936bc9f32c2828f9acfb2459de37fc221701319d1b9a6e91a699da28f3` совпадает с §18** — байты скачал и проверил Codex ([#204, комментарий 7236624093](https://github.com/rimanfatykhov-arch/bms24/issues/204#issuecomment-7236624093); комментарий опубликован с аккаунта владельца, атрибуция подтверждена в [ревью Codex 7237057682](https://github.com/rimanfatykhov-arch/bms24/pull/207#issuecomment-7237057682)). Claude независимо проверил метаданные Drive: размер 151 666 байт и дата изменения (не менялся с 20.09.2026) совпадают. Повторный хеш байтов Claude: NOT RUN (содержимое не скачивалось в сессию). Внутренние файлы (YAML/JSON/Mermaid/PNG) и их SHA-256: NOT RUN; импорт: не выполнялся (CLOUD-1a HOLD) |
| [BMS-ARC-ENT-001](https://docs.google.com/document/d/1D6BBA1_12rkRiXu2sM6y5A6gJQR_ggUCWHe1ujg1hx4/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [BMS-ARC-MIG-001](https://docs.google.com/document/d/19-a39u5U4yI73svjBVyQTafAxWtmTxbNe47D-peIThQ/edit) v0.1 PROPOSED | то же | Прочитано полностью |
| [RELEASE ROADMAP 3.1](https://docs.google.com/document/d/1pHuELJWEXaynqZqI0uzm8brzoLnq2lNnW6u9CCPY85s/edit) | изменён 28.11.2026 07:38 UTC | Прочитано (RR31-D01–D07, CLOUD-0…5, G0–G5) |
| MASTER ROADMAP 3.0 (зеркало в [PR #184](https://github.com/rimanfatykhov-arch/bms24/pull/184), `docs/product/MASTER_ROADMAP_3.md`) | head `96fb84a464caccdaeb1be0d00dc63ac11dc24f69` | Прочитаны MR3-D10–D19, D64–D79, S0–S6, BMS-10. Сам Google-документ MR3 не открывался |
| [PR #95](https://github.com/rimanfatykhov-arch/bms24/pull/95): `ADR-SAAS-001`, `SAAS_MVP.md` | head `9e80ee55dd27718171d266a017a217f7f231409a` | Прочитано. Сохраняется как альтернатива (OD-7), не изменяется |
| [PR #97](https://github.com/rimanfatykhov-arch/bms24/pull/97) | head `696b5b356bc5d9ac1b1a9ce8d586338856dae43a` | Изучен только список файлов |
| Репозиторий `bms24-context` (приватный) | HEAD `a4d3ef1f`, 19.11.2026 | Cloud-материал — только `sessions/2026-11-14-saas-plan-pr95.md` и записи в `DECISIONS.md` о #95/#97 |
| Другие репозитории владельца (`biznes-akademiya-marketing`, `sites`, `ba-chats`, `kzn.mba`) | — | По названиям к Cloud не относятся; содержимое NOT RUN |
| BMS-ARC-DOM-001, BMS-PRD-EDT-001, BMS-ARC-INT-001, BMS-ARC-ERP-001, ADR Odoo Hosting | — | **UNKNOWN**: ссылки на документы не предоставлены |
| Корпоративный Odoo BMS24, хостинг Cloud, DNS `*.bms24.online`, production inventory | — | **UNKNOWN**: доступа нет, серверные проверки запрещены |

---

## 3. Проверка дублей

| Кандидат | Где | Вывод |
| --- | --- | --- |
| Проекция Workspace в Moodle | `workspace_mapping_service` 0.6.8, `local_kbacompany_cloudmap` | EXISTING, *проекция* (`moodle_role = projection_cache`), не Registry. CLOUD-1 пишет в неё |
| Entitlement runtime | `entitlement_service` 0.6.7, `entitlement_snapshot_service` 0.6.7, `local_kbacompany_entsnap` | EXISTING, соответствует ENT-001 §13–16, §51 этап 1. В Cloud — resolver и выдача snapshot; второй resolver в Moodle не нужен |
| Тарифный каталог | `bms_tariff_catalog_service` 1.1, `tariff_catalog.php` | EXISTING, только чтение, константы в коде. После CLOUD-2 — проекция Product Registry, иначе два каталога |
| Реестр компаний | `company_registry_service`, `local_kbacompany_company` | EXISTING, канонический владелец BMS Company. Cloud хранит только `WorkspaceCompanyLink` по UUID |
| Внешние идентичности | `identity_registry`, `local_kbacompany_extmap` | EXISTING, mapping компаний/пользователей у провайдеров (Bitrix24, Odoo клиента). Не путать с CloudCustomer ↔ коммерческий Odoo |
| Odoo | `odoo_adapter_service` 0.9.1.3, `odoo_json2_transport`, `odoo_actual_*` | EXISTING, но это **ERP клиента**, а не корпоративный коммерческий Odoo (MR3-D70) |
| Лицензии Academy | `local/kbacorporate/classes/local/licenses.php`, `local/bitrixsync/.../license_*` | EXISTING, владелец — KBA Corporate (ENT-001 §22) |
| SaaS MVP / изоляция | PR #95 (`ADR-SAAS-001`, `SAAS_MVP.md`), PR #97 | PROPOSED, не смержены. По OD-7 #95 сохраняется как альтернатива; единый ADR — [`ADR-SAAS-002`](../adr/ADR-SAAS-002-hybrid-cloud-isolation-PROPOSED.md) в этом PR, без изменения чужих PR |
| Сервис Cloud / Control Plane | — | Отсутствует в доступных источниках. Дубля нет |

---

## 4. Evidence inventory: что есть в `main`

Машиночитаемая версия — [`cloud0-evidence-inventory.json`](cloud0-evidence-inventory.json).

### 4.1 Компоненты

| Компонент | Путь | Контракт | Состояние | Тесты |
| --- | --- | --- | --- | --- |
| Workspace projection | `local/kbacompany/classes/local/workspace_mapping_service.php` | 0.6.8, projection schema 1.0 | EXISTING. 1:1 Company ↔ Workspace, монотонная `projection_revision`, привязка неизменна, без provider ID | `tests/workspace_mapping_service_test.php` (3) |
| Entitlement resolver | `local/kbacompany/classes/local/entitlement_service.php` | 0.6.7 | EXISTING. Режимы `legacy_compatibility` / `cloud_snapshot` / `suspended`. Порядок: entitlement → company scope → capability → business rule | через тесты snapshot и `platform_boundary_service_test.php` |
| Snapshot cache | `local/kbacompany/classes/local/entitlement_snapshot_service.php` | 0.6.7, schema 1.0 | EXISTING. SHA-256 hash, last-known-good, grace 86400 с, только `mgmt.*`, fail-closed после grace | `tests/entitlement_snapshot_service_test.php` (5) |
| Tariff catalog | `local/kbacompany/classes/local/bms_tariff_catalog_service.php`, `tariff_catalog.php` | 1.1 | EXISTING, только чтение; цен, лимитов, enforcement нет | `tests/bms_commercial_catalog_contract_test.php` (2) |
| Cloud API | `local/kbacompany/classes/external/cloud_api.php`, `db/services.php` | `local_kbacompany_cloud_v1` | EXISTING. 6 функций, `restrictedusers=1`, `enabled=0`, capability `local/kbacompany:cloudsync` (system, RISK_CONFIG, без archetypes) | NOT RUN (отдельного теста нет) |
| Platform boundary | `local/kbacompany/classes/local/platform_boundary_service.php` | 0.6.9 | EXISTING. Владельцы и запрещённые зависимости, в т. ч. `cloud_registry_tables_inside_bms_business_model` | `tests/platform_boundary_service_test.php` |
| Таблицы | `db/install.xml`: `local_kbacompany_cloudmap`, `local_kbacompany_entsnap`; `db/upgrade.php` (строки 2808, 2869, 2903) | — | EXISTING. `cloudmap`: уникальные `companyuuid` **и** `workspaceuuid` | — |
| Изоляция компаний | `docs/04_SECURITY_COMPANY_ISOLATION.md`, `tests/pr48_tenant_isolation_test.php` | — | EXISTING на уровне Company UUID в общем Moodle | 2 |
| Company-level restore в общем Moodle | — | CLOUD-001 §18 («будущая возможность») | **Отсутствует** | — |

### 4.2 Среды и домены

| Объект | Доказательство | Состояние |
| --- | --- | --- |
| Production BMS | `edu.kzn.mba` (упоминания в репозитории; MR3: build2026112301 по отчёту PR #165) | EXISTING по документам; inventory UNKNOWN / NOT RUN |
| Test | `test.bms24.online` | EXISTING по документам, не проверялся |
| Stage41 | `stage41.bms24.online` (PR 1892–1897) | Историческое, текущее состояние UNKNOWN |
| `app.` / `cloud.` / `academy.bms24.online` | MR3-D64–D68, CLOUD-001 §14 | PROPOSED; DNS UNKNOWN; DNS не создавать |
| Корпоративный Odoo | MR3-D70, CLOUD-001 §3 | **UNKNOWN** |

### 4.3 Расхождения кода и контрактов

| # | Расхождение | Где | Риск | Решение / предложение |
| --- | --- | --- | --- | --- |
| D1 | Коды функций: каталог — `management.*`, лимиты `bms.*`; snapshot и ENT-001 — `mgmt.*`, `limit.*` | `bms_tariff_catalog_service::PRODUCTS/LIMITS` против `entitlement_snapshot_service` (стр. 150, 244) | Высокий при включении `cloud_snapshot` | OD-6 ACCEPTED: канон `mgmt.*`/`limit.*`. Mapping — раздел 6.1. Код не меняется в CLOUD-0/1a |
| D2 | Коды продуктов `management/hire/academy/erp` против `mgmt/academy/cloud/erp/int/api` (REG-001 §10) | каталог против REG-001 | Средний | OD-6: application codes по REG-001; `hire` — OPEN (раздел 6.1) |
| D3 | Статусы Workspace: `pending/active/degraded/suspended/closed/error` против `draft/provisioning/active/degraded/suspended/closing/closed` | `workspace_mapping_service::validate_status` против REG-002 §14 | Средний | Таблица соответствия — раздел 6.2 (PROPOSED, без изменения кода) |
| D4 | Мутации проекции и snapshot не пишут audit event (только `createdby/modifiedby`, у snapshot — история строк) | `workspace_mapping_service::bind`, `entitlement_snapshot_service::apply` | Средний (REG-001 §34, ENT-001 §36) | Канонический аудит — в Cloud; Moodle event — отдельный будущий PR |
| D5 | Кардинальность 1:1 против many-company (REG-001 §7) | `install.xml` cloudmap | Низкий для беты | Не менять до решения по холдингам (MR3-D79) |
| D6 | Расхождения контрактов Drive: `Not Provisioned` (REG-001 §60) нет в enum REG-002; статусы ERP entitlement MIG-001 §6 против ENT-001 §5; подпись snapshot ENT-001 §13 против hash в коде | Drive | Низкий / средний | Учесть в v0.2: commercial state ≠ operational state (ENT-001 §26); локальный `not_provisioned` оставить |

---

## 5. Изоляция: shared, isolated, гибрид

### 5.1 Сравнение и принятое решение

| Критерий | A. Общий Moodle, изоляция по Company UUID | B. Отдельный экземпляр на пространство (ADR-SAAS-001, #95) | H. Гибрид (OD-1 ACCEPTED) |
| --- | --- | --- | --- |
| Соответствие решениям | CLOUD-001 §6, REG-001 §12, MR3-D10, `docs/12` | Альтернатива из #95, сохраняется (OD-7) | MR3-D10 + MR3-D11 + OD-1 |
| Готовность кода | Высокая: Company scope, ACL, проекции и snapshot есть | Код BMS тот же; автоматизации экземпляров, конфигурации, cron, обновлений нет | A есть; изолированный вариант — проект |
| Граница безопасности | Логическая; ошибка scope затрагивает соседей | БД, файлы, учётные данные раздельны; общий хост и Control Plane остаются общей зоной | Выбирается на пространство |
| **Независимое восстановление одного пространства** | **Нет.** Backup — на уровне платформы; company-level restore не реализован (CLOUD-001 §18). Restore платформы плюс экспорт компании **не** является независимым восстановлением (OD-1) | Да, штатный restore экземпляра (нужно доказать) | Только для изолированных пространств или после доказанного company-level restore |
| Независимые версии / rollout | Нет | Да | Частично |
| Стоимость и эксплуатация | Ниже | Выше | Средняя |
| Release Conveyor (один ZIP) | Подходит | Тот же ZIP, N установок и квитанций | Подходит |
| Существующие клиенты | Без изменений | Миграция не разрешена | **Без миграции** (OD-1) |
| Модель Registry | `ApplicationInstance` shared, `workspace_uuid = null` | `ApplicationInstance` на Workspace | Обе (`shared_or_isolated`, REG-001) |

**Решение владельца OD-1 (ACCEPTED 28.11.2026):** гибридная архитектура.
- Действующий общий Moodle сохраняется; существующие shared-компании не мигрируют.
- Registry и Entitlements поддерживают оба режима с первого среза.
- Для беты нужны изолированные пространства **либо** доказанное company-level восстановление. Конкретный механизм и среда — отдельное решение (OPEN: механизм восстановления, OD-4).
- Требование независимого восстановления G3 не ослабляется. Ревизия 1 этого документа ошибочно допускала бету на общей платформе с восстановлением платформы и экспортом компании. Эта трактовка **отменена** (раздел 14).

### 5.2 Проверяемый критерий G3 (HOLD до доказательства)

G3 = PASS только если все пункты выполнены на двух пространствах A и B с синтетическими данными, в согласованной среде, с evidence (exact SHA/ZIP, журнал команд, контрольные суммы):

1. **Исходное состояние B зафиксировано:** контрольный набор B₀ — контрольные суммы данных B на момент начала операции (записи БД в границах B, файлы B, конфигурация B, entitlements/проекция B) — и доступность B до операции.
2. **Восстановление A:** A восстанавливается на точку T; после восстановления состояние A совпадает с эталоном T.
3. **Отсутствие воздействия на B** — три раздельно проверяемых условия:
   - 3a. **Неизменность контрольного набора.** Объекты, входящие в B₀, после операции имеют те же контрольные суммы, что в п. 1. Во время восстановления A контрольный трафик эти объекты не изменяет.
   - 3b. **Сохранность новых записей B.** Во время восстановления A в B выполняется заранее описанный контрольный трафик; каждая запись, подтверждённая B (ответ об успехе), фиксируется в журнале W_B с идентификатором и контрольной суммой. После операции все записи W_B присутствуют в B и совпадают с журналом; записей сверх W_B (кроме служебных, перечисленных заранее) нет.
   - 3c. **Итоговый эталон.** Итоговое состояние B равно эталону «B₀ + W_B»; это сравнение заменяет сравнение полного хеша B с п. 1, который не может проверить 3a и 3b одновременно.
   - 3d. **Доступность и изоляция сессий.** B оставался доступным без простоя сверх согласованного порога; сессии и кеш B не сброшены и не смешаны с A.
4. **Отрицательные tenant-сценарии:** одинаковые локальные ID в A и B не открывают чужие объекты; прямые URL/API/файлы/экспорт/поиск/кеш/cron A недоступны из B и наоборот; отзыв доступа действует в открытой сессии.
5. **Восстановление общей платформы плюс экспорт компании не засчитывается** как выполнение п. 2–3.

Для общего Moodle (вариант A) пп. 2–3 сейчас невыполнимы: company-level restore отсутствует. Поэтому G3 — **HOLD**, пока не будет либо изолированных пространств, либо реализованного и доказанного company-level restore. Выбор механизма — отдельное решение.

---

## 6. Политика для legacy-компаний (entitlements)

Основание: ENT-001 §51–53, код `entitlement_service`. Сверка с кодом на baseline 74e00c4 — в последнем столбце.

| # | Правило | Проверка по коду |
| --- | --- | --- |
| L1 | По умолчанию ничего не меняется: без проекции или с `legacy_compatibility` — `allowed = true`, `LEGACY_COMPATIBILITY`. CLOUD-1/2 это не меняют | Подтверждено: `workspace_mapping_service::unmapped()` возвращает `MODE_LEGACY`; `entitlement_service::explain()` возвращает allow для `MODE_LEGACY` |
| L2 | Snapshot не включает enforcement | Подтверждено: `apply()` меняет только `snapshotrevision` через `record_snapshot_revision()`; `entitlementmode` не трогается |
| L3 | До перевода существующей компании в `cloud_snapshot` — утверждённый bundle `bms_legacy_compat` со всеми фактически доступными `mgmt.*` (включая `mgmt.core`, используемый диагностикой); сравнение «доступно сейчас / по snapshot», при отличии HOLD | PROPOSED (каталога нет) |
| L4 | Переключение по одной компании проекцией с более высокой `projection_revision`, с аудитом Cloud и решением владельца; массовое запрещено | Механизм есть: `bind()` обновляет режим; старая ревизия отклоняется. Аудит в Moodle отсутствует (D4) |
| L5 | Откат — новая проекция с `legacy_compatibility` и следующей ревизией; данные не удаляются | Механизм есть (тот же `bind()`) |
| L6 | Новые компании беты — сразу через edition bundle; старые — отдельная контролируемая миграция после беты | PROPOSED |
| L7 | Guards (navigation → read → write) — отдельными PR, по capability class (ENT-001 §12), с тестами legacy/cloud/suspended, без изменения ACL | Guards отсутствуют (`has()` не вызывается) — подтверждено `git grep` |

Автоматический enforcement не вводится ни в CLOUD-0, ни в CLOUD-1a.

### 6.1 Mapping кодов (OD-6, только документ)

Канон — ENT-001 (`mgmt.*`, `limit.*`); application codes — REG-001 §10. Runtime, доступы и legacy enforcement не меняются; исправление `bms_tariff_catalog_service` — отдельный PR по отдельному решению.

| Текущий код (`bms_tariff_catalog_service` 1.1) | Канонический код | Статус |
| --- | --- | --- |
| продукт `management` | application `mgmt` | PROPOSED mapping |
| продукт `academy` | application `academy` | Совпадает |
| продукт `erp` | application `erp` | Совпадает |
| продукт `hire` | — | **OPEN** (судьба Hire отдельно) |
| `management.tasks` | `mgmt.tasks` | PROPOSED mapping |
| `management.calendar` | `mgmt.calendar` | PROPOSED mapping |
| `management.battle_plans` | `mgmt.battle_plans` | PROPOSED mapping |
| `management.indicators` | `mgmt.indicators` | PROPOSED mapping |
| `management.processes` | `mgmt.processes` | PROPOSED mapping |
| `hire.vacancies`, `hire.candidates` | — | **OPEN** |
| `academy.learning` | `academy.learning` (ENT-001 §22 упоминает `academy.corporate`; согласовать в каталоге) | Namespace совпадает, код уточнить |
| `erp.operations` | `erp.*` (ENT-001 §10 называет `erp.application`; согласовать в каталоге) | Namespace совпадает, код уточнить |
| `bms.company_members` | `limit.users` (ENT-001 §31) | PROPOSED mapping |
| `bms.storage_bytes` | `limit.storage_bytes` (нет в примерах ENT-001) | PROPOSED, новый код каталога |
| `bms.ai_operations` | `limit.ai_operations` (нет в примерах ENT-001) | PROPOSED, новый код каталога |
| — (используется диагностикой) | `mgmt.core` | Обязателен в `bms_legacy_compat` (L3) |

Application codes REG-001: `mgmt`, `academy`, `cloud`, `erp`, `int`, `api`, `auth` (при появлении).

### 6.2 Соответствие статусов Registry → проекция (D3, PROPOSED)

| REG-002 Workspace | `cloudmap.cloudstatus` | Примечание |
| --- | --- | --- |
| `draft` | `pending` | Проекция обычно ещё не создаётся |
| `provisioning` | `pending` | |
| `active` | `active` | |
| `degraded` | `degraded` | |
| `suspended` | `suspended` | Resolver возвращает `WORKSPACE_SUSPENDED` |
| `closing` | `suspended` | Новые действия запрещены, данные сохраняются |
| `closed` | `closed` | |
| — | `error` | Только локальное диагностическое состояние проекции |

Проверка таблицы — задача reviewer и валидатора CLOUD-1a; код Moodle не меняется.

---

## 7. Матрица владельцев данных

| Сущность | Канонический владелец | Система | Роль Cloud | Роль Moodle (`local_kbacompany`) | Состояние |
| --- | --- | --- | --- | --- | --- |
| Коммерческий клиент, сделка, заказ, счёт, подписка | Корпоративный Odoo BMS24 — целевой master (MR3-D70, OD-8) | Odoo | `CloudCustomer`, `SubscriptionReference` — ссылки; в бете ручная регистрация с аудитом и provenance (OD-8) | Нет | Odoo UNKNOWN, Cloud PROPOSED |
| Product / bundle / entitlement catalog | Product/Cloud architecture (ENT-001 §55) | Cloud | Владелец (CLOUD-2) | Проекция для чтения (`bms_tariff_catalog_service`, сейчас константы) | Moodle EXISTING, Cloud PROPOSED |
| Workspace, ApplicationInstance, Environment, Domain, ProvisioningJob, AuditEvent | BMS Cloud Control Plane | Cloud (PostgreSQL, MR3-D13) | Владелец | Проекция `cloudmap` | Moodle EXISTING, Cloud PROPOSED |
| Назначенные entitlements и snapshot | Cloud / Entitlement Service | Cloud | Владелец, выдаёт snapshot | Кеш `entsnap`, resolver | Moodle EXISTING |
| BMS Company, memberships, OFS, должности, стратегия, задачи | `local_kbacompany` | Moodle | Только `company_uuid` в `WorkspaceCompanyLink` | Владелец | EXISTING |
| Пользователь | Moodle User (CLOUD-001 §15) | Moodle | Административные ссылки | Владелец | EXISTING |
| Права пользователя (RBAC) | Приложение (capability + BMS RBAC) | Moodle | Нет; entitlement ≠ ACL | Владелец | EXISTING |
| Feature flags | Приложение, в разрезе компании | Moodle | Нет (flag ≠ entitlement) | Владелец | EXISTING |
| Места и зачисления Academy | KBA Corporate | Moodle | `academy.*` entitlement и лимит | `local_kbacorporate` | EXISTING |
| Операционный факт ERP клиента | Odoo клиента | Odoo | `ApplicationInstance` / `IntegrationConnection` | Импорт через `odoo_adapter_service` и `extmap` | Адаптер EXISTING, provisioning PROPOSED |
| Секреты интеграций | Secrets manager (OD-5 OPEN) | — | Только `credential_reference` | Не хранит | UNKNOWN |

---

## 8. Предлагаемая физическая архитектура (без DDL)

```
Корпоративный Odoo (целевой commerce master; наличие UNKNOWN)
   │  бета: ручная регистрация SubscriptionReference с аудитом и provenance (OD-8)
   │  позже: идемпотентные коммерческие события
   ▼
BMS Cloud Control Plane — отдельный сервис, модульный монолит (MR3-D12/13); стек OD-2 OPEN
   ├─ registry      : Customer, Workspace, WorkspaceCompanyLink, ApplicationInstance (shared | isolated), IntegrationConnection
   ├─ catalog       : Product, Bundle, EntitlementDefinition (versioned)
   ├─ entitlements  : WorkspaceEntitlement, resolver, explain, snapshot issuer
   ├─ provisioning  : ProvisioningJob (idempotency key, state machine), operator checklist
   ├─ audit         : AuditEvent append-only
   └─ PostgreSQL (своя БД; не БД Moodle); хостинг OD-4 OPEN
   │  restricted service user, web service local_kbacompany_cloud_v1 (EXISTING, disabled)
   ▼
Общий Moodle (shared ApplicationInstance)            Изолированные пространства (isolated ApplicationInstance)
   ├─ local_kbacompany_cloudmap                        механизм и среда — отдельное решение (OD-1, OD-4)
   └─ local_kbacompany_entsnap                         тот же ZIP BMS, отдельные БД/файлы/учётные данные
```

Принципы:
- P1. Cloud не читает и не пишет таблицы Moodle напрямую — только через `local_kbacompany_cloud_v1`.
- P2. Moodle не обращается к Cloud синхронно в page request (ENT-001 §44): push-проекции и snapshot.
- P3. BMS Company для нового Workspace создаётся только сервисом BMS (REG-001 §39), идемпотентно.
- P4. Секреты Registry не хранит (только ссылки); secrets manager — OD-5 OPEN.
- P5. `ApplicationInstance` поддерживает shared и isolated с первого среза (OD-1). Существующие shared-компании остаются в shared без миграции.
- P6. Реальная оплата и автоматические подписки сейчас не включаются (OD-8).

Не выбирается в CLOUD-0: стек (OD-2), хостинг и среда (OD-4), secrets manager (OD-5), механизм независимого восстановления, DNS/TLS, конвейер выпуска Cloud.

---

## 9. План CLOUD-1…CLOUD-5 и первый PR

| Срез | Содержание | Gate до старта | Gate S/G |
| --- | --- | --- | --- |
| **CLOUD-1a** (ТЗ утверждено, **HOLD**) | `cloud/` в `bms24`: контракты, offline-валидатор, синтетические фикстуры A/B. Без runtime, без DDL, без изменения Moodle (OD-3) | CLOUD-0 PASS от Codex на exact head **и** отдельное GO владельца | — |
| CLOUD-1b | Скелет Control Plane: registry и audit, миграции PostgreSQL только в одноразовой CI-БД | OD-2, OD-5, согласование CI с Codex | — |
| CLOUD-2 | Product/Subscription Registry, catalog, resolver, snapshot issuer, quotas; `bms_legacy_compat` (L3); каталог Moodle как проекция (D1/D2) | CLOUD-1b PASS, решение по Hire | Часть S3 |
| CLOUD-3 | Операторский provisioning (идемпотентные activation/suspension/offboarding), monitoring, два изолированных пространства, backup/restore, отрицательные cross-tenant проверки | Механизм независимого восстановления и среда (OD-1/OD-4), разрешение на среду | **S3 / G3 по критерию 5.2** |
| CLOUD-4 | Закрытая платная бета: оператор, ручная регистрация подписок и оплата по счёту (MR3-D75/76, OD-8) | S0–S3 PASS, G3 PASS, коммерческое GO (G4) | S5 |
| CLOUD-5 | Self-service, онлайн-оплата, масштабирование | Результаты беты (G5) | S6 |

### 9.1 CLOUD-1a — утверждённое ТЗ (реализация HOLD)

Пути (OD-3: каталог `cloud/` репозитория `bms24`):
- `cloud/contracts/registry/v0.1/registry_mvp.yaml` и `.json` — импорт пакета REG-002. SHA-256 ZIP подтверждён (`093fa293…f28f3`); при импорте валидатор обязан сверить SHA-256 внутренних файлов с REG-002 §18 (YAML `dc4a77a0…44b1`, JSON `118e8c74…c47f`, JSON Schema `18332150…7f2`, Mermaid `0d6093db…fd7f`). Несовпадение — FAIL, импорт не выполняется.
- `cloud/contracts/registry/v0.1/exchange.schema.json` — JSON Schema обмена (REG-002 §16).
- `cloud/contracts/registry/v0.1/status_mapping.yaml` — таблица раздела 6.2.
- `cloud/contracts/entitlements/v0.1/catalog.yaml` — EntitlementDefinition, bundles `bms_start` → `bms_professional` → `bms_erp`, `bms_academy`, `bms_legacy_compat`; без цен; mapping раздела 6.1 как `legacy_aliases` (только документ).
- `cloud/contracts/projection/workspace_projection_1.0.schema.json`, `entitlement_snapshot_1.0.schema.json` — формализация **существующих** payload Moodle.
- `cloud/fixtures/synthetic/{workspace_a,workspace_b}.json` — только синтетические UUID; A shared, B isolated.
- `cloud/tools/validate_contracts.py` и тесты рядом (точное место тестов согласуется с Codex).
- CI — **только по отдельному согласованию с Codex** (OD-3); до него валидатор запускается вручную.

Критерии приёмки:
1. Валидатор проверяет инварианты REG-002 §15 и §62 и catalog QA ENT-001 §49 (уникальность кодов, namespace, зависимости и наследование без циклов, handler у provisioning-кодов, migration metadata у deprecated).
2. Валидатор подтверждает, что коды каталога `mgmt.*` принимаются правилами `entitlement_snapshot_service`, и **фиксирует** D1 как известное отклонение (не исправляет).
3. Фикстуры A/B: одинаковые локальные ID не пересекаются; повторное событие не создаёт дубль; закрытый Workspace не получает новый job; `bms_legacy_compat` содержит `mgmt.core`.
4. В `local/`, `theme/`, `db/` ничего не меняется.
5. Нет реальных клиентов, доменов, ключей, подписок, оплаты и персональных данных.
6. `python scripts/qa_local.py`, `python tools/check_reconcile_1879.py`, `git diff --check` — PASS (PHP lint — в окружении с PHP); новые тесты PASS.

Оценка ресурсов (без сроков): CLOUD-1a — один PR небольшого объёма, один исполнитель и независимый reviewer. CLOUD-1b–CLOUD-3 — по 2–4 PR на срез; CLOUD-3 требует серверного оператора и решения по среде и механизму восстановления.

---

## 10. Матрица рисков

| ID | Риск | Вероятность | Влияние | Снижение | Gate |
| --- | --- | --- | --- | --- | --- |
| R1 | Ошибка company scope в общем Moodle раскрывает данные соседа | Средняя | Критическое | Аудит глобальных сущностей Moodle; отрицательные A/B тесты (5.2 п. 4); без fetch-then-filter | G1, G3 |
| R2 | Включение `cloud_snapshot` закрывает существующие функции (D1, неполный bundle) | Высокая без L3 | Высокое | L1–L7; mapping 6.1; одна компания за раз | CLOUD-2 |
| R3 | Независимое восстановление невозможно на общем Moodle; соблазн засчитать restore платформы плюс экспорт | Высокая | Высокое | OD-1: такая трактовка запрещена; G3 HOLD по 5.2 до изолированных пространств или доказанного company-level restore | G3 |
| R4 | Два каталога или два реестра | Средняя | Среднее | Каталог Moodle — проекция с `catalog_version` (MR3-D17) | CLOUD-2 |
| R5 | Повтор события создаёт дубль Company, Workspace или ERP | Средняя | Высокое | Idempotency key (REG-001 §24, ENT-001 §39); тесты в фикстурах | CLOUD-1a / 3 |
| R6 | Корпоративного Odoo нет или он не готов | UNKNOWN | Высокое для автоматизации | OD-8: ручная регистрация `SubscriptionReference` с аудитом и provenance | CLOUD-4 |
| R7 | Секреты в Registry или в коде | Низкая | Критическое | Только `credential_reference`; OD-5; проверка валидатором | CLOUD-1b+ |
| R8 | Учётная запись `cloud_v1` с широкими правами | Средняя | Высокое | Restricted user, только `cloudsync`, токен по IP, ротация; включение только решением владельца | CLOUD-3 |
| R9 | Синхронная зависимость Moodle от Cloud | Низкая | Среднее | P2, last-known-good, grace | CLOUD-2 |
| R10 | Нет аудита мутаций проекции в Moodle (D4) | Высокая | Среднее | Аудит в Cloud; Moodle event — отдельный PR | CLOUD-2 |
| R11 | Расхождение CLOUD-0 с #95 / #184 | Средняя | Среднее | OD-7: ADR-SAAS-002 PROPOSED, #95 сохраняется как альтернатива; чужие PR не изменяются | Review |
| R12 | Миграция существующих shared-компаний в изолированные пространства | Низкая (запрещено OD-1) | Высокое | Не выполняется; отдельный workstream только по решению | — |
| R13 | Импорт REG-002 из файлов, не совпадающих с §18 | Низкая | Среднее | ZIP подтверждён; внутренние SHA-256 сверяет валидатор при импорте | CLOUD-1a |

---

## 11. Матрица закрытия решений (OD)

| ID | Решение | Статус | Решение владельца / текущее состояние | Где отражено |
| --- | --- | --- | --- | --- |
| OD-1 | Модель изоляции и трактовка G3 | **ACCEPTED** 28.11.2026 | Гибрид: shared Moodle сохраняется, изолированные пространства предусмотрены; G3 не ослабляется, restore платформы + экспорт ≠ независимое восстановление; G3 HOLD; shared-компании не мигрируют; для беты — изолированные пространства или доказанный company-level restore. **Механизм и среда — OPEN** | §1, §5, §8 P5, §9, §10 R3/R12, ADR-SAAS-002 |
| OD-2 | Стек Control Plane | OPEN | Не выбирается | §8, §9 (gate CLOUD-1b) |
| OD-3 | Расположение кода и конвейер | **ACCEPTED** | CLOUD-1a в `cloud/` репозитория `bms24`, без изменения Moodle; утверждение места, не разрешение начать; CI — по согласованию с Codex | §9, §9.1 |
| OD-4 | Хостинг, PostgreSQL, среда CLOUD-3 | OPEN | Не выбирается без разрешения | §8, §9 |
| OD-5 | Secrets manager | OPEN | Отдельный ADR до CLOUD-1b | §7, §8, R7 |
| OD-6 | Канон кодов | **ACCEPTED** | `mgmt.*` / `limit.*` по ENT-001; mapping `management.*`/`bms.*` зафиксирован; runtime, доступы, legacy enforcement не меняются; product/application codes — по REG-001. **Hire — OPEN** | §4.3 D1/D2, §6.1 |
| OD-7 | Связь с #95 и #184 | **ACCEPTED** | ADR-SAAS-001 (#95) сохраняется как альтернатива; единый согласованный ADR с учётом #184 — [`ADR-SAAS-002`](../adr/ADR-SAAS-002-hybrid-cloud-isolation-PROPOSED.md) (PROPOSED); чужие PR не закрываются и не переписываются | §3, R11, ADR-SAAS-002 |
| OD-8 | Корпоративный Odoo и протокол billing → Cloud | **ACCEPTED** | Бета: ручная регистрация подписок с аудитом, `SubscriptionReference` и provenance; Odoo — целевой commercial master; наличие Odoo и billing protocol — UNKNOWN; реальные оплата/подписки сейчас не включаются | §7, §8 P6, §9, R6 |
| OD-9 | Независимый reviewer CLOUD-0 | **ACCEPTED** | Codex; Claude сохраняет ownership #207; reviewer не пушит в ветку автора | шапка, §13 |
| — | Судьба Hire | OPEN | — | §6.1 |
| — | Механизм независимого восстановления | OPEN | — | §5.1, §5.2 |
| — | Коммерческие условия (тариф, поддержка, себестоимость) | OPEN | — | §9 (G4) |

---

## 12. Проверки ревизии 2

| Проверка | Результат |
| --- | --- |
| Синхронизация с `main` 74e00c4 (merge, без конфликтов); аудированные файлы не изменились | RUN, PASS |
| Повторный `git grep` вызовов `entitlement_service::has/explain` вне тестов | RUN: только `platform_boundary_service.php:55` (`explain`, диагностика) |
| Сверка L1–L7 с кодом | RUN (чтение кода), результаты в §6 |
| Метаданные ZIP REG-002 в Drive (размер, дата) | RUN, совпадают с подтверждением владельца |
| SHA-256 байтов ZIP REG-002 | PASS — проверил Codex ([#204](https://github.com/rimanfatykhov-arch/bms24/issues/204#issuecomment-7236624093)); повторный хеш Claude NOT RUN |
| SHA-256 внутренних файлов REG-002, импорт | NOT RUN (CLOUD-1a HOLD) |
| `git diff --check`, `python tools/check_reconcile_1879.py`, `python scripts/qa_local.py`, разбор JSON | Результаты — в handoff-комментарии #207 |
| Moodle PHPUnit, upgrade, cron, browser, security acceptance | NOT RUN (docs-only) |
| Серверный inventory, DNS, корпоративный Odoo | NOT RUN (запрещено / нет доступа) |

## 13. Следующие действия

1. Codex (OD-9): независимая проверка exact head #207 по поручению владельца; вердикт PASS/FAIL/HOLD в #207 и #204.
2. Claude: исправления по замечаниям reviewer в своей ветке #207.
3. Владелец: после PASS — отдельное GO на CLOUD-1a; решения OD-2, OD-4, OD-5, Hire, механизм восстановления.
4. До GO CLOUD-1a не начинается; merge и deployment не выполняются.

## 14. История решений

| Дата | Ревизия / событие | Изменение |
| --- | --- | --- |
| 28.11.2026 | Ревизия 1, head `4575a1e4`, base `0884643` | Аудит, рекомендация H, OD-1…OD-9 как открытые вопросы. В §5 и §11 допускалась бета на общей платформе с трактовкой G3 «restore платформы + экспорт компании» |
| 28.11.2026 | [Решение владельца 7236311964](https://github.com/rimanfatykhov-arch/bms24/pull/207#issuecomment-7236311964) | Приняты OD-1, OD-3, OD-6, OD-7, OD-8, OD-9; OD-2/4/5, Hire, механизм восстановления, коммерческие условия — открыты |
| 28.11.2026 | [Предварительная сверка 7236606204](https://github.com/rimanfatykhov-arch/bms24/pull/207#issuecomment-7236606204) | Строка OD-1 в §11 противоречила решению; требуется проверяемый критерий G3 |
| 28.11.2026 | [Контрольная сумма REG-002 7236624093](https://github.com/rimanfatykhov-arch/bms24/issues/204#issuecomment-7236624093) | SHA-256 ZIP подтверждён Codex |
| 28.11.2026 | Ревизия 2, base `74e00c4` | Трактовка G3 ревизии 1 отменена; §5 переписан, добавлен критерий 5.2; §11 — матрица закрытия OD; mapping кодов 6.1 и статусов 6.2; ADR-SAAS-002; обновлён evidence inventory |
| 28.11.2026 | [Ревью Codex 7237057682](https://github.com/rimanfatykhov-arch/bms24/pull/207#issuecomment-7237057682) на `e33def05` | Техническая сверка пройдена, итог HOLD: исправить provenance REG-002 и проверяемость §5.2 п. 3 |
| 28.11.2026 | Ревизия 2.1 | Проверка байтов ZIP REG-002 атрибутирована Codex (Markdown и JSON); §5.2 п. 3 разделён на 3a–3d: неизменность B₀, журнал подтверждённых записей W_B, итоговый эталон «B₀ + W_B», доступность; ADR-SAAS-002 ссылается на эти условия |
