# AI DECISION LOG — v0.31.3

## Decision
Use Moodle core `competency_coursecomp` as the authoritative Course ↔ Competency relation instead of creating a parallel KBA mapping table.

## Reason
The project previously selected Moodle Learning Plans as the authoritative learning-plan mechanism. Keeping Moodle course competency relations authoritative preserves one coherent chain:
Business Role → KBA role requirement → Moodle competency → Moodle course competency → Moodle Learning Plan.

## Safety decision
This release can add mappings only. It does not remove them because a course-to-competency mapping is global to the Moodle course and removal could affect other company contexts using the same course.

## Company boundary
The UI only offers active visible courses already assigned to the selected company via `local_kbacorp_course`, while the actual course competency relation remains the standard Moodle global relation.
