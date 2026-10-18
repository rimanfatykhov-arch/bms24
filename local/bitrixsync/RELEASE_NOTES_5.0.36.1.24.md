# KBA Connect 5.0.36.1.24 — Deal / License Reconciliation

- Added a dedicated admin reconciliation page for Bitrix Deal product quantities vs Corporate licence packages.
- Missing packages and quantity drift can be repaired idempotently by Deal ID + Product Row ID.
- Existing package contract dates are preserved during quantity repair.
- Repair is licence-only: Moodle enrolments and employee seat allocations are never changed.
- No database schema, ACL, enrolment or competency changes.
