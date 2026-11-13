# RC29 / build2026101482 — Limited department plan editing

- `battle_plan:edit` scope `department` requires actual manager organization assignment and owner in that department.
- Grants editing plan reviewer/observer/week dates (without changing owner), weekly goal/outcome. Server rechecks under lock. Cannot change pinned template/quota, owner, company or closed plan.
- Does not provide `battle_plan:approve`, task edit, or lifecycle transitions. Existing action routes still require dedicated rights.
- Editing form may expose other legacy controls; they must still be server-rejected. Dedicated streamlined department editor remains QA/follow-up.
- Important: production test needed for UI permission parity, cross-company, concurrent revision, revoked rights, and multiple departments. No live Moodle acceptance.
