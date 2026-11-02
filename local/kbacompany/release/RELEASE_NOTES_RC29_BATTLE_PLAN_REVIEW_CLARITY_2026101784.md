# BMS24 1.1.0-rc29 build2026101784 — Battle Plan review clarity

- Clarifies approval vs return-to-draft workflow, including the persisted correction history.
- Explains that selecting no task means the correction applies to the whole plan.
- Makes mandatory reviewer feedback on return explicit.
- Rejects invalid review actions before the service call; the service remains authoritative.
- No database schema changes; upgrade checkpoint only.
- Compatible PHP 8.1 syntax.
- Static QA only; Moodle live tests outstanding.
