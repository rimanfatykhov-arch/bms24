# BMOS-A1.1. Реестры принципалов, грантов и занятости (A1-01, A1-02)

**Статус: кандидат, не принят.** Основание — архитектура A0 с независимым PASS Codex на
`68b1dd07b1b75f1af94e6442e629d127f947b95b` (PR #183, Issue #84). Документы A0 (`docs/bmos/A0_*`) находятся в #183;
ссылки ниже даны на них по этому SHA. Версия плагина кандидата — `2026112800` (реестр #198). Номер RC не назначен.

## 1. Что входит

| Часть | Где | Правило |
|---|---|---|
| Схема | `db/install.xml`, шаг `2026112800` в `db/upgrade.php` | Шесть новых таблиц из install.xml: `wprincipal`, `wpaudit`, `wpgrant`, `wpdeleg` (только схема, до A3 не используется), `posoccup`, `posoccuplog`. Существующие таблицы и строки не меняются, backfill нет |
| Выключатель | `settings.php`, `local_kbacompany/bmos_enabled` | По умолчанию 0. При 0 любой вход в сервисы реестра и контекст принципала отвечает `bmos_error_disabled`, `principal_permission::can` — `false`. Остальной продукт работает как до BMOS |
| Реестры | `principal_registry_service`, `principal_grant_service`, `position_occupancy_service` | Пишет только вошедший человек (`$USER->id` = actor) с `permission_service::can_manage_organization` в компании; выдача и продление грантов — дополнительно отдельное полномочие (§2 п. 10). Метода, принимающего `principal_context` как автора записи, нет |
| Общие правила | `bmos_registry` | Фильтр компании в SQL; ссылка на чужую или несуществующую запись — одна ошибка `bmos_error_notavailable`; lock `bmos_principal_<uuid>` берётся первым, затем одна транзакция с изменением и `authzrevision + 1` (A0_03 §2) |
| Контекст | `principal_context_service::context(companyuuid, principaluuid)`, `principal_context` | Параллельно `company_context_service`; human-путь не меняется. Нет `userid`, токена, сессии. Активный `ai_agent`, спонсор — действующий член компании, иначе отказ без раскрытия существования |
| Право | `principal_permission::can(context, object_type, action, resource)` | Deny by default, форма RBAC 2.0. Каждый вызов заново строит контекст из БД и сравнивает `authzrevision`; гранты и занятость читаются с фильтром компании и одним `now` |
| Privacy | `classes/privacy/provider.php` | Метаданные, контексты, экспорт и удаление по человеку для всех полей с Moodle user id |

## 2. Решения реализации (уточнения черновика A0)

1. **Имена полей.** Там, где слово зарезервировано в SQL: `occupancymode` вместо `mode`, `constraintjson` вместо
   `constraint`, `eventname` вместо `event`. Остальные имена — как в A0_02 и A0_08.
2. **Только `ai_agent`.** A1.1 регистрирует только агентов (`userid = 0`, `transportuserid = 0`). Human-принципалы не
   создаются: права людей по-прежнему считает `permission_service` (A0_02 §1). `service_integration` — срез A1-12
   (ADR-0012). Поля для них в схеме есть.
3. **Ключей нет.** `credentialref`, `credfingerprint`, `rotatedat` остаются пустыми; пути записи секрета нет.
4. **Словарь пар.** Грант принимает только пары `access_role_service::OBJECTS` × `ACTIONS`. Проектные пары
   (`agent/*`, `daily_report/*`) добавляются отдельным решением с первой командой (A0_02 §4.2). Имя команды
   проверяется по форме `объект.действие`; реестра команд в A1.1 нет.
5. **Scopes.** Выдаются `own`, `position` (с одобренной или активной занятостью того же принципала),
   `explicit_object` (явный список UUID в `constraintjson`, не больше 200). `delegated_team`, `department`, `subtree`,
   `company` и R4 отклоняются.
6. **Объект.** Полный `principal_object_acl` для реальных объектов — A1-04. В A1.1 `resource` проверяется по явным
   полям: компания, `ownerprincipaluuid` для `own`, `uuid` для `explicit_object`, `positionuuid` для `position`.
   Общее условие для **всех** scopes (ревизия Codex 28.11): объект в компании принципала и не личный — задача без
   поста (`positionuuid` пуст) или любой ресурс с `personal = true` запрещены и для `own`, и для `explicit_object`, и
   для `position`.
7. **Сроки.** `expiresat` > now и ≤ now + 90 дней. Продление — новая строка (новая ревизия, `supersedesuuid`), старая
   становится `superseded`; истёкший, отозванный или заменённый грант не продлевается. Задачи cron, проставляющей
   `expired`, в A1.1 нет: граница — время проверки (A0_03 §2).
8. **Занятость.** `mode=agent` требует куратора — действующего члена компании; утверждает второй человек (не
   создатель и не спонсор, Q-09). `mode=hybrid` требует человека на том же посту в `posassign`. OccupancyView
   возвращает вакантность людей и функциональное покрытие агентом раздельно и не читает `poscover`.
9. **Privacy.** Реестры — записи безопасности компании: удаление по человеку обнуляет его id и тексты его записей
   в журналах, строки остаются. Принципал, потерявший спонсора, и занятость без куратора закрываются (fail closed),
   `authzrevision` растёт.
10. **Полномочие на гранты (A0_02 §4.2, ревизия Codex 28.11).** Управление грантами отделено от
    `manageorganization`. Выдача и продление требуют правил автора записи **и** отдельного полномочия; пока владелец
    его не выбрал, это только администратор сайта. Руководитель организации с `can_manage_organization` гранты не
    выдаёт и не продлевает (`bmos_error_grantauthority`), но может **отозвать**: отзыв только сужает права. Новой
    capability и изменения ролей нет: `db/access.php` закреплён gate `test_no_capability_change_against_base`, а
    права не меняются без решения владельца.

    **Открытое решение владельца (OD-A11-1).** Какое постоянное полномочие выдаёт гранты:
    (а) новая capability `local/kbacompany:managebmosgrants` без archetypes, назначаемая явно, вместе с
    `can_manage_organization` в компании — нужен отдельный OK на изменение `db/access.php` и gate capability;
    (б) роль доступа RBAC 2.0 на новом объекте `agent` (пара `agent/approve`) — нужно решение по словарю;
    (в) оставить только администратора сайта до A2. Рекомендация исполнителя — (а). Пока решения нет, действует (в).

## 3. Тесты → сценарии A0_09 §3

Файл `local/kbacompany/tests/bmos_registry_test.php` (входит в STANDARD-список `tools/tests/moodle_acceptance.php` и
в полный testsuite FULL).

| Тест | Сценарии |
|---|---|
| `test_s28_install_flag_off_by_default_and_every_entry_point_is_closed` | S28.1 (fresh install, версия, выключатель 0, все входы закрыты, шаг upgrade) |
| `test_s01_registration_requires_authority_and_a_current_sponsor` | S01.1 |
| `test_s04_agent_principal_has_no_moodle_identity` | S04.1 |
| `test_s03_company_isolation_without_existence_disclosure` | S03.1 |
| `test_s06_only_an_authorised_human_writes_registries` | S06.1 |
| `test_s36_deny_by_default_with_positive_control` | S36.1 |
| `test_s08_revocation_denies_on_the_next_context_and_check` | S08.1 |
| `test_s24_expiry_and_human_renewal_as_a_new_revision` | S24.1 |
| `test_s02_s10_s33_occupancy_keeps_people_tasks_and_coverage_unchanged` | S02.1, S10.1, S33.1 |
| `test_s35_agent_mode_occupancy_needs_a_second_person` | S35.1 |
| `test_s19_registries_have_no_secret_fields` | S19.1 (часть P; сканер секретов CI — отдельно) |
| `test_s29_privacy_export_and_erasure_by_person` | S29.1 |

Upgrade-часть S28.1 на реальных движках — upgrade-репетиция CI с опубликованной `2026112301` и
`local/kbacompany/cli/test_schema_parity_2026110890.php`. Статус каждого сценария — только по фактическому прогону на
точном head; локальный lint не PASS.

## 4. Чего нет

Command Gateway, команд агентов, Runtime, UI реестра, моделей, ключей, сетевых вызовов, cron. Существующие
назначения, `task.assigneeid`, `dutypositionuuid`, `poscover`, история и ядро Moodle не меняются.
