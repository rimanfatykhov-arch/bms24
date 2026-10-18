# KBA Corporate Dashboard 0.14.6 — Test Report

Target: Moodle 4.1.22 / PHP 7.4.33.

## Static verification
- All PHP files lint successfully.
- `db/install.xml` parses successfully.
- `db/upgrade.php` contains savepoint `2026081804`.
- Public contract exposes `merge_employee_roles` since API 1.13.
- Existing pairing/schema/health methods remain present.
- ZIP root is `kbacorporate/`.

## Regression scenarios

### Elmira
Existing roles: `employee`.
Incoming: `employee`, `department_manager`.
Expected final: `employee`, `department_manager`.

### Artur
Existing roles: `employee`, `corporate_manager`.
Incoming: `employee`.
Expected final: `employee`, `corporate_manager`.

### Idempotency
Existing roles: `employee`, `department_manager`.
Incoming: `employee`, `department_manager`.
Expected status: `unchanged`; no duplicate membership or role.

### Unknown role
Incoming: `employee`, `unknown_role`.
Expected: employee processed; unknown role returned in `ignored_roles`; no fatal error.

## Production note
Live execution against the production Moodle database was not performed during packaging. Backup and normal Moodle upgrade procedure are required before production deployment.
