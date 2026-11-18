# BMS24: единый стандарт backup, восстановления и публикации

Версия BRP-1.0-proposed, 14.11.2026. Документационный стандарт для согласования; не разрешение на операции. Первая карточка: [PR100](PR100_BACKUP_ACCEPTANCE.md).

Это единственная точка входа в операционный процесс. Переиспользует [backup contract](RC30_BACKUP_RESTORE_PLAN.md), [preflight](RC30_PRODUCTION_PREFLIGHT.md), [recovery smoke](RC30_RECOVERY_SMOKE.md), [release69 history](RC30_RELEASE69_RUNBOOK.md) и [QA standard](../05_RELEASE_QA_STANDARD.md). Старые версии/окна/HOLD в них — исторические срезы, не текущие разрешения. FULL, security, Corporate и release gates не ослабляются.

## 1. Ответственность и идентичность

Владелец отдельно разрешает паузу записей и отдельно публикацию exact package; назначает ответственного, RPO/RTO и допустимые условия отката. Один оператор (Codex) исполняет операции с реальными данными; второй проверяет доказательства и не запускает параллельный capture/deploy. Документирование не даёт проверяющему операционных полномочий. Один release lock и backup-set ID; перед началом проверить отсутствие второго исполнителя.

Зафиксировать main/base, PR head, tested merge/tree, SHA256 ZIP и manifest, CI run/attempt/artifact ID, зависимости с byte manifests, версии runtime и источник каждой проверки. Номер build не заменяет SHA. При изменении дерева определить affected checks и повторить их, не переносить PASS автоматически. Пакет не пересобирать между приёмкой и установкой.

Public evidence: aliases, IDs, hashes, статусы и безопасные ссылки. Адреса, пользователи, приватные пути, configuration, dump, файл-листы с PII, ключи и подробные логи — только private runbook владельца. В Git нет реальных backup. Private runbook обязан содержать точные команды оператора, mapping aliases, ACL, способ доступа владельца, restore/abort команды и их версии; заполненные секреты никогда не включать в PR.

## 2. Baseline и вместимость

До capture получить fresh timestamped account used/limit/free BYTES и INODES из источника квоты, filesystem available/inodes и storage backend limits. df общего диска и du аккаунта не подтверждают account quota. Явно записать MB=10^6 или MiB=2^20, GiB=2^30; неизвестные units не смешивать. Инвентаризировать code+все plugins/themes/custom changes, config/external dependencies, DB engines/charset/routines/events/triggers, file stores/symlinks, writers и images по immutable ID/digest.

Для каждого volume и каждой фазы считать одновременно живущие allocations: existing usage + new peak + reserve <= limit. В расчёте свободного места existing usage уже вычтен; повторно не прибавлять. Фазы: pre-copy, quiesced final copy/dump, encryption, independent transfer, extraction/import, baseline, install, rollback, reinstall. Показывать отдельно logical bytes, allocated bytes, DB logical dump budget, DB import/binlog/tmp growth, tar headers, encryption overhead, cache/browser writable layers, sparse/hardlink expansion, reserve и inode demand. Коэффициент сжатия не гарантировать. Существующие images освобождают только incremental image budget: writable layers/temp остаются.

Storage варианты:
- encrypted archive и clone на тесте: одновременно оба, запас и временные файлы;
- encrypted archive на source + независимый encrypted archive у владельца/на другом failure domain + один streaming clone на тесте;
- отдельный согласованный приватный диск/хранилище.

Источник потока и независимая копия не удаляются/не перезаписываются при restore. Два каталога одного диска не независимы. Owner storage: приватный несинхронизируемый volume с ACL, quota/inode, подтверждённой доступностью и процедурой read-back. Если ciphertext сначала попадает во временный файл загрузчика, учитывать обе копии. Rollback — из immutable backup в disposable clone, не второй полный working snapshot; повторное создание/очистка disposable paths требует заранее точной авторизации, не даёт права удалять старые наборы/сайты.

## 3. Согласованность и состав

