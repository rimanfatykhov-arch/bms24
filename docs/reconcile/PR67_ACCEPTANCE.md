# PR67 — bounded overview / indicator registry / market contract repair

2026-11-10. Issue #44. Stacked Draft PR on PR66; RC30 **HOLD**.

## Identity and scope

- Base PR66: `4690e11811b9006386dd4bb13e9e80555f4c3137`.
- Tested source: `e7d8cc06d99ba407662fc9aed86dfc526c583521`.
- Synthetic CI merge: `4964a3b0b31d96d1050eef484ec9cdc717aaad5a` (no branch merge).
- BMS2026111001 / Corporate2026110901. This is a test-only revision of the same runtime.
- [All19 baseline failures, exact identities and next checks](PR67_FAILURE_TRIAGE.md).

The three failures were independently reproduced by calling the existing services'
`contract()` methods: overview expected0.5.10 but returned0.5.11; indicator expected0.5.2
but returned0.5.5.2; market expected0.3.5 but returned0.4.11. Raw PR66 full PHPUnit
results reproduce the same failures on both engines. The owning services are unchanged
between reconcile `0e2bceb7a96868ae267c38b95e618e0d3330e635` and PR66. These are stale
test expectations, not newly implemented domain versions. Overview release notes0.5.11
describe schema-neutral coverage; the current indicator and market declarations retain
their canonical sources, tenant scope and lifecycle constraints. No business-rule change
or new ADR is required for this bounded correction.

Three literal version assertions are corrected. All original assertions remain. Added
assertions cover accessible-plan aggregation/canonical tasks, canonical statval append-only
history, and market company scope/immutable published revisions/no CRM or ERP fact mutation.
Expected versions are not read from the service constant. Declaration assertions do not
by themselves prove behavioral ACL, immutability or tenant isolation.

The existing full suites and all historical scenarios remain enabled. The three selected
suites are additionally run as focused PHPUnit jobs. Both workflow triggers include the
PR66 base branch; no gate is removed or downgraded. Runtime classes, schema, migrations,
Corporate authorization, Moodle Core and plugin versions are unchanged.

## Verification

[Full matrix42786543989](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42786543989)
completed on both engines. Raw artifacts13750233289 (MariaDB) and13750484797 (Percona)
were downloaded and compared with PR66, including every retained suite.
[Exact-ID evidence, exception text, counts, provenance and hashes](PR67_EVIDENCE.json).

| Check | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Overview focused | PASS:1 test /6 assertions | same |
| Indicator registry focused | PASS:9 tests /26 assertions | same |
| Market model focused | PASS:1 test /7 assertions | same |
| Full BMS | FAIL:618 tests /7292 assertions /1 error +15 failures /0 skipped | same |
| Full Corporate | PASS:150 tests /962 assertions /0 skipped | same |
| Resolved / new failures versus PR66 |3 /0 |3 /0 |
| Missing IDs / decreased assertions |0 /0 |0 /0 |
|13 fresh/historical installation-upgrade scenarios | PASS | PASS |
|12 fresh/upgrade column schema comparisons,183 tables | PASS | PASS |
| ADR-0004 repair regression | PASS | PASS |

PR66 was618 BMS tests /7274 assertions /1 error +18 failures /0 skipped. Exactly the
following tests now pass; the other16 unsuccessful identities remain unchanged:

- `local_kbacompany\battle_plan_management_overview_service_test::test_contract_is_schema_neutral_read_model`
- `local_kbacompany\indicator_registry_service_test::test_contract_keeps_one_canonical_indicator_registry`
- `local_kbacompany\market_model_service_test::test_contract`

All32 previous JUnit suites remain, with three additional focused suites (35 total).
Comparison checks unique identities, leaf/header counts, per-test assertion counts,
outcomes, exception types and remaining failure text. Only the generated synthetic
PHPUnit HTML sesskey value is normalized; every other byte of failure text is compared.
No test was removed/skipped, no existing per-test assertion count decreased, and no
new failing identity appeared. Learning, diagnostics, documents, employee membership,
company isolation and migration suites retain their previous results. Existing
company_core contract failures remain FAIL rather than being waived.

[Repair matrix42786544031](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42786544031),
artifacts13748918197/13749883318: all13 fields,30 incompatible-input rejection cases,
110 preserved rows across two companies, fractions, nonempty admin time records,
partial DDL failure without savepoint and subsequent retry PASS.183 table column
schemas match fresh. The deliberately retained legacy task index is checked separately.
Environment evidence binds these results to the synthetic CI merge above and PHP8.1.34.

Both full workflow jobs correctly conclude FAILURE because16 BMS tests still fail.
This is successful completion of the bounded three-contract correction, not full RC30
acceptance. Known failures and the operational gates below remain release blockers.

## Static and artifact evidence

[Static CI42786544027](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42786544027):
both checks and retained PHP7.4 syntax PASS. Local static QA:542/542 PHP lint,
9094 RU/EN keys, XML PASS. Integration preservation check and diff whitespace check PASS.

Artifact13749652903 contains1792 ZIP entries. Candidate ZIP SHA256:
`caa608465d348939d9817c2fa883dae8c938b300a1098c8867694822372a99fa`.
Byte comparison with the PR66 artifact finds zero added/removed entries and exactly the
three changed test files; every runtime entry is identical. The same build number is
intentional for this test-only patch; source SHA and ZIP hash distinguish the artifacts.
This is an unapproved candidate artifact, not a published or deployed release.

## Review, limitations and rollback

Independent architecture, QA and security reviews found no introduced runtime/ACL change
or removed assertions. All19 original failures were classified, with observed behavior
separated from hypotheses. Remaining failures are not waived; an HTML expectation failure
alone is not proof of unauthorized data disclosure or proof of safety.

Production/staging access, deployment, real customer data, real1878/Corporate2613 stack,
browser/manual operational acceptance and branch merging: **NOT RUN**. Testing uses disposable
CI databases and pinned test fixtures. Runtime regression tests cannot certify untested
operational environments. RC30 remains HOLD.

Rollback before integration: close the Draft PR or revert source commit
`e7d8cc06d99ba407662fc9aed86dfc526c583521` on the isolated branch. There is no data migration
or deployment from this PR to undo. Reverting reintroduces three obsolete expectations;
do not undo PR66 schema repair to roll back this test-only change.

Next priority: validate the non-manager workspace failure with negative access/data cases
(non-manager, revoked/inactive membership, foreign company), then correct only the proven
defect in a separate change. Word export/locale, finance/outbox fixtures and expanded
process/navigation contracts remain separate bounded investigations. Do not bulk-update
expectations to current output without checking domain meaning.
