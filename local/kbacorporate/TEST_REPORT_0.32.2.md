# Test Report — KBA Corporate Dashboard v0.32.2

Дата: 8 сентября 2026 года  
Build: `2026090807`  
Среда сборки: isolated source tree; runtime Moodle 4.0 pilot test pending.

## Release Gate

- ZIP root: `kbacorporate/` only — verified during final packaging.
- `version.php`: component/release/build aligned.
- `db/upgrade.php`: savepoint `2026090807`, no DDL/DML migration.
- PHP syntax: **222/222 files passed**.
- `db/install.xml`: XML parse passed; file is byte-identical to v0.32.1.
- language keys: RU/EN parity and duplicate-key check — passed before packaging.
- static local string references in modified files — passed before packaging.
- isolated pure-function smoke test for outcome, summary, state and snapshot decoding — passed.
- destructive migration: none.

## Added regression tests

`tests/competency_reassessment_test.php` covers:

1. Workflow outcomes for missing, pending, rejected, newly assessed, improved, unchanged, declined and target reached.
2. Summary counts and the rule that missing/pending rows do not become confirmed levels.
3. Workflow states before assessment, while awaiting confirmation, with remaining gaps, with rejections and after target completion.
4. Backward-compatible decoding of the versioned snapshot.
5. Exclusion of old Moodle competency grades and evidence by the reassessment cutoff.
6. Snapshot of plan baseline and target without automatic confirmation of an old grade.
7. Idempotent reuse of the follow-up audit for the same development plan.

The PHPUnit file is syntactically valid. Execution requires the configured Moodle PHPUnit environment and remains a pilot/runtime gate.

## Static scenarios reviewed

### R-0322-01 — Completed plan preview

Expected: only a completed KBA development plan can enter the workflow; employee, competencies, baseline, target and courses are shown before creation.

Result: implemented; runtime verification pending.

### R-0322-02 — Old grade isolation

Expected: a Moodle grade/evidence older than the plan cutoff does not auto-confirm the reassessment.

Result: enforced by `collect_reassessment_evidence()` and covered by regression test; runtime verification pending.

### R-0322-03 — New evidence proposal

Expected: post-cutoff Moodle evidence may create a pending proposal, never a confirmed item.

Result: implemented; runtime verification pending.

### R-0322-04 — Explicit confirmation

Expected: only a user with `moodle/competency:competencygrade` may confirm and write the level through Moodle API.

Result: capability guard and API path implemented; runtime verification pending.

### R-0322-05 — Rejected result resubmission

Expected: rejected item can receive a new manual proposal; the audit is reopened and reviewer confirmation is reset.

Result: implemented; runtime verification pending.

### R-0322-06 — Company isolation and POST integrity

Expected: full company access is required; confirmation item and manually selected competency must belong to the current reassessment.

Result: explicit checks implemented; runtime verification pending.

### R-0322-07 — Before/after/target summary

Expected: confirmed results calculate delta, target achievement and remaining gap; missing/pending values stay unknown.

Result: deterministic service methods implemented and covered by regression tests.

## Pilot test still required

- create reassessment from a completed plan;
- verify old current grade is not auto-confirmed;
- refresh new Moodle evidence and observe pending status;
- confirm/reject single and bulk proposals with real capabilities;
- verify Moodle competency grade after confirmation;
- verify remaining-gap and next-plan links;
- verify behaviour for a legacy pre-v0.32.2 development audit;
- execute PHPUnit suite in Moodle environment.
