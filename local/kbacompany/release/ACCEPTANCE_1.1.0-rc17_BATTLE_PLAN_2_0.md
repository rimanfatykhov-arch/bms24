# Acceptance — 1.1.0-rc17 Battle Plan 2.0

## 1. Weekly focus
- Open a draft or active Battle Plan.
- Enter a Week goal and Week outcome and save.
- Reload: both values persist.
- Existing metric state/override behaviour still works.
- Closed plan shows goal/outcome read-only.

## 2. Period metrics
- Open a position with weekly metrics.
- Battle Plan overview shows counts for Within target / Risk / Critical / No data.
- Open Metrics: canonical plan/fact/norm/trend data matches Metrics 2.2.

## 3. Meetings
- As the plan owner, add meetings during the plan week.
- Overview shows count, duration, titles and meeting links.
- As another authorised manager/reviewer, open the same plan.
- Only aggregate count/duration is shown; private meeting titles are not disclosed.

## 4. Weekly load
- Add task plan minutes and meetings.
- Verify Tasks hours + Meetings hours = Total planned hours.
- No working-hours threshold or overload judgement is inferred.

## 5. Carry unfinished work
- With an active plan, leave at least one task open.
- Select it in “Next week and unfinished work”.
- Carry it forward.
- Verify a next-week draft Battle Plan exists for the same employee/position.
- Verify the same canonical task UUID/provider reference exists in both plans.
- Verify task due date, status and history are unchanged.
- Repeat carry-forward: no duplicate plan item is created.

## 6. Close week
- Open task not carried: close must fail.
- Open task explicitly carried into next draft: close may succeed.
- Completed/cancelled tasks require no rollover.

## 7. Assignment boundary
- End the employee's position assignment before next week.
- Carry-forward must be blocked and must not create a next Battle Plan.

## 8. Regression
- Draft → submit → approve/self-approve → active lifecycle still works.
- Task add/remove/create still works.
- Metrics fact entry still works.
- Review/history still works.
- Calendar Battle Plan projection still works.
