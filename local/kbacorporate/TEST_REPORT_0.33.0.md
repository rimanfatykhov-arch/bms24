# TEST REPORT — KBA Corporate Dashboard v0.33.0

## Status

**Static release gate: PASS.**  
**Pilot Moodle runtime tests: NOT YET EXECUTED.**  
Release classification: BETA / prepared for pilot validation, not Stable.

## Build

- Component: `local_kbacorporate`
- Release: `0.33.0 — Критерий аудита → действие по внедрению`
- Build: `2026090810`
- Upgrade baseline: `0.32.3`
- Minimum Moodle: 4.0

## Static checks performed

| Test | Result |
|---|---|
| PHP syntax check for all plugin PHP files | PASS — 229/229 |
| `db/install.xml` XML parse | PASS |
| `version.php` build and release | PASS — 2026090810 / 0.33.0 |
| Upgrade savepoint | PASS — 2026090810 |
| RU/EN key parity | PASS — 2592/2592 |
| Duplicate language keys | PASS — 0 |
| Pure priority deadline test | PASS — 30/60/90 days |
| Non-destructive schema review | PASS — no schema change |
| Existing task-storage reuse review | PASS |
| Company and audit ownership validation review | PASS |
| Session-key protection for Apply | PASS |
| ZIP root and integrity | PASS — one root `kbacorporate/`, 653 entries, 229 PHP files |
| SHA-256 | PASS — companion checksum generated after final packaging |

## Automated test source added

`tests/implementation_actions_test.php` covers:

- default deadline windows;
- positive confirmed gap preview;
- creation of one managed action;
- owner, priority, gap and due-date snapshot;
- repeated execution without duplicates;
- rejection of draft audits.

The test source passes PHP syntax validation. Execution in a configured Moodle PHPUnit environment is still required.

## Required pilot cases

### T-0330-01 Upgrade

Install the full ZIP over v0.32.3. Expected: upgrade reaches build `2026090810`; no schema prompt or data loss.

### T-0330-02 Draft audit

Open actions from a draft audit. Expected: preview explains that creation is disabled; no checkbox Apply is available.

### T-0330-03 Confirmed audit without active programme

Expected: gaps are visible, but creation is disabled and programme activation is requested.

### T-0330-04 Confirmed positive gap

Expected: row contains actual score, target, positive gap, priority, expected result and default due date.

### T-0330-05 Achieved and unassessed criteria

Expected: achieved criteria and unknown/unassessed criteria are excluded from the action list.

### T-0330-06 Route selection

For a criterion with two active routes, select one. Expected: created task snapshots only the chosen route/tool/course.

### T-0330-07 No route

Expected: a basic action can be created with `routeid = 0`; expected result is generated from target/evidence guidance.

### T-0330-08 Owner validation

Select an active employee of the company. Expected: task is assigned. A user outside the company must be rejected server-side.

### T-0330-09 Bulk partial isolation

Select several valid rows. Expected: each valid row is created; a bad row increments errors without duplicating successful rows.

### T-0330-10 Idempotency

Submit the same selection again. Expected: created = 0; existing count increases; only one task per audit item remains.

### T-0330-11 Direct audit links

Expected: a positive-gap criterion shows «Создать действие»; after creation it shows «Открыть действие».

### T-0330-12 Existing task workflow regression

Open the task, start it, submit evidence, approve it. Expected: legacy lifecycle, checklist, discussion and notifications remain functional.

### T-0330-13 Tenant isolation

Attempt to pass an audit/item/route from another company. Expected: access denied or row rejected; no cross-company task is created.

## Stable exit criteria

- all applicable pilot cases PASS;
- PHPUnit suite executed in Moodle environment;
- no cross-company data exposure;
- bulk creation tested with at least one routed and one unrouted criterion;
- TEST_REPORT updated with actual environment, tester and results.
