# BMS24 — этап 1896: выпуск SSL только для stage41.bms24.online

Дата: 03.11.2026.

## Принято по внешней проверке

Пользователь открыл из браузера публичную ссылку
`http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe`
и получил `BMS24_STAGE1895_HTTP01_OK`.

Это доказывает доступность статического HTTP-01 файла на новом имени.
Прежний этап 1895 при первом запуске откатил конфигурацию после 404;
после исправления `fix/1895-acme-probe-retry-diagnostics` файл стал
доступен снаружи. Новый Moodle webserver ещё не запущен.

## Шаг 1 — загрузка и только диагностика

```bash
set -e

REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1896-stage41-certbot-webroot

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1896_certonly_webroot.sh" \
  > /tmp/bms24-stage1896-cert.sh

bash -n /tmp/bms24-stage1896-cert.sh
bash /tmp/bms24-stage1896-cert.sh --check
```

Ожидаемый результат:

```text
STAGE1896_CERTBOT_WEBROOT_PREFLIGHT_OK
Read-only check complete. No certificate requested.
```

Если вывод содержит `STOP`, **не** обходить проверку и не вызывать
Certbot напрямую; сначала разберём причину. Новый `stage41` site
должен оставаться ACME-only, старый Nginx site `bms24-test` должен
присутствовать, оба публичных DNS должны показывать `186.246.110.254`.
Внешний результат уже подтвердил пользователь.

## Шаг 2 — запрос сертификата (только после --check)

```bash
bash /tmp/bms24-stage1896-cert.sh --issue
```

В интерактивном режиме Certbot может спросить email администратора
сертификата и согласие с условиями Let's Encrypt. Пользователь вводит
контакт на своём сервере; email не добавляется в GitHub.

Скрипт вызывает только:

```bash
certbot certonly --webroot \
  --webroot-path /var/www/letsencrypt \
  --domain stage41.bms24.online \
  --cert-name stage41.bms24.online --no-eff-email
```

**Не** использовать `certbot --nginx`: инсталлятор может переписать
существующие виртуальные хосты. Webroot — authenticator only, без
автоматической модификации Nginx.

После выпуска скрипт проверяет сертификат (SAN с новым доменом,
срок действия более 30 дней) и наличие ключа.

Ожидаем `STAGE1896_CERT_ISSUED_AND_VALIDATED`.

## Важные ограничения

- Этот этап не включает HTTPS-server block. Сертификат **выпущен** не
  означает, что сайт уже открыт по HTTPS.
- Веб-сервер Moodle 4.1.22 остаётся выключен, его нельзя открывать до
  отдельного HTTPS + Basic Auth.
- Секреты Certbot account и ключи `/etc/letsencrypt` остаются только
  на VDS. Не копировать их в репозиторий.
- Не редактировать действующий `/etc/nginx/sites-enabled/bms24-test`.
- Строго сохранить HTTP ACME path на 80 и при дальнейшем переключении
  на HTTPS; это требуется для **автопродления** сертификата.
- После подключения конечного Nginx виртуального хоста отдельно
  проверить `certbot renew --dry-run`, актуальные сертификаты и
  корректную загрузку Nginx после продления.
- Пароль Basic Auth создавать в интерактивном режиме, не передавать
  его в командной строке; файл только на VDS.
- Публикацию неподдерживаемой Moodle 4.1.22 ограничить Basic Auth
  и желательно allowlist/VPN.

## Следующий этап 1897

После подтверждённого выпуска SSL:

1. создать отдельный `/etc/nginx/private/bms24-stage41.htpasswd`;
2. заменить временный ACME-only сайт на полноценный отдельный 80+443
   Nginx vhost (только один `server_name` на 80);
3. выполнить `nginx -t` и `systemctl reload nginx` с rollback;
4. проверить 401 Unauthorized на HTTPS без Basic Auth, корректный
   TLS и сохранение ACME HTTP-01 для продлений;
5. только после HTTPS+Auth запускать parity41 webserver и установку Core.

Официальная документация Certbot:
https://github.com/certbot/certbot/blob/main/certbot/docs/using.rst

Официальная документация Nginx Basic Auth:
https://docs.nginx.com/nginx/admin-guide/security-controls/configuring-http-basic-authentication/
