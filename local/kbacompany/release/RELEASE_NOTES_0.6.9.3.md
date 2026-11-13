# BMS v0.6.9.3 — Learning Contour Entry

Build 2026092133.

- Added company-scoped `learning.php` as the BMS entry to development and learning.
- Added **Люди и развитие / People & development** App Shell group with Employees and Development & learning routes owned by `local_kbacompany`.
- Workspace Learning card now opens the same learning contour and uses actual active company-course enrolments when Corporate data is available.
- Manager mode shows company employees, employees training/completed, purchased/allocated/free seats, learning warnings, count-level seat/enrolment drift and per-employee learning status.
- Employee mode shows **Моё развитие / My development**, in-progress/not-started/completed states and `Continue learning` for an active company course.
- Corporate drill-down links are capability-aware and use the real KBA Corporate routes: `company_courses.php`, `progress.php`, `licenses.php`, `index.php`.
- KBA Corporate remains the source of licences/course catalogue/management learning summary; Moodle Core remains the source of enrolment/completion.
- Detailed user-level licence ↔ enrolment reconciliation remains in KBA Corporate; BMS never changes enrolments from reconciliation.
- No schema, ACL, enrolment, licence-allocation, competency, assessment, recommendation or Development Plan mutation.
