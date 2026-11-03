# BMS24 1887 — подготовка runtime нового parity41 (без запуска)

Дата: 03.11.2026.

## Принятые результаты этапа 1886

По скриншоту Timeweb:

- `$version = 2022112822.00;`
- `$release = '4.1.22 (Build: 20251208)';`
- `$branch = '401';`
- Каталоги `/srv/bms24-staging/parity41/moodle/lib` и `admin` существуют.
- Moodle 4.1.22 Core занимает 324 MiB.
- Контейнеров с именем `bms24_parity41` нет.

Код Core уже распакован. **Не повторять этап 1886, не удалять непустой каталог Moodle.**

## Окружения

| Контур | Moodle | PHP | MariaDB | Docker |
|---|---|---|---|---|
| Production `edu.kzn.mba` | 4.1.22 | CLI 8.1.34 | требуется подтверждение | RUWEB, не трогать |
| Старый `test.bms24.online` | 4.0.12 | Docker 8.0.30 | Docker 10.11 | 127.0.0.1:18080, не трогать |
| Новый `parity41` | Core 4.1.22 готов | 8.1 image ещё не проверен локально | отдельная 10.11 БД ещё не запущена | 127.0.0.1:18081, каталог готов |

Образ `moodlehq/moodle-php-apache:8.1-bookworm` существует на Docker Hub.
Moodle 4.1 поддерживает PHP 8.1, но ветка уже завершила поддержку; это временный тестовый контур.

## Запуск скрипта с GitHub

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1887-parity41-runtime-prep

runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"

runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/stage1887_prepare_runtime.sh" \
  > /tmp/bms24-stage1887.sh

bash -n /tmp/bms24-stage1887.sh
bash /tmp/bms24-stage1887.sh --check
```

Команда `--check` только читает конфигурацию и проверяет:
- установленную версию Core 4.1.22;
- отдельные Compose-проект, порт 18081, внутреннюю сеть и том БД;
- отсутствие уже запущенного второго проекта и его volume;
- корректность Compose YAML с временными placeholder-паролями, без создания контейнеров;
- наличие образов `moodlehq/moodle-php-apache:8.1-bookworm`, `mariadb:10.11` в локальном кэше.

Не печатает пароли, не читает `config.php`, не запускает и не скачивает Docker-образы.

### Инициализация только тестовых паролей

После успешного `--check`:

```bash
bash /tmp/bms24-stage1887.sh --init-secrets
```

Скрипт создаёт **только новый** файл
`/srv/bms24-staging/parity41/.env` (0600, root-owned)
с двумя случайными 64-символьными hex-паролями для изолированной MariaDB,
не печатает их и не записывает в GitHub. Если файл уже есть, не перезаписывает.
После создания проверяет синтаксис Compose без отображения интерполированного конфига.

**Скрипт не создаёт DB, не обновляет Moodle и не запускает контейнеры.**
Новые пароли нужны только для `parity41`. Не переносить production пароли/ключи.

## До реального запуска второго стенда (этап 1888)

- Проверить конкретный digest Docker-образов и PHP 8.1 extensions
  (`max_input_vars >= 5000`, mysqli, intl, mbstring, zip и т. п.).
- Проверить значение `$CFG->wwwroot` и HTTPS-имя второго стенда,
  подготовить `config.php` отдельно на хосте; core mount остаётся `:ro`.
- Проверить резервную копию старого staging и её восстановление отдельно.
- Проверить PHP 8.1 / MariaDB 10.11 / Moodle 4.1 совместимость с
  BMS24 и `mod/customcert`.
- Ограничить внешние интеграции, почту и платежи.
- После проверок разрешить создание только `bms24_parity41` контейнеров.

Ни `test.bms24.online`, ни `edu.kzn.mba` на этапе 1887 не изменяются.
