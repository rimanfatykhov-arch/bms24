# KBA Corporate Dashboard v0.31.2 — Automatic GAP Learning Plan Builder

## Scope
Adds a controlled one-click workflow from an employee business-role competency GAP to a standard Moodle Learning Plan.

## Added
- `gap_learning_plan.php`: employee selector, GAP preview, recommended course coverage and Apply action.
- Non-destructive `preview_gap_learning_plan()` and `build_gap_learning_plan()` services.
- Direct entry from Role ↔ Competency ↔ Learning Plan ↔ Course Matrix and Development Plans.
- Automatic creation of a KBA draft plan when no open plan exists.
- Automatic synchronisation of the resulting competency membership to Moodle core Learning Plans.
- Existing draft plans are refreshed by adding/updating current GAP items.
- Existing items outside the current GAP are retained; there is no automatic deletion.
- Pending/active plans are protected from automatic membership changes and must be explicitly returned to draft.

## Database
No schema changes.

## Compatibility
Moodle 4.0+, standard competency/Learning Plan subsystem must be enabled for Moodle plan synchronisation.
