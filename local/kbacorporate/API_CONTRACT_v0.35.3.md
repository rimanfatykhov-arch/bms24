# KBA Corporate ERP API — Competency / Learning / Economic Sync v0.35.3

**Build:** `2026091008`  
**Contract major:** `1`  
**Contract release:** `1.3.0`  
**Public bridge:** `1.20`

## Competency

Competency state is Moodle → ERP read-only. `current_level` is `null` and
`assessment_status=unassessed` when no assessment exists. Course completion does not confirm
or raise a competency. Assessment and reassessment remain Moodle workflows.

## Learning

`learning_catalog_assignment` manages only the company course catalogue. Course identity is
`moodle_course_id` or unique `course_idnumber`. Enrolment, completion and Moodle Learning Plans
are never changed. Employee snapshot exports current Moodle-owned learning status.

## Economic

`economic_value` accepts stable indicator code, period, scenario, value and currency. Incoming
values are source `erp`, unverified and ownership-protected. Manual values are never overwritten.
Snapshot exposes latest context with `causality_established=false`.

## Stable guarantees

- No write operation raises a competency or BMS maturity.
- Unassessed is not level 0.
- Completion is evidence of learning only.
- Correlation is not causality.
- Existing provider tokens and major-v1 fields remain compatible.
