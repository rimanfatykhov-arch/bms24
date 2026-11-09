# BMS24 — Master Context / актуальная инженерная точка

Дата обновления контекстного пакета: **18.10.2026**. Исходная база была проверена по `SOURCE_local_kbacompany_v1.1.0-rc29_build2026101642.zip`.

## Предметная модель

- **BMOS** — Business Management Operating System, предлагаемая концепция класса цифровых управленческих платформ; термин развивается научно, а не объявлен признанным стандартом.
- **BMS24** — текущий продукт ООО «Бизнес Академия», реализующий систему управления компанией (стратегия, организационные функции, должности и люди, процессы, управленческие циклы, планы, задачи, KPI, документы, обучение, аналитика).
- Разработчик методологии и автор концепции: **Фатыхов Риман Хузяханович**, генеральный директор ООО «Бизнес Академия».
- Долгосрочная идея: цифровые функциональные посты и управляемые ИИ-агенты рядом с человеческими сотрудниками; они **не реализованы** как продуктовая функция в 1.1.0 RC29.

## Текущая версия

Актуализация 08.11.2026: в reconcile-кандидате код плагина — **2026101879 / RC29**, с исправлением acceptance из PR #40. Main baseline: `740fce16`; импорт: `eccc1eb`. Все файлы main сохранены. Ниже оставлена историческая точка 1642; новый статус, риски схемы и условия приёмки описаны в `docs/reconcile/PR8_RECONCILIATION.md`. Deployment не выполнен, RC30 не принят.

- `local_kbacompany`, `1.1.0-rc29`, плагин `$plugin->version = 2026101642`.
- `requires=2022041900` соответствует минимальному Moodle 4.0 по `version.php` (не заменяет проверку рабочей версии Moodle и PHP).
- Этот контекст фиксирует **кодовую точку**, не состояние production Moodle.
- Последние крупные этапы: BMS-POS-COVERAGE-01/02/03 (1633–1639), plugin-owned App Shell (1640), UI Kit (1641), Task Center UI W3 first slice (1642).
- **1642**: install/source ZIP существуют и проходят CRC; Moodle staging/live acceptance не выполнялась здесь. Не путать этап UI и исправления прав/DB.

## Границы владения

BMS24 management → `local_kbacompany`. Moodle → авторизация/LMS/core APIs. `theme_kba` → опциональная хост-тема/legacy fallback, NOT BMS24 App Shell owner. Odoo → внешний ERP-движок операционных фактов. Битрикс24 → CRM-провайдер; IDs only mappings. BMS Cloud → отдельный Control Plane.

## Источники истины

1. Вопрос «что в коде» → фактический Git checkout, `version.php`, DB schema и тесты.
2. Проектные архитектурные решения → принятие пользователем + актуальные MASTER/Roadmap/Changelog на Google Drive.
3. Эта локальная копия → переносимый baseline, может устаревать: каждый следующий релиз обновлять `docs/16_CURRENT_RELEASE_RC29_1642.md` или заводить следующее release-status описание.
4. Ранее «1.0 RC» в старом пакете относится к устаревшему состоянию, не использовать как текущую версию.

## Ограничения приёмки

Static lint, zip CRC, единичные mock smoke tests не означают реальную Moodle DB / cron / RBAC / PHPUnit / Boost / KBA Theme acceptance или соответствие prod.

## 08.11.2026 — утверждение ADR-0002 и schema repair

Владелец утвердил базу a36c5ab8 и порядок ADR-0002. Кандидат исправления трёх полей — build2026110890/RC29, не deployment. Реально прочитанный stage41: BMS2026110802, Corporate2026102613, Moodle4.1.22/PHP8.1.34/MariaDB10.11.19. Это более новое функциональное дерево, чем Git baseline1879; сначала нужен отдельный перенос дельт, нельзя откатывать stage41 или Corporate. Отчёт: `docs/reconcile/ISSUE44_SCHEMA_REPAIR.md`. Архитектурные решения сохраняются в GitHub ADR/Issues согласно принятому ADR-0001; историческая ссылка на Drive выше не отменяет это решение.

