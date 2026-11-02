# BMS24 RC29 — Task Center Battle Plan filter — build 2026101676

- Task Center list/kanban can be filtered by an existing visible Battle Plan.
- Filter choices are limited to Battle Plans already visible to the current actor.
- Filtering is applied before pagination/counting through the existing canonical task query.
- Existing Task ↔ Battle Plan relations remain authoritative; no copies are created.
- No DB schema, ACL/RBAC, lifecycle, privacy, or company-isolation changes.
