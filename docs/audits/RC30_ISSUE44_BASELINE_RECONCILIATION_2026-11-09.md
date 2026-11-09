# RC30 P0 — baseline reconciliation audit (Issue #44)

Date: 2026-11-09. **Preliminary static GitHub audit, NOT baseline acceptance.**

## Sources and identity

- `main`: `local/kbacompany/version.php` = `2026101645`, release `1.1.0-rc29`. `upgrade.php` = 261 savepoints (last `2026101645`), `install.xml` = 136 TABLEs, XML schema version `2026101636`.
- `import/build2026101879`: commit `eccc1eb221df90675067b1011e986702d96a4985`; version `2026101879`, still release string `1.1.0-rc29`. `upgrade.php` = 459 savepoints (last `2026101879`), `install.xml` = 142 TABLEs, schema version `2026101863`.
- GitHub compare `main...import/build2026101879`: 11 commits ahead, 0 behind. PR #8 has **2,729 file changes**, including deletion of many `docs/`, `.gitattributes`, `.github/workflows/prepare-release.yml`, and other repository infrastructure. **Do not merge #8 as-is**.
- In the imported `upgrade.php` scan: 198 additional unique savepoints after `2026101645`; none of the original savepoint numbers disappeared; no duplicate or descending savepoint numbers detected. **This does not prove equivalence of existing migration bodies, install.xml consistency or successful Moodle upgrade.**
- Imported `task_rc30_acceptance_service::EXPECTED_PLUGIN_VERSION = 2026101873` conflicts with imported `version.php = 2026101879`. Not yet corrected. A hardcoded equality gate should be reviewed for explicit stable version policy, never weakened to unbounded acceptance.
- Diagnostic acceptance assumes PHP 8.1 + MySQL 5.7. The parity41 template uses PHP 8.1 + MariaDB 10.11. Distinct certification profiles; acceptance must identify true DB product/version. `local_kbacompany` minimum Moodle build is `2022041900` (4.0), NOT proof of full runtime compatibility.

## Product / governance

- Product Vision (draft PR #41) defines four streams: Role Intelligence, BMS AI Core, AI Studio, BMS24.online.
- Governance ADR-0001 (draft PR #43) was accepted by Product Owner, but not merged into `main`. It requires discussion → ADR / Issue → Codex PR / QA → staging acceptance → explicit production approval.
- PR #38 R1 is based on old `main`; PR #39 R2 depends on #38. Rebase/cherry-pick only after canonical RC30 baseline decided, preserving published revisions and company scoping.
- Issue #45 DevOps pipeline follows #44. The stage41 infrastructure PR chain (#35/#36/#54) is useful preparatory staging work, NOT evidence of application baseline, production readiness or a substitute for #44.

## Blockers (priority)

| Priority | Evidence | Required decision/gate |
| --- | --- | --- |
| P0 | PR #8 unexpectedly removes release workflow and architecture docs | Classify all deletions; preserve approved governance, CI, scripts, baseline history; construct a *reconciled* branch, not a wholesale merge |
| P0 | `EXPECTED_PLUGIN_VERSION=2026101873` vs `version.php=2026101879` | Review explicit RC30 acceptance version rule and test mismatch/future version rejection |
| P0 | No verified fresh install / upgrade 1645→1879 on Moodle | Run backup/restore, DB migration and ACL A/B tests in isolated staging |
| P0 | New parity41 HTTPS/Basic Auth not accepted | Finish infrastructure gates before exposing Moodle; no external unprotected Moodle 4.1 |
| P1 | `install.xml` table count 136→142, schema version 1636→1863 | Review all six additions, DB indexes, constraints and exact migration bodies |
| P1 | MySQL 5.7 diagnostics versus MariaDB 10.11 parity | Formal environment matrix and distinct verdicts |
| P1 | R1/R2 depend on old `main` | Reconcile baseline and then rebase feature PRs with tests |
| P1 | Documentation stale about build1642 | Update after base decision with actual SHA/build, not speculation |

## Proposed branching sequence — pending owner approval

1. Keep `main` protected at 1645 while auditing.
2. Review/merge only product-context and governance docs in sequence PR #41 → #43 after architecture review (no deployment).
3. Create a dedicated `integration/rc30-reconciled` **from up-to-date main**. Selectively port audited import changes, retaining repository workflows/docs and original migration invariants. Avoid merging full destructive PR #8.
4. Fix explicit version diagnostics in a separate small PR on the reconciliation branch with regression tests.
5. Verify fresh install and upgrade to proposed version on controlled isolated Moodle environment; record actual PHP/DB versions, index/upgrade consistency, company A/B, backup/restore, UI/cron tests.
6. Port R1 then R2 onto the accepted RC30 base; complete their staging QA.
7. Begin Issue #45 pipeline only after the baseline gate; no automated production deployment.

**Do not rebase/merge PR #8 or R1/R2 or activate server services automatically.** This document proposes a plan, not approval.

## Test evidence (2026-11-09)

- PASS: GitHub metadata PR #8/#38/#39/#41/#43; static version.php checks.
- PASS (limited): comparing numeric upgrade savepoints in GitHub file contents: 261→459, 198 new, no missing/duplicate/descending IDs.
- PASS (limited): compare API ahead=11, behind=0; destructive diff evidence from PR #8.
- FAIL (static discrepancy): RC30 diagnostic constant does not match plugin version.
- NOT RUN: byte-by-byte existing migration equivalence, schema/index full diff, PHP lint, PHPUnit, Behat, Moodle install and upgrade, runtime DB, company isolation, Timeweb checks, browser UI, backup/restore.
- NOT VERIFIED: actual installed plugin build on edu.kzn.mba, test.bms24.online or stage41.bms24.online.

## Review gate

Issue #44 remains **OPEN / incomplete**. Do not label RC30 accepted, do not merge #8 and do not declare staging available until proof and product-owner approval.
