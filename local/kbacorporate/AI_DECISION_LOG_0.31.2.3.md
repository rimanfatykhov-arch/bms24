# AI_DECISION_LOG — v0.31.2.3

## Decision
Introduce a dedicated bulk role-profile editor instead of requiring one-by-one edits of role competencies.

## Why
After selective BMS-Dxx migration, all selected competencies can legitimately have different target levels, priorities and mandatory status. Treating every competency as level 4 / critical / mandatory distorts GAP and Learning Plan generation.

## Safety boundary
The bulk operation is non-destructive: it does not add/remove competencies, delete assessments, change course mappings, or delete/synchronise Moodle Learning Plans. It only updates targetlevel, priority and required on existing role competency rows.

## Versioning
For global role templates the batch touches the template version once, after all row updates, so one user action corresponds to one version event.
