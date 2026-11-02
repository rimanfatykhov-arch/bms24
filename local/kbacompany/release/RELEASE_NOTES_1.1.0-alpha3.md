# local_kbacompany 1.1.0-alpha3 — Moodle Calendar Scope Fix

Build: 2026100617

- Calendar defaults to **My calendar**, mirroring events already visible to the signed-in Moodle user.
- Adds explicit **Company** scope for events from courses bound to the current BMS Company.
- Keeps Moodle Core Calendar as source of truth; no event copies or new calendar tables.
- Personal mode is user-scoped only and does not scan other users or all Moodle courses.
- Company mode preserves explicit Company course binding and Company UUID boundary.
- Adds compatibility for `courseid` / `course_id` catalogue field aliases.
- RC2 remains unchanged; this is an alpha follow-up candidate.
