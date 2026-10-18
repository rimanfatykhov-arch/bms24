# local_kbacompany 1.1.0-alpha2 — BMS Calendar language-cache hotfix

Build: 2026100616

- Fixes Calendar workspace labels rendering as `[[bmscalendar_*]]` when Moodle still has the previous component string map cached after deployment.
- Adds a RU/EN runtime fallback only for the additive BMS Calendar workspace. Canonical Moodle language files remain the primary source.
- Calendar title, menu label and all workspace labels now use the same resilient resolver.
- No DB schema, ACL, calendar data ownership or Company scoping changes.
- Moodle Core Calendar remains source of truth; BMS projection remains read-only.
