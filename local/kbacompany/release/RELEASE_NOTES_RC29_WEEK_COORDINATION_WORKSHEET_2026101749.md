# BMS24 RC29 build2026101749 — weekly coordination worksheet

- Adds a read-only weekly coordination protocol worksheet using accessible, unfinished tasks from the previous week.
- Includes employee, canonical task link and spaces for discussion of cause, decision, owner and deadline.
- Adds an anchored protocol step to the weekly coordination flow.
- The worksheet DOES NOT persist decisions. Changes must be recorded in Task Center. Dedicated protocol persistence requires separate storage and ACL design.
- No database tables, no new permissions; read-only existing management query.
- Static verification only; live Moodle deployment untested.
