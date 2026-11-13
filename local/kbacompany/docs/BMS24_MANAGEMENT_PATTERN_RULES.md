# BMS24 Management Pattern Rules

Status: CANONICAL FACTUAL RULES\
Introduced: `build2026101702`

## Purpose

Recurring management problems are review signals derived from the same accessible weekly Battle Plan management read-model used by Weekly Coordination. They are not employee ratings, diagnoses, causal conclusions, or automatic decisions.

## Scope

Patterns use the currently selected company/unit scope or one accessible employee and the selected bounded 4/8-week history. No new history table or pattern persistence is created.

## Rules

1. **Critical KPI exceptions keep recurring**\
   Trigger: critical KPI exceptions are present in at least **3 of the latest 4 weeks**.

2. **Overdue work repeats for two weeks**\
   Trigger: overdue tasks are present in **both latest 2 weeks**.

3. **Management tasks remain open**\
   Trigger: open manual/management tasks are present in **both latest 2 weeks**.

4. **Low data coverage keeps recurring**\
   Trigger: data coverage is below **80% in at least 3 of the latest 4 weeks with available data**.

5. **Pending decisions remain unresolved**\
   Trigger: indicators requiring a decision are present in **both latest 2 weeks**.

## Product rules

- Every displayed signal must show its explicit rule and the matched weeks.
- Do not combine the rules into a composite score.
- Do not sort or rank employees by a hidden severity model.
- Do not infer causality from a temporal pattern.
- Navigation from the signal must reuse canonical source screens/controllers.
- Source ACL/RBAC/company isolation remains authoritative.
- A threshold/rule change requires updating this document, RU/EN UI text and release documentation in the same build.

## Acceptance

- 4/8-week and employee/unit filters change the factual input scope only.
- Foreign/inaccessible employee scope cannot be forced via URL.
- A rule fires only when its documented condition is true.
- Weeks shown in the signal match the actual history rows that satisfied the rule.
- No `[[missing_key]]` strings.
- No new DDL/write path/pattern entity.


## Pattern → Task feedback (build2026101705)

A task created from a one-employee pattern carries a stable human-readable trace marker in its description. Weekly Coordination resolves that marker only through the canonical Task Center visibility predicate and shows the latest linked task with its live status. The marker is traceability metadata, not authorization. While the latest linked task is active (`open`, `doing`, `review`, `returned`), the UI prioritizes opening that task instead of prompting a duplicate. If the latest linked task is `done` or `cancelled` and the factual pattern still recurs, the manager may create a new canonical task. Build1704 tasks without the marker are detected on a bounded compatibility path by the generated title + assignee where possible.

## Pattern → Task → factual result feedback (build2026101707)

When a linked canonical management task is completed, Weekly Coordination may show a factual comparison using the already selected bounded 4/8-week history for the same accessible employee scope.

Comparison rules:

- completion time is read from canonical Task Center `completed_at`;
- **before** is the latest available history week at or before the task completion week;
- **after** is the latest available subsequent history week;
- the UI shows only Productivity, Effectiveness and Data Coverage as **before / after / delta in percentage points** plus the relevant weeks/dates;
- if there is no subsequent available week with comparison data, show **“Недостаточно последующих данных для сравнения.”**;
- if the bounded history has no usable before point, show an explicit insufficient-before-data message;
- do not classify the movement as caused by the task, do not create a success score, and do not rank the employee;
- every result block must state that temporal movement after a management action is a factual observation and does not by itself prove causality;
- no snapshot table, causal model, pattern-result entity, or alternative Task relation is created.

The trace marker remains metadata only. Task visibility is resolved through the canonical Task Center projection boundary before any linked-task or result feedback is returned.
