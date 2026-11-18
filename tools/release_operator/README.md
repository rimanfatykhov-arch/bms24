# RC31 Pilot04 — оператор релиза, этап preflight и отрицательной приёмки

Единственная операционная точка входа: [BRP-1.1 standard](../../docs/deploy/BACKUP_RELEASE_STANDARD.md). Этот каталог — реализация ограниченного этапа стандарта, а не ещё один конкурирующий runbook. Основа: выборочно перенесённый PR101 `b205669aebb5790a1bb24ac5fca7b8348882369d` и уроки завершённого выпуска PR102. Ветка начинается от main `d35350954146867f80877bcea4729c31477d16d3`.

## Что исполняется

1. `python -m tools.release_operator.preflight --config <private.json>` — read-only проверка строгой конфигурации, окна, путей, ресурсов, baseline/Corporate и точного пакета. JSON stdout содержит только статусы/коды, без приватного конфига и путей. Ненулевой exit означает HOLD. PASS имеет scope `PREFLIGHT_ONLY`, `approval_required=true`, `live_execute_supported=false`.
2. `python tools/release_operator/lifecycle.py rehearse --parent <new-empty-private-temp-directory>` — действительные дочерние процессы и detached watchdog, но только для созданной искусственной файловой модели. Capture → verify → restore → install → smoke → code-only rollback → reinstall → smoke; архив и новые записи сохраняются. Нет shell hooks, SSH, сети, Docker socket, `crontab`, Moodle bootstrap или реальной БД. Fault injection доступен только внутри этой модели.
3. [Encrypted stream helper](../backup/README.md) из PR101 — отдельная Linux/GPG synthetic проверка потока. Plaintext профиль не вызывает GPG и не требует ключ. Helper не является универсальным безопасным tar extractor и не подключён к реальным данным.

## Граница допуска

**Этот PR не содержит разрешённого production executor.** Внешняя live-изоляция, SQL import, Moodle/tenant/browser проверки, owner-key recovery и API серверов не доказываются конфигурационными `true` или синтетической моделью. Inventory проверяется на свежесть и внутреннюю согласованность, но отчёт явно маркирует его как supplied inventory, а не live observation. Git tree в manifest — проверяемая привязка идентификатора; content SHA256 проверяется независимо, а не выдаётся за новое вычисление Git tree на сервере.

Это преднамеренный этап Pilot04: повторяемые проверки и отрицательные контроли до подключения reviewed live adapters. Для operational acceptance будущего адаптера нужны реальная изолированная Moodle/SQL матрица MariaDB/Percona, безопасный collector/drain, привязанные approvals и независимая проверка. Автоматической публикации, approval, удаления старых backup, покупки диска, миграции и изменения rulesets нет. Принимаемый пакет остаётся отдельным неизменяемым артефактом; номер build не заменяет SHA.

## Приватная конфигурация

`config.example.json` — неприменимый шаблон с фиктивными путями/временем, не рабочее разрешение. Заполненный config хранится вне Git в private runbook владельца с ACL. Не помещать адреса SSH, пароли, ключи, SQL, пользователей, реальные file lists или персональные данные в report/PR. Конфигурация задаёт явные bytes/inodes и peak по каждой фазе; старые окна и units не угадываются.

Текущий поддерживаемый package policy — code-only: reviewed before/after allowlist, нет удалений, `version.php`/`db/*` неизменны, Corporate только baseline. Иная миграция требует отдельного проекта адаптера; preflight не легализует её автоматически. Рабочий код не меняется этой проверкой.

Storage policy указывает решение владельца: production + независимый тестовый failure-domain; третья/локальная копия не навязывается. Для plaintext требуется явное решение и private permissions; для encrypted — проверяемая привязка key-recovery evidence. Старые encrypted base/keys и зависимые delta сохраняются. Hash evidence о ключе не равен исполненной проверке восстановления рабочего ключа.

## Проверки

```sh
python -m unittest discover -s tools/release_operator -p 'test_*.py' -v
python -O -m unittest discover -s tools/release_operator -p 'test_*.py' -v
umask 077
export BMS_FIXTURE_DIR="$(mktemp -d)"
LC_ALL=C bash tools/backup/test-stream.sh
```

Требуются Python3.11+; encrypted helper — Linux Bash/GNU tar/coreutils/GPG. Windows не заменяет Linux signal acceptance: SIGKILL явно неприменим, Linux CI обязателен. Тесты используют только новые приватные temp fixtures. Они не удаляют старые backup/сайты; fixture cleanup принадлежит вызывающему и не является разрешением на `prune`. Не загружать synthetic private keys из fixture в CI artifacts. GPG agent синтетического ключа останавливается отдельно.

В existing Dev `checks` добавлены эти команды, без удаления прежних шагов. Изменения CI/tools должны классифицироваться FULL; основной селектор87/shadow86, BROWSER/Merge gate, Corporate и release acceptance сохраняются. Все результаты должны относиться к текущему head/merge tree; старый PASS102 не переносится на этот PR. Детальные итоги и NOT RUN — в карточке нового Draft PR.

## Уроки102 и отрицательные случаи

- Отсутствующий/истёкший deadline и неверные units/квота — FAIL до state change.
- Несовместимый backup path, volume/UID или symlink/alias/overlap — FAIL.
- Дрейф ZIP/manifest/baseline/Corporate, повторные ZIP entries и path traversal — FAIL.
- Ошибка любого ребёнка, позднего parser/hash, timeout или обрыв controller — не успешный capture.
- Ошибка purge при rollback не должна оставлять fixture maintenance/cron остановленными; сам rollback остаётся FAIL.
- Rollback возвращает код и сохраняет новые business/file записи; это не разрешение перезаписать реальную БД старым dump.

Длительности отдельных фаз — telemetry текущего исполнения, не SLA/p95. p50/p95 допускаются только с явным N и сопоставимыми версиями/профилями; этот первый PR их не заявляет.
