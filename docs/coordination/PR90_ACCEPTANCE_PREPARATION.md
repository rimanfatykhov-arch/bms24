# PR90 — подготовка приёмки на настоящем Moodle

Актуальный статус: runtime выполнен на обеих БД в PR98; полный restore PASS, повторная отправка FAIL уже на baseline; acceptance HOLD. Итог и точный новый tree ниже. Первоначальный план сохранён как история, 2026-11-14. Production и работающий staging не использовать.

## Зафиксированный кандидат

- Main: f34ab6ff0d2f67a56972e99c23d096ed77a9873c.
- PR90 head: 62a3e9cafd8252fffc6389af044bf469ff7e3853.
- Локально рассчитанный интеграционный tree: 85255a1bbdbe3a79a6db7714daeae01573b036c0. Git merge-tree завершился без конфликтов; commit и PR merge не выполнялись.
- Относительно main меняется только task_center.css (13 строк). BMS/Corporate в этом tree побайтно по Git совпадают с head90. Инфраструктура CI — из актуального main.
- Подготовлен полный source archive pr90-integrated-source.zip, SHA256 6d35f1e00c5909c4bb73350b125baeb0d6f65e97770234d3e3078f525c7b16f0. Это SOURCE, не install ZIP и не release commit.
- Ранее скачанный CI install ZIP: outputs/pr91-review/pr90-candidate/INSTALL_local_kbacompany_1.1.0-rc29_build2026111100.zip; SHA256 2620157c2017226ff1650337bfc806d1b214db2d2e11b150b492ee360a99b8c6, manifest и SHA256SUMS рядом. Хеш повторно проверен. Source identity привязана к head90 и run43492204724; не переименовывать пакет в новый main release.

## Изолированный runtime

Использовать новый одноразовый CI job/отдельный Docker project, без production backup, с синтетическими компаниями A/B. Moodle4.1.22 и PHP8.1 по pinned baseline workflows; MariaDB10.11.19 и Percona8.0.46-37 — отдельные jobs. У каждой попытки собственные БД, moodledata и evidence. HTTP только127.0.0.1, отключённые почта/cron/реальные интеграции; никаких общих volumes с работающими средами.

Установить именно проверенный plugin ZIP; Corporate из зафиксированного интеграционного source. Записать версии, checksum, tree и engine в отчёт. Секреты actors/config не публиковать. Перед новым CI необходим конкретный review commit от актуального main; текущую чужую ветку этой подготовкой не меняли.

## Первоначальная UI matrix — исходный план до запуска PR98

| ID | Реальный сценарий | Ожидаемый результат |
|---|---|---|
| M01 | Меню Действия карточки, desktop1280 | Открытие не сдвигает вкладки/контент; список виден и доступен |
| M02 | Меню Инструменты списка | Нет сдвига, реальный разрешённый пункт доступен |
| M03 | Viewports320/360/390/480/482/720/768/1280/1440 | Нет горизонтального overflow, обрезки меню и недоступных пунктов |
| M04 | Mobile scroll, длинное меню, нижняя панель | Все пункты достижимы; внутренний scroll работает; фон/панель не мешают выбору |
| M05 | Клавиатура Tab/Enter/Space, открытие и закрытие через summary | Видимый фокус, корректный порядок; нет ловушки фокуса |
| M06 | Touch coarse pointer, элементы вкладок | Реально выполненный44px сценарий; focus/геометрия не сломаны |
| M07 | Manager/non-manager/другая компания | Те же права и набор доступных действий; прямые запрещённые URLs отказаны |
| M08 | Один разрешённый write на синтетической задаче | Действие выполнено один раз; статус/история согласованы; проверить CSRF |
| M09 | Owned shell + legacy fallback | Изменён только owned; legacy функционально сохранён |
| M10 | Console/pageerror и неожиданные HTTP5xx | Ноль ошибок; любая ошибка FAIL, не игнорировать после красивого screenshot |

Сравнить геометрию до/после на настоящем Moodle, не на транскрибированной HTML-фикстуре. Сохранить screenshots и машинные observations. Существующий общий BROWSER не покрывает автоматически меню90.

## Обязательные сопутствующие проверки

Static, обе DB shadow86, исправленный BROWSER93, полный BMS/Corporate/FULL release набор. Пока новые замечания к Merge gate не закрыты, его зелёный verdict не использовать как самостоятельное доказательство полноты. Сопоставить точные тестовые IDs/outcomes; retained2613 хранить отдельно как проверяемый исторический контроль.

Для rehearsal rollback: на одноразовом Moodle сохранить baseline code+DB+files, установить кандидат, затем восстановить весь baseline и проверить данные/ACL/файлы/меню. Это тест отката, не операция production. Не откатывать текущую production-версию к историческому пакету90.

