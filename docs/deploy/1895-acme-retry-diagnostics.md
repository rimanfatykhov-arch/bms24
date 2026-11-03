# BMS24 — исправление этапа 1895: 404 после Nginx reload

## Фактическая диагностика, 03.11.2026

На Timeweb подтверждено:

- Скрипт 1895 прошёл `STAGE1895_DNS_GATE_OK` и `STAGE1895_ACME_ONLY_PREFLIGHT_OK`.
- Создан и проверен архив резервной копии Nginx.
- `nginx -t` выполнился успешно после добавления ACME-vhost.
- **Немедленный** локальный `curl` на `stage41.bms24.online` (с `--resolve` на 127.0.0.1) вернул 404.
- Скрипт отменил изменения и дважды вывел rollback. Сейчас active Nginx вновь содержит только `bms24-test`.
- `namei -l`: ACME probe `/var/www/letsencrypt/.well-known/acme-challenge/bms24-stage1895-probe` существует, файл 0644, каталоги исполняемы.
- Шаблон Nginx содержит правильный `server_name`, `location ^~ /.well-known/acme-challenge/`, `root /var/www/letsencrypt`, `try_files $uri =404`.
- Вывод error.log пуст. Нельзя доказать, что исходный 404 вызван задержкой Nginx: это **гипотеза**. Без проверки активного vhost источник не установлен.

## Что исправлено

Новый скрипт `stage1895_acme_activate_retry.sh`:

- сохраняет защитные проверки и создание Nginx-backup из 1895;
- после `systemctl reload nginx` убеждается, что Nginx на диске включает `/etc/nginx/sites-enabled/bms24-stage41-acme`;
- повторяет HTTP-01 запрос локально до 12 раз с интервалом 1 сек;
- проверяет и код 200, и содержимое `BMS24_STAGE1895_HTTP01_OK`;
- при повторном 404 фиксирует HTTP-код и откатывает только свои новые файлы;
- на успехе по-прежнему не выпускает сертификат и не запускает Moodle.

## Выполнить на Timeweb: сначала --check

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=fix/1895-acme-probe-retry-diagnostics

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1895_acme_activate_retry.sh" \
  > /tmp/bms24-stage1895-retry.sh

bash -n /tmp/bms24-stage1895-retry.sh
bash /tmp/bms24-stage1895-retry.sh --check 186.246.110.254
```

Если выведено `STAGE1895_ACME_ONLY_PREFLIGHT_OK`:

```bash
bash /tmp/bms24-stage1895-retry.sh --activate 186.246.110.254
```

На успехе:

```text
STAGE1895_NGINX_BACKUP_OK ...
STAGE1895_NEW_VHOST_INCLUDED_IN_NGINX_CONFIG
STAGE1895_HTTP01_LOCAL_SUCCESS_ATTEMPT=...
STAGE1895_ACME_ONLY_NGINX_ACTIVE
STAGE1895_LOCAL_HTTP01_PROBE_OK
```

Из **внешнего** браузера запросить точный URL без HTTPS:
http://stage41.bms24.online/.well-known/acme-challenge/bms24-stage1895-probe

Ожидается `BMS24_STAGE1895_HTTP01_OK`.

Если остаётся 404 даже после ретраев, не обходить rollback. Зафиксировать
`STAGE1895_LOCAL_HTTP01_NOT_READY status=404`, после чего отдельно
проанализировать active Nginx server blocks, IP-bindings и журналы именно
в короткий момент активного сайта. Не редактировать `bms24-test`.

**Запрещено сейчас:** Certbot, установка/запуск Moodle, публикация
неподдерживаемой версии без HTTPS и Basic Auth.
