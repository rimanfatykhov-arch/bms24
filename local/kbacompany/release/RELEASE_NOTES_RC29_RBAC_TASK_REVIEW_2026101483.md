# RC29 build2026101483 — Department task definition editing and result review

- Department manager grant `task:edit` controls task-definition `save` for an assigned employee in managed unit; assignee, reviewer, observer, parent/management context may not be changed by this grant.
- Independent `task:approve` grants `accept`/`return` only on task status `review` for assigned employee. No right to start/submit/cancel/delete or edit, and no bypass of existing result and checklist validation.
- Existing creator/assignee/reviewer rights remain unchanged. Scope checked again at server under task lock, with company UUID and active org assignment.
- Possible gaps: legacy user interface actions, wider service paths and race conditions require live Moodle multi-user acceptance. No live tests run.
