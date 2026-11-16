# Daily reports R0 — source preservation and scope

Decision: [ADR-0009](../adr/ADR-0009-daily-employee-reports.md) (PR #115, owner approval 15.11.2026, stage R0 = В12).
Base: `main` @ `881aac2` (merged #113 day summary and #114 Communication Center, which contains #110 and #103).

## What R0 changes

- New workspace sidebar route `dailyreports` → `daily_report.php` («Отчёты за день» / "Daily reports").
- `daily_report.php` renders with its own active route instead of `tasks`, heading «Отчёты за день» and a single
  tab «Мой отчёт». Content is the unchanged read-only day summary from #113.
- Task Center button renamed from «Сводка моего дня» to «Отчёт за день» (report-first wording).
- Workspace quick navigation gets the same link.

No schema, upgrade step, version bump, capability, ACL, privacy provider, message provider or scheduled task change.
The read-only service `daily_report_service` is unchanged: the actor is still the logged-in user only.

## Exact language-file follow-up

Six keys are appended after the Communication Center cleanup block (#114) in each language file; all earlier bytes
(published #110 prefix, #106 day-summary block, #114 cleanup block) are kept.
Before/after hashes are recorded in `daily-reports-r0-source-preservation.json` and chained in
`tools/check_pr53_pr56_integration.py`. The older key `dailyreport_title` is kept (no in-place edits) and is no
longer used by these pages.

## Deviations from the design document

- Sidebar position: directly after «Задачи», as designed (owner confirmed 16.11.2026). The RC29 contract
  `bms_recurring_navigation_test` is updated: «Регулярные задачи» now follow «Отчёты за день» and still precede
  «Боевой план»; its company-scope, URL and active-state assertions are unchanged.
- Short phone label «Отчёты» / "Reports" (owner request 16.11.2026): routes may set an optional `mobile_label`;
  `bms24_nav_groups.mustache` renders it as an extra `aria-hidden` span. At ≤860 px (where the owned shell shows its
  mobile menu) the short span is shown and the full name is visually hidden but still read by screen readers.
  Only `dailyreports` sets it. Legacy `theme_kba` uses its own templates and keeps the full label.
- Task Center button on phones: «Отчёт» / "Report" (owner request 16.11.2026). Bootstrap `d-sm-none` /
  `d-none d-sm-inline` switch below 576 px in both shells (owned uses Moodle's `embedded` layout, Bootstrap stays).
  The link keeps the full name in `aria-label` and `title`. No CSS file changed for this.
- В9 (exact truncation place of the old label) is still open; R0 removes the cause by putting «Отчёт» first.

## Not verified

Moodle PHPUnit (`bms_daily_reports_navigation_test`), real Moodle rendering in legacy/owned shell, theme_kba
rendering, browser/mobile check. Static checks only.