Полный набор: вся Moodle DB, code/Core/plugins/themes/local modifications, moodledata и внешние stores, config и нужные внешние config/key dependencies, owner/mode/ACL metadata, original cron/maintenance/workers и environment mapping. Исключения перечислить и доказать восстановимость. Отсутствующий config нельзя назвать полным disaster-recovery backup.

Rsync pre-copy уменьшает финальную паузу, но требует полного staging allocation и пока не согласованного plaintext набора. До final sync это не accepted backup. Single-transaction dump согласует InnoDB только без concurrent DDL; не синхронизирует DB с файлами. Для streaming tar+encryption без pre-copy все writers должны оставаться исключены весь capture. Альтернативный atomic snapshot требует отдельного доказанного метода DB/files consistency.

Инвентаризировать web/admin/API, cron, workers, integrations и внешние writers. Maintenance не блокирует автоматически каждый канал. Подтвердить drain jobs/transactions и запрет новых writes; неизвестный writer означает HOLD. No force kill неизвестных процессов. Стабильные file timestamps полезны, но сами по себе согласованность не доказывают.

## 4. Подготовка до согласования паузы

Оператор готовит точный versioned capture/restore driver и private commands. Проверяющий проверяет: fail-closed, exclusive lock, уникальные destinations, no overwrite/symlink/path escape, permissions0700/0600, no secrets in argv/logs, deadlines, quota-aware continuous bytes/inode guards, stop до исчерпания reserve. Один df preflight недостаточен; лимит emitted bytes и прогноз следующего этапа обязательны.

Все producer/tee/hash/encrypt/archive/transfer exit codes должны проверяться, включая фоновые процессы и process substitution: pipefail не гарантирует их учёт. Сохранять PID, explicit wait каждого, expected digest length и count. Помечать PARTIAL до успешных проверок; успех tar listing не означает DB restore. До extraction проверить имена/типы/links архива и запрет выхода за clone root; не запускать приложение до проверки завершённого authenticated decrypt. Обрыв/MDC failure оставляет непригодный изолированный clone.

До первого state change поднять независимый от SSH watchdog. Он знает точное original state, backup phase и PID/starttime, прекращает своих capture children при deadline, возвращает прежние cron/workers/access без перезаписи чужих изменений. Идемпотентный finally плюс ручная команда восстановления; конфликт состояния требует немедленной эскалации. Failure tests: dump/encrypt/hash failure, disconnect, timeout, byte/inode budget, wrong key, corrupt/truncated archive, background verifier failure, nonempty destination, invalid archive paths. Это синтетика, не реальные данные.

Key protocol: public recipient fingerprint сверить вне канала архива, private key/passphrase отдельно, owner-controlled protected recovery copy. На source закрытый ключ не нужен. Протестировать точные версии GPG source/test/owner с синтетическим tar, полным decrypt/extract/hash и wrong-key/corruption negatives. Проверить восстановление ключа из резервной копии, а не только из уже открытого agent. Зафиксировать cipher/format/compression; не предполагать межверсионную совместимость. Temporary secret material только в согласованном приватном tmpfs и с cleanup evidence; не удалять persistent key без политики.

## 5. Отдельное разрешение окна и capture

Владелец получает дату/интервал МСК, target alias, перечень временно ограниченных writers, измеренную оценку и hard deadline, consistency method, abort conditions, автоматический/ручной restore и smoke. Требуется явное разрешение именно ограничения доступности. Ни старое окно, ни фраза backup разрешён этого не заменяют. 85 секунд исторической паузы — не SLA. Если длительность нового метода не измерена, сначала согласовать безопасную синтетическую нагрузочную пробу; не обещать15минут.

После разрешения: сохранить exact original states → watchdog → ограничить writers → drain → согласованный capture → проверить exits/coverage → немедленно восстановить original states → проверить сайт и cron. При таймауте/ошибке incomplete/HOLD, не продлевать паузу молча. Перенос и длительные verify выполнять после возвращения сайта, если метод это допускает.

