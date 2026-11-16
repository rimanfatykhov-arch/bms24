# Daily reports R1 — schema, upgrade, rollback and compatibility

Decision: [ADR-0009](https://github.com/rimanfatykhov-arch/bms24/pull/115), stage R1 (owner approval 15.11.2026,
implementation assigned 16.11.2026). Base: `feature/daily-reports-r0-entry` (PR #116). Not deployed, not merged.

## What R1 adds

| Item | Change |
|---|---|
| Version | `local_kbacompany` 2026111100 → **2026111600**, release label unchanged (`1.1.0-rc29`). Corporate stays 2026111100. |
| Tables | `local_kbacompany_dreport`, `_dreportver`, `_dreportlink`, `_dreportrev`, `_dreportlog` (additive, empty). |
| Upgrade | One savepoint 2026111600. Each table is created with `install_one_table_from_xmldb_file()` from `install.xml`, so fresh install and upgrade share one definition. No backfill, no change to existing tables. |
| Switch | `local_kbacompany/dailyreports_enabled`, checkbox, **default off**. Off = the R0 read-only day summary. |
| Privacy | Provider exports own reports (all versions, snapshots, decisions) and decisions made as a reviewer; deletion removes own reports and anonymises this user's reviewer identity and comments on others' reports (owner decision В7). |
| Capabilities | None added. Entry stays `local/kbacompany:viewowncompany`; domain rights are checked in the service. |

Table name deviation: the design called the decision table `dreportreview`; `local_kbacompany_dreportreview` exceeds
Moodle's 28-character table-name limit, so it is `local_kbacompany_dreportrev`. Same columns.

Uniqueness: `dreport (companyuuid, userid, reportday)` and `uuid`; `dreportver (reportuuid, versionno)` and `uuid`;
`dreportlink (versionuuid, taskuuid)`; `dreportrev (versionuuid, requestkey)` and `uuid`. The submission request key on
versions is indexed (not unique, drafts have an empty key) and enforced under the per-report lock.

## Write rules implemented (ADR-0009 D2, D3, design §5)

- Actor is the session user; no employee id is read from the request. Company membership and revocation are checked
  through `company_context_service::context()` before any read or write; every query filters by `companyuuid`.
- Draft on the server with an optimistic revision; a stale revision is a conflict, nothing is overwritten, the form
  keeps the employee text and shows the saved text next to it.
- Submit = save the form text + freeze, under one lock (`dreport:<company,user,day>`) and one transaction.
  The same request key returns the same version (double click, retry, parallel POST).
- Frozen version: text, linked tasks with title/status/due date at submission, day snapshot (first 25 due tasks and
  submissions), position snapshot, reviewer and its source, SHA-256 content hash. Later task changes do not rewrite it.
- Reviewer (В1): Battle Plan reviewer of the reporting week → manager of the employee's unit up the reporting line →
  none. Never the author; revoked or suspended users are skipped.
- Dates: civil day in the user timezone, stored timezone, future days refused, later submission marked.
- Only "What was done" is required (В11). Tasks the author cannot open now cannot be linked.

Not in R1: reviewer queue, decisions, rating, return and rework (R2); attachments (separate slice after R2);
Communication Center thread (R3).

## Rollback compatibility

Moodle refuses a plugin version lower than the installed one, so code-only rollback to #116/#113 is **not possible**
after 2026111600 is installed. Options, in order:

1. Turn `dailyreports_enabled` off: the page returns to the read-only summary; saved reports stay in the database and
   are not shown. Reversible at any time.
2. Forward fix on 2026111601+.
3. Database restore from the backup taken right before the upgrade — only by a separate owner decision; reports
   written after the upgrade are lost.

Before installing R1 anywhere: a fresh, verified database backup and the Codex operator procedure. This change runs no
deployment, backup or rollback.

## Release identity updated with exact values (no gate relaxed)

`tools/check_pr53_pr56_integration.py` (install.xml hash, 484th savepoint 2026111600 after 2026111100, language
chain), `tools/check_reconcile_1879.py` and `task_rc30_acceptance_service::EXPECTED_PLUGIN_VERSION`,
`tools/dev_ai_gate.py`, `tools/adr0005_schema_policy.py` (+ test), `tools/rc30_release_gate.py`,
`tools/check_workflow_policy.py` (+ test), `.github/workflows/bms24-ci.yml` artifact names and expected version,
fixtures `rc30_schema_repair.php`, `s12_clone_fixture.php`, `standard_browser_fixture.php`, PHPUnit
`ordered_index_2026110900_test`, `schema_parity_2026110890_test`. Language evidence:
`daily-reports-r1-source-preservation.json`.
