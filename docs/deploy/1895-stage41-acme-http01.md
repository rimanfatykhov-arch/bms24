# BMS24 — этап 1895: включить только ACME HTTP-01 для stage41

## Подтверждено по консоли Timeweb (03.11.2026)

- `stage41.bms24.online` возвращает **186.246.110.254** через Cloudflare DNS
  `1.1.1.1` и Google DNS `8.8.8.8`
- `DNS_A_MATCHES_EXPECTED_IP` на обоих резолверах, `AAAA` отсутствует
- `STAGE1894_PUBLIC_DNS_GATE_OK`
- Nginx `active`, синтаксис корректен, старый сайт `bms24-test` присутствует
- Порты 80/443 используются Nginx, старый webserver живёт на 18080
- Порт **18081** свободен, новый Moodle webserver пока не запущен
- Новый SSL для stage41 не выпущен
- Файлы ACME/HTTPS подготовлены в
  `/srv/bms24-staging/parity41/https-assets` на этапе 1893

**Не путать:** успешная DNS-проверка не доказывает доступность внешнего
HTTP-01, поэтому сначала запускаем только ACME-vhost и публикуем тестовый
текстовый файл без запуска Moodle.

## Ожидаемый результат этого этапа

Включить **только HTTP на порту 80** для `stage41.bms24.online`:
`/.well-known/acme-challenge/` читает статические файлы из
`/var/www/letsencrypt`, а все остальные пути возвращают HTTP 404.

Скрипт выполняет повторный публичный DNS-gate, проверяет include
`/etc/nginx/sites-enabled/*`, наличие исходного `bms24-test`,
отсутствие конфликтующего server_name, сохраняет root-only backup
`/srv/bms24-staging/backups/nginx-pre-stage1895-*.tar.gz`.
Перед reload проверяет `nginx -t`. При ошибке после создания файлов
удаляет **только новые** файлы `bms24-stage41-acme` и пытается вернуть
старую активную конфигурацию.

### Команда 1 — загрузить скрипт и только проверить (без изменений)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1895-stage41-acme-http01
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1895_acme_activate.sh" \
  > /tmp/bms24-stage1895-acme.sh
bash -n /tmp/bms24-stage1895-acme.sh
bash /tmp/bms24-stage1895-acme.sh --check 186.246.110.254
```

Ожидается `STAGE1895_ACME_ONLY_PREFLIGHT_OK`. Если вывод содержит
`STOP` — не обходить проверку: проверить существующий Nginx.

### Команда 2 — активировать ACME-only (пока БЕЗ сертификата)

Только если пройден `--check`:

```bash
bash /tmp/bms24-stage1895-acme.sh --activate 186.246.110.254
```

Ожидаемые маркеры:

```text
STAGE1895_NGINX_BACKUP_OK ...
STAGE1895_ACME_ONLY_NGINX_ACTIVE
STAGE1895_LOCAL_HTTP01_PROBE_OK
```

После успешной активации:

- `http://stage41.bms24.online/` — **404**;
- `http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe`
  — текст `BMS24_STAGE1895_HTTP01_OK`;
- старый HTTPS `test.bms24.online` не должен измениться;
- новый `https://stage41.bms24.online` пока **НЕ настроен**;
- новый Moodle, пароли, БД и cron остаются без изменений.

**Внимание:** локальный curl-проверочный запрос на 127.0.0.1 **не
доказывает**, что Let's Encrypt видит этот URL извне. Проверить ссылку
из внешней сети/браузера до запуска Certbot. Если снаружи 404/timeout,
проверить настройки firewall/доступ порта 80, не трогать Moodle.

## Следующий этап 1896 — сертификат + Basic Auth, ПОСЛЕ внешней проверки

- Только после успешного HTTP-01 внешнего запроса выполнить
  `certbot certonly --webroot -w /var/www/letsencrypt -d stage41.bms24.online`
  с явно заданными контактными данными владельца сертификата.
  Этот режим **не редактирует Nginx автоматически**.
- Создать отдельный серверный htpasswd для `stage41` (не коммитить пароль).
- Проверить TLS hostname/expiry и `nginx -t`, затем включить
  отдельный HTTPS-сайт с обязательной Basic Auth.
- Проверить, что `test.bms24.online` по-прежнему доступен.
- Только затем планировать новый webserver и установку Moodle с DB.
- Неподдерживаемую Moodle 4.1.22 держать под ограничением доступа.

Официальная документация Webroot:
https://eff-certbot.readthedocs.io/en/stable/using.html#webroot

Этап 1895 **не запускает Certbot**, не включает HTTPS, не публикует
Moodle, не обновляет production.
