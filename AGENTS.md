# BMS24 Dev AI — instructions for code agents (build2026101879)

## Product ownership
The product owner approves scope, architecture-breaking changes and releases.
AI agents prepare proposals, patches, tests and release candidates. No agent may
merge to protected branches, deploy to production, bypass review gates or inspect
client data without a separate authorized procedure.

## Source of truth
- Plugin: `local/kbacompany` (install as `moodle/local/kbacompany`).
- Moodle presentation layer (KBA Theme) is **not** contained in this repository.
- Business modules, routes, permissions and data reside in `local_kbacompany`.
- Current release stays `1.1.0-rc29` until *real* RC30 acceptance.
- `dev_ai/agents.json` is the policy registry. It is not an agent runtime.
- `docs/BMS_DEV_AI_ARCHITECTURE.md` defines boundaries and context.

## Dev AI task contract
For changes initiated by AI developer roles, follow `docs/AI_TASK_LIFECYCLE.md`.
Tasks require an approved scope, isolated worktree, QA + Security reviews and
a human decision. Never let a code author self-certify production readiness.

## Before changing code
1. Read `version.php`, `db/upgrade.php`, existing domain services and relevant
   CRUD/UI standards. Identify company scope, permission checks and side effects.
2. State acceptance criteria and tests. Preserve previous module behavior.
3. Use a feature branch; do not edit production server files.
4. Never add tokens, passwords, customer data, Moodle `config.php` or `moodledata`.
5. For code changes, check RU/EN string parity and Moodle 4.0 PHP constraints.
6. For a release update version and final savepoint monotonically; do not bump
   `release` from rc29 or approve RC30 without server evidence.

## Required checks
- `python tools/dev_ai_gate.py`
- `python tools/test_dev_ai_tools.py`
- `python tools/test_dev_ai_cycle.py`
- `python local/kbacompany/cli/check_upgrade_sequence.py`
- `python local/kbacompany/cli/check_xmldb_schema_names.py`
- `python local/kbacompany/cli/check_php81_compat.py`
- `python local/kbacompany/cli/check_rc29_regression_gate.py`
- PHP syntax lint on actual supported PHP version (8.1 in target deployment)
- Additional domain tests and Moodle PHPUnit/Behat on staging before approval.

## Release rules
Create candidate ZIP through `python tools/build_install.py --output artifacts/INSTALL.zip`.
Never claim a live install, real PHPUnit, production acceptance, or approval
without evidence. Update release notes, tests, checksum and human sign-off.

## Infrastructure and distribution (1874)
- GitHub Actions builds **candidate** archives only. No CI job has production
  credentials or writes to the production Moodle instance.
- `tools/build_install.py` excludes development-only Python `.py` files from
  the production Moodle ZIP; keep them in the Git source repository.
- `tools/verify_install.py` verifies the install archive and its manifest.
- Timeweb staging preparation is manual and requires independent server review.


## Machine dates and timezone contract (build1876)

- For DB date keys, date controls, calendar projections, task SLA or 
  HTML `datetime-local` values, call `business_date_service::day()`,
  `::datetime_local()` or `::yesterday()`; never use localized `userdate()` output.
- The prior `battleplan_date` incident (Nov 2 2026) is covered by both 
  `tools/test_battleplan_date_1875.py` and `tools/test_business_dates_1876.py`.
- Do not alter existing human-facing `userdate()` labels; keep the domain's
  strict `YYYY-MM-DD` validators and timezone-aware `core_date` semantics.

## GitHub review candidate (build1878)

- Every PR should pass `tools/check_workflow_policy.py` and `tools/test_workflow_policy_1878.py`.
- CI artifacts can be generated for PR review, but are NOT authorized production releases.
- Keep remote `main` unchanged until source-history reconciliation, CI, Moodle staging and human approval.