Manifest: set ID, source alias, UTC begin/end/consistency point, tool/driver versions, coverage/exclusions, component identity, counts/sizes/hashes, original states hash, quota snapshot, operator/reviewer, results и references. Внутренний secret-bearing manifest encrypted. Cipher SHA independently read back на source и owner disk; full decrypt integrity; archive verification; ключ восстановлен владельцем. Это ещё не restore PASS.

## 6. Изоляция и реальное восстановление

До bootstrap: отдельные DB/credentials/volumes/address, outbound default deny с реальным отрицательным connect, запрет production endpoints/storage, без docker socket/общих writable mounts/публичного доступа. Cron/queues off; mail/webhooks/MAX/AI/payments выключены. Декодировать baseline config в закрытом месте, создать isolation overlay отдельно с перечнем изменений, не активировать production credentials. Работающие staging/test не использовать.

Restore baseline: выполнить реальный импорт всей DB, проверить exit/table counts/schema/versions и critical row invariants; сверить code/data manifests, каждый relevant DB file reference и contenthash, download SHA, login/pages/roles/company isolation/revoked sessions/history. DB schema listing и tar -t не заменяют SQL import. Только synthetic test objects для write checks. Evidence без PII.

Порядок репетиции: baseline PASS → exact accepted ZIP → clone-only purge caches → loaded asset identity и smoke → полный rollback code+DB+files из набора → повторная data/ACL/files verification → тот же ZIP reinstall → smoke. Corporate и прочие зависимости сохранять согласно baseline; unexpected migration означает HOLD. Проверить desktop/mobile/keyboard, меню, Send/repeat send, persisted rows, attachments/downloads, A/B/foreign/revoked access,5xx/browser errors. Замерить capture/restore/install/rollback, но не выдавать замеры за согласованные RPO/RTO.

## 7. Публикация и откат

Только после mandatory evidence PASS владелец отдельно разрешает exact candidate/package/target/window и rollback policy. Перед исполнением recheck identity/baseline/no concurrent deployment/backup availability. CI не даёт разрешения. Smoke и stop conditions совпадают с репетицией; несовпадение SHA, unexpected upgrade, missing backup/key, capacity, ACL/data failures,5xx — STOP.

При откате после новых production writes сначала остановить дальнейшие writes по утверждённой incident-процедуре и сохранить post-change data. Владелец выбирает reconciliation/forward repair либо явно допустимую потерю в рамках утверждённого RPO. Нельзя молча восстановить старую DB поверх новых записей. Code-only rollback допустим только при доказанной совместимости DB/files. Оригинальное состояние заданий/доступа восстанавливается и проверяется.

## 8. Evidence и retention

Одна строка evidence: gate_id, release/tree/package, backup_set_id, phase, engine, tool/driver version, observed_at UTC, start/end/duration, expected/observed, status PASS|FAIL|NOT RUN, exit codes, immutable log/report digest, public/private reference, operator, reviewer, limitations. Для PASS требуются исполнение и проверенные результаты; planned/reported by author = NOT RUN для независимого reviewer до проверки raw evidence. NOT APPLICABLE только по явному утверждённому правилу.

Предлагаемая retention, НЕ утверждённые сроки: до приёмки сохранить source backup, independent copy, key recovery, original package и evidence. После выпуска сохранить последний проверенный pre-release набор и post-release recovery point на согласованный owner период; archive evidence дольше CI TTL, если срок решения выходит за TTL. Число дней/наборов и юрисдикционные ограничения задаёт владелец. Для удаления каждого набора предъявить ID, immutable copies, restore proof, зависимые overlays/containers, retention expiry, последствия для rollback и точный список paths. Только явное разрешение; сначала проверить разрешённые realpaths и mounts. Никакого автоматического prune старых backup или сайтов.

## 9. Повторение следующего выпуска

Повторяются только parameterized inventory, quota, source identity, rehearsal и approvals, а не изобретение метода. До принятия BRP-1.0, заполнения private driver и первой успешной настоящей репетиции процесс считается описанным, но не операционно доказанным.
