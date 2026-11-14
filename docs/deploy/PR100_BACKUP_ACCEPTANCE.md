# PR100: первая карточка стандарта BRP-1.0

Статус: HOLD, не разрешение на capture или публикацию. Операционный порядок — только [единый стандарт](BACKUP_RELEASE_STANDARD.md). Эта карточка является примером заполнения и не утверждает завершённую настоящую репетицию.

| Identity | Значение |
|---|---|
| PR | #100 |
| head |01af6405bcb7db73f67cb45fee837ba97b8c6ed3|
| base |f34ab6ff0d2f67a56972e99c23d096ed77a9873c|
| tested merge |c1f89d2e3f0be63aa25cab60ce6be8bbc1ef3ee3|
| tree |05603e953939ee606f82d6c9583aaf0378357664|
| ZIP SHA256 |022c9a408873fd31c83c7122aab53558939c3a546c42317c653a0205114a52db|
| manifest SHA256 |3ca7037bde112bc56c707f0fa4d4db6dba4b3854988f4443668233439203b004|

[Runtime CI](https://github.com/rimanfatykhov-arch/bms24/actions/runs/43578010085), [FULL](https://github.com/rimanfatykhov-arch/bms24/actions/runs/43578010149), [repaired profile](https://github.com/rimanfatykhov-arch/bms24/actions/runs/43578010116). Их PASS относится к synthetic CI, не к восстановлению настоящей базы. Corporate не входит в ZIP; фактическую зависимость сверять отдельным manifest.

## Сведение методов

Исторический capture с rsync выполнял live pre-copy, затем блокировку writers/drain, final sync и dump; plaintext staging занимал полный размер набора. Это объясняет короткое quiescence окно, но не задаёт SLA нового encryption pipeline. Прежние наборы продолжают занимать квоту; capture size не равен free account space. Новый метод предполагает encrypted stream, source archive и независимый owner archive, один unpacked clone. Он экономит локальный archive на тесте, но не убирает source archive, DB/import/temp или owner download temporary files.

В private evidence сопоставлены исходный capacity-plan и capacity-correction. Исправление image budget допустимо только для реально выбранных cached image IDs; writable layers не нулевые. Арифметическая поправка: если старый потоковый бюджет15.0GiB и исключается1.0GiB image download, итог14.0GiB, а не13.9GiB. Иное округление требует отдельной строки. Tar overhead измеряется на конкретном layout; фиксированный процент GPG не считается универсальным пределом. SQL budget не является измеренным SQL dump.

## Приёмка на текущем этапе

| Gate | Result | Предел доказательства |
|---|---|---|
| Exact source/package and existing CI |PASS|Не новый runtime run|
| Исторический rsync capture method |PASS review|Не accepted restore нового backup|
| Наличие reusable image IDs |PASS inventory|Новый clone не запускался; pin IDs в driver ещё требуется|
| Source account byte/inode quota |NOT RUN fresh authoritative quota|Старые panel/du оценки не достаточны|
| Owner storage/key recovery |NOT RUN|Владелец должен указать и проверить private destination|
| GPG source→test→owner fixture |NOT RUN independently|Заявление другого исполнителя не заменяет raw evidence|
| Background verifier negative control |FAIL helper design|Ошибка фонового verifier может не попасть в общий exit code|
| Новый consistent capture/independent copy |NOT RUN|Параллельный backup запрещён|
| Реальный SQL import/baseline/ACL/files |NOT RUN|tar listing и file manifest недостаточны|
| Exact ZIP update/rollback/reinstall |NOT RUN actual baseline|Synthetic CI отдельно|
| Production publication |HOLD|Отдельное owner решение после приёмки|

## Передача единственному оператору

Использовать BRP-1.0 из этого PR, после review закрепить commit SHA. До реального capture согласовать private sizing sheet с reviewer. Исправить и воспроизвести ошибку фонового verifier (explicit PID/wait, также ошибка archive parser), quota/bytes/inode guards, unsafe archive paths. Не переносить непроверенную библиотеку в production.

Предоставить свежие account used/limit/free и units; evidence cached image IDs и driver pins; owner storage доступность/ACL/free; exact GPG versions и cross-version synthetic encrypt/decrypt/extract+hash+negative logs; восстановление owner key из backup. Затем exact capture/cleanup/watchdog failure tests и измеренную оценку паузы. Только после этого запросить окно владельца.

После согласованного исполнения предоставить полную таблицу из раздела8 стандарта, private raw logs для reviewer, доказательство original cron/access restoration и отсутствия установки на production. Не считать отсутствие deploy подтверждением отсутствия временной maintenance-паузы: эти события фиксируются раздельно.

## Нерешённые owner решения

Private owner storage и доступ к ключу; authoritative quota или согласованное расширение; retention days/наборы и отдельный список разрешённых удалений; окно/maximum downtime; RPO/RTO; затем exact publication approval. Никаких автоматических покупок, pruning или merge. До первого полного доказанного цикла стандарт воспроизводит требования, но не является доказанной автоматизацией.
