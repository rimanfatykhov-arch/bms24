# RELEASE NOTES — v0.31.2.1 Role Profile & GAP Matrix Integrity

**Component:** `local_kbacorporate`  
**Build:** `2026090609`  
**Classification:** PATCH  
**Moodle:** 4.0+

## Purpose

Prevent BMS domain/grouping nodes (`BMS-Dxx`) from being used as terminal business-role requirements and therefore from contaminating GAP calculations, Moodle Learning Plan templates, and course recommendations.

## Changes

- Adds `Role profile integrity` page with preview of every `BMS-Dxx` requirement and its direct child competencies.
- Adds explicit, confirmed expansion `BMS-Dxx → direct child competencies`; target level, priority and mandatory flag are inherited.
- Existing concrete role requirements are preserved and are not overwritten.
- A domain node is removed only when at least one direct child competency exists.
- New manual `BMS-Dxx` role requirements are blocked in the domain service and hidden from the role competency picker.
- GAP projection and Role ↔ Competency ↔ Learning Plan ↔ Course Matrix ignore legacy `BMS-Dxx` requirements until they are explicitly repaired.
- Moodle Learning Plan role-template synchronisation ignores domain nodes and removes them from a core template on the next explicit sync.
- Global role-template copy/update flows no longer propagate `BMS-Dxx` requirements.
- Fixes missing UI strings `[[competency]]` and `[[preview]]`.

## Data safety

No automatic destructive migration is performed. Existing role requirements remain unchanged after plugin upgrade until an authorised manager explicitly runs the expansion action. No database schema changes.

## Expected pilot flow

1. Open the business-role profile.
2. Open `Role profile integrity`.
3. Review domain nodes and direct child competencies.
4. Explicitly confirm expansion.
5. Verify domain nodes = 0.
6. Return to matrix, select employee, verify GAP and course coverage.
7. Only then preview/apply GAP Learning Plan.
