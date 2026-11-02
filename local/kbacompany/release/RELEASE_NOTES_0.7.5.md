# local_kbacompany 0.7.5 — Development Plan

Build: 2026092313

- Added company-scoped individual development plans generated from competency gaps confirmed by approved assessments.
- Added development actions with competency, baseline/target level, type, due date, responsible employee, learning URL, status and result evidence.
- Added lifecycle draft → active → completed/cancelled and planned → in progress → completed/cancelled.
- Added entry from Employee Competency Profile.
- Preserved learning ownership in Moodle/KBA Corporate; no duplicate course/enrolment/completion engine was created.
- Completing a development action or course does not update employee competency fact; reassessment/approval remains required.
- Added privacy metadata/export/delete support for development plans.
- Schema additive: `local_kbacompany_devplan`, `local_kbacompany_devitem`.
