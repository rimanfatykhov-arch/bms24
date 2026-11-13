# Production read-only refresh — 2026-11-09T20:55:42Z

**Следующий шаг выполнен: свежая инвентаризация получена. RC30 остаётся HOLD.**
Документационное продолжение Draft PR64/Issue44, не разрешение установки или repair.

## Как получены доказательства

1. Существующий stage41 ключ/доверенный host найден; SSH transport установлен,
   сервер закрыл соединение до key exchange. Настройки/ключи не менялись.
2. GUI WinSCP capture недоступен, но в приложении существовала production-сессия.
   Штатный WinSCP.com использовал сохранённую доверенную сессию p706494@kzn.mba;
   пароль не извлекался и не выводился. Host key не подменялся и не принимался заново.
3. Выполнен уже установленный read-only inventory: версии/repair fields/indexes.
4. После независимого security review расширенный PHP reader передан через stdin
   (не установлен на сервер). Literal parsing config без require/eval, read-only
   DB transaction +rollback, metadata/aggregate queries без business rows.
   auto_prepend/append и PHP error logging отключены только для этого процесса;
   постоянные PHP/server settings не менялись.
5. Получены metadata704таблиц/7528колонок. Raw JSON6.49MB хранится локально;
   в Git — безопасные summary,code/version hashes и точный diff. Credentials,
   config contents,PII,task customdata и backup contents не читались в вывод.

[Evidence](RC30_PRODUCTION_REFRESH_EVIDENCE.json), [schema diff](RC30_PRODUCTION_SCHEMA_DIFF.json).
Raw metadata SHA256:
`a507ff28108d4987e4198df108f4407df5b7d896e1983b3f89db03ebd9d6dbf4`.
Локальный файл: `outputs/rc30-production-readiness/production-refresh-20261109.json`.
Reader SHA256 зафиксирован в evidence. Полный rawmetadata не включён в PR;
его сохранность проверяется хешем, повторный approved reader может обновить снимок.

## Фактическое состояние

| Объект | Свежий результат |
|---|---|
| Moodle |4.1.22;code2022112822.00/DB2022112822|
| BMS |code/DB2026101878|
| Corporate |code/DB2026102613;bridge API1.26 и49 public method names|
| PHP CLI |8.1.34;16 выбранных extensions присутствуют|
| Percona |8.0.46-37,utf8mb4_unicode_ci|
| Тема |kba;code/DB2026092213|
| Plugins |472 DB version entries;203 code version files (частичное перечисление типов)|
| Схема |704tables,7528columns,2519distinct indexes (3672index-column rows)|
| BMS/Corporate tables |142/41;наборы соответствуют CI,но определения имеют drift|
| Maintenance |DB flag0;climaintenance.html отсутствует|
| Scheduler |127tasks/120enabled,adhoc0,faildelay0;последнийlastruntime20:55:02Z|
| Scheduler caveat |15enabled tasks с nextruntime старше суток;success/полное здоровье NOT VERIFIED|
| Host space |2439897825280 free из3697941176320 bytes на code/data volume;не account quota|
| Host RAM |MemTotal131356352KiB,MemAvailable97454572KiB;не лимит аккаунта|

Все общие поля старого inventory08.11, включая version-file/schema/bridge hashes,
совпадают (кроме captured_utc). PHP Web,account quotas/inodes,full Core/code tree
identity,резервные копии и restore остаются NOT VERIFIED/NOT RUN.

## Подтверждённый BMS schema drift

- Нет `adminitem.starttime` и `adminitem.duetime`; fresh0906 требует varchar5,
  NOT NULL,default empty. Их не добавляет достижимый хвост upgrade1878→0906.
- Нет `deviation.executionstatus`; достижимый repair0890/0900 существует.
- Нет nonunique task index `(companyuuid,status,duedate)`;repair0900 существует.
- 11 DECIMAL-полей имеютscale0 вместоfresh2/3/6;достижимогоrepair нет:
  finbudfact.actualamount;finbudline.planamount/limitamount;finline.amount;
  statbind.weight;statnorm.targetvalue/minval/maxval;
  statpolicy.dangerdecline/affluencegrowth/powerlevel.

Это реальные COLUMN_TYPE differences,не MySQL integer display-width.
При сохранении precision увеличение scale сокращает целый диапазон. Нельзя
выполнять слепой ALTER или считать его безопасным расширением. Значения строк
не читались: overflow-risk и возможное прошлое округление пока неизвестны.
Изменение типа не восстановит ранее потерянную дробную часть.

Нормализованное сравнение BMS nonprimary indexes выявило только указанный
недостающий ordered index;проверка не аттестует все физические свойства индексов.
**0906 может пройти свои savepoints,сохранив13 дополнительных field/type defects.**
Synthetic CI origins не воспроизводят этот исторический production drift.

## Corporate defaults: отдельный compatibility gate

Corporate41table sets,колонки,нормализованные types/nullability/auto_increment и
nonprimary ordered/unique definitions совпадают с CI0901,ноdefaults различаются:

| Поле | Production2613 | CI0901 |
|---|---|---|
|devplan.status|active|draft|
|member.isemployee|0|1|

Литералы подтверждены сопоставлением metadata SHA256;business rows не читались.
Это не автоматическое доказательство дефекта2613. Особенно нельзя автоматически
менять isemployee на1:это меняет membership semantics вставок без явного значения.
Сохранить установленный Corporate/defaults до отдельного source/behavior review.
API1.26 и имена методов подтверждены,но сигнатуры/authorization/projection semantics
и совместная runtime-приёмка2613 остаются NOT RUN.

## Статусы и следующий безопасный этап

- PASS: fresh metadata acquisition,code/DB version agreement,artifact identity
  PR62 остаётся зафиксированной;независимые QA/architecture metadata reviews.
- FAIL readiness: доказан BMS schema drift,не исправляемый0906;19 прежних BMS tests
  также остаются FAIL. Product release HOLD усиливается.
- NOT VERIFIED:WebPHP,quotas,полный source identity,cron health,backups.
- NOT RUN:точныйproduction1878/2613upgrade,restore,HTTP/UI/fullstack acceptance.

Предлагаемый следующий этап — **отдельный repair proposal/ADR**,без ALTER на
production:описать2adminitem fields и11decimal conversions,согласовать precision/
range policy,подготовитьfail-closed tests и модель наблюдаемой схемы в disposable
MariaDB/Percona. Любые проверки реальных значений/restore production copy требуют
отдельного разрешённого плана;не выводить строки/PII. Corporate defaults не менять
в рамках BMS repair. Затем exact-schema upgrade/repeat/data preservation и
отдельная репетиция согласованного backup/restore. Никакой установки сейчас.

Production/staging code,DB schema/data,maintenance,cron,server configuration и
права не изменялись. Backups не создавались,не переносились,не восстанавливались.

## Независимое ревью

QA сверил raw hash,timestamp,версии,счётчики играницы статусов;архитектор
подтвердил13 дополнительных BMS drift,2Corporate defaults иопасность decimal
conversion. Reader прошёл security review до запуска,замечания исправлены.
Это не заменяет restore,точный upgrade илиsecurity acceptance реального стека.
