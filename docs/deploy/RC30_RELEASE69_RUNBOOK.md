# RC30 — единый кандидат PR69, HOLD

Документ заменяет операционные ссылки на PR62/build0906 в старом runbook.
Старые результаты остаются историей, а не основанием публикации. PR69 открыт
против main; PR64–68 включены через историю исходной ветки PR68. PR не объединяются.

## Идентичность и зависимости

- BMS: `2026111002`. PR69 меняет доказанно устаревшие fixtures/assertions,
  приёмку и release tooling; доменные правила/ACL/Core не меняются.
- Историческая база: BMS1878, source `a87252b642e5796c6d84105dd448d51d9a5b5241`.
  Совпадение отдельных production hashes не доказывает идентичность всего дерева.
- Сохраняемый Corporate: **2026102613**, дерево
  `687c230595bce020fc001db91b3aac276f0fee7d`, manifest
  `../release/corporate2613-manifest.json`. Все830 файлов проверяются до/после
  upgrade. Это точная копия найденного ZIP, совпадающая с830 stage41 fingerprints;
  production проверен только по9 доступным hashes, полный tree требует backup.
- Corporate0901 в основном checkout/старом workflow — диагностический контроль.
  Его PASS не разрешает публикацию поверх2613. BMS install ZIP не содержит Corporate.
- Target: Moodle4.1.22, PHP8.1, MariaDB10.11 и Percona8.0; production снимок
  09.11.2026 показывает Percona8.0.46-37. Перед окном inventory обновляется.
- Единственный release SHA, SHA256 ZIP и manifest фиксируются в итоговом протоколе
  после проверок. Изменение executable/test inputs требует нового привязанного
  доказательства; одинаковый build не означает одинаковые bytes.

## Разработка → тестирование → публикация

1. Одна ветка `release/rc30-production-candidate`, один Draft PR69. Исправлять
   подтверждённые блокеры, фиксируя test ID, причину и before/after.
2. CI строит deterministic install ZIP, проверяет manifest/version/root/CRC/hash,
   выполняет static/ACL/domain/migration тесты. Отдельные профили0901 и2613
   не объединяются в один PASS. Нет deploy jobs/production credentials.
3. Зафиксировать source SHA и скачать именно его артефакт. Проверить SHA256,
   manifest, file list, отсутствие secrets и вложенного plugin root. Сохранить
   долгосрочную копию: Actions retention14дней не является release storage.
4. Выполнить backup/restore/rehearsal по `RC30_BACKUP_RESTORE_PLAN.md`, включая
   весь установленный sibling/theme stack и реальные данные1878/2613.
   Модель2505 колонок/1569 index rows содержит только metadata; это не restore.
5. Браузерная приёмка private clone: вход/выход, обучение/очереди, documents/
   attachments, membership, manager/non-manager, две компании, прямые URL/
   download/export/API, owned/legacy, KBA/Boost, desktop/mobile, diagnostics,
   error logs и отсутствие внешних side effects.
6. Независимые QA/security подтверждают доказательства. Владелец получает
   artifact hash, зависимости, окно, RTO/RPO, abort/rollback criteria и
   разрешает публикацию **этого** кандидата. Пока этого нет — HOLD.
7. Production upgrade — отдельная явно разрешённая операция по отрепетированному
   сценарию. Текущее задание не запускает её и не снимает Draft.

## GO/HOLD

GO требует PASS всех пунктов, связанных с одним release SHA/artifact SHA256:

- CI и целостность install artifact.
- Полная идентичность реальных1878/2613 и совместимость сохраняемого Corporate.
- MariaDB/Percona: fresh, upgrade1878, schema, полный BMS, полный Corporate2613,
  обучение/очереди, documents/membership, company isolation, diagnostics.
- Backup integrity, isolated restore, upgrade rehearsal, data preservation,
  rollback rehearsal и подтверждённые RTO/RPO.
- Read-only13definition/11DECIMAL preflight **после quiesce и до первого upgrade**.
- Критические ACL, браузерная приёмка, независимый review и решение владельца.

`tools/rc30_release_gate.py PRIVATE_BUNDLE.json` проверяет полноту, SHA связи и
контрольные суммы evidence files. Нет PASS/файла/правильного SHA или Corporate0901
вместо2613 → exit1/HOLD. Формат каждого `checks.<gate>`: `status`, `release_sha`,
`artifact_sha256`, относительный `path`, `sha256`. Верхний уровень: `release_sha`,
`artifact_sha256`, относительный `artifact_path` (реальный ZIP хешируется),
integer `bms_version=2026111002`, `corporate_version=2026102613`,
`checks`. Полный список — `REQUIRED` в скрипте. Bundle с customer/infra данными
хранится приватно. Валидатор не доказывает правдивость отчётов: содержание,
подписи и актуальность проверяются независимо. Он не запускает deploy.
Даже полный bundle возвращает `EVIDENCE_COMPLETE` и deployment `HOLD`, а не
автоматический GO. Exit0 означает только комплектность. Реальный ZIP проверяется
отдельно `tools/verify_install.py` и SHA256; окончательный GO принимает владелец
после независимой проверки содержания всех отчётов.

Любой FAIL, NOT RUN, пропуск или неизвестный обязательный пункт означает HOLD.
Известный Corporate schema drift не превращается автоматически в waiver.
Изменение бизнес-defaults/корпоративной авторизации требует отдельного решения;
нельзя менять их ради зелёной CI без проверки фактического поведения.

## Будущее окно и откат

До окна: точные target/artifact/dependency hashes, оператор/reviewers, проверенные
backup+restore, измеренные сроки/место, полный inventory writers и их исходные
состояния. Никаких guessed service names или ручного ALTER на production.

После отдельного разрешения: остановить новые записи и все writers, дождаться
jobs/transactions, создать согласованный private backup, повторить read-only
preflight, установить BMS artifact без замены Corporate2613, выполнить upgrade,
проверить версии/schema/данные/ACL/health. Вернуть исходные состояния сервисов.

STOP до upgrade: недостаточно места, stale/missing backup, mismatch hashes/
версий, активный writer, несовместимые schema/ranges, недоступен rollback.
STOP после начала: unexpected DDL/error, потеря invariants/данных, ACL leakage,
ошибка queues/documents/learning. Не применять ad-hoc исправления в окне.

MySQL DDL не откатывается общей транзакцией; промежуточные savepoints возможны.
Rollback — восстановление **согласованного code+DB+moodledata+config набора**,
проверка исходных1878/2613, данных и ACL. Замена одного PHP каталога/понижение
`config_plugins.version` недопустимы. Новые записи после cutover требуют отдельного
согласования восстановления/RPO; нельзя молча уничтожать их старым backup.

## Issue63

Технический прогресс: единый Draft candidate, независимые CI профили, migration
tests, deterministic artifact, fail-closed evidence gate и runbook. Issue63
нельзя считать завершённым до restore/browser/rollback acceptance и согласованного
первого цикла публикации. CI success сам по себе его не закрывает.