Read-only production inventory08.11.2026: BMS2026101878, Corporate2026102613, Moodle4.1.22/PHP CLI8.1.34, Percona Server8.0.46-37. Исторический MySQL5.7 не является фактической production DB. По отдельной команде владельца настроен ограниченный SSH inventory stage41→RUWEB; код/БД Moodle не менялись.

## 2026-11-09 — isolated schema P0 repair

Candidate build2026110900 corrects XMLDB warnings and adds the missing ordered task index through a reachable checkpoint. See docs/adr/ADR-0003-ordered-index-schema-repair.md. PR48/49 remain unmerged; unrelated full-suite blockers remain HOLD. No production or staging deployment is authorized.

## 2026-11-09 — communication/Corporate runtime candidate

BMS2026110901 and local Corporate0.40.10-bms.1 build2026110901 preserve the approved source lineage while fixing observed runtime blockers. See docs/reconcile/COMMUNICATION_CORPORATE_RUNTIME_FIXES.md. Live Corporate.13 and stage0802 remain separate unreconciled feature trees; no deployment/merge authorization.

PR52 tested source469afad: complete MariaDB/Percona matrix confirms Corporate150/962 and communication/privacy/provider/schema/tenant gates PASS. Full BMS558/5397 retains23 errors/22 failures on both engines: release acceptance HOLD. Evidence: docs/reconcile/PR52_RUNTIME_ACCEPTANCE.md. PR48/49/50/52 remain draft and unmerged.

## 2026-11-09 — Issue44 re-audit and RC30 diagnostics

The current owner prompt preserves approved ADR-0002. PR55 historical observations do not supersede that decision or PR52 evidence. Separate candidate BMS2026110902 repairs Moodle exception classification and RC30 severity fixtures; Corporate2026110901 is unchanged. See docs/reconcile/ISSUE44_REAUDIT_RC30_DIAGNOSTICS.md. No new main-based integration, merge, staging/production deployment or Issue45 operational rollout is authorized by this source increment.

PR56 tested979a23e: MariaDB/Percona both pass31 focused RC30 tests, six install/upgrade scenarios and full Corporate150/962. Full BMS563/5496 still has23 errors/20 failures; no new failing identities. Issue44/release HOLD. Report: docs/reconcile/PR56_RC30_ACCEPTANCE.md. CI artifact verified; no deployment. Cross-checkout CRLF/LF packaging difference is recorded for Issue45.

## 2026-11-09 — selective PR53 + PR56 candidate

Owner requested integration of two distinct BMS0902 candidates. Conflict map was prepared first; new branch starts at exactPR56, selects functional0804/queue/learning fromPR53 and preserves Corporate0901/communication/RC30. New BMS0903 has no new DDL; both exact0902 sources are separate upgrade origins. See docs/reconcile/PR53_PR56_CONFLICT_MAP.md. Read-only stage41 hashes confirm Corporate2613 has additional/different source; candidate is not its accepted replacement. No main merge or staging/production changes.

Draft PR57 tested80f454b: both MariaDB10.11/Percona8.0 pass9 install/upgrade scenarios,8 exact183-table schema comparisons,12 preserved storage fixtures per scenario, full Corporate150/962 and targeted learning/queue/communication/RC30/access checks. Full BMS598/6981 retains20 errors/19 failures: exactly the39 shared parent failures, no new regressions. The old PR53 inbox identity is explicitly mapped to two passing PR56 datasets. Static/artifact CI PASS; release HOLD. See docs/reconcile/PR57_ACCEPTANCE.md and pr57-integration-results.json. Next work is focused diagnosis of those blockers plus separate Corporate2613 semantic reconciliation; no deployment authorization.
