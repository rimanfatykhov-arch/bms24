# KBA Corporate Dashboard 0.16.5 — Competency Evidence Progress

Date: 24 August 2026
Status: Beta / pilot

## Changes
- Competency status `В развитии` now starts from real evidence, even before a final competency level is assigned.
- Added read-only projection of `competency_usercompcourse` course-scoped competency results.
- Course-scoped competency grades may provide the current level when the final user competency grade is not yet set.
- Evidence is grouped in the UI as `Подтверждение обучения`, `Проверка`, and `AI Оценка`.
- Removed Moodle branding from learner-facing competency evidence labels.
- Explicit proficiency remains required for `Подтверждено`; evidence alone never auto-confirms competence.

## Database
No local_kbacorporate schema changes.

## Compatibility
Moodle 4.0+.