Готовность к production здесь не заявляется: после runtime нужны результаты восстановления, release manifest, отдельная карточка и разрешение владельца. Этот документ разрешений не выдаёт.

## Повторная проверка доступности, 14.11.2026

Main и head #90 повторно прочитаны из GitHub и не изменились. Локальный Docker не найден ни в PATH, ни по стандартному пути Docker Desktop; наличие wsl.exe не подтвердило установленную исполняемую distro. Отдельный разрешённый одноразовый runtime для этого кандидата не установлен. Старые SSH-маршруты через production/staging не используются как разрешение создать там среду.

Недостающий ресурс: disposable Linux runner/VM с Docker, браузером Playwright и доступом к pinned образам Moodle/PHP/MariaDB/Percona; отдельные сети, БД и volumes, синтетические A/B, заблокированные реальные уведомления. Предоставить его можно отдельным job после согласования с Claude либо выделенной тестовой VM. Это блокирует M01–M10 и restore, но не документационное согласование.

| Проверка | Результат |
|---|---|
| Повторное чтение main / PR90 head | PASS, указанные выше SHA не изменились |
| Повторная локальная SHA256 проверка двух архивов | PASS, оба SHA256 совпали с указанными выше |
| M01–M10 на MariaDB / Percona | NOT RUN |
| Установка и полный restore code+DB+files | NOT RUN |
| Production pilot readiness | HOLD |

Документ не содержит install ZIP: локальные outputs — evidence paths оператора, не публичные download URLs. Перед runtime получить CI artifact run 43492204724, сверить manifest и SHA256; при иной source identity пересобрать и повторить проверки. Нельзя выдавать source tree за опубликованный release SHA.

## Продвижение: отдельный Actions runtime

Создан Draft PR #98 https://github.com/rimanfatykhov-arch/bms24/pull/98, ветка test/pr90-isolated-moodle, первоначальный head98d34d3. Это явный зависимый кандидат: PR96 head38a2115 + только CSS PR90; не main и не принятие #96. Новый workflow PR90 disposable Moodle rehearsal запущен: https://github.com/rimanfatykhov-arch/bms24/actions/runs/43563243747 .

Runtime больше не блокируется отсутствием локального Docker: доступны отдельные GitHub-hosted runners с service DB каждого engine. Код готовит synthetic baseline main, install ZIP/manifest/checksum, menu/access observations и snapshot→install→restore→reinstall. Private actors/config/logs/SQL/snapshots не публикуются. Первоначальная конфигурация покрывает M01–M05 частично; M06–M10 не объявляются пройденными. PARTIAL намеренно возвращает ненулевой код. Итог требуется читать по конкретному run, не по этому описанию. Полная приёмка остаётся HOLD.

## Фактический итог run43564455219 (обе БД)

PR98 head960151efcc24c448be022fe126a1d81613ed5ce0; проверенный synthetic merge SHA e26e7af92f77db265a2a8858630056042be78bd1, tree d5472483057240be23e80c16e3efd0cf2923ab8a. Dependencies: PR96 **38a2115**, PR90 **62a3e9c**, main **f34ab6f**. Это не новый head947447c PR96 и не release SHA.

- PASS на MariaDB/Percona: baseline fresh install;18 menu observations (9 ширин) на candidate и reinstall;15 task-tabs/mobile checks;6 access checks; MA/EA/EB и owned/legacy menu/route comparison.
- PASS: backup code+DB+files, полное восстановление с byte-equal повторным дампом БД, hashes всего code/filedir, history и ACL/downloads после restore. Устанавливается один ZIP SHA2562620157c2017226ff1650337bfc806d1b214db2d2e11b150b492ee360a99b8c6, checksum совпадает на обеих БД.
- FAIL: повторная отправка compact discussion — baseline_repeat, candidate и reinstall. Send message включена, но перекрыта Recent activity panel; hit-test возвращает DIV.bms24-task-panel-body. Это воспроизведение на baseline, не доказанная новая регрессия #90. Force-click/JS submit/удаление истории не применялись.
- NOT RUN: оставшиеся write/CSRF сценарии после Timeout. Общая M01–M10 не принята; независимая проверка корректно сохраняет FAIL.

[Полный отчёт и ограничения](https://github.com/rimanfatykhov-arch/bms24/pull/98#issuecomment-6873001981), [run и artifacts](https://github.com/rimanfatykhov-arch/bms24/actions/runs/43564455219). Места локализации UI: task_center.css sticky aside panel rules и task_ui_service.php composition discussion/recent activity. Product code не исправлялся тестовым harness.

Следующий шаг Codex: локализовать узкое исправление перекрытия и повторить M08/матрицу после согласованного fix; текущий fallback через другую страницу не является PASS исходного сценария. Claude завершает независимые blockers #96. Никакого production-пилота до принятия обоих результатов и release gates.
