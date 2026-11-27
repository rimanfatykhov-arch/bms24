# BMS24 CI tiers: FAST / STANDARD / FULL (RC31 Pilot 03, Issue #82)

GitHub runs checks according to PR risk. The three required GitHub statuses remain Merge gate, checks and Retained PHP 7.4 syntax compatibility.

## Classification (`tools/ci/classify_change.py`)

Input: `git diff base..head`. Output: profile, changed modules, focused PHPUnit, mandatory security gates, reasons.
Highest profile wins; **unknown paths = FULL**.

| Profile | Triggers |
|---|---|
| FULL (by path) | `db/*`, `version.php`, `lib.php` (pluginfile), `settings.php`, `classes/privacy|external|task|event`, `*access*`, `*permission*`, `membership*`, company context / identity registry, integrations (Bitrix24, Odoo, public/cloud API), `local/kbacorporate/*`, `theme/*`, `.github/*`, `tools/ci/*`, `tools/tests/*`, `tools/build_*`, `tools/check_*`, `release/*`, any unknown path |
| FULL (by added/removed lines) | `$_FILES`, File API / pluginfile, capability/login checks, sesskey, domain authorization helpers (`can_*`, `require_participant`, `is_revoked`…), raw SQL, outbound HTTP, dangerous primitives, schema/upgrade calls — **adding or removing** such a line is FULL |
| STANDARD | other PHP / JS / Mustache / tests / CLI in `local/kbacompany` |
| FAST | CSS, `lang/*`, `pix/*`, Markdown/docs, images |

Verified on the completed releases: PR #80 (CSS) → **FAST**; PR #81 (attachments) → **FULL** (`$_FILES`, File API in
`communication_service.php`, `tasks.php`, `communication_center.php`, …) — never STANDARD just because it has no migration.

## Workflow `.github/workflows/ci-tiers.yml`

| Job | What | Target |
|---|---|---|
| Plan | targets = this PR; when `tools/ci/**` or this workflow changes, adds the seeded-defect controls | seconds |
| FAST | classifier (+ unit tests), `git diff --check`, PHP 8.1 lint, JS syntax, AMD src/build sync, EN/RU parity, CSS brace + BMS UI scoping, deterministic ZIP from `git archive` with source identity (every entry == Git blob) and SHA256 | 1–3 min |
| STANDARD | runs for STANDARD and FULL: fresh Moodle 4.1.22 PHPUnit site (MariaDB, composer vendor cached, **no historical upgrades**), focused PHPUnit + security gates (`pr48_tenant_isolation`, `membership_revocation`, `rc30_integrated_access`, `task_membership_regression`, `privacy_provider`) | 5–10 min |
| BROWSER | runs for FULL in parallel with STANDARD: fresh Moodle 4.1.22 (MariaDB and Percona) + candidate plugins, PHP built-in server, synthetic companies A/B, Chromium with outbound requests blocked. Scenarios cover task-tab keyboard focus; attachments in the compact task discussion and Communication Center (text, text+file, file only, double submit, invalid sesskey, size limit at `upload_max_filesize=64K`, no orphan files); protected download for participant / other company / anonymous / revoked / reopened | ~20–25 min |
| FULL | unchanged existing workflows: PR48 Moodle acceptance (MariaDB + Percona, historical upgrades, PHPUnit, schema parity), ADR-0004, repaired Corporate2613, BMS24 Dev AI checks (required) | 25–40 min |

Every job writes a summary with PASS/FAIL/NOT RUN and per-step timing, and uploads JSON evidence. NOT RUN is never PASS.

## Not yet implemented

