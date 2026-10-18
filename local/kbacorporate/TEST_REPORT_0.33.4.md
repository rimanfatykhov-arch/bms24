# Test Report v0.33.4

## Scope

Статическая, контрактная, миграционная и упаковочная проверка реестра экономических показателей.

## Environment

- Source baseline: `v0.33.3.1 / 2026090814`
- Target build: `2026090815`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | 248 files, no syntax errors | PASS |
| `db/install.xml` parse | well-formed XML | PASS |
| fresh-install tables | 3 tables: 48 fields, 5 keys, 10 indexes | PASS |
| upgrade definitions | exact field/type/default/key/index parity with install.xml | PASS |
| version metadata | build `2026090815`, release `0.33.4` | PASS |
| upgrade path | savepoint `2026090815` | PASS |
| RU/EN language parity | 2884 keys in each language | PASS |
| duplicate language keys | none | PASS |
| literal plugin string references | 2087 references, 0 missing | PASS |
| capabilities | view/manage definitions present | PASS |
| pure economic service smoke test | decimal, period keys, scenarios and CSV safety | PASS |
| privacy payload anonymisation | scalar actor fields and embedded verifier IDs covered | PASS (static) |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entries | PASS |
| destructive migration | absent | PASS |

## Code-level scenarios

`tests/economic_indicators_test.php` defines checks for:

1. stable built-in indicator codes;
2. creation of a company indicator, value and history records;
3. normalisation of quarterly periods;
4. uniqueness per indicator, period and scenario;
5. coexistence of actual and plan values in one period;
6. idempotent migration from the legacy audit snapshot table;
7. source mapping from legacy ERP provider tokens.

`tests/privacy_provider_test.php` includes the three new economic tables in metadata coverage.

The PHPUnit classes are included but were not executed because a configured Moodle PHPUnit environment is unavailable in the build container.

## Required pilot runtime tests

1. Back up and upgrade a database copy from build `2026090814` to `2026090815`.
2. Verify creation of the three tables and 22 global system indicators.
3. Open the registry as administrator, manager, company manager and unauthorised user.
4. Confirm company isolation for catalogues, values, audit links and CSV.
5. Add actual, plan and forecast values for the same indicator/period.
6. Attempt a duplicate value for the same indicator/period/scenario and verify rejection.
7. Create, edit, archive and reactivate a company-specific indicator.
8. Link a value to an audit of the same company and attempt a cross-company audit ID.
9. Edit a legacy economic audit snapshot and verify mapped actual values in the registry.
10. Repeat the legacy migration/sync and confirm no duplicate rows.
11. Verify change history before/after payloads and actor attribution.
12. Export a filtered CSV and verify encoding, selected rows and formula protection.
13. Exercise privacy export/delete/anonymisation for authors and verifiers.
14. Confirm existing BMS audit, competency, learning-plan, Kanban and evidence flows still open.

## Verdict

Prepared for pilot runtime validation. Because the release adds three tables, it must not be promoted to Stable until upgrade, rollback prerequisites and company-isolation scenarios are recorded on a copy of the target Moodle environment.
