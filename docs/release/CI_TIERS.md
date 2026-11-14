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

## Merge gate (RC32, dependent on the BROWSER hardening PR)

Job **`Merge gate`** in `BMS24 CI tiers` (`tools/ci/merge_gate.py`, tests `tools/ci/test_merge_gate.py`) always runs and gives one
verdict for the PR head:

| Profile | Required for merge | FULL |
|---|---|---|
| FAST (docs, lang, pix; CSS until PR #92 decides) | FAST PASS | observation only |
| STANDARD (application code without FULL triggers) | FAST + STANDARD (every selected test PASS) + BROWSER on MariaDB **and** Percona (all required checks) | observation only |
| FULL (DB/upgrade/version, ACL/permissions/membership, File API/pluginfile/`$_FILES`, sesskey, raw SQL, external API, Corporate, CI/test infra, unknown path) | FAST + STANDARD + BROWSER + **all FULL workflows `success` on the same SHA** | **required** |

Fail closed: invalid/missing classification, missing report, FAIL, NOT RUN, cancellation or unexpected skip blocks the merge.
NOT APPLICABLE only by the rules in the table. Small diff size never lowers the profile.

### RC32 review hardening (Issue #82, comment 6872172812)

- **Job outcomes.** The gate reads the job conclusions of its own run attempt through the Actions API. `Plan targets`,
  `FAST / this-pr`, `STANDARD / this-pr`, `BROWSER / this-pr / MariaDB 10.11.19` and `BROWSER / this-pr / Percona 8.0.46-37`
  must be `success` for every profile (for FAST they finish quickly with an explicit NOT APPLICABLE step). A job that uploaded
  a PASS report and then failed, was cancelled, skipped or is missing blocks the merge. If the API cannot be read: FAIL.
- **Job roles.** `this-pr` = the candidate (required). `negative-*` (planted defects, only planned when the tier machinery
  changes) = required when planned: the job succeeds only if the defect was detected. `replay-*` = information only; reported
  under `information`, never part of the verdict.
- **Identity.** Every FAST/STANDARD/BROWSER artifact carries `identity.json` (`tools/ci/stamp_identity.py`): tier, target,
  head, base, profile, engine, run id/attempt, job and SHA256 of the report bytes. The gate compares it with the PR event
  head/base and this run id, the classification head/base with the event, and re-hashes the downloaded report.
- **BROWSER completeness.** A BROWSER report must contain every name from `contract.required_check_names()` (40) with PASS,
  carry the expected `dbtype` and overall PASS (40 checks since the Codex review); a report with an unrelated single PASS is incomplete.
- **FULL selection.** Per FULL workflow *file* (`pr48-moodle-acceptance.yml`, `rc30-schema-repair.yml`,
  `rc30-corporate2613-repair.yml`, `bms24-ci.yml`) the newest `pull_request` run for the same head SHA, PR number and base SHA
  at event time is used; its latest attempt must be `success`. An older successful run never masks a newer failure,
  cancellation or unfinished run. The retained Corporate2613 workflow (historical expected FAIL) is not part of FULL.
- **Browser errors (Codex review P1).** The attachment and oversize scenarios record uncaught page exceptions and console
  errors for every phase, also on failure; `attachments_no_browser_errors` and `oversize_no_browser_errors` FAIL on any
  error except `net::ERR_FAILED` console lines for external requests aborted by the loopback route (same rule as task tabs).
  A missing error list is a FAIL. Runtime negative control: `negative-browser-page-error`. BROWSER now has 40 required checks.
- **Re-run provenance (Codex review P2).** Artifacts are named `...-a<run_attempt>`. For every required job the gate computes
  the attempt in which it actually executed (on *Re-run failed jobs* GitHub copies successful jobs into the new attempt with
  the original `started_at`; verified on runs 43561610462 and 43561610550). It reads only that attempt's artifact and requires
  `identity.run_attempt` = that attempt and `identity.job` = the producing job (`fast`/`standard`/`browser`). Reused successful
  jobs are accepted with this provenance; a stale report from a failed or superseded execution is not.
- **Limitations.** The gate script is executed from the PR merge commit, so a PR can change the gate itself; such PRs touch
  `tools/ci/` and are therefore always FULL. Request scoping to `127.0.0.1:8080` is not OS-level network isolation.

**Rollback of the CI change:** revert the merge commit of the RC32 review PR (CI files only); no data or schema involved.

**Merge ≠ production.** A green gate allows merging to `main` only. Every production installation still requires: release
artifact identity (Git SHA + ZIP SHA256 built in CI), staging/clone acceptance, backup + rehearsed rollback, release card and
explicit owner approval of the exact build, server and window.
