# TEST REPORT — KBA Connect v5.0.32 RC1

## Build-level checks
- PASS: `version.php` component/version/release present.
- PASS: `db/install.xml` preserved from working production branch.
- PASS: `db/upgrade.php` contains `xmldb_local_bitrixsync_upgrade()` and savepoint `2026083200`.
- PASS: `lang/en/local_bitrixsync.php` exists.
- PASS: `lang/ru/local_bitrixsync.php` exists.
- PASS: PHP syntax check for all changed PHP files.
- PASS: new code avoids PHP 8-only string helper functions and is written for PHP 7.4 compatibility.
- PASS: endpoint method restrictions implemented (GET status, POST provision).
- PASS: Bearer token comparison uses `hash_equals()` and the token is not logged.
- PASS: no `crm.contact.userfield.delete` operation exists in the role-schema service.
- PASS: role handoff strategy is MERGE-only.

## Isolated idempotency harness included
`cli/test_corporate_role_schema.php` validates with fake adapters:
1. first provision creates one field;
2. second provision creates no duplicate;
3. removed enum is restored with update, not a second field;
4. wrong field type returns safe `field_schema_conflict`.

This harness is intentionally isolated from real Bitrix24 and restores KBA Connect config values after execution.

## Production acceptance still required after install
- Configure identical internal Bearer token in Corporate Dashboard and KBA Connect.
- Corporate Dashboard v0.14.3 / Public API 1.10 detected.
- `schema/status` authenticates and returns `ready=false` before first provision if field is absent.
- First provision creates Contact field and all roles from Dashboard schema.
- Second provision is idempotent.
- Remove one enum manually in a safe test field scenario and confirm recovery.
- Confirm schema conflict produces JSON error, not HTML/PHP exception.
- For Arthur, select Employee + Corporate Manager and verify `read_contact_roles()` returns both machine codes.
- Confirm existing Bitrix → Moodle user/enrolment path remains operational if Corporate role API is unavailable.

## RC2 UI checks
- PASS: Corporate page contains existing Corporate API URL/Key controls.
- PASS: Internal Bearer token is editable without exposing the stored value.
- PASS: Empty secret fields preserve existing secrets.
- PASS: Token generation uses `random_bytes()` and POST + sesskey.
- PASS: Schema status is displayed through `corporate_role_schema_service::status()`.
- PASS: Provision action uses `corporate_role_schema_service::provision()` and remains idempotent.
- PASS: No Bitrix24 secret is rendered in the Corporate page.
