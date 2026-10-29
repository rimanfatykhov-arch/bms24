# local_kbacompany 1.1.0-rc17 — Battle Plan 2.0

Build: `2026101237`

## Scope

This release turns the weekly Battle Plan overview into one management workspace without introducing parallel task, meeting or metric storage.

### Weekly focus and outcome
- Week goal and closing outcome are stored under the existing `local_kbacompany_bplan.statepayload` namespace `week2`.
- No new table or column is introduced.
- Closed plans remain immutable.

### Period metrics
- The overview surfaces the existing canonical position metric status summary from Metrics 2.2.
- Full plan/fact/norm/trend remains on the Metrics tab.

### Meetings and weekly load
- Meetings are read-only projections from canonical BMS Meeting participation.
- For the plan owner, meeting titles and links are shown.
- For another employee's plan, only aggregate meeting count/duration is shown; private meeting titles are not disclosed.
- Weekly load is informational only: planned task minutes + meeting duration. It is not a timesheet or a hidden overload score.

### Carry unfinished work to next week
- Open tasks can be explicitly carried to next week's draft Battle Plan.
- The canonical task is not copied and its due date/status/history are not rewritten.
- The next Battle Plan stores only another reference to the same canonical task/provider object.
- The operation is idempotent for already linked task references.
- A source week may close with an unfinished task only when that exact source item was explicitly carried and the target plan contains the matching reference.
- If the employee is not assigned to the position during the next week, rollover is blocked.

## Compatibility
- No DB schema changes.
- No capability changes.
- No privacy storage changes.
- Existing metric override payload keys are preserved.
