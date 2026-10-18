# v0.31.0 — Role ↔ Competency ↔ Learning Plan ↔ Course Matrix

## Purpose
Introduces the first consolidated matrix joining the existing KBA business-role competency profile, employee competency snapshot, individual development/learning plan, and company course mappings.

## Added
- New `role_learning_matrix.php` workspace.
- Role filter and optional employee filter.
- Role → competency → target level → current level → GAP view.
- Company course coverage for every required competency.
- Existing individual development plan status per competency.
- One-click opening/creation of an employee learning plan from GAPs.
- Entry point from the Business Roles workspace.

## Architecture
This release reuses existing authoritative domains and does not duplicate them:
- business role requirements: `local_kbacorp_busrole` / `local_kbacorp_rolecomp`;
- current competency state: Moodle competency subsystem via `competencies::get_user_snapshot()`;
- learning plan: `local_kbacorp_devplan` / `local_kbacorp_devitem`;
- course coverage: Moodle course competencies + company course scope.

No database schema change and no destructive migration.
