# RC30 bounded date/task/Battle Plan regression diagnosis

Base PR58 head:d3a16c3d08b9b10ac7edc32658157aa7f237e9a0; its tested runtime
e37bb2a0c8c767d93f9e9e0d7647fd98af037740. BMS0904/Corporate0901.
Independent reproduction was requested on unchanged runtime before fixture edits:
workflow42600654381 attempt2, fresh disposable MariaDB10.11 and Percona8.0.
At initial document preparation reproduction was pending and fixtures unchanged.
Before applying fixes, new MariaDB artifact13669416723 from attempt2 reproduced
all eight exact identities, with603 tests/7042 assertions,14 errors/18 failures,
zero skipped. Independent security reviewer confirmed this against actual JUnit.
The Percona baseline rerun continues independently; its evidence will be appended.

## Owning domain requirements

- `product_service::validate_period` requires exact Gregorian YYYY-MM-DD, Monday
  for weekly and day01 for monthly periods. `deviation_service::observation_date`
  requires an observation inside its approved norm period. Check chronology must
  not precede the observation or corrective task's acceptance timestamp.
- Moodle `userdate()` defaults fixday=true and removes the leading zero from
  `%d`; it is a display formatter. Existing `business_date_service::day` preserves
  Gregorian ISO keys in the user's timezone. No Moodle Core modification is needed.
- `task_service::validate_common` rejects due date before start and earlier due
  time on the same date. Optional empty start is supported. Overdue projection
  uses a valid earlier civil date, not an internally contradictory date pair.
- `battle_plan_service::create_manual_task` inherits the plan reviewer when0 is
  supplied. Task submit enters review; explicit authorized accept finishes it.
  Plan submission rejects empty plans. Closure rejects unfinished tasks unless
  explicitly carried to another plan. Existing approvals/history must be retained.

## Eight targeted first failures

| Suite / method | Evidence and intended correction |
|---|---|
| deviation / test_accepted_task_does_not_close_deviation | Shared fixture uses display-formatted date; use one timestamp for accepted task and ISO observation, retain check-required denial |
| deviation / test_recheck_and_explicit_closure_do_not_create_statistics | Same fixture; retain verified/closed and no synthetic statistics, test chronology denial |
| deviation / test_lowered_norm_cannot_be_used_as_improvement | Same fixture; retain rejection of easier norm as improvement |
| deviation / test_regular_user_cannot_read_deviations | Same fixture; retain foreign/nonmember access denial |
| task / test_workspace_and_tasks_page_agree_on_open_and_overdue_tasks | Jan01 due overrides Sep14 start; use optional empty start and user's yesterday, retain canonical counter/projection checks |
| task / test_workspace_open_tasks_remain_visible_after_completed_history | Same invalid pair for29 tasks; retain28 completed history rows and visible open overdue task |
| battle_plan / test_battle_plan_references_one_canonical_task_and_closes_after_execution | Task submitted to inherited reviewer but not accepted; assert premature-close denial, reviewer accept, then owner close |
| battle_plan / test_active_plan_accepts_scoped_manual_task_but_closed_plan_rejects_it | Empty plan submitted; assert denial, seed initial work, approve, add extra work, finish+accept BOTH tasks, close, retain closed-plan denial |

Independent QA reviewed the same owning services and confirmed these fixture
contradictions. Source inspection alone is not runtime reproduction. Changing
business rules is unnecessary; no ADR introduces a new rule. Corporate authorization,
Moodle Core, UI, finance, AI Studio and SaaS remain outside scope. All earlier tests,
including learning, RC30 diagnostics, documents, tenant isolation and migration
acceptance remain required. New negative cases must supplement existing assertions.
