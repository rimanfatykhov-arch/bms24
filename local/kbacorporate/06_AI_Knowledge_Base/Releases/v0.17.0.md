# KBA Corporate Dashboard 0.17.0 — Role Competency Profile Foundation

## Added
- KBA business roles with canonical `BMS-ROLE-*` codes and UUIDs.
- Role competency target profiles with target level, priority and required flag.
- Employee business-role assignments scoped by company.
- Provider-neutral external mapping table for Bitrix24 and future ERP/HR systems.
- Target profile resolver that merges multiple roles using the highest target level for duplicate competencies.
- Role-required competencies are surfaced in `Мои компетенции`, including competencies not yet linked to an enrolled course.
- `Требуется для роли` source label in competency cards.
- Public internal bridge API 1.14: `get_business_role_catalog`, `assign_business_role`, `get_employee_target_profile`.

## Preserved
- Existing `employee`, `corporate_manager`, `department_manager`, `hr` membership flags remain access/integration roles and are not converted into competency business roles.
- Moodle core competency results and evidence remain the source of current competency state.
- Existing KBA Connect/Bitrix24 sales and enrolment flow remains compatible.

## Database
Adds four tables:
- `local_kbacorp_busrole`
- `local_kbacorp_rolecomp`
- `local_kbacorp_member_busrole`
- `local_kbacorp_extmap`

Upgrade is in-place from 0.16.10; do not uninstall the plugin.

## Compatibility
- Moodle 4.0+
- PHP baseline unchanged
