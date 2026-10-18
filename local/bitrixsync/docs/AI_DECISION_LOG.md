# AI Decision Log — KBA Connect v5.0.32 RC1

## Decision 1 — Bitrix24 integration ownership
Bitrix24 REST remains exclusively in KBA Connect. `local_kbacorporate` stores no Bitrix webhook, OAuth token or access token.

## Decision 2 — Contact role field
Corporate learning roles belong to a person and therefore the field is provisioned only on CRM Contact, never Deal, Company or Lead.

## Decision 3 — Schema authority
KBA Connect does not invent the corporate role list. The authoritative role schema is supplied by KBA Corporate Dashboard Public API v1.10 via `bridge::get_bitrix_role_schema()`.

## Decision 4 — Idempotent, non-destructive provisioning
Provisioning never deletes a field or existing business values. Compatible fields are reused, missing enums are appended, unsafe schema conflicts stop with a structured error.

## Decision 5 — Stable role codes
Bitrix numeric enum IDs are portal-specific. KBA Connect derives and caches `enum_id → rolecode` from enum XML_ID values.

## Decision 6 — MERGE role strategy
Role delivery to Corporate Dashboard is additive in this release. Existing manually assigned roles are not removed.

## Decision 7 — Backward compatibility
Corporate Dashboard API v1.10 is required only for role-schema features. Existing API v1.9 company/employee/licence integration remains compatible. Corporate role failures must not roll back Moodle user/enrolment operations.

## 2026-08-18 — Corporate Role Schema settings location

Decision: Corporate Dashboard connection, internal Bearer security, Bitrix Contact corporate-role field status, role contract, schema check and provisioning are presented together on `KBA Connect → Corporate`.

Reason: Corporate integration operations should have one administration surface. The backend boundary remains unchanged: Bitrix24 credentials stay only in KBA Connect and Corporate Dashboard communicates through its internal API contract.
