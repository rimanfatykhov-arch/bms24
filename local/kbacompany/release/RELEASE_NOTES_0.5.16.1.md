# BMS 0.5.16.1 — Canonical Company Membership Null Safety

Build: `2026091515`

## Fix

An integration-created canonical company can be visible to a site administrator or another user with `local/kbacompany:viewallcompanies` even when that viewer has no row in `local_kbacompany_member`. Moodle `$DB->get_record()` returns `false` for that normal case. v0.5.16 passed that boolean into `membership_roles(?stdClass)`, causing a TypeError.

The canonical company DTO now normalises the missing record to `null` before strict role projection. This preserves the intended contract: no membership means no company-scoped roles, not an exception.

## Scope

- schema-neutral
- no access.php changes
- no services.php changes
- no Bitrix/KBA Connect contract changes
- no local_kbacorporate changes
