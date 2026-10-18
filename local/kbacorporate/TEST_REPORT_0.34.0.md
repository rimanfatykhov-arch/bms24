# Test Report v0.34.0

## Scope

Статическая, контрактная, алгоритмическая и упаковочная проверка сравнения подтверждённых аудитов BMS «до / после».

## Environment

- Source baseline: `v0.33.4 / 2026090815`
- Target build: `2026090901`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | 251 files, no syntax errors | PASS |
| `db/install.xml` parse | well-formed XML | PASS |
| schema comparison with v0.33.4 | byte-identical `install.xml` | PASS |
| version metadata | build `2026090901`, release `0.34.0` | PASS |
| upgrade path | savepoint `2026090901` | PASS |
| RU/EN language parity | 2967 keys in each language | PASS |
| duplicate language keys | none | PASS |
| literal plugin string references | 4072 references, 0 missing | PASS |
| comparison service smoke test | criteria, risks, scale normalisation, economics, filters | PASS |
| company/tool/confirmed pair validation | implemented in service and controller | PASS (static) |
| CSV safety | uses economic registry `csv_safe()` | PASS (static) |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entries | PASS |
| destructive migration | absent | PASS |

## Algorithmic smoke scenarios

The isolated service smoke test verified:

1. matching criteria by immutable `criterioncode`;
2. improved, worsened, unchanged, newly assessed, added and removed states;
3. new, resolved and unknown risk transitions;
4. target-gap changes;
5. completed actions and accepted evidence in the audit interval;
6. normalised comparison of different scoring scales;
7. direction-aware economic outcomes (`up`, `down`, `target`);
8. rejection of cross-currency money comparison;
9. combined criterion filters.

`tests/implementation_audit_comparison_test.php` contains equivalent Moodle PHPUnit regression scenarios. The PHPUnit class is included but was not executed because a configured Moodle PHPUnit environment is unavailable in the build container.

## Required pilot runtime tests

1. Upgrade a backup copy from build `2026090815` to `2026090901`.
2. Open the comparison page as site administrator and as an assigned curator.
3. Confirm that a user without access to the company cannot open either audit through crafted IDs.
4. Compare the two latest confirmed audits of the same tool.
5. Open comparison from the later audit and verify automatic selection of the nearest earlier audit.
6. Attempt to compare the same audit, different companies, different tools and a non-confirmed audit.
7. Verify index, coverage, section and criterion totals against source audits.
8. Verify matching across two published tool versions using stable criterion codes.
9. Verify actions created from the earlier audit and evidence accepted between the two audit dates.
10. Add linked actual economic values to both audits and verify direction-aware changes.
11. Verify that different currencies are marked not comparable.
12. Exercise status, risk, section and text filters.
13. Export CSV and verify encoding, filtered rows and formula protection.
14. Confirm that no audit, task, evidence or economic record changes after viewing/exporting.
15. Purge caches and confirm the compact navigation item and entry buttons.

## Verdict

Prepared for pilot runtime validation. Promotion beyond BETA requires recorded tests against real confirmed audits, company isolation, curator permissions and CSV output on the target Moodle environment.
