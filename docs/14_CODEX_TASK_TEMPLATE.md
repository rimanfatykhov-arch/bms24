# Task Template for Codex

## User-visible objective

[One UI or business behavior to change; capture screenshot / expected result.]

## Current source & constraints

- Verify `local/kbacompany/version.php` against documented baseline 1642.
- Module files: `[specific paths]`.
- BMS24 `owned`/`legacy`: preserve both unless separately authorized.
- Keep company-scoped ACL, Moodle capabilities, sesskey, revision/audit, canonical UUIDs, personal vs position tasks.
- PHP/Moodle host versions: [verify actual staging environment; do not assume].

## Before editing

Read `AGENTS.md` and relevant docs; show exact code references, concise plan, DB/ACL/privacy impact, tests and rollback.

## Implementation

Smallest coherent patch; no unrelated DB/RBAC/Cron changes for UI-only tasks. Never patch theme to solve BMS task-domain issues.

## Tests and report

- `python scripts/qa_local.py`; focused tests; `git diff --check`.
- State success/fail/not run separately for mock, Moodle PHPUnit, Behat, real Moodle, cross-company checks.
- Provide changed files, release artifacts **only when actually generated** and staging acceptance instructions.
