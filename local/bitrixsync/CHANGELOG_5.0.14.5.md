# KBA Connect v5.0.14.5 — Changelog

## Bitrix REST Hardening

- Hardened REST response parsing.
- Added BOM/whitespace cleanup before JSON decoding.
- Added redirects and compression support.
- Raised timeout for CRM userfield discovery.
- Added one-time JSON transport fallback for read-only methods.
- Explicitly prevents retry of write/mutating REST methods.
- Added detailed non-JSON diagnostics.
- Added Bitrix24 CRM REST Probe CLI.
- Added Bitrix24 REST Diagnostics page in Moodle admin.
- Production Finalize now fails cleanly with a diagnostic next step.
- Automatic inbound remains OFF on upgrade.
