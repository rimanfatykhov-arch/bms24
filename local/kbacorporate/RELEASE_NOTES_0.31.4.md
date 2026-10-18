# KBA Corporate Dashboard v0.31.4 — Course Coverage Integrity

**Component:** `local_kbacorporate`  
**Build:** `2026090614`  
**Classification:** MINOR, backward-compatible  
**Date:** 2026-09-08

## Purpose

Add a non-destructive integrity layer for the chain:

`Business Role → Competency → Moodle Course → GAP → Moodle Learning Plan`.

The previous release allowed a manager to create standard Moodle course-to-competency relations. This release verifies whether those relations are complete and trustworthy before a role profile is used to generate employee learning routes.

## Added

- New page `role_course_coverage_integrity.php`.
- Role coverage status based on mandatory and supporting competencies.
- Separate lists of:
  - mandatory competencies without an active company course;
  - supporting competencies without an active company course;
  - active company courses without any standard Moodle competency relation;
  - duplicate Moodle `course ↔ competency` rows in the current role/company scope;
  - role competency mappings to hidden courses or inactive company-course assignments.
- Direct navigation from:
  - business-role profile;
  - role course coverage page;
  - role learning matrix.
- Stable anchors from integrity findings back to the exact competency in the coverage editor.
- Additional regression guard for active company courses without competencies.

## Readiness semantics

- **Requires courses:** one or more mandatory competencies have no active company course, or duplicate rows exist.
- **Partial:** mandatory coverage is complete, but supporting gaps or catalogue issues remain.
- **Ready:** all role competencies are covered and no duplicates/out-of-catalogue mappings are detected.

## Safety contract

- No database schema changes.
- No automatic creation, deletion or reassignment of courses or competencies.
- No automatic deletion of duplicate Moodle core records.
- No automatic removal of out-of-scope mappings.
- The page is diagnostic; fixes remain explicit manager actions.
- Standard Moodle `competency_coursecomp` remains the authoritative relation.

## Upgrade path

In-place upgrade from `v0.31.3` or earlier supported plugin versions. Existing data is not rewritten.
