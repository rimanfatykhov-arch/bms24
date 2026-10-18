# local_kbacompany 1.1.0-alpha4 — BMS Calendar CRUD

Build: 2026100618

- Adds controlled create/edit/delete for manual Moodle Calendar events inside BMS24.
- Moodle Core Calendar remains source of truth; no local calendar table is introduced.
- Personal events require Moodle own-calendar permission.
- Course events are limited to courses explicitly linked to the current Company and require Moodle calendar management permission in that course.
- Activity/module, subscription and group-generated events stay read-only and must be changed in their owning subsystem.
- Mutations are POST-only and protected by sesskey; delete requires explicit confirmation.
- No schema/ACL/privacy changes.
