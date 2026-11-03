# Этап 1894 — подтверждение публичного DNS до ACME

Дата: 03.11.2026.

## Состояние по фактическому выводу этапов 1892 и 1893

- `NGINX_SYNTAX_OK`, `NGINX_SERVICE active`
- `certbot 2.9.0`
- действующий Nginx site `/etc/nginx/sites-enabled/bms24-test`
- порты 80, 443 слушают на 0.0.0.0
- `127.0.0.1:18080` обслуживает старый staging
- порт 18081 свободен; нового Moodle webserver нет
- parity41 MariaDB работает, health=healthy
- нового сайта Nginx и сертификата `stage41.bms24.online` пока нет
- `STAGE1892_HTTPS_READINESS_AUDIT_COMPLETE`
- `STAGE1893_HTTPS_ASSETS_PREPARED`

**Видимая часть скриншота НЕ содержит результат DNS-записи**. Ни факт
наличия публичной A-записи, ни правильный публичный IPv4 пока не подтверждены.

## Команда read-only проверки DNS на Timeweb

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1894-stage41-dns-gate

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1894_dns_gate.sh" \
  > /tmp/bms24-stage1894-dns.sh

bash -n /tmp/bms24-stage1894-dns.sh
bash /tmp/bms24-stage1894-dns.sh --expected-ip PUBLIC_VDS_IPV4
```

`PUBLIC_VDS_IPV4` заменить на публичный IPv4, **проверенный в панели
Timeweb конкретного сервера**. На более раннем скриншоте WinSCP встречался
`186.246.110.254`, но перед использованием нужно подтвердить, что этот
IP по-прежнему назначен нужному VDS.

Проверка выполняет DNS A и AAAA на рекурсивных резолверах
`1.1.1.1` и `8.8.8.8`, сравнивает A с указанным IPv4, проверяет
текущий Nginx и конфигурацию `wwwroot` без показа секретов.

Успех:
```text
STAGE1894_PUBLIC_DNS_GATE_OK
STAGE1894_READ_ONLY_DNS_AUDIT_COMPLETE
```

Если `STAGE1894_DNS_GATE_PENDING`: DNS ещё не подтвердился на обоих
резолверах, или присутствует AAAA, требующий отдельной проверки.
Если `DIG_MISSING`: DNS средствами этого скрипта не подтверждён; не
приступать к выпуску TLS до отдельной проверки. Скрипт НЕ устанавливает
`dig` и ничего не меняет.

## Если A-запись отсутствует

В панели **фактического DNS-хостинга домена** `bms24.online` создать
только новую запись:

| Тип | Имя | Значение |
|---|---|---|
| A | stage41 | публичный IPv4 VDS из панели Timeweb |

Не менять `test.bms24.online`, A-записи основного домена или MX.
Проверить, что у `stage41` нет ошибочного AAAA: HTTP-01 может обращаться
по IPv6, если AAAA существует. Не удалять валидную AAAA вслепую —
сначала сверить реальный маршрут.

## После успешного DNS gate — план отдельного этапа

1. Сделать резервную копию текущей конфигурации Nginx и проверить её
   восстановимость; не трогать существующий `bms24-test`.
2. Проверить фактический `nginx -T` и include `sites-enabled`.
3. Подготовить `/var/www/letsencrypt` и изолированный **ACME-only**
   vhost для `stage41.bms24.online`. Текущий шаблон лежит в
   `/srv/bms24-staging/parity41/https-assets/acme-only.conf.example`.
4. После `nginx -t` — только `reload` и HTTP-01 диагностика снаружи.
   Веб-сервер Moodle не запускать. Прочие HTTP-URL на новом host дают 404.
5. Только после успешного HTTP-01 теста использовать
   `certbot certonly --webroot -w /var/www/letsencrypt -d stage41.bms24.online`.
   Этот режим получает сертификат, но **не переписывает Nginx**.
6. Создать отдельную Basic Auth, включить конфигурацию HTTPS только
   после проверки сертификата и прав; удостовериться, что без пароля —
   HTTP 401. Нельзя публиковать неподдерживаемую Moodle 4.1.22 открыто.
7. Затем проверить условия установки Moodle с отдельной БД, без реальной
   почты/платежей/Bitrix24.

Официальные источники:
- Certbot: https://certbot.eff.org/instructions
- Webroot: https://eff-certbot.readthedocs.io/en/stable/using.html
- HTTP-01: https://letsencrypt.org/docs/challenge-types/

**Этап 1894 — только read-only аудит, никакого DNS/HTTPS запуска.**
