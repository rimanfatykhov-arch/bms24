# KBA Corporate Dashboard 0.17.1 — My Competencies

Release date: 2026-09-01
Build: `2026090100`
Public API: `1.15`

## Outcome

`Мои компетенции` is now a company-scoped employee development view. For each competency it can show the business-role target, authoritative current level, gap, latest assessment and courses assigned to the selected company that can help close the gap.

## Data rules

- Moodle `competency_usercomp.grade` is the authoritative current level.
- Course-scoped results and completion are provisional learning evidence only.
- A missing grade is `Не оценено`; it is not converted to level `0`.
- The role target is the maximum target across active roles in the selected company.
- Priority uses `critical > core > additional`; required is true when any selected role requires the competency.
- Roles from separate companies are never merged.
- An authenticated learner can always open their own profile; viewing another employee still requires the existing capability and company visibility checks.
- Level captions come from the configured Moodle scale when available.
- Hidden or otherwise unavailable courses are not exposed as recommendations.
- The role-competency table definition avoids redundant foreign-key indexes and passes Moodle 4.0 XMLDB collision rules.
- External mapping technical tokens are bounded so their unique composite index is valid on the production Moodle/MySQL stack.

## Upgrade

Upgrade in place from 0.16.10 or 0.17.0. Do not uninstall. A direct 0.16.10 upgrade first creates the 0.17.0 role-domain tables and then records the non-destructive 0.17.1 savepoint.

## Compatibility

Public API major version remains 1 and backwards compatible. API release 1.15 changes unsafe zero-company behavior: the global catalog returns global roles only, while an employee target profile with several memberships returns `company_context_required`.

## Release gate

Run Moodle PHPUnit and the manual multi-company acceptance scenarios in `TESTING.md` on the target Moodle stack before packaging or production deployment.
