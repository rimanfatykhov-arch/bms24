# RC30 — воспроизводимый deployment runbook

2026-11-09. **PLAN ONLY / HOLD. Не выполнять сейчас.**
Это последовательность будущей отдельно разрешённой операции на `edu.kzn.mba`.
Ни maintenance, ни cron, ни backup, ни installation/upgrade/purge/restore здесь
не запускались. Подготовка документа и Draft PR не является G4 approval.

## Обязательные входы — до назначения окна

| Вход | Сейчас |
|---|---|
| Product owner,оператор,независимый проверяющий,канал инцидента | Должны быть назначены |
| UTC window,максимальный downtime/DDL lock,abort deadline | Не согласованы |
| Exact production source/DB/schema/plugin/theme/Web profile | NOT VERIFIED |
| Tested immutable artifact и однозначная версия | PR62 identity ниже; ещё не accepted release |
| Corporate2613 retained/reconciled decision | Не принято, compatibility NOT RUN |
| Backup-set ID +restore/rollback evidence,RTO/RPO | NOT VERIFIED / NOT RUN |
| Полный список writers/jobs/inbound webhooks/outbound integrations | NOT VERIFIED |
| 19 BMS failures и HTTP/UI/ACL/education/performance gates | Не закрыты |
| Команда владельца на host+SHA+hash+window+runbook+rollback | Отсутствует |

Пустые входы — stop condition; runbook не считается ready-to-execute.
После закрытия gates владелец отдельно подтверждает точный artifact, сервер,
окно, операции обслуживания/backup, план восстановления и границы smoke tests.
При новых source changes все SHA/hash/CI-ссылки обновить; не переиспользовать
старый протокол под изменённый ZIP.

## Кандидат для анализа, не разрешённый deployment package

- Source `acff5299f895badb3f5efbfc84b676ef8f7ef9fd`, документационный head
  `e7e9b1b5f0c23b14da9bcc8ad2195d468ce8ee3f`, BMS2026110906.
- GitHub run42686347343/artifact13704536748, INSTALL ZIP1790files.
- SHA256 `afa36dead177ceb87b0a2833891823cc70980fed890992fcd04c2ebf5debd080`.
- Manifest `MANIFEST_build2026110906.json` из того же artifact.
- В ZIP только kbacompany; Corporate0901/Core/theme не устанавливать вместе с ним
  по умолчанию. Сохранение установленного Corporate2613 ещё требует acceptance.
- PR60 ZIP0906 имеет другой hash. Отсутствие version bump в test-only PR62 не
  разрешает выбирать архив по имени/build. Не использовать latest или rebuild
  на production. Проверить retention CI и наличие approved bytes до окна;
  архивирование approved code artifact разрешать отдельно от production data.

## Последовательность после отдельных approvals

| Шаг | Действие / ответственный | Gate / доказательство / остановка |
|---|---|---|
|1. Freeze release|Release manager фиксирует SHA,manifest,ZIP hash,dependency lock и source review|Один неизменный пакет; при расхождении STOP|
|2. Preflight|Оператор повторяет metadata/ресурсы/versions/schema/cron/plugin inventory|Сверить с rehearsal; любое drift — HOLD|
|3. Approval|Владелец подтверждает host,window,artifact,операторов,maintenance/writers,backup+restore scope|Без явной команды не переходить к шагу4|
|4. Quiesce writers|По согласованному списку закрыть inbound changes,включить maintenance,остановить scheduler/workers/integrations; сохранить прежние состояния|Maintenance сам по себе не блокирует cron/API; убедиться в завершении running jobs/transactions|
|5. Coherent backup|Оператор создаёт согласованный DB+code+moodledata+config set проверенным инструментом|Hash/integrity/coverage/time consistency; нехватка места/ошибка — STOP до изменения кода|
|6. Restore verification|Проверяющий выполняет approved isolated restore нового набора; либо эквивалентный явно утверждённый контроль, с уже доказанной полной процедурой|По умолчанию требуется полный test restore; автоматического исключения ради времени нет|
|7. Migration rehearsal|В изолированной точной копии применить выбранный BMS пакет с сохраняемым Corporate; измерить DDL и доказать данные/schema/ACL|Никакого пробного DDL на production; все обязательные gates PASS|
|8. Install code|Оператор проверяет hash/root/manifest,подготавливает отдельный release path и заменяет только согласованный plugin path по hosting-safe процедуре|Не накладывать неполный ZIP поверх дерева с остаточными файлами; сохранить rollback code,не трогать config/data/Core/Corporate/theme|
|9. Moodle upgrade|Запустить один upgrade через подтверждённый PHP CLI,под deployment lock; вести приватный журнал|Любая ошибка/несовместимый guard/превышение lock-time — STOP; не reset version,не обходить guards|
|10. Cache|Только после успешного upgrade выполнить стандартную очистку Moodle caches подтверждённым runtime|Не вручную удалять moodledata; проверить Web opcode behavior по утверждённой конфигурации|
|11. DB/identity|Сверить code/DB versions,expected fields/index order/full schema,data invariants и logs|Не ограничиваться номером0906;unexpected data/schema change — rollback decision|
|12. Security/education smoke|Назначенные тестировщики проверяют login/courses/gradebook/files,tenant A/B,nonadmin/manager/reviewer,direct GET/POST и revoked membership|Чужие данные/permission bypass/утрата курсов/файлов — P0,немедленный STOP|
|13. UI/business smoke|Владелец проходит заранее согласованные learning/tasks/Battle Plan/docs/processes/metrics/communications сценарии в legacy/owned и поддержанных темах|Не произвольное экспериментирование с реальными данными; разрешённые test records заранее определены|
|14. Controlled resume|По отдельному подтверждению вернуть maintenance/входы и writers в согласованном порядке;сначала наблюдаемый canary jobs/integration|Оценить backlog/duplicates/latency,не запускать весь накопленный backlog без контроля|
|15. Close window|Release manager фиксирует deployment record,actual hashes/versions/UTC,smoke,job health и owner acceptance|При неполноте evidence статус HOLD,не объявлять production ready|

