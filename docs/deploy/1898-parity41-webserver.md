# BMS24 Stage 1898 — закрытый запуск webserver Moodle 4.1.22

Дата подготовки: 09.11.2026. **Подготовлено в GitHub, не выполнено на Timeweb.**

Аудит: фактический `local/kbacompany/version.php` в ветке содержит build `2026101645` (release `1.1.0-rc29`); `docs/00_MASTER_CONTEXT.md` описывает более ранний снимок `2026101642`. На этапе 1898 версию плагина не меняем.

## Необходимые фактические подтверждения

1. Stage 1896: `STAGE1896_CERT_ISSUED_AND_VALIDATED`.
2. Stage 1897: `STAGE1897_HTTPS_BASIC_AUTH_ACTIVATED` и внешний HTTPS без логина отвечает 401.
3. Stage 1889: отдельная MariaDB `bms24_parity41-db-1` действительно healthy.
4. В `/srv/bms24-staging/parity41/moodle` уже установлен Moodle Core 4.1.22 с отдельным `config.php`; `.env` имеет 0600.
5. Образ `moodlehq/moodle-php-apache:8.1-bookworm` загружен локально.
6. Перед запуском убедиться в корректности DB host/password, HTTPS reverse proxy, отсутствии боевых SMTP, платежей, Bitrix/webhook, фоновых задач. Проверять значения **локально**, не отправляя секреты.

## Загрузка скрипта из ветки PR

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1898-parity41-webserver-gated

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1898_webserver.sh" \
  > /tmp/bms24-stage1898.sh
bash -n /tmp/bms24-stage1898.sh
bash /tmp/bms24-stage1898.sh --status
bash /tmp/bms24-stage1898.sh --check
```

Ожидаются `STAGE1898_RENDERED_COMPOSE_ISOLATED`, `STAGE1898_TLS_AUTH_ACME_GATE_OK`, `STAGE1898_PARITY_DB_HEALTHY`, `STAGE1898_WEB_PREFLIGHT_OK`. При любом STOP запуск запрещён.

## Запуск: только после принятия 1896–1897 и безопасной конфигурации

```bash
bash /tmp/bms24-stage1898.sh --start
```

Ожидается `STAGE1898_HTTPS_STILL_401` и `STAGE1898_ISOLATED_WEB_STARTED`. После запуска отдельно проверить `docker compose ... ps`, последние логи, локальную страницу и удалённый 401. Не вводить Moodle admin password до оценки логов и конфигурации.

## Границы и риски

- Скрипт не выполняет Moodle installer, DB migrations или установку плагинов, не включает cron.
- Не редактирует Nginx, сертификат, существующий test.bms24.online или edu.kzn.mba.
- Контейнер запускается с localhost port 18081, за уже работающими HTTPS + Basic Auth.
- `--check` не проверяет реальное подключение PHP к MariaDB и отсутствие внешней отправки писем; требуется отдельная ручная ревизия.
- При ошибке после начала `--start` скрипт пытается остановить **только** новый parity41 webserver; не удаляет контейнер и не трогает DB/volume. Если даже остановка не удалась — требуется осмотр оператором. Логи и результат Compose сохраняются для диагностики.
- Moodle 4.1.22 устарела. Никакого открытого доступа и реальных персональных/платёжных данных.
- Следующий этап 1899: PHP/MariaDB integration check и установка Moodle в изолированной базе с контролируемой миграцией.

## Аудит безопасности 09.11.2026

- Проверяется **итоговая** конфигурация `docker compose config --format json`: ровно два сервиса, изолированная внутренняя сеть, DB без опубликованных портов, web только localhost 18081, ожидаемые образы и три монтирования. Любое неожиданное поле из проверяемых инвариантов — STOP.
- Требуется Python 3 на хосте. `--check` не загружает образы и не создаёт контейнеров/томов. `--status` также не меняет Compose, но требует подготовленные локальные файлы.
- Запрет на запуск, если в каталоге `parity41` есть файл `DO_NOT_START`.
- `--start` не заменяет ручную проверку PHP config.php, отключения SMTP, платежей, Bitrix24, cron или наличия чужих данных. **Перед запуском обязательно провести ручной безопасный аудит.**
- Не интерпретировать HTTP ответ localhost как завершённую установку Moodle; подтверждается только то, что веб-контейнер отвечает и HTTPS без авторизации остаётся 401.
- Тесты на Timeweb, реальное MariaDB соединение, внешний HTTPS и автоматическое продление сертификата на момент публикации **не выполнялись**.
- Следующий этап 1899 следует проектировать как отдельный **read-only** аудит PHP/MariaDB, без автоматической установки/миграции в этом PR.
