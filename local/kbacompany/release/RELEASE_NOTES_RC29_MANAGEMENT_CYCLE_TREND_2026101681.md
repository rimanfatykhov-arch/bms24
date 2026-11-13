# RC29 build 2026101681 — Management cycle trend

## Added

- Battle Plan overview now shows a bounded trend of up to 8 visible weekly plans for the same employee and position.
- Weekly trend dimensions remain separate:
  - task productivity = completed tasks / canonical Battle Plan tasks;
  - metric effectiveness = `ok` metrics / evaluated metrics (`ok + risk + critical`);
  - metric data coverage = evaluated metrics / all projected metrics.
- Current week is highlighted; missing data is rendered as unavailable rather than as zero effectiveness.

## Architecture boundary

The trend reuses existing task and indicator projections. No new persistence, employee score, threshold model, ACL, lifecycle, privacy or company-isolation semantics were introduced.
