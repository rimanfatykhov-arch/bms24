# BMS24 — этап 1897 без дополнительного пароля

Изменение архитектуры от 03.11.2026: **Basic Auth отменена по решению
владельца BMS24**. Для конечного пользователя будет только вход Moodle.
Задачи безопасности выполняются за счёт HTTPS, минимального экспонирования,
настроек самого Moodle и, при необходимости, ограничений по IP/VPN.

Этот файл заменяет устаревший план «HTTPS + Basic Auth». Не выполняйте
старую `/tmp/bms24-stage1897.sh` и не создавайте `htpasswd`.

## Фактическое состояние

Из последних скриншотов на Timeweb:
- `STAGE1897_CERT_VERIFIED` — сертификат нового домена есть и проверен;
- `STAGE1897_ACME_BEFORE_SWITCH_OK` — HTTP-01 доступен локально;
- попытка `--make-auth` завершилась `htpasswd unavailable`;
- попытка `--activate` завершилась
  `Basic Auth file missing or has incorrect permissions`;
- поэтому **старый HTTPS-сайт с Basic Auth не был активирован**.

## Почему сначала техническая страница

Moodle 4.1.22 не поддерживается производителем. Чтобы не публиковать
непроверенный Moodle сразу после запуска контейнера, сначала включается
**HTTPS maintenance (503)** без второго пароля и без проксирования Moodle.
После проверки установки и безопасности в следующем этапе заменим
maintenance на proxy `127.0.0.1:18081` — логин будет только Moodle.

## Запуск — новый скрипт, старая версия запрещена

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=fix/1897-remove-basic-auth
BASE=/srv/bms24-staging/parity41

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1897_https_no_extra_password.sh" \
  > /tmp/bms24-stage1897-noauth.sh

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/nginx-stage41-no-basic-auth-maintenance.conf.example" \
  > /tmp/bms24-stage41-maintenance.conf

bash -n /tmp/bms24-stage1897-noauth.sh
install -m 0644 /tmp/bms24-stage41-maintenance.conf \
  "$BASE/https-assets/https-maintenance-no-auth.conf.example"

bash /tmp/bms24-stage1897-noauth.sh --status
bash /tmp/bms24-stage1897-noauth.sh --check
```

Ожидается:
```text
STAGE1897_TLS_CERT_OK
STAGE1897_ACME_RENEWAL_ROUTE_OK
STAGE1897_NO_BASIC_AUTH_PREFLIGHT_OK
```

Если проверка успешна:
```bash
bash /tmp/bms24-stage1897-noauth.sh --activate
```

Успешная активация:
```text
STAGE1897_NGINX_BACKUP_OK ...
STAGE1897_HTTPS_CERT_AND_MAINTENANCE_OK
STAGE1897_HTTP01_RENEWAL_PATH_OK
STAGE1897_NO_EXTRA_PASSWORD_ACTIVE
```

Внешние проверки:
- `https://stage41.bms24.online/` — HTTPS 503 с
  `BMS24_PARITY41_HTTPS_MAINTENANCE`, **не** запрос пароля;
- `http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe`
  — HTTP 200, `BMS24_STAGE1895_HTTP01_OK`;
- действующий `https://test.bms24.online` продолжает работать.

Ничего не менять в `test.bms24.online` и `edu.kzn.mba`.

## Следующий этап 1898

1. Подтвердить HTTPS и работу HTTP-01 для продления.
2. Проверить Docker Compose, локальное подключение MariaDB, config.php,
   настройки `sslproxy`, PHP и совместимость плагинов.
3. Установить Moodle только на parity41 и протестировать вход, сессии и ACL.
4. Заменить maintenance на защищённый HTTPS proxy к
   `127.0.0.1:18081` **без Basic Auth** после приёмки.
5. Не подключать боевые Bitrix24/платежи/email/вебхуки.
6. Для устаревшей Moodle 4.1.22 предпочтителен дополнительный
   сетевой контроль доступа (VPN/allowlist) на период тестирования.
   Это не второй пароль приложения.

Скрипт сохраняет резервную копию Nginx, проверяет TLS,
откатывает новые конфиги при ошибке и не запускает Moodle.
