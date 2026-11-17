# Corporate defaults per ADR-0005 (fix for PR #124 finding P1)

Accepted decision: [ADR-0005](../adr/ADR-0005-corporate2613-schema-policy-PROPOSED.md) (owner, 2026-11-10) — new
omitted-field inserts use `local_kbacorp_member.isemployee` DEFAULT 0 and `local_kbacorp_devplan.status` DEFAULT
`draft`. No new business decision is involved.

## Two Corporate trees

| Tree | Role | ADR-0005 before this PR |
|---|---|---|
| `release/corporate2613` (patch on the production-verified Corporate2613 source, 0.40.13-bms.2, 2026111100) | Production Corporate prerequisite | Fresh `install.xml` DEFAULT 0 **and** upgrade step 2026111004 `local_kbacorporate_adr0005_defaults()` |
| `local/kbacorporate` (0.40.13-bms.3, 2026111100) | Corporate installed by CI next to the BMS candidate | Fresh DEFAULT 1, **no** 2026111004 step → historical upgrades keep 1 |

The BMS release package installs only `local_kbacompany` (`tools/acceptance/pr90/package.py`: "Corporate is a tested
prerequisite, not included in this ZIP"). Production runs the Corporate2613-derived 2026111100, which already has
DEFAULT 0. This PR changes nothing on production and installs no other Corporate.

## Defect and the first fix attempt

PR #124 found fresh DEFAULT 1 vs ADR-0005. The first commit of this PR changed only `install.xml`. Full CI then showed
that the repository tree's upgrade path keeps DEFAULT 1 for historical databases (PR48 candidate-schema: "column
'isemployee' has default '1', expected '0'", 13 historical sources × 2 engines). Changing only the fresh definition would
have moved the divergence, not removed it.

## Change

- `local/kbacorporate/db/install.xml`: `isemployee` DEFAULT 1 → 0; byte-identical to the Corporate2613 package file
  (SHA-256 `6b90b627…`).
- `local/kbacorporate/db/upgrade.php`: function `local_kbacorporate_adr0005_defaults()` and the step
  `if ($oldversion < 2026111004)` ported byte-for-byte from `release/corporate2613/compatibility.patch` (same
  savepoint). It validates both column definitions (Moodle and native) before any DDL, changes only the default with
  native `ALTER COLUMN … SET DEFAULT` (indexes untouched; Moodle 4.1 XMLDB refuses default changes on indexed columns),
  checks the postcondition before the savepoint, and does no DML. Already-correct defaults are a no-op.
- No version change (`local/kbacorporate` stays 2026111100): every real installation runs the Corporate2613 package
  and already passed its 2026111004 step; the repository tree's historical sources in CI are below 2026111004.
- `docs/reconcile/corporate-api126-source.json`: both files marked `modified` with exact before/after hashes;
  new hashes chained in `tools/check_pr53_pr56_integration.py`. Evidence:
  `corporate-fresh-adr0005-source-preservation.json`.
- Regression tests:
  - `local/kbacompany/tests/corporate_member_default_adr0005_test.php` (fresh PHPUnit install; in STANDARD):
    column default `0`, omitted → 0, explicit 0 → 0, explicit 1 → 1, `devplan.status` default `draft`.
  - `tools/tests/test_corporate_adr0005_convergence.py`: fresh defaults, function identical to the package, step order
    and savepoint, no DML in the step. Fails on `main` (DEFAULT 1, no step).
  - PR48 candidate-schema after every historical upgrade, on MariaDB and Percona, compares the upgraded schema with
    `install.xml` (no exclusions).

Existing rows, IDs, memberships, plan statuses, indexes and authorization are unchanged.

## Still required (owner / Codex, not done here)

Merge by owner decision; repeat the #124 fresh/historical structural comparison and the 8-way concurrency probe; owner
review on dev; Codex release with a fresh verified backup.
