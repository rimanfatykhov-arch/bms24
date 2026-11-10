# RC30 — резервные копии и изолированное восстановление

2026-11-10. **HOLD. PLAN ONLY.** Владелец запросил разработку полного backup по SSH.
Остановка production writers и окно недоступности пока не согласованы;
создание и восстановление копии в текущей работе не выполнялись.
Нет доказанного актуального backup set; это не утверждение,
что backups отсутствуют. Наличие архива не равно возможности восстановиться.

## Текущее состояние доказательств

| Объект | Наличие/актуальность/целостность | Восстанавливаемость |
|---|---|---|
| Moodle/BMS DB: все таблицы,engine,charset,routines/events при наличии | NOT VERIFIED | NOT RUN |
| moodledata: прежде всего filedir,доступные file references,внешние stores | NOT VERIFIED | NOT RUN |
| Moodle Core+все plugins+theme+custom code | NOT VERIFIED | NOT RUN |
| config.php и требуемые server settings/keys | NOT VERIFIED; секреты не читались | NOT RUN |
| Общая точка согласованности DB/code/files | NOT VERIFIED | NOT RUN |
| Offsite/encryption/key availability/retention | NOT VERIFIED | NOT RUN |
| Свободное место/квоты/IO budget | Host volume space получен;quota/IO budget NOT VERIFIED | NOT RUN |
| RTO/RPO, обратное восстановление | Цели должен согласовать владелец | NOT RUN |

Упомянутый в [SSH inventory отчёте](../reconcile/ISSUE44_SCHEMA_REPAIR.md)
backup authorized_keys относится к инфраструктурной настройке доступа.
**Он не является backup Moodle/DB/moodledata.** CI synthetic preservation
fixtures также не являются копией production и не доказывают disaster recovery.

## Read-only проверка существующих копий администратором

Сначала получить из существующего журнала backup job идентификатор, UTC начала/
конца, покрытие, способ согласованности, исходный DB/profile, code SHA/tree hashes,
moodledata snapshot ID, размеры, хеши, exit/result. Не открывать dump/списки
пользователей/файлов в чате; журнал может содержать secrets/PII — передавать
только whitelist metadata. Пути/ключи шифрования хранить в защищённой системе,
не в GitHub. Не перечислять содержимое архивов в публичном отчёте.

Для конкретного уже существующего файла (путь администратор задаёт приватно):

```sh
B='/absolute/path/to/already-existing-reviewed-backup'
stat --printf='bytes=%s mtime=%y permissions=%a\n' -- "$B"
if BACKUP_SHA_LINE=$(sha256sum -- "$B" 2>/dev/null); then
    printf '%s\n' "${BACKUP_SHA_LINE%% *}"
else
    printf 'sha256_read=FAIL\n' >&2
    exit 1
fi
```

Сопоставить вычисленный hash с ранее сохранённым доверенным manifest;
только вычислить новый hash недостаточно для доказательства неизменности.
mtime не доказывает время данных или общую точку согласованности.
Для подтверждённого gzip-формата, в согласованное окно нагрузки:

```sh
if gzip -t -- "$B" >/dev/null 2>&1; then
    printf 'gzip_integrity=PASS\n'
else
    printf 'gzip_integrity=FAIL\n'
fi
```

Для другого формата — проверенная встроенная verify/test команда соответствующего
инструмента, без extraction/repair и без вывода содержимого. Не применять gzip
к зашифрованному/иному формату. Доступность ключей проверяет уполномоченный
администратор без раскрытия. Формат, version restore-tool и совместимость Percona
физического backup должны быть известны до выбора команды восстановления.
Недоступность ключа/неполное покрытие/ошибка integrity — P0 HOLD.

Read-only disk/inode/quota inventory — в [preflight](RC30_PRODUCTION_PREFLIGHT.md).
Размеры считать для каждого отдельного volume и quota: DB, moodledata, code,
backup destination, temporary DDL files и isolated restore storage. Плановый
запас должен одновременно вместить согласованный backup, восстановленную копию,
новый код/индексы и рабочий рост. Не использовать произвольное «2x достаточно»:
требуются реальные размеры, measured rehearsal peak и согласованный запас.
Не удалять старые backups ради места в рамках аудита.

## Контракт согласованного backup set — будущая операция

После отдельного разрешения администратор выбирает проверенный механизм для
фактического Percona/table-engine профиля. DB logical single-transaction применим
только при совместимых transactional tables и отсутствии конкурентного DDL;
он сам по себе не согласует DB с filedir и внешними stores. Physical backup
нуждается в подходящем инструменте/prepare/version и отдельной репетиции.

У maintenance есть ограничения: он не останавливает cron, workers, webhooks,
CLI/API writers и внешние интеграции. Для согласованной точки нужно заранее
инвентаризировать и на время окна остановить/заблокировать **всех writers**
с отдельным разрешением и зафиксировать их состояние для возобновления.
Проверить отсутствие выполняющихся jobs/долгих транзакций и новых записей.
Согласовать DB snapshot и moodledata/filedir, code/config и внешние зависимости.

