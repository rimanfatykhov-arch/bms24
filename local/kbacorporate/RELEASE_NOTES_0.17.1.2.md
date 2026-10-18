# KBA Corporate Dashboard 0.17.1.2 — Business Role Management UI

Release date: 2026-09-01

Build: `2026090102`

Public API: `1.15`

## Outcome

Administrators and authorised corporate managers can now configure the business-role data already consumed by **My competencies**:

- create company business roles with stable `BMS-ROLE-*` codes;
- define role → Moodle competency → target level, priority and required marker;
- assign several business roles to one employee;
- deactivate one explicit assignment while retaining history;
- use global roles without allowing corporate managers to edit their definitions.

## Security and data rules

- Every page requires `local/kbacorporate:managerolecompetencies` and full access to one explicit company.
- Company roles cannot be opened or edited from another company context.
- Global role definitions are read-only for corporate managers.
- Assignment is additive: selecting roles never removes existing manual or integration assignments.
- Role requirements remain separate from Moodle's authoritative current competency levels and evidence.

## Upgrade

Install the full ZIP over build `2026090101`. Do not uninstall the plugin. Build `2026090102` adds a non-destructive savepoint and makes no database schema changes.
