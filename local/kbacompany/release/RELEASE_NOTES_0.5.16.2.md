# BMS 0.5.16.2 — Company Adoption & Integration Stabilization

Build: `2026091516`

This hotfix stabilizes the BMS Company/Employee bridge after production validation. When a Bitrix Company was admitted before the Corporate Company identity was available, v0.5.16 could create a second integration Company row. The hotfix makes the historical legacy-preserved Company UUID canonical once the Corporate identity is known.

## Behaviour

- no Company UUID from the historical BMS/Corporate context is replaced;
- the Bitrix external mapping is transferred to that historical UUID;
- active integration-created employee memberships move to the canonical Company;
- unfinished outbox commands move with the canonical Company and gain the external Company ID;
- the fresh integration duplicate is archived as `superseded`;
- independent business data on the duplicate blocks automatic reconciliation rather than being moved silently;
- integration diagnostics detect the installed KBA Connect bridge class correctly.

No database schema changes are introduced.
