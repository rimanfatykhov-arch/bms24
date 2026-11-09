# PR53: общий кандидат0804 + PR48–50

Дата:2026-11-09. Кандидат BMS2026110902, Corporate2026102610/API1.26.
Ветка `integration/0804-schema-0901` сохраняет первоначальное имя; номер ZIP0902 выбран после обнаружения независимого PR52 с номером0901. PR52 не включён.

Проверенный head: `6103059fa283db61199ddc2656865feea935e9fb`; PR merge tree: `de16b0d8e2929b4f9d9aef280b6f80a91a1ad4c3`.
Последующее добавление этого отчёта не изменяет проверенный код/ZIP.

- [Draft PR53](https://github.com/rimanfatykhov-arch/bms24/pull/53), base PR50 `5c59057cb7af3fd545cd661bb7c2b3daa5ae7cd9`.
- [Статический CI42587346888: PASS](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42587346888).
- [Полная матрица42587346907: FAIL/HOLD](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42587346907).

## Состав и сохранность

Функциональный delta1878→0804 взят из PR51 `d4887103c13f45c42b73b455a95dba142d7ba116`. Истории не имеют merge-base; целиком дерево не заменялось. Из44 путей40 перенесены точно после нормализации EOL, два теста усилены/исправлены, upgrade.php и version.php согласованы. Постатейные SHA приведены в `functional0804-integration.json`.

Schema helpers, install.xml и все соседние компоненты, включая Corporate, сохранены относительно PR50. Version-only checkpoints0301–0804 упорядочены перед0890/0900;0902 не добавляет DDL. Проверка source preservation PASS.

## Реальное выполнение на обеих СУБД

Одноразовые Linux runners, PHP8.1.34, Moodle4.1.22 core `df1157bb845eabedd31989d6a1a6e18f638ff890`; MariaDB10.11.19 и Percona8.0.46-37. Никакие stage/production или общие локальные базы не использовались.

| Проверка | MariaDB | Percona |
|---|---|---|
| Fresh install + CLI upgrade1645/1879/0804/0890/0900 |6 сценариев PASS|6 сценариев PASS|
| Сравнение183 таблиц fresh/upgrade, данные двух компаний, повторный upgrade |PASS для всех5 исходных версий|PASS для всех5 исходных версий|
| Field repair и частичные/некорректные схемы |29 тестов/3721 assertions PASS|29/3721 PASS|
| Ordered index, коллизии, повтор, сохранение старого индекса |9/103 PASS|9/103 PASS|
| Task/deviation tenant isolation |2/66 PASS|2/66 PASS|
| Очередь, права на работу/оценку, grant/revoke второй компании |9/99 PASS|9/99 PASS|
| Карточка: company scope, курсы, скрытые/нулевые/шкальные оценки, подтверждение |5/65 PASS|5/65 PASS|
| Profile / Role Intelligence / readiness / design |1/5 +4/16 +3/9 +3/13 PASS|то же|
| Company core |16/78,0 errors,2 старых failures|то же|
| Полный BMS |587/6813,37 errors,29 failures,0 skipped|то же|
| Corporate full suite |FAIL: discovery fatal|FAIL: discovery fatal|
| Corporate diagnostic files |27 PASS/10 FAIL|26 PASS/11 FAIL|
| Решение о выпуске |HOLD|HOLD|

Corporate diagnostic JUnit содержит144 теста: MariaDB666 assertions/29 errors/3 failures; Percona638/35/2. Файл с fatal при discovery не включён в эти144 теста. Раздельный запуск файлов не заменяет успешный полный suite.

Сравнение идентификаторов падающих BMS-тестов с фактическими JUnit PR50:70→66 на обоих движках, **0 новых**,4 прежних больше не падают. Это не утверждение об отсутствии любых возможных дефектов.

## Исправления после первого прогона

Первый MariaDB-прогон616cfe0 обнаружил27 ошибок replay-тестов: они передавали0900 после сохранения0902. Повтор теперь использует установленную версию, при этом строгая проверка достижения0902 и неизменности схемы/данных сохранена. Тест переноса назначения читал отсутствующие slots.employee_id/assignment_id; теперь проверяет assignments, идентичность сотрудника/назначения, новый slot_uuid и current status. Прикладной код не изменялся. Повторная полная матрица проверила эти исправления; прежний прогон не объявляется PASS задним числом.

## QA и безопасность

Независимый QA review подтвердил выборочную интеграцию, сохранность schema helpers/соседних компонентов и отсутствие ослабления исправленных assertions. Runtime результаты целевых проверок PASS, полный регресс HOLD.

Security review подтвердил целевые сервисные ACL-проверки на обеих базах: queue9/99, learning5/65, tenant2/66 — без ошибок и пропусков. Очередь персональная по Moodle capabilities, а не реестр выбранной компании; выдача/отзыв адресных прав меняют доступ к второй компании ожидаемым образом.

**Общий security/privacy PASS не заявляется:** остаются6 BMS privacy failures и1 Corporate privacy failure. Наличие старых падений не делает их безопасными. Browser/HTTP ACL, полный sibling-stack и установленный stage Corporate2613 здесь не проверялись. В исторических0804/1879 сценариях отсутствующий Corporate восстановлен из main1645; это явно реконструированная пара.

## Артефакт и следующие шаги

CI ZIP `INSTALL_local_kbacompany_1.1.0-rc29_build2026110902.zip`,1783 файла.
SHA-256: `fd2159daa2eea1c0543488eaa0051effc6bb1dab8d94533492822650af70128d`.
Две сборки в CI совпали; скачанный ZIP повторно проверен по manifest. Это артефакт кандидата, не одобрение деплоя.

1. Разобрать оставшиеся66 BMS failures/errors и Corporate discovery/runtime/privacy; отдельно оценить изменения PR52.
2. Согласовать Corporate2610/2613 и полный набор соседних плагинов перед установкой.
3. После закрытия блокеров — отдельная stage41 браузерная приёмка Алексея/Анны и HTTP ACL.

Слияние, деплой и изменения production не выполнялись. PR остаётся Draft.