Шаги6–7 обычно репетируются заранее на отдельно разрешённой копии. Для финального
freeze/backup набор может отличаться по времени данных: владелец должен согласовать
длительность окна и способ подтверждения именно нового набора. Нельзя молча
пропустить restore из-за долгого downtime. До решения runbook остаётся HOLD.

Примеры стандартных команд **только будущей операции**, не исполнялись:

```sh
# После G4 approval, из подтверждённого ROOT и PHP binary:
/usr/bin/php /var/www/p706494/data/www/edu.kzn.mba/admin/cli/maintenance.php --enable
/usr/bin/php /var/www/p706494/data/www/edu.kzn.mba/admin/cli/upgrade.php --non-interactive
/usr/bin/php /var/www/p706494/data/www/edu.kzn.mba/admin/cli/purge_caches.php
# Только после принятия smoke и решения оператора/владельца:
/usr/bin/php /var/www/p706494/data/www/edu.kzn.mba/admin/cli/maintenance.php --disable
```

Эти команды не задают scheduler/hosting deployment/backup tool: конкретные имена
сервисов,roots,account и проверенные backup/restore команды сейчас неизвестны.
Их обязан заполнить оператор после inventory и rehearsal до G4. Не подставлять
угаданный nginx/systemctl/docker/cron command. В текущем аудите эти команды запрещены.

## Rollback: единый согласованный набор

Триггеры: migration error/несоответствие schema,утрата/повреждение данных,
company leak/ACL bypass,broken login/courses/files,несовместимый Corporate,
неприемлемые locks/длительность,неподконтрольные integrations. Оператор блокирует
новые writes и сообщает владельцу/проверяющему; не продолжает «чинить на месте».

1. Оставить/восстановить maintenance и остановку всех writers в пределах утверждённого
   аварийного плана. Изолировать outbound side effects. Не делать автоматический
   downgrade версии и не повторять произвольный DDL.
2. Выбрать exact pre-deploy backup-set ID, проверить hashes и coherent point.
   Если после открытия сайта появились новые записи, оценить RPO/data loss и
   получить решение владельца: нельзя молча затереть новые данные старым snapshot.
   Защищённое сохранение post-failure evidence/data — отдельная явно разрешённая
   операция, не включать secrets/PII в GitHub.
3. Совместно восстановить прежний code+DB+moodledata+config/server settings,
   включая точный Corporate/theme/sibling stack. Замена только PHP недопустима
   после DDL/data migration. Использовать исключительно отрепетированный restore.
4. Проверить baseline versions/schema/data/files,login/courses,tenant isolation,
   внешние idempotency и отсутствие orphaned operations. Очистка caches только
   стандартным approved способом после согласованного восстановления.
5. Разрешить обслуживание и writers только после успешного smoke и команды
   владельца/оператора. Зафиксировать incident,actual RTO/RPO и дальнейший HOLD.

## Owner UI acceptance и итоговая запись

Владелец хочет тестировать новые функции в интерфейсе. Сначала предоставить
изолированную закрытую копию с явно обозначенным окружением и контролируемыми
уведомлениями. Production UI acceptance возможна только после закрытия P0 и
отдельной установки; само желание посмотреть UI не является разрешением deploy.

Deployment record: exact SHA/ZIP/manifest/hash,все plugin code+DB versions,
Core/WebPHP/DB profile,UTC start/end,backup-set+restore protocol,RTO/RPO,
результаты acceptance,оператор/проверяющий/owner decision,оставшиеся ограничения.

**Сейчас GO отсутствует.** [Preflight](RC30_PRODUCTION_PREFLIGHT.md),
[риски](RC30_COMPATIBILITY_MATRIX.md), [backup/restore](RC30_BACKUP_RESTORE_PLAN.md)
должны быть закрыты до отдельного production approval. Этот PR только документы.
