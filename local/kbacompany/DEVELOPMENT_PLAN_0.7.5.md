# BMS 0.7.5 — Development Plan

## Purpose

Individual employee development plans turn confirmed competency gaps into managed development actions without changing ownership boundaries between BMS Management and Moodle/KBA Corporate.

## Flow

Approved Assessment → Employee Competency Profile → confirmed GAP → Development Plan → development actions → reassessment.

## Rules

- A plan can be generated only from gaps whose current fact came from an approved formal assessment.
- Creating or completing a plan item never changes the employee competency fact.
- Course completion is evidence of activity, not automatic competency attainment.
- Learning execution remains owned by Moodle/KBA Corporate.
- BMS stores the management intent: what competency must be developed, baseline, target, action, responsible, due date, linked learning URL and result evidence.
- Competency attainment requires a later approved Assessment Engine decision.
- AI may propose development actions but cannot activate/complete plans or confirm competency attainment.

## Lifecycle

Plan: draft → active → completed / cancelled.

Item: planned → in_progress → completed / cancelled.

## Storage

- `local_kbacompany_devplan`
- `local_kbacompany_devitem`

Both are company-scoped before fetch. Canonical employee identity remains Moodle User + BMS membership; position/profile references preserve the context used to build the plan.
