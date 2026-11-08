# BMS 0.7.0 — People Foundation

## Scope

0.7.0 starts the BMS People architecture without implementing recruitment, 360, adaptation or offboarding workflows prematurely.

Delivered:

- top-level BMS «Люди / People» workspace;
- compact navigation: Employees and Learning are reached through People instead of duplicating flat sidebar items;
- Company-scoped governance mapping to Moodle Core Competency Frameworks;
- company-scoped competency catalog facade that resolves permitted frameworks before fetching competencies;
- versioned Role DNA root with UUID + revision + immutable stable human reference `RLD-*`;
- dedicated `managepeople` and explicit `viewpeopleprivate` capabilities;
- BMS People service contracts and privacy metadata foundation.

## Architecture boundaries

- Moodle Core Competencies remains the technical canonical competency catalog/engine.
- BMS owns competency governance, role requirements, assessment/evidence and confirmed management state.
- 0.7.0 does not create a second competency registry.
- Course Completion does not equal Competency Achievement.
- Role DNA semantic blocks are deliberately deferred to 0.7.1.
- Recruitment, Professional Recognition, 360, Adaptation, Development and Offboarding remain future 0.7.x increments.
- ALT-SAL-NC-001 is the reference implementation, not automatically seeded into customer data.
