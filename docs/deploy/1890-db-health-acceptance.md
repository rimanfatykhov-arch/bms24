# BMS24 Stage 1890 — приёмка отдельной MariaDB (только чтение)

## Фактический результат 1889 — Timeweb, 03.11.2026

По снимку консоли:

- `STAGE1889_COMPOSE_CONFIG_OK`
- `STAGE1889_PHP_OVERRIDE_READY` с `memory_limit=256M`, `max_input_vars=5000`
- Созданы Docker network `bms24_parity41_internal` и named volume `bms24_parity41_db`
- Запущен **только** контейнер `bms24_parity41-db-1` (MariaDB 10.11)
- Статус спустя 8 секунд — `health: starting`. Это пока **не приёмка**.
- Новый webserver и Moodle не запускались; существующий staging и production не изменялись.

## Сначала простая проверка health

В консоли Timeweb:

```bash
docker inspect bms24_parity41-db-1 \
  --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}'
```

Ожидается `healthy`. Если ответ `starting`, повторить проверку позднее.
Если `unhealthy`, **не** удалять контейнер/том, не выполнять `down -v` и не
переинициализировать БД; безопасно проверить status/health logs отдельно.

## Скрипт приёмки (после healthy)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1890-parity41-db-acceptance

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1890_db_acceptance.sh" \
  > /tmp/bms24-stage1890.sh

bash -n /tmp/bms24-stage1890.sh
bash /tmp/bms24-stage1890.sh
```

Скрипт проверяет: health `healthy`, private network `bms24_parity41_internal`,
volume `bms24_parity41_db`, отсутствие опубликованного DB порта,
тестовые SQL доступ и **пустоту** `paritymoodle` (0 таблиц), PHP 8.1,
`memory_limit=256M`, `max_input_vars=5000`.

Он не открывает `.env` в консоли, использует переменные окружения уже
запущенного DB контейнера, не выводит секреты, SQL только `SELECT`.
PHP-контроль выполняется временным контейнером с `--network none`, без
постоянного хранения и без установки Moodle.

Успешные маркеры:

```text
STAGE1890_DB_STATUS running=true health=healthy
STAGE1890_DB_ISOLATION_OK
STAGE1890_DB_CONNECT_OK database=paritymoodle tables=0
STAGE1890_PHP_CONFIG_OK
STAGE1890_PARITY41_DB_ACCEPTANCE_OK
```

## До следующего этапа 1891

- Подготовить полностью отдельный `config.php` и secret delivery.
- Определить собственный HTTPS FQDN для parity41; никогда не использовать
  текущий `test.bms24.online` с `wwwroot` parity.
- Добавить в webserver только пароль **тестового пользователя** БД, а не root.
- Не запускать Moodle через http/непроверенный HTTPS и не подключать production
  данные, CRM webhook, email или платежи.
- Проверить резервную копию старого staging восстановлением.
- Moodle 4.1.22 — временный неподдерживаемый parity-контур.

Этот PR не запускает постоянные контейнеры и не изменяет `edu.kzn.mba`.
