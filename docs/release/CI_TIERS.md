# BMS24 CI tiers: FAST / STANDARD / FULL (RC31 Pilot 03, Issue #82)

Implements `CI-TIERS-PROPOSAL.md` (RC31 Pilot 02). Stage 1: the new tiers run **in addition to** all existing workflows;
required checks, branch protection and the release policy are unchanged until the owner approves a new standard.

## Classification (`tools/ci/classify_change.py`)

Input: `git diff base..head`. Output: profile, changed modules, focused PHPUnit, mandatory security gates, reasons.
Highest profile wins; **unknown paths = FULL**.

| Profile | Triggers |
|---|---|
| FULL (by path) | `db/*`, `version.php`, `lib.php` (pluginfile), `settings.php`, `classes/privacy|external|task|event`, `*access*`, `*permission*`, `membership*`, company context / identity registry, integrations (Bitrix24, Odoo, public/cloud API), `local/kbacorporate/*`, `theme/*`, `.github/*`, `tools/tests/*`, `tools/build_*`, `tools/check_*`, `release/*`, any unknown path |
| FULL (by added/removed lines) | `$_FILES`, File API / pluginfile, capability/login checks, sesskey, domain authorization helpers (`can_*`, `require_participant`, `is_revoked`…), raw SQL, outbound HTTP, dangerous primitives, schema/upgrade calls — **adding or removing** such a line is FULL |
| STANDARD | other PHP / JS / Mustache / tests / CLI in `local/kbacompany` |
| FAST | CSS, `lang/*`, `pix/*`, Markdown/docs, images |

Verified on the completed releases: PR #80 (CSS) → **FAST**; PR #81 (attachments) → **FULL** (`$_FILES`, File API in
`communication_service.php`, `tasks.php`, `communication_center.php`, …) — never STANDARD just because it has no migration.

## Workflow `.github/workflows/ci-tiers.yml`

| Job | What | Target |
|---|---|---|
| Plan | targets = this PR; when `tools/ci/**` or this workflow changes, also replays PR #80 and PR #81 | seconds |
| FAST | classifier (+ unit tests), `git diff --check`, PHP 8.1 lint, JS syntax, AMD src/build sync, EN/RU parity, CSS brace + BMS UI scoping, deterministic ZIP from `git archive` with source identity (every entry == Git blob) and SHA256 | 1–3 min |
| STANDARD | runs for STANDARD and FULL: fresh Moodle 4.1.22 PHPUnit site (MariaDB, composer vendor cached, **no historical upgrades**), focused PHPUnit + security gates (`pr48_tenant_isolation`, `membership_revocation`, `rc30_integrated_access`, `task_membership_regression`, `privacy_provider`) | 5–10 min |
| BROWSER | runs for STANDARD and FULL in parallel with STANDARD: fresh Moodle 4.1.22 (MariaDB) + candidate plugins, PHP built-in server, synthetic companies A/B, Chromium with outbound requests blocked. 21 checks: task-tab keyboard focus; attachments in the compact task discussion and Communication Center (text, text+file, file only, double submit, invalid sesskey, size limit at `upload_max_filesize=64K`, no orphan files); protected download for participant / other company / anonymous / revoked / reopened | ~3.5 min |
| FULL | unchanged existing workflows: PR48 Moodle acceptance (MariaDB + Percona, historical upgrades, PHPUnit, schema parity), ADR-0004, Corporate2613 repaired/retained, BMS24 Dev AI checks (required) | 25–40 min |

Every job writes a summary with PASS/FAIL/NOT RUN and per-step timing, and uploads JSON evidence. NOT RUN is never PASS.

## Not yet implemented

- Percona in STANDARD (FULL keeps it).
- Cached pre-installed PHPUnit database (init still installs from scratch each run).
- Required-check / branch-protection changes — owner decision (see Issue #82).
