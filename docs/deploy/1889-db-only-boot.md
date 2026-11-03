# BMS24 Stage 1889 — настройка PHP и запуск только новой тестовой MariaDB

Дата: 03.11.2026. Основание: фактический вывод Timeweb этапа 1888.

## Подтверждено (1888)

- `PHP_VERSION 8.1.34`
- `REQUIRED_EXTENSIONS OK`
- `MAX_INPUT_VARS 5000`
- `MEMORY_LIMIT 128M` (требует корректировки **для нового** Moodle)
- `OPCACHE PRESENT`
- `STAGE1888_PHP81_RUNTIME_OK` и `STAGE1888_IMAGE_PREFLIGHT_OK`
- PHP image `moodlehq/moodle-php-apache:8.1-bookworm` присутствует на Timeweb
- Постоянные parity41 контейнеры ещё отсутствуют

## Что изменится

Новые настройки PHP создаются в **только** `/srv/bms24-staging/parity41`:

- `config/php/90-bms-parity.ini`: `memory_limit=256M`, `max_input_vars=5000`, ограничения uploads, логирование
- `runtime.override.yml`: добавляет bind mount ini в `/docker-entrypoint.d` нового webserver, как документировано moodlehq; **пока webserver не запускается**
- При запуске `--start-db`: Docker Compose создаёт отдельные `bms24_parity41-db-1`, `bms24_parity41_db` и `bms24_parity41_internal`.
- Порт `127.0.0.1:18081` **не открывается** до отдельного запуска webserver.
- Секреты новой БД читаются только из `parity41/.env`, без вывода в консоль.

В настройке нового PHP ограничение памяти контейнера остаётся 1024 MiB; **256M — лимит одного PHP-процесса, не контейнера**. Из-за 3.8 GiB RAM без swap нельзя допускать тяжёлого одновременного тестирования обоих контуров.

## Команда 1 — подготовить PHP overlay (без запуска)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1889-parity41-db-only-bootstrap

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

for item in \
  "stage1889_db_only.sh:bms24-stage1889.sh" \
  "runtime.override.yml:bms24-parity41-runtime.override.yml" \
  "php-parity.ini:bms24-parity41-php.ini"; do
  SOURCE=${item%%:*}
  DEST=${item#*:}
  runuser -u bmsdev -- git -C "$REPO" show \
    "refs/remotes/origin/$BRANCH:tools/deploy/parity41/$SOURCE" > "/tmp/$DEST"
done

bash -n /tmp/bms24-stage1889.sh
bash /tmp/bms24-stage1889.sh --prepare
```

Ожидается:
```text
STAGE1889_COMPOSE_CONFIG_OK
STAGE1889_PHP_OVERRIDE_READY
No Docker container created and no database touched.
```

## Команда 2 — запустить только новую MariaDB

Только после успеха команды 1:

```bash
bash /tmp/bms24-stage1889.sh --start-db
```

Это **реальное, но изолированное изменение нового тестового контура**: будет создан и запущен
только новый контейнер MariaDB, инициализирована чистая БД `paritymoodle`.
Не запускать `docker compose up` без указания сервиса: эта команда
создаст также webserver, что **пока не одобрено**.

Ожидается:
```text
STAGE1889_COMPOSE_CONFIG_OK
STAGE1889_DB_CONTAINER_STARTED (not yet an acceptance pass)
```

Не выдавать полный `docker compose config`, `docker inspect` с Environment
или `cat parity41/.env` — они могут показать тестовые секреты.

### Команда 3 — только просмотр статуса

```bash
bash /tmp/bms24-stage1889.sh --status
docker inspect bms24_parity41-db-1 \
  --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}'
```

Принятие следующего этапа зависит от `healthy`, а не только от `Started`.
Если контейнер unhealthy, **не трогать** старый staging, не выполнять `docker compose down` глобально.
Запросить безопасный анализ статуса без вывода секретов.

## Стоп-факторы до установки Moodle

- Новый `config.php` **не создан**; URL для второго HTTPS-стенда ещё не определён.
- Полнота резервной копии старого staging ещё не проверена восстановлением.
- Версия PHP Web в production и версия MariaDB production ещё не зафиксированы.
- Комплект совместимых плагинов Moodle 4.1.22 нужно установить отдельно; `mod/customcert` в старом staging отсутствовал.
- Не включать внешние интеграции, email, Bitrix24, реальные платежи и cron.
- Не запускать новый webserver и не открывать его наружу до утверждения HTTPS, ACL, `config.php` и источника тестовых данных.
- Moodle 4.1 больше не поддерживается; parity — промежуточный стенд перед планом обновления.

Документация настроек PHP-образа: https://github.com/moodlehq/moodle-php-apache/blob/main/README.md
