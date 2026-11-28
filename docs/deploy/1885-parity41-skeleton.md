# Этап 1885 — подготовка изолированного Moodle 4.1.22 (без запуска)

Дата: 03.11.2026. Входящие фактические данные Timeweb: 2 vCPU, RAM 3.8 GiB,
available 2.9 GiB, swap 0, диск свободно ~43 ГБ, inode свободно 6 260 918,
порт `18081` не занят на момент проверки. Текущий `test.bms24.online`:
Moodle 4.0.12, PHP 8.0.30, MariaDB 10.11.19, 3 активных контейнера.

## Почему не меняем старый staging

`edu.kzn.mba` использует Moodle 4.1.22, PHP CLI 8.1.34.
Чтобы проверить производственные релизы BMS24, нужен отдельный parity-стенд.
Moodle 4.1 больше не получает обновления безопасности, поэтому parity —
**временный** этап; затем нужен план перехода на поддерживаемую ветку.

## Проект второго окружения

| Настройка | Новый parity41 |
|---|---|
| Новый каталог | `/srv/bms24-staging/parity41` |
| Compose project | `bms24_parity41` |
| PHP | `moodlehq/moodle-php-apache:8.1-bookworm` |
| СУБД | `mariadb:10.11`, **своя** пустая БД |
| Доступ к HTTP | **только** `127.0.0.1:18081`, HTTPS-домен позднее |
| Сеть | `bms24_parity41_internal`, internal |
| Том БД | `bms24_parity41_db` |
| Memory limit | web 1024 MiB, db 640 MiB |
| CPU limit | web 1.0, db 0.6 |
| Moodle Core | **отдельная** чистая копия Moodle 4.1.22 |
| Moodledata | `parity41/moodledata` с правами UID 33 |
| Существующий staging | Не трогать |

Суммарные контейнерные ограничения ~1.63 GiB. Это не гарантия отсутствия
OOM на VDS с 3.8 GiB RAM и нулевым swap. После запуска мониторить
`docker stats`, `free -h`, oom-kill события. При нехватке памяти запускать
parity по расписанию, останавливать только **его** контейнеры или увеличить VDS.

## Подготовить каталог (без запуска и без скачивания)

На Timeweb, root, после просмотра файлов PR:

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1885-parity41-isolated-compose

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/prepare_parity41.sh" \
  > /tmp/bms24-prepare-parity41.sh

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/compose.yml" \
  > /tmp/bms24-parity41-compose.yml

bash -n /tmp/bms24-prepare-parity41.sh
bash /tmp/bms24-prepare-parity41.sh /tmp/bms24-parity41-compose.yml
```

Ожидаемый результат: `STAGE1885_PARITY41_DIRECTORY_PREPARED`.

**Выполнение команды создаёт только** каталог `parity41`, два пустых
подкаталога `moodle` и `moodledata` и новый `compose.yml`.
Она не создаёт/не запускает контейнеры, не изменяет старый Compose,
не читает `config.php`, не скачивает Moodle и не затрагивает production.

## Gate 1886 — до первого запуска

1. Получить Moodle 4.1.22 из официального архива, проверить SHA256, распаковать
   только в `parity41/moodle`. Нельзя смешивать Core 4.0 и 4.1.
2. Проверить соответствие `$CFG->wwwroot` будущему HTTPS-адресу, прежде чем
   открывать стенд через обратный прокси. Не использовать localhost в URL Moodle.
3. Создать новые **случайные** пароли для изолированной БД вне GitHub;
   `.env` и `config.php` сохранять с ограниченными правами.
4. Устанавливать плагины только проверенных версий, отдельно решить Custom Certificate.
5. Перед первым `docker compose up` сделать резервную копию текущего staging и
   проверить восстановление без использования production-данных.
6. Проверить `docker compose config --quiet` без печати секретов.
7. Запускать `docker compose -p bms24_parity41 -f ... up -d` отдельно и лишь
   после утверждения конфигурации; никакого `docker compose down` с обычным проектом.
8. Сначала провести тесты изолированной сети, HTTPS и ролей без реальных CRM,
   платёжных шлюзов, почтовых рассылок и внешних вебхуков.
9. Подготовить отдельный план перехода production на поддерживаемую Moodle.

Документация: [Moodle 4.1.22](https://moodledev.io/general/releases/4.1/4.1.22),
[официальные архивы](https://packaging.moodle.org/stable401/),
[PHP 8.1 Docker image](https://hub.docker.com/r/moodlehq/moodle-php-apache).

**Текущая стадия:** готов шаблон проекта. Ни контейнеры, ни БД, ни DNS,
ни production не изменены.
