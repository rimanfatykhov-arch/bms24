# BMS24 — Tasks / Subtasks 2.0 (build2026101832)

- Component: `local_kbacompany`
- Release line: `1.1.0-rc29`
- Previous version: `2026101831`
- Current build: `2026101832`
- Data migration: **none**; existing tasks, checklist, discussions and Battle Plan links are preserved
- Production acceptance: **NOT RUN**

## Scope

1. Subtask progress is now calculated over *all visible direct child tasks*, not over the 20 rows on the current page. It displays accepted-completed count, percentage, overdue count, awaiting-review count and cancelled count. Cancelled children are excluded from the completion denominator, and deleted children from all counts.
2. An actor who can see the parent but not all of its children is shown a limited-visibility warning. The UI does not present a partial percentage as an overall one. Hidden task IDs, titles and counts are not included in the read projection.
3. The parent cannot be submitted or accepted if any *direct* child remains open, doing, returned or under review, even if that child is not visible to the actor. The server rechecks this for every transition, independent of UI. It does **not** automatically complete or cancel the parent.
4. Subtasks awaiting review still block parent completion until their result has actually been accepted.
5. Parent submit and accept use the same company-scoped lock as new task creation and existing-task linking, so these paths cannot insert a child in between the completion check and the parent status update. New manual child creation rechecks parent eligibility under this lock.
6. A clickable parent breadcrumb is displayed when viewing a child. Overdue child deadlines get an explicit indicator.

## Behavioral rules

- `done` means the subtask has completed the existing canonical completion/review workflow.
- `cancelled` is excluded from the active completion denominator and does not block the parent.
- `deleted` is excluded from all metrics and the gate.
- Completion percentage is read-only presentation; it does **not** auto-transition the parent.
- The parent can only be closed through its existing explicit submission/acceptance workflow with a recorded result.
- Deeper descendants are handled by the same rule when their immediate parent is submitted. The parent gate checks direct children; accepted descendants are already required before their direct parent can be submitted after this release. Historical pre-release data may still require manual audit.

## Security

- No new permissions, cross-company lookups or data copies.
- The canonical company scope and `projection_access` predicate remain the read boundary.
- The completion gate checks all direct children **server-side**, including hidden ones, returning a generic error without revealing them.
- The parent task revision is still checked, and the existing task audit trail is unchanged.

## Changed files

- `classes/local/task_service.php` — counts, progress, completion gate, locking
- `classes/local/task_ui_service.php` — progress, warning, overdue, parent breadcrumb
- `styles.css` — scoped task detail styles
- `lang/ru/local_kbacompany.php`, `lang/en/local_kbacompany.php`
- `tests/task_subtasks20_test.php`
- `version.php`, `release/CURRENT_RELEASE.json`, release documentation

## Installation

1. Back up Moodle DB, `moodledata`, current `local/kbacompany`.
2. Install update **on staging first** without uninstalling the plugin.
3. Run normal Moodle notifications/upgrade and purge caches.
4. Verify the build in `version.php` / the plugins overview.
5. On a parent with 3 children, complete one and cancel one: the visible progress should show 50% (1 / 2 active), and the remaining active child should block parent submission.
6. Finish the last active child, then submit the parent with an explicit result.
7. Repeat under a reviewer, a restricted employee and two different companies.

## Live validation required

Moodle 4.x + PHP 8.1.34 + MySQL 5.7/actual production DB: validate locks, actual grade/status interactions, query performance, permissions, no cross-company leakage, nested parent/child workflows and Battle Plan membership.
