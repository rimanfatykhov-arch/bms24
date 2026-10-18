# Test Report v0.34.1

## Scope

Статическая, алгоритмическая, языковая и упаковочная проверка панели экономических показателей аудита.

## Environment

- Source baseline: `v0.34.0.2 / 2026090903`
- Target build: `2026090904`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | no syntax errors | PASS |
| `db/install.xml` parse | well-formed XML | PASS |
| schema comparison with v0.34.0.2 | byte-identical `install.xml` | PASS |
| version metadata | build `2026090904`, release `0.34.1` | PASS |
| upgrade path | savepoint `2026090904` | PASS |
| RU/EN language parity | identical key sets | PASS |
| duplicate language keys | none | PASS |
| literal plugin string references | 0 missing | PASS |
| dashboard algorithm smoke test | direction, delta, selection limit | PASS |
| core chart integration | line/bar/series/axis, no external CDN | PASS (static) |
| cross-currency protection | trend and variance comparison blocked | PASS (static) |
| company/audit isolation | implemented in controller/service | PASS (static) |
| CSV route | filters and selected indicators preserved | PASS (static) |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entries | PASS |
| destructive migration | absent | PASS |

## Algorithmic scenarios

The isolated smoke test verifies:

1. growth is positive for an `up` indicator;
2. reduction is positive for a `down` indicator;
3. a `target` indicator remains neutral without an explicit target;
4. missing values produce the `unknown` state;
5. explicit indicator selection is de-duplicated, ordered and limited to 8.

`tests/economic_dashboard_test.php` additionally covers in a configured Moodle PHPUnit environment:

- actual, plan and forecast values for the same period;
- current and previous factual values;
- KPI delta and chart series;
- verified share and summary counters;
- deterministic indicator selection;
- opening an old audit outside the default date window.

The PHPUnit class is included but was not executed because a configured Moodle PHPUnit environment is unavailable in the build container.

## Required pilot runtime tests

1. Upgrade a backup copy from build `2026090903` to `2026090904`.
2. Purge Moodle caches.
3. Open the dashboard as administrator and as a corporate manager with access to one company.
4. Confirm that a user from another company cannot open the dashboard or export by crafted IDs.
5. Add two actual values for `REV`; verify KPI delta and line chart.
6. Add plan and forecast for the current period; verify the bar chart and variance.
7. Test `up`, `down` and `target` indicators.
8. Add monetary values in two currencies; verify that the trend chart is hidden with a warning.
9. Select more than 8 indicators; verify truncation warning and stable selection.
10. Apply category, periodicity, date and verified-only filters.
11. Open the dashboard from an older audit and verify linked values appear without manual date expansion.
12. Compare with the previous confirmed audit and open the full audit comparison.
13. Export CSV and verify it contains only filtered/selected data.
14. Confirm that viewing and export do not change registry values or audit records.
15. Check mobile and narrow desktop layouts.

## Verdict

Prepared for pilot runtime validation. Promotion beyond BETA requires recorded verification of chart rendering on Moodle 4.0, company isolation, audit context, CSV output and mobile layout on the target server.
