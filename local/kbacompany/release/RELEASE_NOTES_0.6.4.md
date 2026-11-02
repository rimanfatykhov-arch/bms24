# BMS v0.6.4 — Platform Boundary & Company Isolation Hardening

Build `2026092125`.

- Introduced `company_context_service` as the canonical company-scope boundary for BMS services and migrated domain/application service calls to it without changing existing authorization semantics.
- Added a minimal `local_kbacompany_cloudmap` projection for future Company UUID ↔ BMS Cloud Workspace UUID binding. No existing company is auto-mapped.
- Added `entitlement_service` with legacy-compatible default behavior so current customers keep all existing functionality until explicit Cloud cutover.
- Added provider-neutral `platform_event_envelope` contract with event UUID, Company UUID, entity revision and stable idempotency key.
- Added management-only Platform diagnostics and read-only `local_kbacompany_platform_context` external function.
- Hardened one Staffing Plan child read to include explicit `companyuuid` scope.
- Extended duplicate-company reconciliation conflict detection to protect Finance, Resource Gate and future Cloud Workspace mapping data.
- Added privacy metadata/export/anonymisation for Workspace mapping actor IDs.
- BMS Cloud Registry, Odoo runtime and subscriptions remain outside Moodle. KBA Connect and KBA Corporate ownership boundaries remain unchanged.
