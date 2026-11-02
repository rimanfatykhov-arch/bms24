# BMS 0.6.9.6 — Stable Code Autogeneration

Build `2026092136`.

This hardening release removes manual Stable Code entry from the Administrative Scale and introduces a reusable company-scoped stable-code allocator.

## What changed

- Administrative Scale Stable Code is generated server-side on record creation.
- Codes are immutable across rename/edit/new revisions.
- Archived codes are never reused.
- Existing historical/manual codes are preserved; the allocator adopts the highest existing generated suffix for the same Company + Administrative Scale kind before issuing the next code.
- Code sequences are company-scoped and kind-scoped. UUID remains the canonical identity.
- New reusable `stable_code_service` and `local_kbacompany_codecounter` infrastructure can be adopted by later BMS canonical domains without changing the identity model.
- Administrative Scale create/edit UI now shows either “will be assigned automatically” or the immutable assigned code; there is no editable code field.

## Prefixes

`LTG`, `GOAL`, `POL`, `PLN`, `PRG`, `STEP`, `PRJ`, `ORD`, `TSK`, `IDL`, `STAT`, `CKP`.

Examples: `PLN-0001`, `PRG-0001`, `TSK-0001`.

## Architecture boundary

Stable Code is a human-readable reference, not canonical identity. BMS Company UUID and entity UUID remain canonical. No KBA Corporate, enrolment, Competency, ERP actual, Cloud or Theme business-ownership changes.
