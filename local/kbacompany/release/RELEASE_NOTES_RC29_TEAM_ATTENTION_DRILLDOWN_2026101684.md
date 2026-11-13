# RC29 build 2026101684 — Team Attention Drill-down

- Makes the team attention summary cards clickable without introducing an employee ranking.
- Adds a presentation-only `teamsignal` filter for Attention / Insufficient data / Improving / Stable.
- Makes each employee management signal a direct link into the relevant canonical Battle Plan context:
  - task/operational attention -> Tasks;
  - insufficient metric data / effectiveness issue -> Metrics;
  - otherwise -> the Battle Plan overview.
- Keeps Battle Plan ACL, lifecycle, privacy, company isolation and persistence unchanged.
- No database schema changes.
