# PR66 — approved ADR-0004 schema repair, BMS2026111001

2026-11-10. Continues Issue #44; stacked on PR65. **Draft; RC30 HOLD. No deployment/merge.**

## Identity and change

- Base PR65: `4e5482df3d5bd8fd4cc604c070ed8728e7c0fc17`.
- Tested source: `98ecf00a4c39299377f056f852c7ecc8b95ecd16`.
- Synthetic GitHub test merge: `79fa0d5170c7cdaeba816cb1427077f71be0c83b`; no branch was merged.
- BMS2026111001 / Corporate2026110901; Moodle4.1.22 / PHP8.1.34.
- [Accepted ADR-0004](../adr/ADR-0004-historical-adminitem-decimal-parity.md): preserve fresh precision,
  preflight every target definition and all DECIMAL values before group DDL, reject incompatibility.

New frozen helper `local/kbacompany/db/upgrade_schema_2026111001.php` adds missing
adminitem.starttime/duetime and repairs11 DECIMAL scale-zero definitions. Checkpoint1001
is greater than0906 and reachable from both1878 and the latest candidate. Only the
known legacy or final definitions are accepted: precision/scale, signed type, nullability,
default, generated/auto attributes, engine and strict SQL mode are checked. Bounds use
bound strings explicitly cast to DECIMAL, not PHP floats. Existing values are not
rounded, clipped, replaced, deleted or reassigned. Defaults remain semantically identical.

All13-field metadata and11-column range checks finish before any1001-group DDL.
Postconditions precede savepoint. A partial DDL failure withholds1001 and permits retry.
Historical checkpoints before1001 can already have committed during a1878 upgrade;
this is not an atomic rollback of the complete migration chain. All writers must be
quiesced for any future authorized run: strict mode does not eliminate concurrent writes.

Corporate, authorization, company UUID/RBAC, Moodle Core, install.xml and the old
upgradelib.php are unchanged. The old upgrade file is byte-identical after removing
only the appended1001 block. Diagnostic/version/artifact gates advance coherently.
Two old PHPUnit assertions now expect terminal1001 instead of0906; all other assertions
and test IDs are retained. The legacy standalone parity double models later fields as
already matching independent install.xml; all40 old cases/371 assertions remain.

## Specialized repair acceptance

[Run42754435728](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42754435728).
Artifacts MariaDB13734414734 / Percona13735570845 independently downloaded/checked.

| Check | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Fresh install; synthetic1878 →1001 using actual Moodle CLI | PASS | PASS |
|13 repaired fields, zero column differences across183 plugin tables | PASS | PASS |
|22 out-of-range cases: each11 field, positive/negative | PASS | PASS |
|8 metadata/connection rejection cases | PASS | PASS |
|All30 rejections: exact data unchanged, no group DDL, no1001 savepoint | PASS | PASS |
|110 integer boundary rows,55 per company, exact identities/values | PASS | PASS |
|NULL/default preservation; existing indexes retained | PASS | PASS |
|Partial actual DDL, injected driver failure, no target savepoint | PASS | PASS |
|Actual CLI retry after partial DDL; repeat schema equality | PASS | PASS |
|Signed fractional extrema and smallest fraction in all11 fields | PASS | PASS |
|Two existing nonempty adminitem time records retained on repeat | PASS | PASS |

Failures cover wrong precision/scale/default/nullability/signedness/type, incompatible
admin time definition, and non-strict session mode. These are test-only mutations in
a new disposable DB, reverted in fixtures before positive migration; no real data used.
The partial-failure proxy runs two real admin ADDs and the first real DECIMAL ALTER,
then throws before the second DECIMAL ALTER. DB version remains0906; genuineCLI retry
reaches1001 with preserved data. No production fault hook exists.

Fresh/upgrade column parity is exact. Task index control deliberately retains a legacy
reversed index; its preservation is checked separately, not misreported as identical
physical index names to fresh. Other index snapshots and complete repeat snapshots match.
No XMLDB/debug warnings found in specialized logs. The pinned fixture is metadata-derived;
it is not a production clone or a real Corporate2613 acceptance run.

## Full regression

Final source [run42754435777](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42754435777)
completed on both engines. [Exact IDs, counts and hashes](PR66_EVIDENCE.json).

| Check | MariaDB | Percona |
|---|---|---|
|13 fresh/historical install-upgrade scenarios | PASS | PASS |
|12 fresh/upgrade schema comparisons,183 tables | PASS | PASS |
|Full BMS |618 tests /7274 assertions /1 error +18 failures /0 skipped | same |
|Full Corporate |150 tests /962 assertions /0 failures /0 skipped | same |
|New failures / missing IDs / assertion decreases versus PR62 |0 /0 /0 |0 /0 /0 |

Artifacts13736535680/13735703032 downloaded and compared against PR62 JUnit by exact
class/test/dataset identifiers for every retained suite. All previous scenarios and
suites remain; exact PR65/0906 upgrade is additional. All19 unsuccessful BMS IDs are
unchanged. Full acceptance therefore remains FAIL/HOLD; the13 schema defects are
fixed without new regressions. Learning/diagnostics/documents/membership/isolation
focused suites retain their previous outcomes and assertions. Existing company_core
contract failures are retained, not waived. No test is skipped or deleted.

Next bounded work: independently reproduce the remaining contract-version failures
(management overview, indicator registry, market model) and verify domain contracts
before changing fixtures. Existing19 failures and real-stack/operational gates must
close before RC30 acceptance. No unrelated fix is included in this PR.

## Static checks and artifact

[Static CI42754435741](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42754435741): PASS.
Local QA:542 PHP files,9094 RU/EN keys, XML PASS. Reconciliation/source preservation,
workflow policy12 tests, PHP7.4 retained syntax and final harness lint PASS.

Artifact13734059692:1792 ZIP entries, SHA256
`e2a2ccd11ba04cadfb82f7f331233d38cd9fdf03a794e8d79210b46d80ed73f4`.
Rebuilt twice with identical bytes, manifest verification PASS. Compared with PR62:
zero removals; two added files (frozen helper/release notes); six changed files
(version, upgrade, diagnostic version constant, two PHPUnit terminal-version assertions,
standalone migration double). Every other ZIP entry is byte-identical. It is an
unapproved candidate, not a published production release.

## Review, limits and rollback

Architecture, QA and security reviewed source. Strict serialized DECIMAL snapshots and
nonempty admin time preservation were added from review feedback. Final source review
confirmed those corrections; QA independently verified both specialized artifacts.
Human acceptance, real1878/Corporate2613 compatibility, browser/HTTP security, full
sibling stack, backups/restore, operational performance and deployment remain separate gates.
No production/staging access or changes occurred in this task. No PR merged.

Close/revert the unmerged PR for source rollback. Any future deployed rollback requires
a verified coherent DB+code+moodledata/config restore; reverting PHP alone is insufficient.
Never ALTER back to scale0 after fractional values have been written. On partial DDL,
resolve the cause and rerun the guarded checkpoint; do not manually advance its version.
Out-of-range real data requires a separate approved domain decision, never automatic clipping.
