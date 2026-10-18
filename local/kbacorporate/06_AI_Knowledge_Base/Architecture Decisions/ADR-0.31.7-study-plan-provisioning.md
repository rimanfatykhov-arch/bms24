# ADR-0.31.7 — Explicit study-plan provisioning

## Decision

Use a separate confirmation endpoint between selectable preview and writes to KBA/Moodle.

## Data ownership

- KBA: role target, gap baseline, priority, mandatory flag, course recommendation, deadline and approval workflow.
- Moodle: standard role templates, learning plans and competency membership.

## Guarantees

- server-side revalidation;
- idempotent repeated execution;
- owner verification;
- exact competency-set verification;
- KBA persistence when Moodle sync is temporarily unavailable;
- no automatic course enrolment.
