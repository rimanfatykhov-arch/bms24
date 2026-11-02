# RC29 build 2026101682 — Team productivity and effectiveness

## Scope
- Adds a management-team view to Battle Plan overview for the selected week.
- Keeps task productivity, metric effectiveness and metric-data coverage as separate measures.
- Adds factual week-over-week deltas per employee/position.
- Uses weighted totals for the visible management slice instead of averaging employee percentages.
- Adds direct links back to each canonical Battle Plan.

## Method
- Productivity = completed canonical Battle Plan tasks / visible canonical Battle Plan tasks.
- Effectiveness = metrics in `ok` / evaluated metrics (`ok + risk + critical`).
- Data coverage = evaluated metrics / all projected metrics.
- Missing metric data is not treated as zero effectiveness.
- No composite employee score or ranking is introduced.

## Impact
- Read-model/presentation only.
- No schema, ACL/RBAC, lifecycle, privacy or company-isolation changes.
