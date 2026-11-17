# Corporate fresh default per ADR-0005 (fix for PR #124 finding P1)

Accepted decision: [ADR-0005](../adr/ADR-0005-corporate2613-schema-policy-PROPOSED.md) (owner, 2026-11-10) — new
omitted-field inserts use `local_kbacorp_member.isemployee` DEFAULT 0 and `local_kbacorp_devplan.status` DEFAULT
`draft`. No new business decision is involved.

## Defect

`local/kbacorporate/db/install.xml` declared `isemployee DEFAULT="1"`. The upgrade path
(`local/kbacorporate/db/upgrade.php`, field added with default `'0'`) and the production Corporate2613 package
(`release/corporate2613`, patched `install.xml`) both give 0. A fresh install and an upgraded database therefore stored
different values for an insert without `isemployee`. Inherited; not introduced by ADR-0009 R1/R2.

## Change

- `local/kbacorporate/db/install.xml`: `isemployee` DEFAULT 1 → 0. The file is now byte-identical to the
  Corporate2613 package `db/install.xml` (SHA-256 `6b90b627…`, listed in `release/corporate2613/manifest.json`).
- No version bump: `install.xml` is read only on a fresh install; upgraded databases already have DEFAULT 0, and the
  existing upgrade steps are unchanged. No DML, no index change, no authorization change, existing rows untouched.
- `docs/reconcile/corporate-api126-source.json`: entry marked `modified` with exact before/after hashes, recorded in
  `local_patch.files` and `local_patches`; its own new hash is chained in `tools/check_pr53_pr56_integration.py`.
  Evidence: `corporate-fresh-adr0005-source-preservation.json`.
- Regression: `local/kbacompany/tests/corporate_member_default_adr0005_test.php` runs on the fresh PHPUnit install:
  column default `0`, omitted → 0, explicit 0 → 0, explicit 1 → 1; `devplan.status` default stays `draft`.
  Added to the fixed STANDARD allowlist. The red reproduction in PR #124 (`test_corporate_fresh_adr0005.py`) is
  expected to turn green on this tree.

## Still required after this change (owner/Codex, not done here)

Repeat the fresh/historical structural comparison on both engines with the #124 harness, the 8-way concurrency
probe, owner review on dev, then a Codex release with a fresh verified backup. Merge and deployment are separate decisions.
