# local_kbacompany 1.1.0-rc4 — Full Battle Plan Editing

Build: `2026101218`

## Scope

- Reworks Battle Plan edit into one full management workspace.
- Draft week is editable with duplicate-period protection.
- Reviewer and observer are editable on non-closed plans.
- Managers may change employee/position only for an empty draft; linked tasks block ownership reassignment.
- Owner/position changes clear pinned template/norm references so incompatible design revisions are never silently retained.
- Current task composition, totals, completion, overdue count and planned minutes are visible directly on the edit screen.
- Existing canonical tasks can be linked from edit.
- A new canonical task can be created directly from edit and immediately referenced by the Battle Plan.
- Removing a task from a Battle Plan removes only the reference, never the task itself.
- Lifecycle status remains controlled only by submit / approve / close actions.

## Data model

No database schema change. No new task storage. `local_kbacompany_bplanitem` remains the reference layer over canonical tasks.

## Live acceptance

Not run on the target Moodle instance in this package build.
