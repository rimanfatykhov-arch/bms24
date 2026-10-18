# BMS24 Architecture Boundaries

## BMS Management / Moodle

Owns management design, planning and governance:
- Company
- owner requirements
- philosophy / values / principles
- strategy
- business model
- organizational-functional structure (OFS)
- organization design
- roles
- positions
- employees / company membership history
- functions
- processes
- goals
- programs
- management projects
- indicators / KPI
- competencies
- development plans
- financial scenarios
- CFO / responsibility-center structure
- budgets and management financial plan
- management cycles
- audit
- CAPA
- improvement

## Odoo ERP

Owns transactional operational actual:
- operational CRM / sales execution
- invoices
- payments
- procurement
- expenses
- inventory
- manufacturing
- operational projects
- timesheets
- accounting transaction facts
- assets
- operational HR
- payroll
- leave/time off

BMS may plan/manage; Odoo records operational execution/fact.

## Moodle / KBA Corporate

Owns learning execution:
- courses
- content
- enrolments
- groups
- completion
- grades
- certificates
- learning evidence

## BMS Cloud

Owns infrastructure/control plane:
- customers
- workspaces
- entitlements
- applications
- environments
- domains
- provisioning
- releases
- deployments
- health
- backup metadata
- infrastructure audit

Do not implement these as hidden Moodle-domain ownership.

## CRM providers

Bitrix24 is the current provider.
Architecture must remain provider-neutral.

The BMS domain may know provider mappings and synchronization state.
It must not treat provider record IDs as canonical BMS identities.

## UPDATED 18.10.2026 — Presentation ownership

The BMS24 application shell/UI Kit moved into `local_kbacompany` as of builds 1640–1642. `theme_kba` remains an optional Moodle presentation host and legacy fallback, **not owner of current BMS24 shell/menu/component design**. Any contradictory older notes are superseded for this boundary. See `docs/17_UI_SHELL_AND_DESIGN_SYSTEM.md`.
