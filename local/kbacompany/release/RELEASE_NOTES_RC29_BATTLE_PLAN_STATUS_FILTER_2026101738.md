# BMS24 RC29 build2026101738 — Battle Plan status quick filter

- Adds a visible status navigation immediately above the Battle Plan list: All, Draft, Submitted, Active, Closed.
- Uses the canonical `status` GET filter and existing `battle_plan_service::listing()` ACL-scoped query.
- Preserves `scope`, `q`, `user` and `week`; resets pagination to page 1 on status changes.
- Existing advanced status selection remains available in the filter form.
- No DDL, no new states, no write paths.
- Live Moodle acceptance remains NOT RUN.
