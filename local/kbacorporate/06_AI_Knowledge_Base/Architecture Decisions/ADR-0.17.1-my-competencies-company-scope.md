# ADR — v0.17.1 My Competencies Company Scope

Date: 2026-09-01
Status: accepted

## Decision

Business-role targets and course recommendations require one explicit company context. If an employee has exactly one accessible employee membership, that company is selected automatically. If several are available and no `companyid` is provided, Corporate Dashboard shows the personal Moodle projection without role targets or recommendations and asks for a company selection.

The authoritative current level is read only from `competency_usercomp.grade`. Course-scoped competency grades and course completion may create provisional evidence but cannot update or replace the authoritative level.

## Consequences

- No target profile can combine roles belonging to different customers.
- Cross-user views do not reveal course names outside the selected company.
- `Не оценено` remains distinct from stored level `0`.
- Course recommendations are limited to active `local_kbacorp_course` assignments mapped through Moodle `competency_coursecomp`.
- Existing additive `merge_employee_roles()` behavior remains unchanged because access roles and competency business roles are separate domains.
