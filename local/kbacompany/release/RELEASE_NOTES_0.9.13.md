# local_kbacompany 0.9.13 — IDEF0 ↔ SIPOC Consistency

- Adds read-only consistency diagnostics between the IDEF0 and SIPOC projections of one canonical Process Model.
- Verifies Input/Output element identity and canonical Product bindings without copying or synchronising data.
- Distinguishes projection mismatch from missing Product formalisation.
- Adds the consistency state to both IDEF0 and SIPOC read views.
- Remediation routes to existing canonical CRUD only.
- No database, ACL, privacy, SIPOC write, BPMN write or execution-engine change.
