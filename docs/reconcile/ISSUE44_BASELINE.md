# Issue #44 — архитектурное заключение о базе RC30

Проверка: 2026-11-08. **HOLD: база релиза не принята.**
Подготовлен документационный PR из `docs/issue44-rc30-baseline` поверх #46.
PHP, схемы, CI, версии плагинов, main и серверы этим PR не меняются.

## Результат для владельца

Предлагается продолжать стабилизацию от `a36c5ab8ac129433eeddb0ae341717e35b614ac8`
в будущей `integration/rc30-baseline`, не объявляя его устанавливаемым RC.
Главный новый блокер — fresh1645→1879 не воспроизводит три поля fresh1879.
Исправления acceptance1873→1879 и Corporate API1.21→1.26 уже подготовлены
в отдельных PR, но не являются доказательством успешного обновления Moodle.
Решение о базе и порядке интеграции предложено в [ADR-0002](../adr/ADR-0002-rc30-baseline-and-integration.md).

## Источники и границы доказательств

Прочитаны AGENTS, MASTER, Architecture Boundaries, Release QA и Workflow candidate;
[Product Vision из #41](https://github.com/rimanfatykhov-arch/bms24/blob/9c758d154988564f09bbea979be4a46454d753ca/docs/22_BMS24_PRODUCT_VISION_FOUR_STREAMS.md),
[ADR-0001](https://github.com/rimanfatykhov-arch/bms24/blob/cd1cd314d74e7946354ba7ae6576cbef8636e56d/docs/adr/ADR-0001-bms24-decisions-and-release-governance.md),
[реестр решений](https://github.com/rimanfatykhov-arch/bms24/blob/cd1cd314d74e7946354ba7ae6576cbef8636e56d/docs/DECISIONS.md)
и Workflow из #43; Issues #44/#45. Их отсутствие в main не отменяет
текущего указания владельца и принятых продуктовых принципов.

Долговременная запись решений — GitHub. Историческая строка MASTER о Google Drive
как источнике архитектурной истины требует согласования с ADR-0001 при переносе
governance. Accepted владельцем и merged в main — разные статусы.

[GitHub snapshot](issue44-github-snapshot.json) содержит полные SHA/base/head/status.
[Source evidence](issue44-source-evidence.json) содержит SHA256 version/install/upgrade,
извлечённые regex-анализом version guards и структурный diff XMLDB для main/import/candidate/R1/R2.
Git diff посчитан с `--no-renames`; XML сравнен по таблицам, полям, keys и indexes.
Это анализ исходников, не исполнение миграций и не deployment record.

## Фактическая карта Git

| Точка | SHA (сокращён) | BMS | Состояние и роль |
| --- | --- | --- | --- |
| main | 740fce16 | 2026101645 / RC29 | Основная ветка, не доказанная production-версия |
| #8 import | eccc1eb2 | 2026101879 / RC29 | Draft; acceptance ожидал1873 |
| #40 version fix | e2374237 | 2026101879 / RC29 | Draft поверх #8; строгая identity1879 |
| #42 reconcile | 3f000449 | 2026101879 / RC29 | Draft; сохранены main-файлы, Corporate API1.21 |
| #46 Corporate | a36c5ab8 | 2026101879 / RC29 | Draft поверх #42; Corporate2610/API1.26 с локальными исправлениями |
| #38 R1 | aadc0354 | 2026101645 / RC29 | Draft от main; три добавленных файла |
| #39 R2 | 092bcb27 | 2026101645 / RC29 | Draft поверх R1; не новая базовая версия |
| #41 vision | 9c758d15 | Документы | Draft от main |
| #43 governance | cd1cd314 | Документы | Draft поверх #41; принцип принят владельцем |
| #37 stage41 | 105e9acd | 2026101645 / RC29 | Draft ops-ветки; stage1897 — номер операции, не BMS build |

Все перечисленные PR проверены открытыми, draft и unmerged. Исходники #37
содержат шаблон Moodle4.1.22/PHP8.1/MariaDB10.11; это не результат осмотра сервера.

Main→import: **2078 удалений, 986 добавлений, 75 изменений**.
Main→candidate #46: **0 удалений, 1022 добавления, 91 изменение**.
Числа относятся к закреплённым SHA, не к последующим документационным commit.
Реестр всех исходных удалений и зависимостей: [reconciliation](PR8_RECONCILIATION.md).

## Миграции: что подтверждено

Main XMLDB: 136 таблиц; candidate:142. Таблицы не удаляются.
Добавлены bp_proto, bp_baudit, implproj, commrev, taskbundle и taskbrun
(все с префиксом local_kbacompany). Их создание присутствует в upgrade после1645.
После1645 есть198 guards до1879; проверка порядка активной серии и savepoints проходит.
XMLDB VERSION меняется с2026101636 на2026101863: это маркер схемы, не версия плагина.

В1863 добавлены четыре nonunique индекса, определения совпадают с install.xml:

| Таблица | Индекс | Поля |
| --- | --- | --- |
| task | status_due_ix | companyuuid,status,duedate |
| tasklog | lastlog_ix | companyuuid,taskuuid,id |
| bplanitem | legacy_task_ix | companyuuid,provider,taskuuid |
| bplanitem | provider_ref_ix | companyuuid,provider,externalid |

Их наличие не подтверждает EXPLAIN/latency или отсутствие блокировок при создании.
В BMS XMLDB не объявлены foreign keys: cross-company ссылки и orphan records
нужно проверять доменными сервисами и integrity-тестами; это не доказательство утечки.
В Corporate есть объявленные FK, но сами по себе они также не обеспечивают company ACL.

### Подтверждённый schema gap — блокер RC

| Поле | Fresh1645 | Fresh1879 | Путь upgrade из1645 |
| --- | --- | --- | --- |
| task.starttime | Отсутствует | char5, NOT NULL, default пустая строка | Добавляется только под oldversion<2026101219; из1645 пропускается |
| task.duetime | Отсутствует | char5, NOT NULL, default пустая строка | Тот же пропущенный guard1219 |
| deviation.executionstatus | Отсутствует | char16, NOT NULL, default pending | Добавление для deviation не найдено; одноимённое добавление относится к bp_proto |

Доказательства в candidate: `db/upgrade.php:4210` (task guard),
`db/upgrade.php:5556` (bp_proto), `db/install.xml:560,1680,1682` (целевые поля).
У ранее обновлявшейся базы поля могут уже присутствовать — её фактическая схема
не исследована. Подтверждён проблемный путь **чистая1645 →1879**, а не повреждение сервера.
Task service читает/записывает поля времени (task_service.php:570–580,949), поэтому
этот gap затрагивает рабочую логику. Прямое использование deviation.executionstatus
в deviation_service не установлено; его назначение следует подтвердить перед repair.
Текущий RC30 gate проверяет четыре индекса и integrity; полную эквивалентность
схемы он не проверяет. Поэтому даже его успешный результат не исключает этот gap.

Решение: новый достижимый идемпотентный checkpoint в отдельном PR после inventory
и выбора уникального build. Требования и тесты перечислены в ADR-0002.
Здесь намеренно не изменены старые guards или installed version records.

## Версии, диагностика и зависимости

Точная проверка1879 исправлена в #40 и сохранена в #42/#46; тест отвергает
1873, нулевую, более старую и более новую installed version. Не делать gate
динамическим от DB version: это скрывает неверный артефакт. Релизная идентичность
должна включать SHA и оба plugin build, поскольку одинаковый1879 уже означает
несколько разных деревьев. Автоматический prepare-release остаётся HOLD.

Corporate2610/API1.26 в #46 реализует семь требуемых methods, исправляет
query-boundary ACL и тесты. Это patched source candidate, не подтверждённая
установленная версия. Остальные local-компоненты main сохранены.
Подробности: [Corporate candidate](CORPORATE_API126.md). Тема KBA не поставляется
этим Git-деревом; наличие, версия и legacy fallback проверяются отдельно.
CI INSTALL включает только kbacompany — для paired release нужен отдельный
Corporate artifact и manifest с обоими SHA256.

## Окружения и покрытие

| Профиль/контур | Подтверждено | Статус runtime |
| --- | --- | --- |
| Локальный source QA | PHP8.1.34; чистые fixture/query-spy тесты | PASS только указанного source scope; Moodle DB не запускалась |
| GitHub CI #46 | PHP8.1 checks, PHP7.4 BMS syntax, ZIP build | PASS; SQL/Moodle installation отсутствуют |
| Исторический acceptance target | Код gate требует PHP8.1 + MySQL5.7 | NOT RUN на реальной DB; версия сервера неизвестна |
| stage41 parity | В #37 compose указаны PHP8.1 и mariadb:10.11, Moodle4.1.22 в подготовке | NOT RUN; шаблон не доказывает установку, HTTPS или restore |
| test.bms24.online | Контур назван в ADR-0001 | NOT RUN; SHA, Moodle/PHP/DB и installed plugin versions неизвестны |
| edu.kzn.mba production | Контур назван в ADR-0001 | NOT RUN; ни подключения, ни изменения не выполнялись |
| bms24.online pilot | Продуктовая цель | Не объявляется работающим коммерческим SaaS |

PHP7.4 lint — проверка синтаксиса BMS, не сертификация runtime Corporate или Moodle.
MariaDB может сообщать mysql family, но текущий gate проверяет engine/version;
strict parity будет отклонён с mysql57_target_not_verified. Это корректное
различение профилей, а не повод убрать предупреждение.

По [официальным требованиям Moodle4.1](https://moodledev.io/general/releases/4.1)
минимальны PHP7.4, MySQL5.7/MariaDB10.4; точный patch и совместимость всех плагинов
проверяются отдельно. По [официальному lifecycle](https://moodledev.io/general/releases)
security support Moodle4.1 завершился08.12.2025. На дату аудита2026-11-08 историческая
parity4.1 не является безопасной коммерческой целевой платформой без отдельного
плана обновления. Новый поддерживаемый стек не выбирается автоматически этим PR.

## План интеграции и риски Role Intelligence

1. Review #41→#43; согласовать governance с candidate, точечно обновить защищённый workflow.
2. Утвердить базу ADR-0002; review #42→#46 и correction PR схемы, без повторного полного overlay #8.
3. Перенести три файла R1, затем четыре новых файла R2, navigation hunk positions.php
   и по13 readiness_* ключей RU/EN. Сохранить современный CI; старую правку имени
   artifact из R2 не переносить.
4. R1 использует неизменившиеся position/function/roledna и company/permission services.
   Shell вырос0.5→0.6, begin/finish сохранены. Source-level несовместимости не выявлены,
   но объединённые Moodle tests ещё не выполнялись.
5. R1 читает все ревизии выбранной компании; R2 пагинирует отображение. Проверить
   ограничение запросов/памяти на большой компании до приёмки.
6. После исправлений зафиксировать уникальный paired RC artifact; затем runtime gates.
   AI Core, AI Studio и pilot идут отдельными ADR, без дублирования канонического BMS.

## Тест-план и блокирующие условия

Для каждого SQL-профиля в одноразовой изолированной Moodle DB:

- Fresh candidate и fresh1645→candidate: сравнить таблицы, типы/default/nullability,
  indexes; сохранить fixture данные/UUID; повторить upgrade для идемпотентности.
- Проверить реальную ранее установленную1879 отдельно от fresh1645; поля могут отличаться.
- Moodle PHPUnit обоих plugins, schema-gap regression, evidence latest/batch/cap,
  R1/R2 на новой базе. Администратор не заменяет тест employee/manager/curator.
- Две компании: прямые GET/POST, cross-company UUID/IDs, файлы, exports, cache,
  cron и provider bindings; отсутствие Corporate/старый API должны давать явный unavailable.
- Task/kanban/Battle Plan workflows, legacy/owned, Boost/KBA,1440/390,
  CSRF и write permissions; EXPLAIN и latency четырёх индексов на реальных объёмах.
- Backup→изолированный restore→smoke с записью версий/хешей. Не считать rollback
  доказанным наличием shell-скрипта; не откатывать код поверх новой DB вслепую.

Нужен редактированный read-only inventory каждого разрешённого контура: host alias,
время, Git SHA или provenance файлов, Moodle core version, PHP CLI и FPM versions,
DB engine/full version/collation, plugin code и installed DB versions, наличие
трёх полей/четырёх индексов, последнее подтверждённое restore evidence. Секреты,
config.php, DSN и персональные записи в Git не помещать. В этом проходе данные
серверов не получены; эксплуатационные scripts не запускались.

## Выполненные проверки и review

PASS: pinned Git/XMLDB inventory; check_reconcile_1879; check_upgrade_sequence;
check_xmldb_schema_names; test_rc30_version_1879 (10 version/mode cases);
test_rc30_runtime_1865 (8 runtime cases). QA дополнительно выполнил
check_php81_compat с PHP81_BIN=PHP8.1.34 (525 native lint), php -l upgrade.php
и test_rc30_acceptance_1864 — PASS. Source finding schema parity: **FAIL**
для описанного fresh1645 пути; текущие статические gates его не обнаруживают.

Предыдущий [CI #46](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42509901962)
прошёл для a36c5ab8; он не подменяет CI нового PR. Независимые Chief Architect,
QA и security/Role Intelligence reviewer реально выполнили анализ.
Backend migration и UI реализации в этом documentation PR нет; AI Engineer и
DevOps implementation не запускались как фиктивные параллельные команды.

NOT RUN: все серверные inventory, DB upgrade/fresh install, Moodle PHPUnit,
HTTP tenant ACL, браузер, производительность и restore. Нет установочного
артефакта, созданного вручную для deployment этим PR.

## Критерии Issue44 и передача в Issue45

| Критерий #44 | Итог |
| --- | --- |
| Точные SHA и main/import diff | Выполнено, evidence JSON |
| Version/XMLDB/upgrade/dependencies проверены | Source audit выполнен, runtime не подтверждён |
| Конфликты перечислены и имеют решения | Версия/API исправлены в PR; schema correction спроектирован, ещё не реализован |
| Матрица сред и покрытия | Составлена; реальные серверные значения NOT RUN |
| Порядок интеграции одобрен владельцем | PENDING: ADR-0002 Proposed |
| Никаких merges/deployments | Выполнено |

Issue44 остаётся открытой. Следующий безопасный набор: согласовать ADR-0002,
получить разрешённый read-only inventory без изменения сред, выделить уникальный
build, подготовить schema correction с реальными regression tests в отдельном PR.
После закрытия этих условий перейти к Issue45: изолированный runtime CI,
paired immutable artifacts, затем проект защищённого staging pipeline.
Обычная команда следующего шага не включает production deployment.

Откат текущего PR — revert документационного commit; DB/ACL impact отсутствует.
