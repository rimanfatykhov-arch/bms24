# Corporate 0.40.7 / API 1.25 — BMS24 weekly task-section projection

Read-only public API `bridge::get_implementation_program_task_sections(companyid, actorid, programid)`.

- Reuses Corporate authorization for a paid, visible program and requires a confirmed baseline audit.
- Returns minimal per-task fields: canonical `impltask:<id>`, audit section code, status, title, assignee and due timestamp. No comments, evidence bodies, contacts, internal notes or mutation.
- Excludes deleted tasks, mismatched company and audit IDs.
- Refuses attribution if another paid program is canonical for the same baseline audit (aligns with existing task provider version 1.22).
- Prevents partial summaries: more than 5000 tasks results in `complete=false` and an empty task list.
- No XMLDB changes. Database migration is a no-op savepoint for 2026102516.

Requires live Moodle/Corporate permission and query-performance validation.
