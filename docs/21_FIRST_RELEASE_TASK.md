# Next milestone after repository audit — UI Architecture W3.2

**Goal:** migrate `recurring_tasks.php` presentation to plugin-owned BMS24 UI Kit under the `owned` flag. Maintain `legacy` behavior exactly; preserve recurring generator, company-scoped queries, `taskrec` revisions, immutable task occurrences and approved Battle Plan associations.

## Specific work

1. Inspect `recurring_tasks.php`, `recurring_task_service.php`, `bms24_shell_service.php`, `bms24_ui_kit_service.php`, existing Mustache fragments, current CSS.
2. Identify GET/POST forms, UI modes list/create/detail/edit, hidden revision/requestkey/sesskey, owner/manager ACL and `dutypositionuuid` selector.
3. Add/extend scoped UI components and view models. Keep human-readable RU/EN strings; preserve existing field names/IDs/behavior.
4. Verify both `owned` and `legacy`, cross-company/permission negative paths, active/draft/paused/archived statuses, revision history and occurrence links.
5. Add tests; document performance/mobile/kba+boost gates.
6. If user's request is only for a design migration, **do not** alter DB, scheduler, recurrence idempotency or RBAC.

## Deliverables

- Small diff + tests + release notes + explicit staging acceptance.
- Do NOT increment build/version until the code change is implemented and packaging checks actually pass. Do not reuse build 1642 as a new release.
- Keep master/roadmap/changelog aligned when connectors available, but don't claim they were edited without verification.
