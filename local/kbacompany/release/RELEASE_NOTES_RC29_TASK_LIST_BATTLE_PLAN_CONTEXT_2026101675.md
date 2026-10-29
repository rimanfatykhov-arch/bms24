# BMS24 1.1.0-rc29 build 2026101675

## Task Center — Battle Plan context in list and kanban

- Linked Battle Plans are now visible directly in Task Center list rows and kanban cards.
- Each compact plan chip shows the weekly period and current Battle Plan status and links back to the canonical plan.
- At most two plan links are rendered per task; additional memberships are summarized with a compact count.
- Membership is loaded in one bounded, read-only batch projection for the current Task Center page rather than per-row queries.
- Existing task visibility and Battle Plan owner/reviewer/observer visibility remain authoritative.
- No task, Battle Plan, relation, DB schema, ACL, lifecycle, privacy, or company-isolation semantics changed.
