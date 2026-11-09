# RC30 — резервные копии и изолированное восстановление

2026-11-09. **HOLD. PLAN ONLY.** Создание, перенос и восстановление production
копий требуют отдельного разрешения. В текущем аудите ни одна из этих операций
не выполнялась. Нет доказанного актуального backup set; это не утверждение,
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
| Свободное место/квоты/IO budget | NOT VERIFIED | NOT RUN |
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
Новая backup-копия в этом задании не создаётся.

## Test restore — отдельное разрешение до выполнения

1. Владелец утверждает backup-set ID, цель, операторов, срок хранения,
   допустимый объём production-данных, защищённую изолированную площадку и RTO/RPO.
2. **До импорта любых данных** обеспечить private network/deny-egress, запрет
   доступа к production DB/storage/API, закрытый hostname и доступ только
   назначенным тестировщикам. Никакого публичного clone.
3. До первого запуска PHP отключить scheduler/workers/mail relays/webhooks/ERP/
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
8. Зафиксировать отдельную исходную точку clone; выполнить rehearsal BMS0906 с
   **точным сохраняемым Corporate2613** и полным sibling stack. При необходимости
   Corporate reconciliation сначала отдельный approved candidate/матрица.
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
