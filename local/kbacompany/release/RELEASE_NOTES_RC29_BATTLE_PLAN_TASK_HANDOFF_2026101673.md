# RC29 build 2026101673 — Battle Plan to Task Center handoff

- Adds a direct `Create management task` action to the Battle Plan next-actions panel when attention signals exist.
- Opens the canonical Task Center create form with safe presentation-only prefill for assignee, reviewer/observer, plan period and Battle Plan link.
- GET prefill never creates or changes a task; canonical Task Center POST validation, ACL, company isolation and Battle Plan linking remain authoritative.
- No database schema, lifecycle, privacy or capability changes.
