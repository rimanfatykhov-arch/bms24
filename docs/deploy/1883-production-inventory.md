# BMS24 — этап 1883: аудит production перед первым управляемым релизом

## Цель и ограничения

Сравнить фактические версии Moodle и собственных плагинов на двух окружениях:
`edu.kzn.mba` (production, RUWEB/ISPmanager) и `test.bms24.online` (staging, Timeweb).

**Этап только для чтения.** Нет установки, обновления БД или изменения файлов Moodle.
Скрипт `tools/deploy/inventory_moodle.sh` читает только статические поля version.php Moodle и известных плагинов,
наличие config.php **без чтения содержимого**, а также версию PHP CLI. Он не читает пароли,
токены, пользовательские данные, Moodle config.php, базу данных или moodledata.

## Production RUWEB — запуск

1. Скачать `inventory_moodle.sh` из этой ветки или архива этапа.
2. Через WinSCP разместить вне веб-каталога, например `$HOME/bms24-inventory.sh`.
3. В ISPmanager Shell-клиенте:

```bash
umask 077
bash "$HOME/bms24-inventory.sh" \
  --root /var/www/p706494/data/www/edu.kzn.mba \
  > "$HOME/bms24-production-inventory.txt"
cat "$HOME/bms24-production-inventory.txt"
```

Результат должен начинаться с `FORMAT|bms24-inventory-v1`, содержать строки `MOODLE|` и `PLUGIN|`,
и завершаться `END|bms24-inventory-v1`. Ошибка STOP означает, что ожидаемый каталог не найден.
Отчёт хранить вне веб-каталога. Не запускать `upgrade.php`, `purge_caches`, `chmod -R`, `chown -R`.

## Staging Timeweb — запуск

Тот же скрипт, например `/tmp/bms24-inventory.sh`:

```bash
umask 077
bash /tmp/bms24-inventory.sh \
  --root /srv/bms24-staging/moodle-test/moodle \
  > /srv/bms24-staging/inventory-staging.txt
```

Скрипт читает host-mounted Moodle files без изменения Docker-контейнера.

## Сравнение (на локальном компьютере, Python 3)

Перенести оба TXT в одну папку с `tools/deploy/compare_inventory.py`:

```bash
python3 compare_inventory.py \
  --production bms24-production-inventory.txt \
  --staging inventory-staging.txt \
  > bms24-release-gap.md
```

Скрипт помечает разные версии, несовпадение component, попытку downgrade и случаи,
когда новый плагин требует более новую версию Moodle, чем установлена на production.
Это только статическая совместимость, а не подтверждение безопасной установки.

## Release gates после отчёта

- Проверить реальные версии PHP Web/FPM отдельно: CLI PHP может отличаться.
- Проанализировать XMLDB, db/upgrade.php, порядок установки и зависимости.
- Отдельно проверить совместимость `mod/customcert` с версией Core.
- Подготовить backup DB + moodledata + код; **проверить восстановление** на отдельном контуре.
- Подготовить maintenance, откат, smoke курсов/сертификатов/прав/платежей.
- Никогда не переносить тестовую БД, moodledata или config.php на production.
- PR #17/#19 не устанавливать на production без отдельной приёмки.

Первый production deploy — только после анализа **фактических двух инвентаризаций**.
