# KBA Corporate Dashboard 0.16.3

## Competencies from enrolled courses

- `Мои компетенции` no longer requires a Moodle Learning Plan to show course competencies.
- The dashboard reads `competency_coursecomp` for all active courses in which the user is enrolled.
- Competencies from courses, Learning Plans and existing user competency evidence are merged without duplicates.
- Each competency card shows its source course and/or development plan.
- For `BMS-CRS-OWN-MBS-001 — Практикум «Масштаб бизнеса через систему»`, the seven blueprint competencies use target level 3.
- A Learning Plan remains optional and additive.
- No new database tables or destructive migrations.
