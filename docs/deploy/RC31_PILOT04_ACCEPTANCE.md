# RC31 Pilot04 — начальная приёмка оператора (#104)

Base: main `d35350954146867f80877bcea4729c31477d16d3`. Scope: read-only preflight и действительная процессная **синтетическая** модель. Это первый этап, не production-ready executor и не разрешение на реальные операции. Текущие run/attempt/head результаты ведутся в Draft PR; этот файл не присваивает PASS будущим SHA.

## Переиспользование PR101

Из `b205669aebb5790a1bb24ac5fca7b8348882369d` выборочно перенесены11 файлов: standard+исторические PR100 evidence, четыре ссылки существующих release runbooks/gates, `tools/backup/{.gitattributes,README.md,stream-lib.sh,test-stream.sh}`. Ни чужая ветка, ни runtime/Core/Corporate не меняются. Root attributes и QA standard сохранены. Стандарт уточнён под последнее решение production+test/optional encryption; helper усилен guard всех output sidecars и alias/symlink collisions. Исторические NOT RUN/HOLD PR100 не являются текущим состоянием production и не используются как разрешение.

Новый preflight parameterizes выбранные проверки пакета/allowlist, а не копирует приватные операторские scripts/адреса/ключи из выпуска102. Lifecycle воспроизводит его ошибки на искусственных файлах и дочерних процессах. Shared entry: [BRP](BACKUP_RELEASE_STANDARD.md), implementation/limits: [README](../../tools/release_operator/README.md).

## RUN локально

- Source preservation `check_reconcile_1879.py`, `check_pr53_pr56_integration.py` — PASS.
- Workflow policy — PASS; прежние условия/jobs не удалены, добавлены проверки в `checks`.
- Bash syntax stream/test — PASS.
- Preflight7tests normal/-O,26exact failure-code mutations, protected schema/allowlist/JSON/ZIP — PASS. Финальное исправление isolated traversal/duplicate проверено отдельным тестом с точной причиной; полный текущий набор повторяется в CI.
- Lifecycle7actual-process tests Windows — PASS,1explicit SIGKILL N/A. Включены10faultcases, own PID/birth, concurrent service state/hardlinks, preservation/timeout. Linux coverage остаётся обязательной.
- Независимое review нашло и закрыло stale-sidecar overwrite, ложный phase failure через испорченный key proof, generic negative FAIL, смешение ZIP traversal+duplicate faults, чужой PID/hardlink и перезапись concurrent cron. Это исправления тестов/инструментов, не отключение проверок.

## Обязательная CI-приёмка текущего кандидата

Existing Dev `checks` запускает весь Python suite normal/-O, включая Linux SIGKILL; затем inherited encrypted helper с26negative controls и byte-equal восстановлением искусственного архива. Python3.11/Linux/GPG runtime не заменяется Windows результатом. Соответствующие logs/CI ссылки прилагаются в PR после исполнения; отсутствие результата означает NOT RUN.

Изменения `.github`/оператора требуют FULL по прежнему primary selector. Не менялись BROWSER/Merge gate/rulesets, FULL/schema/Corporate/release acceptance. Реальная SQL/Moodle матрица относится к existing FULL; JSON/files lifecycle не называется SQL restore. Existing release rehearsal `release/*` branch applicability не расширяется и не отменяется этим PR; branch `tools/*` не выдаёт себя за принятый release package.

## NOT RUN / оставшиеся условия operational допуска

- Любые реальные backup/deploy/SSH/maintenance/cron, production/staging — NOT RUN и вне поручения.
- Production live adapter, collector доверенных данных, canonical DB drain, remote transport, authenticated encrypted extraction/path/link protection — не реализованы этим этапом.
- Реальный SQL import, fresh/upgrade/ACL/browser на новом live adapter обеих СУБД, owner recovery рабочего ключа и независимость failure-domain — не доказаны supplied config или synthetic fixture.
- Новый release artifact/owner approval/site/window не выдаются. Нет опубликованных p50/p95/SLA/retention сроков, удаления наборов или покупки ресурсов.

До operational допуска: независимая приёмка текущего PR/CI, отдельный reviewed live adapter и его isolated dual-engine Moodle rehearsal, затем только новое разрешение владельца на конкретные реальные операции. Issue #104 и этот Draft не заменяет approval или ready release.
