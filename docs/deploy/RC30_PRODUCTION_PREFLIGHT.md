# RC30 — production preflight edu.kzn.mba

Дата аудита: 2026-11-09 UTC. **Вердикт: HOLD. Развёртывание не разрешено.**
Документационная ветка `audit/rc30-production-readiness` от точного PR62
`e7e9b1b5f0c23b14da9bcc8ad2195d468ce8ee3f`; Issue [#44](https://github.com/rimanfatykhov-arch/bms24/issues/44).

## Полномочия и источники

Владелец разрешил read-only аудит и подготовку, но явно запретил установку,
миграции, изменение конфигурации/maintenance/cron/Nginx/прав, создание, перенос
и restore production backups. Ни одна из этих операций не выполнена.
Архитектор, QA и security участвовали независимо; их выводы сверены с файлами.

Сохраняются [ADR-0002](../adr/ADR-0002-rc30-baseline-and-integration.md) и
[ADR-0001, точный источник](https://github.com/rimanfatykhov-arch/bms24/blob/cd1cd314d74e7946354ba7ae6576cbef8636e56d/docs/adr/ADR-0001-bms24-decisions-and-release-governance.md).
ADR-0001 отсутствует в данном checkout; прочитан по указанному SHA. Принятие
владельцем не означает merge в main или разрешение установки.

Статусы: PASS — конкретная выполненная проверка прошла; FAIL — проверка выявила
несоответствие; NOT RUN — операция/тест не выполнялись; NOT VERIFIED — нет
достаточных доказательств состояния. Неизвестность обязательного gate блокирует GO.

## Доступ и фактические версии — обновлено по live inventory

Новый снимок production получен **2026-11-09T20:55:42Z** через существующую
доверенную сессию WinSCP `p706494@kzn.mba`. Сохранённый пароль использован самим
клиентом, не извлекался. Host-key checking не отключался. Reader передан в stdin,
не записывался на сервер; Moodle bootstrap, DML/DDL, cron, backup/restore не запускались.
Дополнительный PHP reader использовал read-only transaction, выключенные prepend/
append/error logging и выдавал только metadata/счётчики. Никаких business rows,
users, task customdata, содержимого config, паролей или токенов в отчёте нет.

Ранее alias bms-erp не прошёл host-key проверку. Правильный stage41 адрес с
существующим ключом закрывал соединение до key exchange; этот маршрут не менялся.
GUI capture WinSCP оставался недоступен. Штатный console client с сохранённой
production-сессией обеспечил прямой доверенный read-only доступ; forced handler
и серверная конфигурация не расширялись.

[Свежий протокол](RC30_PRODUCTION_REFRESH.md), [metadata evidence](RC30_PRODUCTION_REFRESH_EVIDENCE.json),
[точные schema differences](RC30_PRODUCTION_SCHEMA_DIFF.json).
Исторический [inventory08.11](../reconcile/issue44-production-inventory.json)
сохранён; общие версии и hashes в двух снимках совпадают.

| Объект | Подтверждено свежим снимком | Граница |
|---|---|---|
| Moodle code / DB |4.1.22,2022112822.00 /2022112822|Full Core tree SHA NOT VERIFIED|
| PHP CLI |8.1.34,16 выбранных extensions присутствуют|Web SAPI NOT VERIFIED|
| DB |Percona8.0.46-37,utf8mb4_unicode_ci|Metadata, не load/restore acceptance|
| BMS code / DB |2026101878 /2026101878|Не новый candidate0906|
| Corporate code / DB |2026102613 /2026102613|bridge declaresAPI1.26;behavior compatibility NOT RUN|
| KBA Theme |Активная kba,code/DB2026092213|UI/полный theme source NOT VERIFIED|
| Plugins |472 DB version entries,203 code version files|Code enumeration частичная по plugin types|
| Schema |704 tables,7528 columns,2519 distinct indexes|BMS142; новые drift blockers ниже|
| Maintenance |DB flag0,cli maintenance marker отсутствует|Не изменялся|
| Scheduled/adhoc |127 scheduled,120 enabled;adhoc0;faildelay0|15 enabled nextruntime старше суток;health NOT VERIFIED|
| Storage/memory |Получены host-level значения|Account quotas/inodes/capacity acceptance NOT VERIFIED|
| Backups/restore |Не проверялись/не выполнялись|NOT VERIFIED / NOT RUN|

**Новые P0 schema gates:** отсутствуют adminitem.starttime/duetime и11 DECIMAL
полей имеют scale0 вместо ожидаемых2/3/6.0906 не исправляет их на пути1878→0906.
Известные deviation.executionstatus и ordered task index также отсутствуют;
для них достижимые0890/0900 repairs существуют. Не запускать upgrade до отдельного
repair proposal, проверки диапазонов и exact-schema rehearsal. Подробнее в матрице.

## Идентичность кандидата и gates

- PR62 head: `e7e9b1b5f0c23b14da9bcc8ad2195d468ce8ee3f`.
- Проверенный source: `acff5299f895badb3f5efbfc84b676ef8f7ef9fd`.
- Проверенный synthetic merge: `32c06de0892adf3d210535c2432a11a0c1110bd3`.
  Текущий synthetic merge GitHub может отличаться после docs-коммитов; его нельзя
  выдавать за SHA выполненного CI. После source изменялись только документы.
- BMS2026110906 / release1.1.0-rc29; RC30 — цель acceptance, не принятый release.
- Corporate2026110901 / 0.40.10-bms.1 — **только изолированная CI-пара**.
- Run [42686347343](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42686347343), artifact13704536748.
- ZIP `INSTALL_local_kbacompany_1.1.0-rc29_build2026110906.zip`.
- SHA256 `afa36dead177ceb87b0a2833891823cc70980fed890992fcd04c2ebf5debd080`.
- Manifest `MANIFEST_build2026110906.json`,1790 entries; CRC/hash/manifest PASS.
- Старый PR60 ZIP имеет SHA256 `05a4a65ffd896990ece324a157ee8b6e1ef011ee8e2459e3a3f1d20777e03986`.
  Архивы отличаются ровно двумя PHP tests; остальные entries побайтно равны.
  **Одинаковый build0906 не делает ZIP взаимозаменяемыми.** Не использовать latest.

| Проверка | Результат и граница |
|---|---|
| Static, PHP7.4 syntax, packaging | PASS, run42686347343 |
| BMS PHPUnit MariaDB10.11.19 / Percona8.0.46-37 | FAIL:618 tests/7274 assertions,1 error+18 failures,0 skipped на каждой |
| Corporate PHPUnit0901 | PASS150/962 на каждой; НЕ Corporate2613 |
| Fresh +11 historical origins | PASS12 scenarios,11 comparisons183tables,24 preservation logs на каждой |
| Точная пара production1878+2613 →0906 | NOT RUN; from1879 не заменяет эту пару |
| Kanban | PASS10/90; исходные21 падения стали19, новых нет |
| Learning/queues/docs/employees/RC30/member/tenant сервисы | PASS в CI; HTTP/session полнота не доказана |
| Source preservation | PASS для main740fce1/2078 сохранённых путей; не full production/sibling compatibility |
| Artifact vs Git | PASS по содержанию:19 исторических EOL-only различий,58 ожидаемых development exclusions; не raw byte equality со всеми Git blobs |
| Ограниченный secret scan артефакта | PASS: запрещённых имён/4 сигнатур нет; не полное доказательство отсутствия любых secrets |
| Полный production secret audit / configuration | NOT VERIFIED; секреты не читались и не публиковались |
| UI/HTTP/direct GET/POST/session ACL/KBA+Boost/legacy+owned | NOT RUN для реальной production-пары |
| Cron/integrations/performance/restore | NOT RUN для реальной production-пары |
| GO на production | HOLD |

Повторно прочитаны локальные ZIP/manifest/raw JUnit и живые GitHub metadata.
CI не перезапускался: код кандидата не меняется. [Полный протокол PR62](../reconcile/PR62_ACCEPTANCE.md),
[точные19 IDs](../reconcile/pr62-remaining-triage.json), [scan](RC30_ARTIFACT_SCAN.json).
Ограниченный scan проверяет private-key headers, GitHub tokens, AWS access IDs,
OpenAI project-key signatures и запрещённые имена; результаты не содержат значений secrets.

## GitHub: граф зависимостей

| PR | Head SHA | Base | State |
|---|---|---|---|
| [#53](https://github.com/rimanfatykhov-arch/bms24/pull/53) | `28a60d010d8c59fd301a2bf1b2df628e38fb79dc` | `fix/pr49-schema-p0` / `5c59057cb7af3fd545cd661bb7c2b3daa5ae7cd9` | OPEN / Draft / unmerged |
| [#54](https://github.com/rimanfatykhov-arch/bms24/pull/54) | `6a0c81fa731e8269881fb13ea1e28f66697a1823` | `ops/1897-stage41-https-basic-auth` / `751046db0cd7f6f2a9fe1b84ce6f8c2471050222` | OPEN / Draft / unmerged |
| [#55](https://github.com/rimanfatykhov-arch/bms24/pull/55) | `a2347d2192a7df498b68ca0d24795f14b4eca7b2` | `main` / `740fce16db15bb3551071cc11fe584c680fd738d` | OPEN / Draft / unmerged |
| [#56](https://github.com/rimanfatykhov-arch/bms24/pull/56) | `1ced6b88e5933bb8d63aa1806411ac4d8a6a5443` | `fix/pr50-runtime-blockers` / `1146e1bc330621ffcfa7dcb3881a2188110904c8` | OPEN / Draft / unmerged |
| [#57](https://github.com/rimanfatykhov-arch/bms24/pull/57) | `1bfa75bf439e8cf45725c2c3bb69ca01b543fb01` | `fix/issue44-rc30-diagnostics` / `1ced6b88e5933bb8d63aa1806411ac4d8a6a5443` | OPEN / Draft / unmerged |
| [#58](https://github.com/rimanfatykhov-arch/bms24/pull/58) | `d3a16c3d08b9b10ac7edc32658157aa7f237e9a0` | `integration/rc30-pr53-pr56-0903` / `1bfa75bf439e8cf45725c2c3bb69ca01b543fb01` | OPEN / Draft / unmerged |
| [#59](https://github.com/rimanfatykhov-arch/bms24/pull/59) | `2446347adf26ebb9e1520414a65c89e930dadba2` | `fix/pr57-document-employee-blockers` / `d3a16c3d08b9b10ac7edc32658157aa7f237e9a0` | OPEN / Draft / unmerged |
| [#60](https://github.com/rimanfatykhov-arch/bms24/pull/60) | `a60ce4e99fe1931774eaa7da3be0e23fe1d0b92f` | `fix/rc30-period-task-battleplan` / `2446347adf26ebb9e1520414a65c89e930dadba2` | OPEN / Draft / unmerged |
| [#62](https://github.com/rimanfatykhov-arch/bms24/pull/62) | `e7e9b1b5f0c23b14da9bcc8ad2195d468ce8ee3f` | `fix/rc30-task-membership-batch-deadlines` / `a60ce4e99fe1931774eaa7da3be0e23fe1d0b92f` | OPEN / Draft / unmerged |

Основная линия: #56 → #57 → #58 → #59 → #60 → #62. Функции #53 перенесены
выборочно в #57. #54 — отдельная ops-ветка, #55 — документы для main.
**#61 — Issue Design System2.0, не PR**, не входит в release scope.
Снимок: [RC30_AUDIT_EVIDENCE.json](RC30_AUDIT_EVIDENCE.json).

| PR | Static PASS run | Moodle FAIL run |
|---|---|---|
|53|42587346888|42587346907|
|56|42589047887|42589047888|
|57|42596006149|42596006129|
|58|42600654023|42600654381|
|59|42610777658|42610777656|
|60|42678997127|42678996842|
|62|42686347343|42686347318|

#55 CI42585706108 PASS; для head#54 workflow runs не возвращены, NOT DEPLOYED/NOT ACCEPTED.
Номера PR не задают порядок автоматического merge. В данном аудите merge не выполнялся.

## Безопасные команды администратору — только чтение

Не обходить host-key проверку; доверенный fingerprint получить через независимый
канал владельца сервера. С уже доверенной административной сессии stage-host:

```sh
ssh -F /root/.ssh/bms24_ruweb_inventory.conf -o BatchMode=yes -o StrictHostKeyChecking=yes ruweb-inventory inventory
```

Существующий forced handler разрешает только literal `inventory`, stdin закрыт.
Он не даёт общий shell и не покрывает следующие проверки. Не расширять handler
в рамках этого задания. Остальное выполняет администратор непосредственно в
доверенной production-консоли, результаты передаёт только в обезличенном виде.

```sh
date -u '+%Y-%m-%dT%H:%M:%SZ'
/usr/bin/php -r '
$out = ["php_version" => PHP_VERSION, "sapi" => PHP_SAPI];
foreach (["mysqli","curl","dom","fileinfo","gd","intl","mbstring","openssl","simplexml","soap","sodium","xml","xmlreader","zip","zlib","Zend OPcache"] as $ext) {
    $out["extensions"][$ext] = extension_loaded($ext);
}
foreach (["memory_limit","max_execution_time","max_input_vars","post_max_size","upload_max_filesize","opcache.enable","opcache.enable_cli"] as $name) {
    $out["ini"][$name] = ini_get($name);
}
echo json_encode($out, JSON_PRETTY_PRINT), PHP_EOL;
'
df -Pk /var/www/p706494/data/www/edu.kzn.mba
df -Pi /var/www/p706494/data/www/edu.kzn.mba
free -m
getconf _NPROCESSORS_ONLN
```

Отдельно выполнить df для приватно подтверждённых moodledata/DB/backup volumes.
Хостовые free/CPU не доказывают квоты аккаунта — проверить панель.
Web PHP: прочитать существующие настройки handler домена в панели и существующий
ограниченный диагностический экран. Не создавать phpinfo.php, не публиковать
phpinfo/environment/config. PHP-FPM binary version без привязки к домену недостаточна.
Если безопасного источника нет — NOT VERIFIED.

Версии code без require/include/bootstrap (выполняется из production-консоли):

```sh
ROOT=/var/www/p706494/data/www/edu.kzn.mba
/usr/bin/php -n /dev/stdin "$ROOT" <<'PHP'
<?php
$root=$argv[1];
$files=['moodle'=>$root.'/version.php'];
foreach (['local','theme','mod','blocks','enrol','auth','filter','report','repository','admin/tool'] as $type) {
    foreach (glob($root.'/'.$type.'/*/version.php') ?: [] as $path) {
        $files[substr($path,strlen($root)+1)]= $path;
    }
}
foreach ($files as $component=>$path) {
    if (!is_file($path) || is_link($path)) { continue; }
    $text=file_get_contents($path);
    preg_match('/\$(?:plugin->)?version\s*=\s*([0-9.]+)/',$text,$v);
    echo json_encode(['component'=>$component,'code_version'=>$v[1]??null,
        'version_file_sha256'=>hash('sha256',$text)]), PHP_EOL;
}
PHP
GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false -C "$ROOT" rev-parse --verify HEAD
GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false -C "$ROOT" diff --no-ext-diff --no-textconv --quiet HEAD -- . ':!config.php'
printf 'tracked_source_diff_exit=%s
' "$?"
```

Git exit0=tracked tree clean,1=tracked diff,>1=error; untracked files не покрыты.
Отсутствие Git требует безопасного file-hash manifest code-only, без config,
secrets, backups, data, logs; полный source identity остаётся NOT VERIFIED до
его сопоставления. Version-only hashes недостаточно. Таблица SQL ниже охватывает
все установленные plugin DB versions; перечисление code directories выше —
стартовый набор, дополнить недостающие plugin types по установленному списку.

В уже аутентифицированной DB-консоли с выбранной Moodle DB (без password argv),
заменить `PFX` приватно подтверждённым префиксом. Не читать другие config values,
users, task customdata, тексты сообщений или записи бизнеса:

```sql
START TRANSACTION READ ONLY;
SELECT @@version AS db_version, @@version_comment AS db_distribution,
       @@sql_mode AS sql_mode, @@character_set_database AS charset,
       @@collation_database AS collation, @@max_allowed_packet AS max_allowed_packet,
       @@innodb_buffer_pool_size AS innodb_buffer_pool_size;
SELECT name,value FROM PFXconfig WHERE name IN ('version','maintenance_enabled','theme');
SELECT plugin,value AS db_version FROM PFXconfig_plugins WHERE name='version' ORDER BY plugin;
SELECT component,classname,disabled,lastruntime,nextruntime,faildelay
  FROM PFXtask_scheduled ORDER BY component,classname;
SELECT COUNT(*) AS pending_adhoc_tasks FROM PFXtask_adhoc;
SELECT TABLE_NAME,ENGINE,TABLE_COLLATION,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH
  FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
       (COLUMN_DEFAULT IS NULL) AS default_is_null,SHA2(CAST(COLUMN_DEFAULT AS CHAR),256) AS default_sha256,EXTRA,
       CHARACTER_SET_NAME,COLLATION_NAME
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE
  FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME;
SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
  FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION;
ROLLBACK;
```

Произвольные defaults не передавать: query возвращает только NULL flag/hash;
три repair defaults проверять приватно по ожидаемым empty/empty/pending.
TABLE_ROWS — оценка InnoDB, не доказательство сохранности. Constraints сравнивать
с XMLDB и application invariants; отсутствие SQL FK само по себе не дефект Moodle.
Maintenance также проверить на наличие cli maintenance marker в приватно
подтверждённом dataroot, не создавать/удалять его. Cron: панель должна подтвердить
schedule, account, PHP binary и последнее успешное выполнение. lastruntime не
доказывает успех всего cron. Не публиковать сырой crontab, ps aux или логи.

Для Corporate2613 отдельно собрать code-only SHA256 bridge.php, implementation
projection/discussion/board/curator и публичные API signatures с параметрами через
статический анализ/tokenizer без include. Сопоставить с [матрицей](RC30_COMPATIBILITY_MATRIX.md).
Не выгружать production-данные; при необходимости переноса исходников сначала
исключить config/secrets и получить отдельное разрешение на конкретный набор.

## Что закрывает HOLD

1. Fresh metadata получены; подтвердить полный source identity, Web PHP, quotas и закрыть обнаруженный schema drift.
2. Corporate2613 semantic/API reconciliation и выбор сохранения установленного Corporate;
   replacement только отдельным согласованным планом.
3. Согласованный backup set и успешный isolated restore + exact1878/2613 upgrade rehearsal.
4. Закрытие19 ошибок и обязательных HTTP/UI/tenant/cron/integration/performance gates.
5. Выбор одного SHA/manifest/hash, принятие QA и владельцем; затем отдельное разрешение
   на конкретный host/window/runbook/rollback. Этот документ такого разрешения не даёт.

[Риски и совместимость](RC30_COMPATIBILITY_MATRIX.md) · [Backup/restore](RC30_BACKUP_RESTORE_PLAN.md) · [Runbook](RC30_DEPLOYMENT_RUNBOOK.md).

## Независимое ревью документации

Архитектор сверил миграции/API и ограничения2613; QA сверил PR graph,19 IDs,
rawCI/manifest/hash и ссылки; security проверил команды и границы backup/restore.
Замечания внесены до docs commit. Это ревью документов, не production security
acceptance. Все7 shell snippets проверены bash -n, оба PHP static-parser snippets — lint; расширенный reader дополнительно проверен security reviewer до фактического
read-only запуска через сохранённую WinSCP-сессию. Финальные статусы production gates остаются неизменными.
