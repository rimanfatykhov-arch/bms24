# Этап 1891 — отдельный config.php для Moodle 4.1.22

Дата 03.11.2026. Стенд `/srv/bms24-staging/parity41`.

## Приёмка этапа 1890

По выведенному пользователем скриншоту:

```text
STAGE1890_DB_STATUS running=true health=healthy
STAGE1890_DB_ISOLATION_OK
STAGE1890_DB_CONNECT_OK database=paritymoodle tables=0
PHP 8.1.34 memory_limit=256M max_input_vars=5000
STAGE1890_PHP_CONFIG_OK
STAGE1890_PARITY41_DB_ACCEPTANCE_OK
```

Этап 1890 завершён. Тестовая MariaDB запущена, но таблицы Moodle
ещё не установлены. Не запускать `--start-db` снова, не удалять volume.

## Проект настроек Moodle (только новое окружение)

| Свойство | Значение |
|---|---|
| $CFG->wwwroot | **предложение** `https://stage41.bms24.online`, DNS и HTTPS ещё не созданы |
| dbtype / dblibrary | `mariadb` / `native` |
| dbhost / dbname / dbuser | `db` / `paritymoodle` / `parityuser` |
| dbpass | взять только из `parity41/.env` (никогда не выводить) |
| dboptions dbcollation | `utf8mb4_unicode_ci` |
| dirroot | `/var/www/html` |
| dataroot | `/var/www/moodledata` |
| sslproxy | true (внешний HTTPS завершится на Nginx) |
| noemailever | true — случайные письма с тестового Moodle запрещены |
| config.php | `parity41/moodle/config.php`, `root:www-data 0640` |

Используем рекомендованный для Moodle 4.1 драйвер MariaDB. Отдельная
установка через HTTPS reverse proxy потребует поддержки
`X-Forwarded-Proto: https` и сохранения заголовка `Host` на Nginx.
Именно этот reverse proxy будет готовиться позднее; сейчас ни DNS,
ни Nginx, ни сертификаты **не изменяются**.

Официальные документы:
- https://docs.moodle.org/401/en/MariaDB
- https://docs.moodle.org/401/en/HTTPS
- https://docs.moodle.org/401/en/Configuration_file

## Timeweb — подготовка и проверка (только parity41)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1891-parity41-config-prep

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1891_prepare_config.py" \
  > /tmp/bms24-stage1891-config.py

python3 -m py_compile /tmp/bms24-stage1891-config.py
python3 /tmp/bms24-stage1891-config.py --check
```

Ожидается `STAGE1891_CONFIG_ABSENT` и
`STAGE1891_ISOLATION_BASELINE_OK`.

### Создать config.php

Только если имя `stage41.bms24.online` будет выделено новому стенду
или заранее выбран другой **уникальный** HTTPS-поддомен домена
`bms24.online`. Это лишь локальная запись URL, а не публикация DNS.

```bash
python3 /tmp/bms24-stage1891-config.py \
  --create --wwwroot https://stage41.bms24.online
```

Ожидается:

```text
STAGE1891_PARITY41_CONFIG_CREATED
Permissions: root:www-data 0640
TEST_DATABASE: db / paritymoodle / parityuser
Credentials hidden. No Moodle webserver, cron or migration started.
```

Не выводить `cat .../config.php` или пароль DB в чат, терминал
или GitHub. Скрипт откажется перезаписывать существующий config.php.

### Синтаксис PHP (не исполнять конфигурацию)

```bash
docker run --rm --network none --read-only \
  --memory 384m --cpus 0.4 --pids-limit 64 \
  --security-opt no-new-privileges \
  -v /srv/bms24-staging/parity41/moodle/config.php:/tmp/config.php:ro \
  --entrypoint php moodlehq/moodle-php-apache:8.1-bookworm \
  -l /tmp/config.php
```

Ожидается `No syntax errors detected in /tmp/config.php`.
Это **только** проверка синтаксиса; Docker Compose/Moodle не запускаются.

## Gate для следующего этапа (1892)

1. Подтвердить реальное имя `stage41.bms24.online` и настроить только
   новую DNS A-запись на VDS. Проверить `getent ahostsv4`.
2. Запланировать **отдельный** Nginx server block с Let's Encrypt
   и ограниченным доступом (basic auth или allowlist IP), не менять
   `test.bms24.online`.
3. Перед публикацией проверить внешний HTTPS, SNI и сертификат.
4. Подтвердить сохранность и возможность восстановления существующего staging.
5. Проверить доступность webserver к db внутри закрытой сети;
   только затем устанавливать Moodle с отдельной пустой БД.
6. Плагины BMS24 и Custom Certificate добавлять после чистой установки
   и проверки совместимости. Не подключать production CRM/платежи/email.

**Внимание:** Moodle 4.1.22 не поддерживается производителем, использовать
только как временную parity-среду и обязательно ограничить доступ.
