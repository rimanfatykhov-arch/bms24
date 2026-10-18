# KBA Connect v5.0.34.5 — Role Schema Normalization Fix

- Removed unsafe fallback that treated the entire schema response as roles.
- Accepts roles only from an explicit `roles` container.
- For paired Corporate API 1.11+, uses the canonical four-role contract as fallback.
- Adds a hard whitelist: employee, corporate_manager, department_manager, hr.
- XML_ID is always normalized to the stable rolecode.
- Unknown metadata such as `contact`, `kba_corporate_roles`, or field labels can no longer become Bitrix enum values.
- Provisioning fails safely if the normalized role contract is incomplete.
- Corporate UI now displays Raw schema keys, Normalized roles, Unexpected values, and Missing roles.