Manifest набора должен содержать минимум:

- Уникальный backup-set ID, UTC интервал и согласованную точку данных.
- Host/environment alias, DB distribution/version/charset, plugin code+DB versions.
- Source/tree SHA, manifest code, каждый archive size/SHA256, tool version.
- DB scope (все Moodle/plugin tables; необходимые routines/triggers/events).
- moodledata coverage, внешние file stores, owner/mode/ACL metadata.
- Конфигурационный bundle ID и защищённый способ доступа к ключам без самих secrets.
- Результат consistency/integrity checks, storage/quota headroom, retention/offsite.
- Оператор/проверяющий, RPO/RTO target, путь к restore protocol.

Полный secret-bearing manifest не помещать в GitHub. В PR — только безопасные
идентификаторы, hashes/status/time и ссылка на защищённое хранилище по политике.
Новая backup-копия ещё не создана. Порядок подготовки и исполнения — ниже.

## Исполнимый порядок подготовки SSH backup для PR69

Git используется только для исходного кода. DB, moodledata, config.php и архивы
с данными не попадают в Git, PR, CI artifacts или публичный web-root.
Предложение хранить копию в корне `www` отклонено: доступ через HTTP не должен
зависеть от расширения архива или текущих настроек web server.

Предлагаемая private destination: `/var/www/p706494/data/private-backups/edu.kzn.mba/`.
Это **предложенный, ещё не проверенный и не созданный** путь. Перед созданием
нужно проверить realpath всех родителей, отсутствие symlink, пересечения с
DocumentRoot/alias всех сайтов и moodledata, владельца, ACL, quota и свободное
место. Каталог принадлежит backup-оператору (0700), файлы 0600, `umask 077`.
Нельзя считать путь закрытым только потому, что он расположен рядом с `www`.
Нужна также независимая зашифрованная offsite-копия и проверка доступности ключа.

1. **Read-only inventory.** Через доверенную SSH-сессию проверить место/inodes/
   quota, размеры DB/code/moodledata, таблицы и storage engines, external file
   stores, версии dump/restore tools. Из приватной конфигурации извлекаются
   только пути/тип соединения внутри процесса; пароли и содержимое config.php
   не выводятся. Составить список web/API/cron/adhoc/queue/integration writers
   и точные команды остановки/возобновления с текущими состояниями. Существующий
   forced-command маршрут `inventory` не даёт права выполнять backup: не
   расширять SSH handler автоматически ради этой процедуры.
2. **Согласовать конкретное окно.** Показать владельцу длительность, влияние,
   destination, перечень writers, RTO/RPO, место восстановления и ответственного.
   Сам запрос на backup не подтверждает остановку сайта или изменение cron.
   До согласования остановки можно оценивать размеры и готовить команды;
   несогласованную live-копию не выдавать за recovery baseline.
3. **Quiesce.** В утверждённое окно заблокировать новые web/API записи, включить
   maintenance, остановить все перечисленные writers, дождаться завершения
   jobs/транзакций, запретить конкурентный DDL и изменения code/files. Отдельно
   проверить внешние writers: одного maintenance Moodle недостаточно.
4. **Создать новый уникальный набор.** Только после проверки пункта 3: logical
   dump всех Moodle tables с triggers/routines/events при наличии, точный code
   tree с конфигурацией и весь moodledata с владельцами/правами/ACL. Для Percona
   logical dump использовать совместимый клиент и `--single-transaction
   --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --triggers
   --routines --events`, только если
   проверены engines и привилегии. DB credentials — в приватном client option
   file, не в аргументах/логах. Без проверенных engines/клиента — STOP, выбор
   подходящего snapshot/physical механизма отдельно. Не использовать
   `--force` для игнорирования ошибок и не считать пустой dump успехом.
   Архивы code и moodledata создаются по проверенным абсолютным путям;
   symlink/external stores включаются отдельными объектами manifest, а не
   молча разыменовываются. Временные файлы сохраняются как incomplete до
   успешного завершения всех команд; существующие копии не перезаписываются.
5. **Проверить набор.** Exit codes, формат/целостность, размеры и SHA256 каждого
   объекта, полный перечень покрытых источников, UTC начало/конец, версии,
   согласованная точка и исходные hashes. Секретный manifest хранить приватно.
   Только успешный полный набор получает статус captured; это ещё не restored.
6. **Возобновить работу.** Восстановить ровно прежние состояния сервисов после
   проверки, что создание backup не повредило исходные данные; вернуть maintenance
   в его исходное состояние (не выключать, если он был включён до окна).
   На ошибку/timeout назначенный оператор также принимает и фиксирует решение
   о безопасном возобновлении каждого writer; нельзя оставлять остановленные
   процессы без контроля или автоматически запускать ранее выключенные.
   При ошибке сохранить диагностический статус и
   incomplete набор приватно; не продолжать upgrade и не удалять старые backups.
7. **Копировать и восстановить изолированно.** Проверить offsite hash, отсутствие
   доступа clone к production/network endpoints до импорта и выполнить весь
   протокол ниже. Только успешные restore + rehearsal + rollback дают основание
   обсуждать production upgrade отдельным решением владельца.

