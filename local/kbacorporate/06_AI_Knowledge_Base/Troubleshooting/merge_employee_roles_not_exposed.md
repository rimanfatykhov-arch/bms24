# merge_employee_roles_not_exposed

Symptom: KBA Connect reads Bitrix rolecodes but reports `merge_employee_roles_not_exposed`.

Resolution: install Corporate Dashboard 0.14.6 / Public API 1.13 and complete Moodle DB upgrade to build 2026081804. Verify `bridge::contract()` contains `merge_employee_roles` and Health/API diagnostics show the new build.
