# BMS24 RC29 — build2026101514

## Scope
- Scoped proposal-only AI buttons added to editable Position Create/Edit fields `name` and `purpose`.
- Aligned target IDs (`pdu_name`, `pdu_purpose`), frontend field allowlist and server bridge allowlist.
- Employee Create/Edit: existing AI draft panel is available separately from sensitive identity/contact fields; no AI edits to personal fields.
- Roles: current page is a readonly aggregation/assignment view; existing draft panel is kept; no invented editable role form.
- Requires local_kbaai >= 0.9.4 with declared `position`, `employee`, `role` domains; uses existing provider, no change to local_kbaai code in this build.
- No AI automatic saving or evaluation of employees. No DB DDL.

## Verification
PHP lint, archive integrity and static field allowlist checks. No Moodle browser, LLM provider, user-role or cross-company end-to-end tests performed.
