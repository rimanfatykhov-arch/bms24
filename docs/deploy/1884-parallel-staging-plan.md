# Stage 1884 — параллельная staging-среда под production parity

Основание: фактический аудит 03.11.2026.

| Параметр | Действующий staging | Боевой edu.kzn.mba |
|---|---|---|
| Moodle | 4.0.12 | 4.1.22 |
| PHP | 8.0.30 (в Docker) | CLI 8.1.34, Web SAPI пока неизвестен |
| MariaDB | 10.11.19 | Пока неизвестна |
| mod/customcert | отсутствует | 2022112820, требует Core >= 2022112820 |
| Хранилище Timeweb | 48G всего; 4.9G занято; 43G свободно | Отдельный хост |

## Решение

Текущую среду `test.bms24.online` на Moodle 4.0.12 **не обновлять in-place** и не затрагивать её базу.

Для parity требуется отдельный независимый стенд Moodle 4.1.22 с PHP 8.1 и совместимой MariaDB, собственными `config.php`, Moodledata и пустой/санитизированной тестовой БД. Ни один файл с производственными секретами или содержимым production БД не переносить. Включить `mod/customcert` только после проверки совместимости.

Домен нового контура, порты, ресурсы Docker и объёмы дисков выделить после read-only preflight. Никаких `docker up`, `docker compose down`, `prune` или обновлений на этом этапе.

## Проверка ресурсов VDS (read-only)

На VDS Timeweb под root:

```bash
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1884-parallel-staging-preflight
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/stage1884_capacity_preflight.sh" \
  > /tmp/bms24-stage1884-preflight.sh
bash -n /tmp/bms24-stage1884-preflight.sh
bash /tmp/bms24-stage1884-preflight.sh
```

Скрипт показывает RAM/swap, CPU, диск, inode, размеры известных каталогов, Docker cache,
запущенные контейнеры, только имена путей монтирования и открытые порты. Не читает пароль,
`.env`, `config.php`, БД, пользовательские записи или `moodledata`.

## Gate перед созданием второго окружения

- [ ] Проверены фактические RAM/swap, CPU, диск, свободные inode.
- [ ] Установлено, не переполнит ли второй набор Docker-образов/volume доступный диск.
- [ ] Уточнены версии PHP Web на production и MariaDB production (отдельные запросы к администратору; без секретов).
- [ ] Проверено существование внешнего, пригодного к восстановлению бэкапа первой staging-среды.
- [ ] Выделены отдельные `COMPOSE_PROJECT_NAME`, БД/тома и host port.
- [ ] Решена HTTPS-публикация второго тестового домена через reverse proxy без изменения текущего сайта.
- [ ] Подготовлена и просмотрена разница между 4.0.12 и 4.1.22, настройками PHP 8.1 и версиями плагинов.
- [ ] Продумана миграция с 4.1 (уже снята с поддержки) на актуальную поддерживаемую Moodle.

**Ограничение:** конфигурация production неизвестна за пределами файловых версий, не обещать полную совместимость до реальной проверки. `PR #17` и `PR #19` не устанавливать на production без отдельной приёмки.

## Результаты фактической проверки (03.11.2026, скриншот Timeweb)

Скрипт завершился маркером `READ_ONLY_PREFLIGHT_1884_OK`. Из видимой части вывода:

| Параметр | Значение |
|---|---|
| Код Moodle в текущем staging | 401M |
| Каталог Docker | 576K |
| Backups | 15M |
| Import | 21M |
| Docker images | 2.026 GB |
| Docker containers | 3 запущены (webserver, db, mailpit) |
| Docker database local volume | 284.1 MB |
| Webserver bind | `/srv/bms24-staging/moodle-test/moodle -> /var/www/html` |
| Webserver listen | `127.0.0.1:18080` |
| Host listen | `0.0.0.0:80`, `0.0.0.0:443`, `127.0.0.1:18080` |
| Свободно на диске (предыдущий вывод) | 43G |

Порт `18081` не перечислен среди слушающих на момент проверки, но это не гарантия доступности при развёртывании.
Верхние секции отчёта с `free -h`, `nproc`, `df -i` не попали на присланный скриншот и **не подтверждены**.

### Следующий read-only шаг перед созданием второго стенда

```bash
echo "=== MEMORY ==="
free -h
echo "=== CPU ==="
nproc
echo "=== FREE INODES ==="
df -i /srv/bms24-staging
echo "=== PORT 18081 ==="
ss -lnt '( sport = :18081 )'
```

Если ресурсов недостаточно — новый стенд пока не запускать, использовать отдельный VDS
или кратковременную среду по расписанию. Существующие контейнеры, volumes и сайт не трогать.
