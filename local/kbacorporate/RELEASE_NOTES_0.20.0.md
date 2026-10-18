# KBA Corporate Dashboard 0.20.0

Build: `2026090237`  
Public API: `1.16`  
ERP exchange contract: `1`

- Added a stable Moodle REST boundary for future Odoo/BMS Digital ERP integration.
- Added provider-neutral external ID mappings and idempotent upserts for companies, employees and business-role assignments.
- Added paged outbound company snapshots for roles, departments, confirmed competencies and development progress.
- Added a dedicated high-risk capability and a disabled, restricted External Service.
- Excluded employee PII and evidence text from outbound snapshots.
- Preserved manual role assignments and all existing Bitrix24/KBA Connect API methods.

No database schema change is required. Moodle registers the capability and External Service during the standard plugin upgrade.
