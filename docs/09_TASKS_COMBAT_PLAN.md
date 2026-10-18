# Tasks, Combat Plan and Management Execution

## Principle

Do not create duplicate task engines.

Combat Plan / management initiatives should reuse the canonical task domain wherever possible.

Typical relation:
Goal / Gap / Initiative / Management Project
→ Action / Task
→ Responsible
→ Deadline
→ Status
→ Metric / expected result
→ Fact / evidence

## UX expectations

Task card should clearly expose:
- title
- status
- responsible person
- creator/requester where applicable
- due date
- priority
- linked BMS object
- description/result
- comments/history
- files where allowed

Person references should open the employee card.

## Fact entry

"Enter fact" must:
- identify the metric/period unambiguously
- show planned/previous/current value context where useful
- validate unit/type
- preserve audit
- avoid silent overwrite of historical fact

The UI should distinguish:
- plan
- forecast
- fact
- correction

Corrections should be auditable.

## Position Coverage invariants (build1633–1642)

`task.assigneeid` = original Moodle user; optional `dutypositionuuid` = explicitly position-bound work. Personal/legacy-empty means NOT auto delegated. Valid approved company-scoped and date-bounded substitution resolves effective executor for execution-only rights; do not transfer reviewer/approval/secret attachments/private calendar. Recurrence uniqueness company + series + period, not person or revision. Keep approved Battle Plans unchanged. `tasks.php` UI W3 is opt-in, not a new task domain.
