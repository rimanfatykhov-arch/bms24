# BMS24 Dev AI — instructions for code agents (build2026101873)

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