Конкретные host/service/credential команды не подставляются по догадке.
До read-only inventory и согласования окна этот порядок является runbook,
а не разрешением запуска destructive или downtime операций.

## Test restore — отдельное разрешение до выполнения

1. Владелец утверждает backup-set ID, цель, операторов, срок хранения,
   допустимый объём production-данных, защищённую изолированную площадку и RTO/RPO.
2. **До импорта любых данных** обеспечить private network/deny-egress, запрет
   доступа к production DB/storage/API, закрытый hostname и доступ только
   назначенным тестировщикам. Никакого публичного clone.
3. **До импорта SQL** установить и проверить `event_scheduler=OFF` на отдельном
   сервере БД восстановления: dump может содержать события. До первого запуска
   PHP отключить scheduler/workers/mail relays/webhooks/ERP/
   Bitrix24/payments/SSO callbacks на уровне инфраструктуры; исходящие вызовы
   идут только в локальные sinks/fakes. Одних Moodle email flags недостаточно.
   Не изменять production endpoints для этого; меняется только approved clone.
4. Подготовить совместимый Percona/PHP/Web/extensions/Core и точный набор plugins/
   theme. Проверить hashes выбранного backup. Code/config/moodledata/DB восстановить
   как согласованный набор с правильными owners/modes. Креды production не должны
   давать clone доступ к боевым ресурсам; тестовые secrets — отдельные.
5. Перед web startup проверить clone wwwroot/dataroot/DB target, запрет внешних
   подключений, session/cookie isolation и отсутствие scheduled execution.
   Пользовательские данные доступны только в рамках согласованного доступа;
   обезличивание — отдельный approved процесс, а не автоматическая порча evidence.
6. Подтвердить восстановление **исходной пары1878/2613** до upgrade: версии,
   whole-schema/critical row counts, UUID/reference invariants и file hashes;
   вход, курс, enrollment, gradebook/completion/competency, documents/attachments,
   роли/две компании. Сравнивать данные локально; в отчёт только aggregate/hash.
7. Замерить restore duration и фактическую точку данных/RPO. Один успешный gzip-test
   не заменяет этот шаг. Проверить полный expected file set по DB filereferences,
   а не только наличие нескольких произвольных файлов.
8. Зафиксировать отдельную исходную точку clone; выполнить rehearsal BMS2026111002
   из единого release SHA PR69 с
   **точным сохраняемым Corporate2613** и полным sibling stack. При необходимости
   Corporate reconciliation сначала отдельный approved candidate/матрица.
   Перед **первым** upgrade выполнить read-only проверку всех 13 определений
   и всех значений 11 DECIMAL-полей из ADR-0004. Проверки внутри миграции1001
   недостаточно: более ранние0890/0900 могут уже зафиксировать DDL/savepoints.
   Повторить проверку после остановки writers непосредственно перед upgrade.
   Подготовлен bootstrap-free reader `tools/run_rc30_preupgrade_preflight.php`:
   JSON со строковыми host/dbname/user/password/prefix и integer port поступает
   через stdin из приватного источника (0600), результат содержит только
   статусы полей. Соединение локальное или через доверенный SSH tunnel,
   транзакция READ ONLY; нужен SELECT-only account. Код Moodle/config.php
   reader не исполняет, migration helper не вызывает. Не использовать shell
   tracing или пароль в командной строке. Reader в production ещё **NOT RUN**.
   Запускать PHP с `-d auto_prepend_file= -d auto_append_file= -d display_errors=0
   -d display_startup_errors=0 -d log_errors=0`, чтобы никакой host prepend не
   выполнился раньше reader. Не применять `-n` без отдельно подключённых PDO drivers.
9. Сравнить before/after schema/constraints/index order, business counts/checksums,
   canonical company UUID, membership/RBAC, audit/revision, Moodle course/file data.
   Выполнить repeat upgrade и все acceptance cases из матрицы. Замерить DDL lock/
   disk/IO/время, снять только обезличенные error summaries.
10. На clone смоделировать failure и **совместно восстановить старые code+DB+
    moodledata+config**, убедиться в работающем исходном приложении и отсутствии
    внешних side effects. Не считать git checkout/замену PHP полноценным rollback.
11. Независимый QA/security подтверждает restore+upgrade+rollback evidence.
    Сохранить протокол, затем согласовать retention/удаление clone отдельно.

## Критерий готовности

PASS возможен только при проверенном coherent set, успешном isolated restore,
сохранении образовательных/бизнес-данных, точной совместимости, доказанных ACL,
rehearsed rollback, приемлемых измеренных RTO/RPO и owner sign-off.
Сейчас все эти production recovery gates остаются NOT VERIFIED / NOT RUN.

Fresh read-only metadata от20:55:42Z не включают backup archives. Наличие,полнота,
контрольные суммы и restore по-прежнему не подтверждены. Найденные13 дополнительных
field/type mismatches должны войти в isolated rehearsal до выбора deployment package.