- Percona in STANDARD (FULL keeps it).
- Cached pre-installed PHPUnit database (init still installs from scratch each run).
- Required-check / branch-protection changes — owner decision (see Issue #82).

## Merge gate

Job **`Merge gate`** in `BMS24 CI tiers` (`tools/ci/merge_gate.py`, tests `tools/ci/test_merge_gate.py`) always runs and gives one
verdict for the PR head:

| Profile | Required for merge | FULL |
|---|---|---|
| FAST (docs, lang, pix; CSS until PR #92 decides) | FAST PASS | not scheduled |
| STANDARD (application code without FULL triggers) | FAST + STANDARD (every selected test PASS) | not scheduled |
| FULL (DB/upgrade/version, ACL/permissions/membership, File API/pluginfile/`$_FILES`, sesskey, raw SQL, external API, Corporate, CI/test infra, unknown path) | FAST + STANDARD + BROWSER + **all FULL workflows `success` on the same SHA** | **required** |

Fail closed: invalid/missing classification, missing report, FAIL, NOT RUN, cancellation or unexpected skip blocks the merge.
NOT APPLICABLE only by the rules in the table. Small diff size never lowers the profile.

### RC32 review hardening (Issue #82, comment 6872172812)

- **Job outcomes.** The gate reads the job conclusions of its own run attempt through the Actions API. `Plan targets`,
  `FAST / this-pr` and `STANDARD / this-pr`. FULL also requires both BROWSER jobs
  must be `success` for every profile (for FAST they finish quickly with an explicit NOT APPLICABLE step). A job that uploaded
  a PASS report and then failed, was cancelled, skipped or is missing blocks the merge. If the API cannot be read: FAIL.
- **Job roles.** `this-pr` = the candidate (required). `negative-*` (planted defects, only planned when the tier machinery
  changes) = required when planned: the job succeeds only if the defect was detected. The historical #80 and #81 replays
  were pilot evidence and are no longer scheduled in PR CI.
- **Identity.** Every FAST/STANDARD/BROWSER artifact carries `identity.json` (`tools/ci/stamp_identity.py`): tier, target,
  head, base, profile, engine, run id/attempt, job and SHA256 of the report bytes. The gate compares it with the PR event
  head/base and this run id, the classification head/base with the event, and re-hashes the downloaded report.
- **BROWSER completeness.** A BROWSER report must contain every name from `contract.required_check_names()` (130) with PASS,
  carry the expected `dbtype` and overall PASS (130 checks: 42 before + 7 message-action + 7 task-opened + 8 Communication Center + 7 daily-reports entry + 13 daily reports R1-R4/summary/discussion + 5 Battle Plan discussion + 4 Communication Center UX-1/UX-2/UX-3 fixed frame/UX-4 stable open conversation + 6 day feed + 8 notification bell + 16 adaptive navigation + 7 Information Centre checks); a report with an unrelated single PASS is incomplete.
- **FULL selection.** Per FULL workflow *file* (`pr48-moodle-acceptance.yml`, `rc30-schema-repair.yml`,
  `rc30-corporate2613-repair.yml`, `bms24-ci.yml`) the newest `pull_request` run for the same head SHA, PR number and base SHA
  at event time is used; its latest attempt must be `success`. An older successful run never masks a newer failure,
  cancellation or unfinished run. The gate also requires success from both database jobs in each FULL acceptance workflow. Historical Corporate2613 and the STANDARD shadow benchmark are available through manual runs.
- **Browser errors (Codex reviews P1 and #96 comment 6873684005).** Every browser context uses `tools/ci/browser/events.py`:
  the test route lets only `http://127.0.0.1:8080` through and aborts every other request with the distinct code
  `blockedbyclient`, recording `{source: route_abort, url, resource_type, reason}`; every page records `requestfailed`
  `{url, resource_type, failure}`, `pageerror` and console errors `{text, url}`. **Policy** (`contract.browser_event_violations`):
  the only tolerated errors are, per recorded `route_abort` of an **expected** external URL
  (`EXPECTED_BLOCKED_EXTERNAL`: exactly `https://cdn.jsdelivr.net/npm/mathjax@2.7.9/MathJax.js?delayStartupUntil=configured`,
  the Moodle MathJax loader - the only external request observed in run 43571513719 on both engines), at most one
  `requestfailed` with exactly `net::ERR_BLOCKED_BY_CLIENT.Inspector` and one console line exactly
  `Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector` located at that URL (values observed with Playwright 1.49.1).
  Everything else FAILs: any `net::ERR_FAILED`, any failed request to 127.0.0.0/8, ::1, localhost or 0.0.0.0, an abort of an
  unexpected external URL, a similar-but-different URL, consequences without (or in excess of) abort records, other codes or
  prefixes, pageerror, any other console error, legacy or malformed events, a missing event list. No blanket exceptions.
  Negative-control patches are materialized with `git add -A` so that patches creating files are applied completely. Checks: `task_tab_desktop_no_browser_errors`,
  `task_tab_mobile_no_browser_errors` (re-evaluated from events, producer status not trusted), `attachments_no_browser_errors`,
  `oversize_no_browser_errors`, `access_no_browser_errors`. Runtime negative controls: `negative-browser-page-error`
  (pageerror) and `negative-browser-failed-request` (a local script on 127.0.0.1:8080 whose response is cut short; the failed
  request must be the reported cause). BROWSER has 130 required checks (incl. 7 for editing/withdrawing task-discussion messages, 7 for the task-opened History event, 8 for the Communication Center cleanup, 7 for the daily reports entry, 13 for saved daily reports R1-R4, the monthly summary and the report discussion, 5 for the Battle Plan discussion, 4 for the Communication Center UX-1 layout, UX-2 primary action geometry, UX-3 fixed frame (`communication_ux3_fixed_frame`) and UX-4 stable open conversation (`communication_ux4_stable_thread`), 6 for the day feed, 8 for the notification bell, 16 for the adaptive BMS24 navigation (`adaptive_nav.py`) and 7 for the Information Centre (`infocenter.py`)).
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
