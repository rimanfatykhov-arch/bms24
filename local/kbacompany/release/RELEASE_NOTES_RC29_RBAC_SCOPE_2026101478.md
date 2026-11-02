# RC29 build2026101478 — department-scoped read access (partial)
- Company role matrix: `task:view` and `battle_plan:view` with scope `department` (no new tables).
- Only people explicitly marked as managers in active organizational assignments can read assigned task rows and plans of members of their own units. No department manager assignment => no department projection.
- Existing participant access and legacy company-wide managers remain.
- Task list/calendar and task detailed file guards use the read predicate. Plan direct detail uses scoped read predicate. **Other plan projections and exports not yet scoped**; no claims of end-to-end scoped management.
- View-only grant does not authorize edits, approvals or task execution.
- Avoid granting general company-wide `edit` to users intended for department-only access.
- Requires Moodle live security acceptance incl. cross-company and revoked assignment checks.

STATIC QA: PHP lint PASS; Moodle runtime and PHPUnit tests NOT RUN. Limited feature; do NOT treat as final company ACL acceptance.
