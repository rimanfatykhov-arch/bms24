# BMS24 — Instructions for Codex (VS Code / repository root)

Current source candidate: `local/kbacompany/version.php` → `local_kbacompany` **1.1.0-rc29**, `$plugin->version = 2026110901` (communication/Corporate ERP runtime repair, not deployable over newer feature trees). Approved ADR-0002 baseline is a36c5ab8; see `docs/reconcile/ISSUE44_SCHEMA_REPAIR.md`. Reconciled from PR #8 with main files retained and the PR #40 acceptance fix. See `docs/reconcile/PR8_RECONCILIATION.md`. This is NOT an accepted production release. Historical build1642 documentation is retained as history.

## Read only what is relevant

1. `docs/00_MASTER_CONTEXT.md` (product, current state)
2. `docs/01_ARCHITECTURE_BOUNDARIES.md`, `docs/02_MOODLE_PLUGIN_RULES.md`, `docs/04_SECURITY_COMPANY_ISOLATION.md`
3. `docs/16_CURRENT_RELEASE_RC29_1642.md`, `docs/17_UI_SHELL_AND_DESIGN_SYSTEM.md`
4. Domain-specific docs in `docs/` according to the task.
5. `docs/05_RELEASE_QA_STANDARD.md` before packaging.

For your first session follow `CODEX_FIRST_TASK.md` and **audit before changing code**.

## Platform & ownership

- Moodle hosts authentication, LMS, core DB/file/output APIs and lifecycle. No core patches.
- `local/kbacompany` **owns BMS24** domain, routes, canonical tables, company/RBAC security, App Shell, Mustache templates, BMOS visual tokens and UI Kit.
- `theme/kba` is an **optional Moodle host theme and legacy fallback**, NOT the long-term owner of the BMS24 App Shell. Do not put BMS menu, permissions, CRUD, app shell, or BMS CSS tokens into this theme.
- `local_kbacompany/bms24_shell_mode`: `legacy` (default) or `owned` (opt-in). Keep fallback usable and reversible.
- Other systems: Moodle/KBA Corporate → learning; Bitrix24 → current CRM provider; Odoo → potential operational ERP; BMS Cloud → distinct control plane, not a hidden Moodle module. External IDs are mappings, not canonical identity.
- BMOS is a **proposed category/concept** (Business Management Operating System). Digital agent posts are a planned stream, **not implemented 1.1.0 features**.

## NON-NEGOTIABLE security

Shared Moodle serves multiple companies. Scope reads/writes/exports/files/cache/events/scheduler/AI context to canonical company UUID **at query/service boundaries**; never fetch every company's records then filter PHP-side. Authorization must consider both Moodle capability and company/BMS domain rights.

Write actions: validate request, current user, company & object membership, `sesskey`, domain constraints, optimistic revision/transaction/locks where applicable, audit. Escape user strings and type URL parameters. Never invent data or access to external systems. Never commit passwords, access tokens, `.env`, `config.php`, `moodledata`, database dumps, or credentials to Git.

## Position Coverage invariants

- `task.assigneeid` identifies **original** Moodle user; `dutypositionuuid` is explicit position responsibility. Empty duty position means personal task; never auto-delegate it.
- Only approved, current, company-scoped position coverage may change the **effective executor** of explicitly position-bound open work. Existing approvals, personal messages, protected attachments, private calendar, RBAC and review rights must not be transferred implicitly.
- Preserve UUID, original user, immutable audit history and approved Battle Plan. Recurring instance idempotency must not depend on assignee or revision.
- No retroactive mass migration of task ownership or permissions.

## UI / design system

BMOS colors: Navy `#0B2B41`, Emerald `#0C6C52`, Sky `#E4F2FB`, Mint `#ECF7F3`, Sand `#FDF6ED`, Blue `#1777B5`, AI Violet `#6B43B5`.

Own-shell UI lives in `local/kbacompany/templates/bms24_*` and `local/kbacompany/bms_ui/*`, CSS scoped `.bms24-app`. Reuse `bms24_shell_service`, `bms24_ui_kit_service`, canonical BMS render models. New BMS logic in the plugin; render-only Mustache, no SQL/business decisions in templates. Preserve legacy selectors/JS contracts until tests prove removal safe. Human-readable RU/EN text, responsive tables, keyboard focus.

## Release and work discipline

- Reconcile authorization covers isolated source/CI changes only. Do not merge or deploy.
- Run `python tools/check_reconcile_1879.py` and imported workflow gates in addition to main static QA. Independent QA/security and human review remain required before acceptance; never invent review approvals.

- Before editing: inspect `git status`, plugin version, relevant pages/services, affected tests; provide concise scope and risk notes.
- Small coherent patch; do not mix theme decoupling with unrelated feature work, schema, ACL or integration changes.
- Keep old behavior in `legacy`; new UI selected only by server-side switch; no silent switch-on for companies.
- PHP compatibility: actual plugin `requires=2022041900` (Moodle 4.0). Existing docs also mention Moodle 4.1.22/PHP7.3+, **but this environment baseline is not yet verified**; ask for target PHP/Moodle versions before using newer language features. Local PHP 8.4 lint does not prove old-PHP compatibility.
- Run `python scripts/qa_local.py`, focused tests, language parity, and `git diff --check`. A local lint/smoke is NOT Moodle PHPUnit, real DB upgrade, cron/race, security acceptance, or production validation.
- Never deploy or change production without explicit user confirmation and backup/rollback plan.
- End with changed files, DB/ACL/privacy/integration impact, tests RUN and NOT RUN, known risks, exact next acceptance actions.
- For material architecture revisions, record the decision in `docs/` and update MASTER/Roadmap/Changelog when permitted; don't claim cloud updates if not performed.
