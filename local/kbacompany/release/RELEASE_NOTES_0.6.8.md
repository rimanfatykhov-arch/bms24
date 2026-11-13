# BMS v0.6.8 — Cloud Workspace Mapping + Diagnostics

Build: 2026092129

- Stable Company UUID ↔ Workspace UUID binding.
- Versioned Cloud Workspace projection with monotonic revision.
- Provider-neutral ERP application state and integration-health diagnostics.
- Restricted Cloud API endpoints for Workspace projection contract/apply/status.
- Manager diagnostics show Cloud status, sync freshness, snapshot revision, ERP state and integration health.
- Existing entitlement rollout remains unchanged; legacy companies stay legacy-compatible until explicit cutover.
