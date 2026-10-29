# RC29 build 2026101680 — Management cycle productivity and effectiveness

## Added

- Battle Plan overview now contains a **Productivity and effectiveness** management-cycle block.
- **Task productivity** = completed canonical Battle Plan tasks / all canonical Battle Plan tasks.
- **Metric effectiveness** = metrics in `ok` state / all metrics that already have an assessment (`ok + risk + critical`).
- **Metric data coverage** = assessed metrics / all projected weekly metrics, with `nodata` kept explicit.
- When previous-week data exists, productivity and effectiveness show percentage-point movement vs the previous week.

## Method boundary

The two dimensions are intentionally **not averaged into one employee rating**. Task completion and business-result attainment answer different management questions. No new thresholds, persistence, employee scoring, ACL, lifecycle, privacy or company-isolation rules were introduced.
