# local_kbacompany 0.9.10.2 build2026100604 — IDEF0 Tab Routing Fix

Hotfix over exact 0.9.10.1 build2026100603.

The IDEF0 tab contains a digit. Moodle `PARAM_ALPHA` strips digits, turning `idef0` into `idef`; the existing allowlist then rejected the value and returned to the default tab. Both Process detail and Process design routes now read `tab` with `PARAM_ALPHANUMEXT`, while the existing strict allowlists remain unchanged.

No schema, ACL, privacy, business logic, Process Model, ICOM, Semantic Core or presentation-layout changes.
