# Stage 1893 — подготовка HTTPS / ACME файлов без активации

## Исходное состояние (подтверждено)

Этап 1891 принят на основании вывода Timeweb: `config.php` расположен
только в `/srv/bms24-staging/parity41/moodle/config.php`, права
`root:www-data 0640`, URL `https://stage41.bms24.online`, PHP lint успешен.

Ветки этапов 1892–1893 на этом шаге **только подготавливают файлы**.
Вывод live-аудита этапа 1892 пока не получен. Поэтому мы не можем
считать подтверждёнными наличие DNS-записи, доступность Nginx,
Certbot, ACME-проверок или сертификата.

## Защитные принципы

- Никаких изменений в старом `test.bms24.online`, его
  `127.0.0.1:18080`, старых сертификатах или production `edu.kzn.mba`.
- Пока SSL не выдан, открывать для нового имени **только** ACME-challenge,
  а на прочие HTTP-запросы возвращать 404. Не проксировать Moodle по HTTP.
- После SSL — отдельный HTTPS server block с обязательным Basic Auth и
  только `proxy_pass http://127.0.0.1:18081`.
- `stage41` использует неподдерживаемую Moodle 4.1.22, поэтому доступ
  должен оставаться закрытым. Не публиковать без HTTPS + аутентификации.
- Никаких паролей в GitHub, консольном выводе или переписке.

## Команда 1: выполнить предварительный аудит 1892 (если ещё не выполнен)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1892-parity41-https-readiness

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1892_https_readiness.sh" \
  > /tmp/bms24-stage1892.sh

bash -n /tmp/bms24-stage1892.sh
bash /tmp/bms24-stage1892.sh
```

Ожидаем `STAGE1892_HTTPS_READINESS_AUDIT_COMPLETE`. Результаты
`DNS_IPV4_NOT_RESOLVED`, `NGINX_NOT_INSTALLED` или
`TLS_CERT_NOT_PRESENT` — **не** основание активировать публичный сайт.

## Команда 2: подготовить отдельные конфиги как ФАЙЛЫ (не применять)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1893-parity41-https-assets

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1893_prepare_https_assets.sh" \
  > /tmp/bms24-stage1893.sh

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/nginx-stage41-acme-only.conf.example" \
  > /tmp/bms24-stage41-acme-only.conf

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/nginx-stage41.conf.example" \
  > /tmp/bms24-stage41-https.conf

bash -n /tmp/bms24-stage1893.sh
bash /tmp/bms24-stage1893.sh --check
bash /tmp/bms24-stage1893.sh --prepare
```

Ожидаемый результат:

```text
STAGE1893_HTTPS_ASSETS_PREPARED
Location: /srv/bms24-staging/parity41/https-assets
State: templates staged only; Nginx NOT reconfigured; no DNS, TLS, or Docker changes.
```

На VDS появятся только файлы:

- `/srv/bms24-staging/parity41/https-assets/acme-only.conf.example`
- `/srv/bms24-staging/parity41/https-assets/https.conf.example`

**Важно:** `--prepare` намеренно не перемещает файлы в
`/etc/nginx/sites-available` и не делает reload. Код Moodle не запускается.

## После результатов аудита (отдельный этап 1894, НЕ выполнять сейчас)

1. Проверить публичный IP VDS в панели Timeweb и DNS A нового поддомена.
2. Проверить, что существующий Nginx использует `sites-enabled`; не
   предполагать это без просмотра фактического `nginx -T`.
3. Создать отдельный ACME webroot и **временно** активировать только
   HTTP 80 ACME-only конфиг; сначала `nginx -t`, затем `systemctl reload nginx`.
4. Проверить прохождение ACME-челленджа и получить Let’s Encrypt сертификат
   **только для** `stage41.bms24.online`. Не заменять существующие сертификаты.
5. В интерактивном режиме создать уникальный Basic Auth пароль в
   `/etc/nginx/private/bms24-stage41.htpasswd`, 0600/0640 с
   доступом для Nginx. Не записывать пароль в shell history.
6. Только при наличии cert/key, Basic Auth и успешном `nginx -t`
   заменить ACME-only конфиг на отдельный HTTPS-конфиг и выполнить reload.
7. Протестировать 401 без Basic Auth, TLS-сертификат и правильный Host/SNI.
   Затем подготовить отдельно запуск `parity41` webserver и установку Moodle.

Не использовать `certbot --nginx` без изучения существующих виртуальных
хостов — инструмент может изменять файлы Nginx. Вместо этого предпочтителен
контролируемый `certbot certonly --webroot` после проверки webroot.

Ветки 1892/1893 допускают подготовку **до** публикации DNS, но ни один
из скриптов не производит действий с Nginx, DNS, сертификатами или Docker.
