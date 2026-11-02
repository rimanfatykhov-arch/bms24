# local_kbacompany 1.1.0-alpha14.1 — Calendar Moodle 4.1 html_writer compatibility hotfix

Build: 2026101205

## Fix
- Replaced unsupported `html_writer::strong()` calls in Calendar day planner / meeting finder rendering with Moodle 4.1-compatible `html_writer::tag('strong', ...)`.
- Fixes fatal error `Call to undefined method html_writer::strong()` in `/local/kbacompany/calendar.php`.
- No business logic, data model, permissions, recurrence semantics, or notification behavior changed.

## Compatibility
- Exact base: 1.1.0-alpha14 build2026101204.
- No database schema change.
