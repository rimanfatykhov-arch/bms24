# KBA Corporate Dashboard v0.31.3 — Role Competency Course Coverage

## Purpose
Close the next link in the learning architecture: Role → Competency → Course → GAP → Moodle Learning Plan.

## Added
- New page `role_course_coverage.php` for one business role in one company context.
- Coverage metrics for all role competencies and mandatory competencies.
- Company-scoped list of active visible courses.
- Safe creation of Moodle core `course ↔ competency` relations.
- Idempotent add operation: existing relations are not duplicated.
- Direct navigation from the business-role profile and role learning matrix.

## Safety / contract
- No database schema changes.
- No automatic or destructive removal of course-competency mappings.
- A Moodle course-competency relation is global to the Moodle course; the page clearly states this.
- Only active visible courses already assigned to the selected company are offered.
- Domain nodes `BMS-Dxx` remain excluded because the page operates on working role competencies only.

## Downstream effect
Existing GAP and Learning Plan code already reads Moodle `competency_coursecomp`. A newly created mapping is read automatically by the existing matrix and recommendation pipeline. For a specific learner, the GAP builder recommends the course when it is actionable for that learner (for example enrolled, covered by an active company licence, or available through an open enrolment method).
