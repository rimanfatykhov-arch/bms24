# Daily reports ADR-0010: source preservation and gates

Decision: [ADR-0010](../adr/ADR-0010-daily-report-reviewer-org-chart.md), owner decision 2026-11-21.

## Intentional pinned changes (exact hashes in `daily-reports-adr0010-source-preservation.json`)

- `local/kbacompany/lang/{en,ru}/local_kbacompany.php`: one block appended after the #145 block. It has a marker and
  41 new keys (`dailyreports_*`, `messageprovider:dailyreport_copy`, `communication_notification_dailyreport_copy`).
  Every earlier byte is unchanged, and no earlier key is overridden. Checked by
  `tools/tests/test_dailyreport_source_preservation.py`.
- `local/kbacompany/classes/local/communication_service.php`: `dailyreport_copy` added to `NOTIFICATION_KINDS` only.
- `local/kbacompany/db/install.xml`: two tables appended after `local_kbacompany_dreportlog`. These are
  `local_kbacompany_dreportcc` and `local_kbacompany_dreportset`. Earlier table definitions are unchanged.

## Version identity

`$plugin->version` is 2026111601 → **2026112301**, and the upgrade step is `2026112301`, savepoint 486. The version is
updated in:

- CI workflow artifact names and `--expected-version`;
- `task_rc30_acceptance_service::EXPECTED_PLUGIN_VERSION`;
- `check_reconcile_1879`, `check_pr53_pr56_integration` (chronology), `dev_ai_gate` (required release note and step);
- `check_workflow_policy` and its test;
- `rc30_release_gate` (pair 2026112301 / Corporate 2026111100);
- `adr0005_schema_policy` and its test;
- the fresh-install version assertions of `rc30_schema_repair.php`, `s12_clone_fixture.php`,
  `standard_browser_fixture.php`, `ordered_index_2026110900_test.php` and `schema_parity_2026110890_test.php`;
- the offline schema parity adapter, which now expects seven daily report tables.

Not changed on purpose:

- `tools/acceptance/pr90/daily_reports_upgrade_policy.py`: exact 1100 → 1601 evidence of #124;
- the #124 artifact `07a0c7f7…`.

## Not done here

No deploy and no switch changes: `dailyreports_enabled` stays as it is, 0 on production. Moodle PHPUnit, dual-engine
fresh/upgrade and browser acceptance run only in CI; a local run proves none of them.
