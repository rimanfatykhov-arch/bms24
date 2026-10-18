# TEST REPORT — KBA Corporate Dashboard v0.33.1

## Status

**Static release gate: PASS.**  
**Pilot Moodle runtime tests: NOT YET EXECUTED.**  
Release classification: BETA / prepared for pilot validation, not Stable.

## Build

- Component: `local_kbacorporate`
- Release: `0.33.1 — Управление доказательствами внедрения`
- Build: `2026090811`
- Upgrade baseline: `0.33.0`
- Minimum Moodle: 4.0

## Static checks performed

| Test | Result |
|---|---|
| PHP syntax check for all plugin PHP files | PASS — 233/233 |
| `db/install.xml` XML parse | PASS |
| Fresh-install / upgrade table field parity | PASS |
| `version.php` build and release | PASS — 2026090811 / 0.33.1 |
| Upgrade savepoint | PASS — 2026090811 |
| RU/EN key parity | PASS — 2686/2686 before final packaging |
| Duplicate language keys | PASS — 0 |
| Literal local language references | PASS — no missing literal keys |
| Review CSRF protection | PASS — `require_sesskey()` |
| Task/evidence context binding | PASS — evidence must belong to URL task |
| Self-review guard | PASS — explicit server-side rejection |
| Version uniqueness review | PASS — unique `(taskid, versionno)` index |
| File access review | PASS — task access required in `pluginfile` |
| Privacy and company deletion review | PASS — tables, actors and files covered |
| ZIP root and integrity | PASS — one root `kbacorporate/`, 670 entries, 643 files, 233 PHP files |
| SHA-256 | PASS — companion checksum generated after final packaging |

## Automated test source added

`tests/implementation_evidence_test.php` covers:

- submit evidence v1;
- transition task to review;
- reject v1 with reviewer feedback;
- return task to work;
- submit immutable v2 with `supersedesid`;
- accept v2;
- complete the task;
- preserve both versions and four log events;
- prevent duplicate pending submissions;
- prevent self-review.

The test source passes PHP syntax validation. Execution in a configured Moodle PHPUnit environment is still required.

## Required pilot cases

### T-0331-01 Upgrade schema

Install the full ZIP over v0.33.0. Expected: build reaches `2026090811`; both evidence tables are created; existing tasks remain unchanged.

### T-0331-02 Start task

Open a planned task as its owner and start it. Expected: status becomes «В работе» and the evidence action becomes available.

### T-0331-03 Submit v1

As the owner, submit a summary, URL and one file. Expected: evidence `v1` is created, task becomes «На проверке», payload is visible in history.

### T-0331-04 Duplicate pending submission

Try to send a second version while v1 is pending. Expected: submission is unavailable; no duplicate record is created.

### T-0331-05 Self-review

Attempt review as the submitting owner. Expected: operation is rejected.

### T-0331-06 Reject v1

As curator/manager, return v1 with a comment. Expected: v1 status is «Возвращено на доработку», task returns to «В работе».

### T-0331-07 Reject without comment

Expected: server-side validation blocks the operation.

### T-0331-08 Submit v2

As owner, correct the materials and submit again. Expected: v2 is created; v1 remains unchanged; `supersedesid` references v1.

### T-0331-09 Accept v2

As another authorised reviewer, accept v2. Expected: v2 is «Принято», task is «Выполнено», completion timestamp is set.

### T-0331-10 File access

Owner, curator and authorised manager can open evidence files. An unrelated user and another-company manager cannot.

### T-0331-11 Legacy evidence

Open a task with pre-v0.33.1 evidence. Expected: legacy comment/link/files remain readable and are not silently converted or removed.

### T-0331-12 Status integrity

After a version exists, attempt to change status through task edit. Expected: status is locked; lifecycle changes happen through evidence review.

### T-0331-13 Privacy and deletion

Exercise privacy export/delete and company deletion in a test environment. Expected: no orphan log/file records and no cross-company disclosure.

## Stable exit criteria

- all applicable pilot cases PASS;
- PHPUnit suite executed in Moodle environment;
- schema upgrade validated on a copy of production data;
- file access tested for owner, curator, manager and unrelated user;
- rejection/resubmission/acceptance tested end to end;
- privacy and company-delete cases completed;
- this report updated with environment, tester and actual results.
