# BMS24 1.1.0-rc29 build 2026101670

## Battle Plan: adjacent-week comparison

- Adds a read-only comparison between the selected Battle Plan and the immediately preceding plan for the same employee and position.
- Shows task totals, completed/open work, and canonical indicator condition counts for both weeks.
- Links directly to the previous Battle Plan.
- Reuses existing task and indicator projections; no new analytics storage or parallel lifecycle logic.
- No DB schema, ACL/RBAC, privacy, lifecycle, or company-isolation changes.
