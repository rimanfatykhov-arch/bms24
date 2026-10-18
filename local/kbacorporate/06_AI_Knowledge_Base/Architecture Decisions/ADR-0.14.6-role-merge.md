# AI Decision Log — 0.14.6

## ADR: MERGE-only external role synchronisation
External integrations may add corporate business roles but must not remove existing/manual roles. Therefore `merge_employee_roles()` implements set union only.

## ADR: Keep unified membership
The existing `(companyid, userid)` unique membership is retained. A role-per-membership-row model is explicitly rejected because it would reintroduce duplicate users in the company UI.

## ADR: Extend current storage, do not introduce a new role table in this release
The existing membership already stores employee and corporate manager flags. To minimise upgrade risk, `department_manager` and `hr` are stored as two additional flags.

## ADR: Department manager business role does not grant department scope
`isdepartmentmanager=1` records the external business role. Existing scoped access remains controlled by `local_kbacorp_dept_member`; KBA Connect role synchronisation therefore cannot accidentally grant access to unrelated departments.

## ADR: Canonical version number
`0.14.5` was already released as Integration Health Center. Reusing the same number for different code would be unsafe. This merged release is therefore `0.14.6`, build `2026081804`, Public API `1.13`.
