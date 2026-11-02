# BMS 0.6.9.11.1 — Company Context & AI Stabilization

Build 2026092208.

Schema-neutral stabilization release over the 0.6.9.11 checkpoint.

## Included
- BMS Assistant field whitelist, proposal-only UX and safe AJAX error handling.
- First read-only Market Data Tool Gateway adapter for the Rosstat Open Data catalogue.
- Business Architecture guided sequence aligned with the canonical BMS chain: Owner → Company Philosophy → Market → Business Model → Financial Model → Gaps / Strategy / Administrative Scale → Organization → Processes → Functions / Positions / Roles → Documents / Indicators / Improvement.
- Business Architecture readiness is now separated for Owner, Philosophy, Market, Business Model and Financial Model instead of the legacy combined cards.
- Learning contour runtime hotfix for Moodle enrolment schema: `enrol.courseid` is used instead of the non-existent `enrol.course` field.

## Boundaries
- No schema changes.
- No direct AI writes to canonical BMS data.
- No guessed ISWORK endpoint.
- External market adapter is read-only and only creates a candidate/source selection until the user confirms a normal domain write.
