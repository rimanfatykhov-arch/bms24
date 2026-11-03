# Этап 1886 — официальные исходники Moodle 4.1.22 для parity41

Выполненный этап 1885 подтверждён в Timeweb маркером `STAGE1885_PARITY41_DIRECTORY_PREPARED`.
Каталог `/srv/bms24-staging/parity41` существует и пока не содержит Moodle Core или БД.

## Источник

Архив: https://packaging.moodle.org/stable401/moodle-4.1.22.tgz

Контрольная сумма: https://packaging.moodle.org/stable401/moodle-4.1.22.tgz.sha256

Официальный индекс [Moodle Packaging](https://packaging.moodle.org/stable401/) содержит
оба файла (пакет опубликован в декабре 2025). Использовать **фиксированную**
версию 4.1.22, а не `moodle-latest-401`. У Moodle 4.1 поддержка закончилась,
этот стенд нужен только для временного воспроизведения production.

## Шаг — загрузить и распаковать в новый каталог

```bash
set -e
REPO=/srv/bms24-staging/workspace/bms24
BRANCH=ops/1886-parity41-core-verify
runuser -u bmsdev -- git -C "$REPO" fetch --no-tags origin \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
runuser -u bmsdev -- git -C "$REPO" show \
  "refs/remotes/origin/$BRANCH:tools/deploy/parity41/install_moodle_4122_core.sh" \
  > /tmp/bms24-install-moodle-4122.sh
bash -n /tmp/bms24-install-moodle-4122.sh
bash /tmp/bms24-install-moodle-4122.sh
```

Скрипт:
- откажется перезаписывать непустой `parity41/moodle`;
- загружает архив и SHA-256 только по HTTPS, проверяет фактический SHA-256;
- проверяет все пути архива, отказывает на специальных файлах/симлинках,
  превышении лимитов, неверном корне и отсутствии Moodle Core;
- проверяет `$version=2022112822.00` и `$branch='401'`;
- извлекает код во временную директорию и переносит только в
  `/srv/bms24-staging/parity41/moodle`;
- **не запускает Docker**, не создаёт пользователей, не читает и не меняет БД,
  `config.php`, старый staging или боевой `edu.kzn.mba`.

Ожидаемые маркеры:

```text
STAGE1886_SHA256_OK ...
STAGE1886_ARCHIVE_STRUCTURE_OK files=...
STAGE1886_MOODLE_4_1_22_CODE_READY
```

Если интернет с VDS недоступен, можно скачать два файла через браузер
с указанных официальных ссылок, загрузить через WinSCP, затем выполнить
`bash /tmp/bms24-install-moodle-4122.sh --archive /path/moodle-4.1.22.tgz --sha256 /path/moodle-4.1.22.tgz.sha256`.
Использовать только SHA-256, полученный из официального источника.

## Следующий этап 1887 — пока заблокирован

- Проверить Docker-образ PHP 8.1 и PHP-расширения, не запуская старый Compose.
- Задать отдельную тестовую БД и `config.php` без обмена секретами.
- Откорректировать режим mount webroot: первоначальный Compose-шаблон
  использует `:ro`, что не позволяет Moodle-установщику создать config.php.
  Предусмотреть создание config.php на хосте с ограниченными правами, не
  делать основной веб-каталог постоянно writable для веб-сервера.
- Установить совместимые плагины, включая `mod/customcert`, только после
  тестов их совместимости с Moodle 4.1.22.
- Проверить HTTPS, отдельные Docker-тома, ресурсы VDS, восстановление backup.
- Ограничить исходящие соединения тестовых интеграций CRM/почты/платежей.

**До всех проверок не запускать `docker compose up` и не обновлять production.**
