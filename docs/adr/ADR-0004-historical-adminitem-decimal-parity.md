# ADR-0004 — Historical adminitem and DECIMAL schema parity

Status: **Proposed — owner decision required before remediation**. Date: 2026-11-10.
Scope: Issue #44, RC30; follows ADR-0002/0003. RC30 remains **HOLD**.

## Evidence and limits

PR #64, source `511d8f4c765abd4f0901595e74d2124154e2b8d4`, records the read-only
2026-11-09T20:55:42Z inventory in
[`RC30_PRODUCTION_SCHEMA_DIFF.json`](../deploy/RC30_PRODUCTION_SCHEMA_DIFF.json).
Raw metadata SHA256: `a507ff28108d4987e4198df108f4407df5b7d896e1983b3f89db03ebd9d6dbf4`.
The inventory is metadata, not a data-range audit or a restorable database backup.
No new staging/production access is needed or authorized by this ADR/test task.

The observed BMS version is 2026101878. Candidate 2026110906 does not repair:

| Table suffix | Fields | Observed type | Fresh contract |
|---|---|---|---|
| adminitem | starttime, duetime | absent | varchar(5), NOT NULL, DEFAULT '' |
| finbudfact | actualamount | decimal(18,0) | decimal(18,2) |
| finbudline | planamount, limitamount | decimal(18,0) | decimal(18,2) |
| finline | amount | decimal(18,0) | decimal(18,2) |
| statbind | weight | decimal(8,0) | decimal(8,3) |
| statnorm | targetvalue, minval, maxval | decimal(18,0) | decimal(18,6) |
| statpolicy | dangerdecline, affluencegrowth, powerlevel | decimal(8,0) | decimal(8,3) |

All table names start `local_kbacompany_`. Historical table creation and subsequent
adminitem upgrades do not add those time fields. Some historical NUMBER declarations
pass scale as the unsigned argument instead of combined precision/scale length.
That is a plausible source of scale-zero columns, not proof of how this production
database acquired its state. Rewriting old migrations cannot fix checkpoints already
passed. Current 0890/0900 repairs cover task time fields, deviation.executionstatus
and the ordered task index, not these thirteen differences.

## Proposed decisions

1. Add absent adminitem time fields in a **new reachable checkpoint greater than
   2026110906**, after approval and dependency/order review. Preserve existing values;
   validate existing definitions first and stop on incompatibility. Use XMLDB CHAR
   length 5, NOT NULL, empty default represented according to the driver convention.
   Do not infer missing historical times or replay old savepoints.
2. Choose the DECIMAL policy explicitly before implementation:
   - **Recommended: retain the fresh contract**, preflight all eleven fields and all
     existing values before the first DDL, stop without conversion when any value or
     definition is incompatible. Preserve nullability/defaults and exact decimal strings.
   - Expand precision to `(20,2)`, `(24,6)`, `(11,3)` to preserve the former integer
     ranges. This changes the canonical schema and potentially API/calculation limits;
     requires an owner decision, install/upgrade alignment and domain validation review.
   - Defer conversion and retain HOLD pending evidence/decision.
3. No rounding, clipping, NULL substitution, row deletion or float-based range checks.
   The declared fixed-precision conversion shrinks integer digits 18→16, 18→12 or
   8→5. Existing values may exceed the new limits. An ALTER does not restore any
   fractional information previously lost; metadata cannot establish whether loss occurred.
4. A preflight failure must occur before any DDL for this repair group. MySQL-family
   DDL is not generally rollback-atomic: recheck postconditions before savepoint and
   support safe retry after partial DDL. Do not label application-code rollback a
   schema/data rollback; a verified backup/restore procedure is a later deployment gate.
5. Corporate 2613 vs 0901 and Corporate default differences remain separate blockers.
   Do not change Corporate authorization, defaults, company UUID/RBAC, Moodle Core,
   existing migration history, runtime version or release artifact in this proposal.

## Reproduction contract

`tools/tests/rc30_schema_drift.php` runs only in a new disposable local CI database.
It installs pinned Moodle with candidate BMS0906/Corporate0901, captures the fresh
schema, then constructs exactly the thirteen observed differences plus the known
deviation-field and ordered-index defects as positive controls. It seeds synthetic
integer boundary values under two company UUIDs **after** changing column definitions.
This is a metadata-derived reconstruction, not a clone or a full 1878/2613 installation.

The actual Moodle CLI upgrade from synthetic DB version1878 must repair both controls
while all thirteen target defects remain. Exact synthetic data and legacy indexes must
survive; repeat upgrade at the resulting0906 must leave schema/data unchanged. Each
field has its own result identifier. A green reproduction job means the defects were
confirmed; schema parity is explicitly **FAIL**, and release acceptance remains HOLD.
No tests are skipped or replaced in the existing full acceptance workflow.

Future repair tests must instead require strict fresh/upgrade parity, exact fraction
storage after conversion, preservation of compatible values, all-fields preflight
rejection for out-of-range/incompatible definitions, no savepoint after failure,
safe partial-DDL retry and unchanged two-company permissions. Those repair/ACL tests
are not implemented or claimed by the present reproduction task.

## Rollback and acceptance

This change adds documentation and isolated test tooling only. Close/revert its PR
to remove it; no deployed schema or business data requires rollback. Test databases
and credentials exist only on the ephemeral runner. Do not execute fixture modes on
an existing Moodle installation. No production/staging changes or PR merge.

Owner acceptance of a DECIMAL option is required before remediation; it is not an
authorization to deploy. Full regression, exact source/artifact identity, Corporate
compatibility, backup/restore and deployment approval remain independent gates.
