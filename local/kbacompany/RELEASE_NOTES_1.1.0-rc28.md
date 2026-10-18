# BMS24 1.1.0-rc28 — RBAC 2.0 / first enforced slice

Build: 2026101460. Base: RC27 / build2026101459. Release status: CANDIDATE; production acceptance NOT RUN.

## Changes
- New company-scoped BMS24 **access roles**, independent from business/competency roles, Moodle profiles and job positions.
- Company owner (with existing management capability) or site administrator can open **BMS Settings → Роли и права**, create roles, generate Architect/CEO/Manager/Employee templates, assign and revoke verified Moodle user IDs, configure company-wide `management_cycle` view/create/edit grants. CEO template includes these grants but does not assign itself to users.
- Four new empty tables: `local_kbacompany_aclrole`, `...aclgrant`, `...aclassign`, `...aclaudit`; audit trails on role, grant and assignment changes. No automatic mass role assignments and no modifications to Moodle capabilities.
- Management-cycle navigation, controller and services use dedicated access role permissions for view/create/edit; pre-existing company owner/manager management permission retained as a compatibility fallback. Security is server-side and company-scoped; role editing uses POST+sesskey.
- Historical company membership and canonical BMS membership both supported when assigning roles; no matching by display name alone.
- PHPUnit test for revoke / company isolation / unsupported grants prepared but **NOT RUN** (no configured Moodle PHPUnit runtime).

## Explicit limitations / safety
- **Only `management_cycle` view/create/edit is active in this first slice.** Other objects are NOT granted by this new role service yet: task, KPI, battle plan, process, document, employee, communication, work calendar and integration permissions remain under their existing ACL services.
- Department/own/team/subtree scope and comment/approve/delete/export on management cycles are NOT ENABLED; these require separate resource-scope verification and service-level enforcement.
- CEO *job title* does not automatically grant CEO access role. An authorized role manager must verify and assign the user's Moodle ID explicitly.
- Old Moodle owner/manager capabilities and Company membership remain effective; custom-role permissions cannot revoke their pre-existing rights.
- Private HR content, owner-private, files and account privileges are not opened through this feature.

## Validation
Run static PHP, JS, XML, language-key and upgrade checks and archive integrity checks separately; consult acceptance matrix. PHPUnit, Behat, browser, company isolation with two real users, staging migration, backup/restore and performance are NOT RUN unless recorded as such.

## Installation
Back up Moodle DB and plugin directory, install on staging, run upgrade to 2026101460. Create role templates in **Настройки → Роли и права**, manually assign CEO role to the correct Moodle user after identity verification. Check both the sidebar and management-cycles access on authorized and unauthorized accounts. Do not update production privileges before negative-access acceptance.
