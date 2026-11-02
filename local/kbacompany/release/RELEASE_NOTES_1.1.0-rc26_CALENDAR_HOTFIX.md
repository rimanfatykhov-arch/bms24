# BMS24 RC26 build2026101357 — Employee Calendar Card

- Added Calendar tab to existing Employee record.
- Uses canonical calendar_workspace_service::today_snapshot; no parallel calendar registry.
- Personal event details only when record user ID equals logged-in Moodle user ID and can_inspect passes.
- Other employee cards display privacy note, not their private data or logged-in actor’s events.
- No schema change. PHP lint verification and Moodle live acceptance are separate checks; live NOT RUN.
- Follow-up: team/employee schedule access requires explicit role-specific contract and safe projection.
