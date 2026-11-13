# BMS24 1.1.0-rc29 build2026101746 — Employee execution links

- Employee card > Battle Plan tab: new link to personal execution analytics, filtered by selected employee ID. Underlying analytics retain all server-side company/plan/task ACLs and require historical closure snapshots.
- Battle Plan > Management overview > personal analytics: conditional navigation to employee profile Battle Plan tab, shown only if personnel directory_record authorizes access.
- Existing tasks and plans are not copied or changed. No database schema changes.
- Historical data without closure snapshots are not synthesized.
- Live Moodle acceptance: NOT RUN.
