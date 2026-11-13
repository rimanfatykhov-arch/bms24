# RC30 — thirteen historical schema differences reproduced

Date: 2026-11-10. Issue #44; Draft PR #65, based on PR #64.
**Reproduction PASS; schema parity FAIL; release HOLD.** No remediation implemented.

## Identity and scope

- Base: `511d8f4c765abd4f0901595e74d2124154e2b8d4`.
- Tested source: `696213c01ed7e30a768c1c2dcd2abae7608e072d`.
- GitHub synthetic test merge: `11ccb31fa85014263d2d2485bb005cc271754204` (not a merge into any branch).
- BMS2026110906 / Corporate2026110901, unchanged source and versions.
- Moodle4.1.22 / PHP8.1.34. Pinned workflow sources and images are in
  `.github/workflows/rc30-schema-reproduction.yml`.
- [Proposed ADR-0004](../adr/ADR-0004-historical-adminitem-decimal-parity.md).
- [Machine-readable evidence, individual test IDs and file hashes](RC30_SCHEMA_REPRODUCTION_EVIDENCE.json).

This constructs the observed metadata drift in a fresh isolated candidate installation,
then sets its synthetic BMS DB version1878 and runs the genuine Moodle CLI upgrade.
It is not a full historical installation, production clone, real data-range audit,
Corporate2613 compatibility test or tenant authorization test. No production/staging
connection, data copy, deployment, merge or release publication occurred.

## Results

[Reproduction run42751792049](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42751792049).

| Check | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Fresh Moodle + candidate install | PASS | PASS |
| Fixture exposes1878 to plugin manager and upgrade detector | PASS | PASS |
| Actual CLI upgrade reaches0906 | PASS | PASS |
| adminitem.starttime/duetime still absent, individually checked | PASS reproduced, 2/2 | PASS reproduced, 2/2 |
| Eleven DECIMAL columns still scale0, individually checked | PASS reproduced, 11/11 | PASS reproduced, 11/11 |
| Exactly13 column differences across183 plugin tables | PASS | PASS |
| Positive controls: deviation field + ordered task index repaired | PASS | PASS |
|198 synthetic boundary rows,99 per company: exact values/UUIDs/IDs | PASS | PASS |
| Existing task indexes preserved, including reversed-order control | PASS | PASS |
| Repeat upgrade at0906: full schema snapshot and fixture data stable | PASS | PASS |
| Strict candidate schema parity | **FAIL,13 defects retained** | **FAIL,13 defects retained** |
| Full BMS/Corporate PHPUnit, browser/ACL, actual1878+2613 upgrade | NOT RUN | NOT RUN |

Artifacts: MariaDB `13733178816`; Percona `13733283596`. Each includes fresh/first/repeat
schema snapshots, environment identity, install/upgrade logs, exact synthetic rows,
row identity sets, index controls and per-field reproduction results. Independently
downloaded and checked:183 tables,13 exact column differences,198 rows split99/99,
first/repeat schema and result equality, first upgrade log actually enters BMS,
repeat log correctly reports no upgrade needed. No claim of full fresh/index parity:
task index controls are checked separately; the extra legacy index is intentional.

The DECIMAL fixtures cover zero, ±1, maximum in-range integers, nearest out-of-range
integers and the old full integer limits, always as strings. Other nullable fields
are also retained exactly. These are storage/migration fixtures inserted directly,
not evidence that domain services authorize such records or accept these boundaries.

[Static CI42751792249](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42751792249): PASS,
including existing gates and PHP7.4 retained plugin syntax. Local reconciliation gate,
541-file PHP lint,9094 RU/EN keys, XML and new harness lint: PASS. Non-CI invocation
refusal before DB access: PASS. Existing acceptance workflow and all plugin sources
are byte-unchanged relative to base.

Deterministic INSTALL ZIP remains1790 entries, SHA256
`afa36dead177ceb87b0a2833891823cc70980fed890992fcd04c2ebf5debd080`, identical to PR62.
No new runtime build number or approved release is produced.

## Failed fixture attempts retained as evidence

- Run42750812072 / source6b674049: FAIL at `upgrade.version0906`; CLI reported no
  upgrade required. Initial synthetic downgrade did not invalidate version metadata.
- Run42751331846 / source116ff024: FAIL at the same assertion. Resetting plugin-manager
  cache alone was insufficient. Moodle's `moodle_needs_upgrading()` checks the aggregate
  `allversionshash`, not installed DB plugin versions.
- Final fixture invalidates both caches **only in its new disposable DB**, asserts
  upgrade pending before CLI, then asserts lifecycle complete afterward. Core source,
  migration entrypoint, version assertions and defect/preservation checks were not bypassed.

The frozen Moodle implementation is available in
[moodlelib.php](https://github.com/moodle/moodle/blob/df1157bb845eabedd31989d6a1a6e18f638ff890/lib/moodlelib.php)
and [CLI upgrade](https://github.com/moodle/moodle/blob/df1157bb845eabedd31989d6a1a6e18f638ff890/admin/cli/upgrade.php).

Architecture/QA/security agents reviewed the initial proposal and harness. Their
suggestions led to symlink/dataroot checks, loopback-only DB port publication and exact
row identity-set assertions. Subsequent corrections were locally reviewed and verified
by the two-engine CI; no final independent approval or human acceptance is claimed.

## Remaining decisions and rollback

Approve a DECIMAL policy in ADR-0004 before implementing repair. Recommended: preserve
fresh precision with exact, all-fields fail-closed range/definition preflight before DDL.
New adminitem fields require a reachable new checkpoint; keep historical savepoints.
Next repair tests must reject unsafe ranges before mutation and prove fresh/upgrade
parity plus fractional storage, partial-DDL recovery and preservation of existing data.

Prior full-suite baseline remains618 BMS tests with19 unsuccessful and zero skipped;
Corporate0901 previously150/962 PASS. Those are prior results, not re-executed here.
Real Corporate2613 compatibility, operational backup/restore, HTTP/ACL/full sibling
stack and the remaining regression failures remain release blockers.

Rollback this change by reverting/closing the docs/tests PR; no deployed data changed.
Ephemeral runner storage is discarded by CI. Do not run fixture modes on an existing
Moodle instance. No merge, staging/production mutation or deployment authorization.
