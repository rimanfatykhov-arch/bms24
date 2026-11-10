# Selective Corporate2613 repair in PR69

## Current ADR-0005 candidate

Version2026111004 /0.40.13-bms.2 supersedes the1003 code-only candidate described
below. Owner accepted defaults0/draft for future omitted-field inserts. Existing
data and both historical duplicate index pairs remain intact. Fresh XMLDB and a
new prevalidated/idempotent XMLDB upgrade checkpoint implement this decision.
Three additional PHPUnit cases cover defaults, explicit values, owner/pending
state and migration preflight/preservation/repeat. Full physical index/column
and all-table Corporate data evidence are required by the repaired profile.
Only two exact historical extra indexes are permitted by the schema comparator;
no DB index is dropped. See accepted ADR-0005 for policy and release HOLD gates.

The following1003 description remains history of the cumulative code repair.

This source candidate is based on the exact830-file production-verified Corporate
commit `687c230595bce020fc001db91b3aac276f0fee7d`. It is NOT the canonical0901
diagnostic plugin in `local/kbacorporate`, and must never be replaced by that tree.

`compatibility.patch` and `manifest.json` define the complete reviewed delta.
`python tools/build_corporate2613.py --output NEW_DIRECTORY` verifies every base
file, applies the strict patch, verifies every resulting file, and produces a
deterministic separate Corporate ZIP and identity. Existing directories are refused.
No files are deleted or added to Corporate:830 before and after. All2613-specific
board, curator paging, task discussion/projection features remain intact.

Runtime fixes: nullable ERP mapping, MySQL reserved SYSTEM identifier handling,
complete Moodle name projections, valid sectioncode `0`, and removal of a normal
role-merge success developer warning. Company/provider filters, bindings, role
merging, capability definitions and authorization checks are unchanged.

Tests: correct generator timestamps, by-reference variables, API1.26 expectations,
numeric privacy-metadata adapter, valid business-role code and real manager actor
fixtures. Preserve all existing test IDs and assertions. Three standalone projection
scripts become genuine PHPUnit cases, with every original assertion executed.
Bring forward additional ERP isolation and name-projection regressions only.
The2613 paging fixture also violated the existing unique(itemid,routeid,deletionkey)
constraint once its by-reference error was fixed. Its22 extra tasks now use distinct
existing items in the same audit, with matching criterion/section snapshots. All10
original paging assertions remain, with one added fixture-size check; no index change.

Version2026111003 (`0.40.13-bms.1`) is greater than2613 and diagnostic0901. Its only
upgrade checkpoint is code-only: no DDL, data rewriting or permission changes.
This does not authorize installation over arbitrary feature trees; exact source
identity and the approved migration origin remain mandatory gates.

Acceptance profiles stay separate: diagnostic0901, immutable retained2613, and
repaired2613 (fresh + exact1878/2613 observed-metadata upgrade). The repaired profile
must load the materialized tree, verify all830 resulting hashes and prove Corporate
historical logical schema preservation (XMLDB-listed tables/fields/index structures;
not names/FK/triggers/engine/collation or real production data). Full fresh/upgrade
comparison is NOT waived.

Unresolved schema decision: production devplan.status default `active` versus fresh
`draft`, member.isemployee default `0` versus fresh `1`, and two additional historical
index structures. They remain explicit HOLD; no implicit change of membership or
plan lifecycle policy is made. Recovery/browser/rollback remain separately required.

Rollback in isolated acceptance: rebuild the exact retained2613 source and restore
the coherent pre-upgrade DB/files snapshot. Production rollback requires the approved
backup rehearsal and window; decrementing plugin version is not a recovery plan.
