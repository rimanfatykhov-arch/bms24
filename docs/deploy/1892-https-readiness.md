# BMS24 Stage 1892 — DNS / HTTPS / Nginx preflight

Дата: 03.11.2026. Цель: подготовить безопасный доступ к **отдельному**
Moodle 4.1.22 на `stage41.bms24.online`, не затрагивая старый тестовый
`test.bms24.online` и production `edu.kzn.mba`.

## Приёмка 1891 (по фактическому скриншоту)

```text
STAGE1891_CONFIG_ABSENT (expected before --create)
STAGE1891_ISOLATION_BASELINE_OK
STAGE1891_PARITY41_CONFIG_CREATED
Location: /srv/bms24-staging/parity41/moodle/config.php
Permissions: root:www-data 0640
WWWROOT_PROPOSED: https://stage41.bms24.online
TEST_DATABASE: db / paritymoodle / parityuser
No syntax errors detected in /tmp/config.php
```

Никакие сертификаты, DNS и виртуальные хосты для нового имени ещё не
настроены. **Важно:** `config.php` с секретом БД никогда не показывать в
терминале, чатах, логах или GitHub.

## Шаг 1: read-only preflight на Timeweb

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

Скрипт выводит **только**:
- разрешён ли A-record `stage41.bms24.online` и текущие адреса интерфейсов;
- установлен/активен ли Nginx, проходит ли `nginx -t`;
- установлен ли Certbot, присутствует ли сертификат нового имени;
- имена Nginx site-конфигов, ссылки на целевой домен только по **путям файлов**, без их содержимого;
- занятые порты 80/443/18080/18081;
- здоровье отдельной MariaDB и имена parity-контейнеров.

Ожидаемый финальный маркер:
`STAGE1892_HTTPS_READINESS_AUDIT_COMPLETE`.

Отсутствие DNS или TLS на этом этапе — не ошибка скрипта, а
**неудовлетворённая предпосылка** следующего этапа.

## План публикации (позже, после аудита)

1. Создать **новую** A-запись `stage41.bms24.online` с IP этого VDS в
   DNS-панели домена. Не менять запись `test.bms24.online`.
2. Подтвердить извне A record, затем проверить, что Nginx обслуживает 80/443
   и текущие сайты остаются рабочими.
3. Сформировать отдельный виртуальный хост, отдельный каталог ACME-челленджей,
   сертификат Let's Encrypt и файл Basic Auth на самом VDS. Пароль Basic Auth
   не должен попадать в репозиторий или вывод команд. Для Moodle 4.1.22,
   уже снятой с поддержки, ограничение доступа обязательно.
4. Применить только Nginx конфигурацию `stage41` после
   `nginx -t`; предпочтительнее `reload`, а не `restart`, чтобы не
   разрывать действующие соединения.
5. Шаблон **для просмотра, НЕ для немедленной активации**:
   `tools/deploy/parity41/nginx-stage41.conf.example`. Он предполагает,
   что уже выпущены TLS cert/key, создан отдельный htpasswd, а веб-контейнер
   позже доступен **только** на `127.0.0.1:18081`.
6. Перед запуском Moodle проверить резервную копию и возможность восстановления
   текущего staging, а также реальные ограничения PHP и контейнера. Новый
   `config.php` использует `sslproxy=true`, поэтому Nginx обязан передавать
   корректный `X-Forwarded-Proto=https` и `Host`.
7. Включить только новый `parity41` веб-контейнер после готовности HTTPS.
   Почта, CRM, платежи, cron и внешние вебхуки остаются выключенными.

**Не делать**: не копировать `config.php` из production, не выполнять
`docker compose down -v`, не запускать `docker compose up` без выбранных
сервисов, не применять шаблон Nginx до готового сертификата/htpasswd.

Ссылки для сверки:
- https://docs.moodle.org/401/en/HTTP
- https://github.com/moodlehq/moodle-php-apache#initialisation-scripts

## Следующий gate

Прислать результат read-only аудита. Если `DNS_IPV4_NOT_RESOLVED`,
сначала создать отдельную DNS A-запись. Не переходить к открытию внешнего
сайта до готовой TLS-защиты и Basic Auth.
