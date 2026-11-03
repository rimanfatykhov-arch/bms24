# BMS24 Stage 1897 — HTTPS + Basic Auth для закрытого parity41

Дата подготовки: 03.11.2026.

## Состояние, подтверждённое пользователем

На этапе 1895 из внешнего браузера по HTTP-01 получен
`BMS24_STAGE1895_HTTP01_OK`. Это **не** подтверждает выпуск сертификата
1896. Фактического `STAGE1896_CERT_ISSUED_AND_VALIDATED` пока нет.

Данный этап **подготовлен, но не принят**. Не запускать старый
`test.bms24.online` или новый Moodle до SSL+Basic Auth.

## Файлы и расположение

- Исходный HTTPS-шаблон:
  `/srv/bms24-staging/parity41/https-assets/https.conf.example`
- Активный ACME-only сайт:
  `/etc/nginx/sites-enabled/bms24-stage41-acme`
- Будущий HTTPS сайт:
  `/etc/nginx/sites-enabled/bms24-stage41`
- Пароль Basic Auth (hash, не исходный пароль):
  `/etc/nginx/private/bms24-stage41.htpasswd`
- Сертификат:
  `/etc/letsencrypt/live/stage41.bms24.online/fullchain.pem`
- Приватный ключ:
  `/etc/letsencrypt/live/stage41.bms24.online/privkey.pem`
- Проксируемый порт будущего Moodle: `127.0.0.1:18081`

## Шаг 1 — сначала завершить этап 1896, если SSL ещё не получен

Для первого выпуска сертификата нужен уже проверенный ACME-only vhost.
Использовать подготовленный ранее скрипт:

```bash
bash /tmp/bms24-stage1896-cert.sh --check
bash /tmp/bms24-stage1896-cert.sh --issue
```

Выполнять `--issue` **только** после успешного `--check`.
Если предыдущий временный файл пропал (например, перезапущен VDS),
сначала вновь загрузить его из ветки `ops/1896-stage41-certbot-webroot`
согласно `docs/deploy/1896-stage41-certbot-webroot.md`.
При уже выпущенном сертификате `--issue` не повторять.

Ожидаемый результат: `STAGE1896_CERT_ISSUED_AND_VALIDATED`.

## Шаг 2 — загрузить скрипт этапа 1897

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1897-stage41-https-basic-auth

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1897_https_basic_auth.sh" \
  > /tmp/bms24-stage1897.sh

bash -n /tmp/bms24-stage1897.sh
bash /tmp/bms24-stage1897.sh --status
bash /tmp/bms24-stage1897.sh --preflight
```

Ожидаются `STAGE1897_CERT_VERIFIED`,
`STAGE1897_ACME_BEFORE_SWITCH_OK` и
`STAGE1897_BASIC_AUTH_PENDING` (до создания пароля).

Если `STAGE1897_CERT_GATE_PENDING` — вернуться к этапу 1896,
не пытаться обходить его.

## Шаг 3 — создать уникальный пароль только на сервере

Требуется утилита `htpasswd` (Ubuntu `apache2-utils`).
Скрипт не устанавливает системные пакеты автоматически.
Проверить `command -v htpasswd`; при отсутствии
отдельно согласовать установку `apache2-utils`.

```bash
bash /tmp/bms24-stage1897.sh --make-auth
```

Скрипт интерактивно запрашивает пароль для пользователя
`stage41reviewer` два раза, пароль не показывается.
Пароль не нужно отправлять в ChatGPT, GitHub или скриншотах.
Сохраняется только bcrypt-хэш в файле `root:www-data 0640`,
каталог `/etc/nginx/private` — `root:www-data 0750`.

Ожидается `STAGE1897_BASIC_AUTH_CREATED`.

## Шаг 4 — повторный preflight, затем активация

```bash
bash /tmp/bms24-stage1897.sh --preflight
```

Только при `STAGE1897_PREFLIGHT_OK`:

```bash
bash /tmp/bms24-stage1897.sh --activate
```

Скрипт:

- создаёт root-only backup Nginx;
- создаёт `bms24-stage41`, выключает **только**
  `bms24-stage41-acme` symlink и включает новый symlink;
- проверяет `nginx -t` и выполняет reload (не restart);
- проверяет **без отключения TLS verify**, что локальный HTTPS
  возвращает 401 до ввода пароля;
- проверяет, что HTTP-01 probe остаётся доступен по HTTP/80;
- при ошибке удаляет новый HTTPS-файл и симлинк и возвращает
  старый ACME-only симлинк.

Ожидается:

```text
STAGE1897_NGINX_BACKUP_OK ...
STAGE1897_HTTPS_401_UNAUTHENTICATED_OK
STAGE1897_HTTP01_RENEWAL_PATH_OK
STAGE1897_HTTPS_BASIC_AUTH_ACTIVATED
```

Вебсервер Moodle **ещё не запущен**; после успешного Basic Auth
и до запуска Moodle аутентифицированный пользователь может получить 502.
Это не ошибка SSL.

## Приёмка извне (после активации)

```bash
curl -I https://stage41.bms24.online/
```

Ожидается `HTTP/2 401` либо `HTTP/1.1 401`, без ошибок
сертификата. Браузер предложит Basic Auth.

```bash
curl -i http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe
```

Ожидается HTTP 200 и `BMS24_STAGE1895_HTTP01_OK`.

При наличии выпуска сертификата и настроенного Nginx проверить
`certbot renew --dry-run` отдельно, убедиться, что продление
не меняет другие виртуальные хосты и Nginx подхватывает
обновлённый сертификат при reload. Это отдельный gate.

### Следующий этап 1898

Проверить условия запуска только `parity41 webserver` на localhost 18081,
проверить SSL proxy settings/DB endpoint, восстановление тестового
контента и безопасность неизвестных плагинов перед установкой Moodle 4.1.22.

**Защита:** Moodle 4.1.22 снята с поддержки. Не давать публичный
доступ без Basic Auth / VPN, не подключать production-платежи,
CRM/webhook, SMTP и cron.
