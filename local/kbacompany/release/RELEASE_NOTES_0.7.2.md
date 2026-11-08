# BMS 0.7.2 — Employee Competency Profile

Build: 2026092310

- Adds an employee competency profile linked to the employee's current canonical Position assignment.
- Compares employee evidence snapshots with the latest published Position Competency Profile.
- Shows target level, current evidence level, GAP and missing evidence without treating missing data as zero competence.
- Stores company-scoped evidence snapshots in `local_kbacompany_empcomp` with source, evidence text/URL and recorder metadata.
- Course completion does not automatically confirm competence. Moodle remains a possible evidence source only.
- Formal assessment workflow, methods, reviewers and attainment decisions remain deferred to 0.7.3 Assessment Engine.
- Position changes recompute comparison against the new published position profile without rewriting employee evidence.
