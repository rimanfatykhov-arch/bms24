# BMS24 Stage 1888 — проверка PHP 8.1 Docker image (без запуска Moodle)

## Подтверждённая приёмка 1887 (скриншот Timeweb от 03.11.2026)

- `STAGE1887_COMPOSE_CONFIG_OK` — как на placeholder-конфигурации, так и после создания .env
- `STAGE1887_TEST_DB_SECRETS_CREATED` — изолированные тестовые пароли сгенерированы
- `STAGE1887_PARITY41_RUNTIME_PREP_OK`
- `IMAGE_LOCAL mariadb:10.11`
- `IMAGE_NOT_PRESENT moodlehq/moodle-php-apache:8.1-bookworm`
- `No container started, no Docker image downloaded, no Moodle install performed.`

Новая база и PHP 8.1 пока **не запущены**. Проект bms24_parity41 пока представляет собой отдельный подготовленный каталог.

## Проверка версии PHP и расширений

Тег `moodlehq/moodle-php-apache:8.1-bookworm` опубликован автором `moodlehq` на Docker Hub:
https://hub.docker.com/r/moodlehq/moodle-php-apache/tags

Новый скрипт сначала подтягивает только этот image, затем запускает **временный** контейнер
с ограничением памяти/CPU, отключённой сетью и read-only filesystem исключительно
для `php -r`: проверить версию PHP 8.1.x и основные необходимые расширения.

### Команда Timeweb (root)

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1888-php81-image-preflight

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1888_php81_image_probe.sh" \
  > /tmp/bms24-stage1888-php81.sh

bash -n /tmp/bms24-stage1888-php81.sh
bash /tmp/bms24-stage1888-php81.sh --check
bash /tmp/bms24-stage1888-php81.sh --pull-and-probe
```

### Ожидаемые итоги

- `STAGE1888_PHP81_RUNTIME_OK`
- `STAGE1888_IMAGE_PREFLIGHT_OK`
- Вывод `PHP_VERSION 8.1.x`, `REQUIRED_EXTENSIONS OK`, `MAX_INPUT_VARS ...`
- Вывод image ID и `RepoDigest` для последующей фиксации в Compose.

При `IMAGE_NOT_PRESENT` первая команда `--check` лишь сообщает, что образ
отсутствует — это ожидаемо. `--pull-and-probe` скачает официальный образ (~400 МБ
сжатых данных для amd64) и запустит лишь **короткоживущий** PHP probe,
не Moodle и не MariaDB.

**Ни Docker Compose, ни контур на порту 18081, ни база данных при этом
не запускаются.** Код на `test.bms24.online` и `edu.kzn.mba` не меняется.
Публикации HTTPS/изменений DNS пока нет.

## После этой проверки

1. Зафиксировать образ по полному digest, а не использовать изменяемый тег для
   воспроизводимых будущих развёртываний.
2. Подготовить host-side `config.php` отдельно от production, проверить
   $CFG->wwwroot для будущего HTTPS-адреса и права файлов.
3. Подтвердить php.ini, max_input_vars, opcache и остальные требования Moodle.
4. Убедиться, что резервная копия старого staging восстанавливается отдельно.
5. Проверить плагины, совместимые с Core 4.1.22, включая Custom Certificate.
6. Лишь после отдельного согласования запустить именно `bms24_parity41` с
   изолированной MariaDB; при росте RSS/OOM приостановить parity-контур.

Moodle 4.1.22 — неподдерживаемая ветка для **временного parity тестирования**,
а не целевая production-платформа.
