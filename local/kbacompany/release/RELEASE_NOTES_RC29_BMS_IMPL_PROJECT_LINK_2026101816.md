# BMS24 build2026101816 — Corporate program ↔ Administrative Scale Project

BMS-owned explicit one-to-one mapping (not automatic project creation). Requires Corporate 0.40.5/API 1.23.

## Install
1. Back up Moodle code and database.
2. Update `local_kbacorporate` to `2026102514`.
3. Update `local_kbacompany` to `2026101816`, run Moodle upgrade and purge caches.
4. Ensure `kbacorporate_impl` company source is bound and Corporate company management rights apply.
5. Publish an Administrative Scale Project, then open Administrative Scale > BMS Implementation Projects.
6. Link a paid active/paused/completed Corporate program to a published project.

## Behaviour / non-goals
- One Corporate program maps to one BMS project per company; one project maps to at most one program.
- No Corporate task/attachment/comments copied and no status mutation.
- Link and unlink require POST + sesskey; both plugin ACL checks; immutable company identity mapping.
- Only already published, non-stale BMS projects qualify. No project creation or automatic task-to-project binding.
- Corporate aggregates are task completion counters, not validated BMS methodology progress.

## Acceptance
Test authenticated management with paired companies, cross-company tampering, direct URL, duplicate link,
link withdrawal, published-to-archived project, Corporate paused/completed/unpaid programs, pagination,
real Moodle 4.1/PHP 8.1 and MySQL upgrade. The latter are not verified by local static tests.
