# Integration Contract — BMS v0.5.16

## Ownership

### Bitrix24
Owns CRM Company, Contact and Deal data, including the Company flag «Клиент BMS».

### KBA Connect (`local_bitrixsync`)
Owns Bitrix24 credentials, REST, webhook processing, CRM field discovery and integration orchestration.

### BMS (`local_kbacompany`)
Owns canonical BMS Company, employee membership, external mappings for BMS identities, organization/positions, indicators, Battle Plans and canonical Tasks.

### KBA Corporate (`local_kbacorporate`)
Remains the Corporate Learning bounded context: corporate training membership, licences/seats, course/group allocation, progress, grades, BBB, certificates and learning analytics.

### Moodle Core
Owns User, Course, Enrolment, Completion and Grade.

## PHP integration API

BMS exposes service methods for KBA Connect:

- `integration_service::upsert_company_from_external()`
- `integration_service::upsert_employee_from_external()`
- `integration_service::pending_commands()`
- `integration_service::complete_command()`
- `integration_service::fail_command()`
- `integration_service::retry_command()`
- `integration_service::diagnostics()`

No Moodle external web-service function is added in v0.5.16; `db/services.php` remains unchanged. The contract is an internal PHP boundary between co-installed plugins.

## Company admission

KBA Connect MUST evaluate the Bitrix Company flag and pass the result explicitly.

- `false` + no mapping → `ignored_not_bms_client`.
- `true` + no mapping → create BMS Company and bind Bitrix Company ID.
- `true` + mapping → update same BMS Company.
- `false` + mapping → archive mapped BMS Company.

If KBA Connect knows the legacy Corporate Company ID, it SHOULD pass `legacycompanyid` to allow adoption of the existing stable company UUID.

## Outbound command contract

Supported command kinds:

- `company_reconcile`
- `employee_upsert`
- `employee_close`

Commands are durable and idempotent while pending. KBA Connect should perform external operations idempotently using external mappings.

For `employee_upsert`, successful completion requires both:

- `external_contact_id`
- `corporate_member_reconciled=true`

The expected consumer sequence is:

1. resolve external Bitrix Company ID;
2. resolve/create Bitrix Contact with that `COMPANY_ID`;
3. keep the existing Moodle User ID;
4. reconcile Corporate Learning company/member using the existing KBA Corporate bridge API;
5. call `complete_command()` with both success markers.

## Loop prevention

Inbound updates must first resolve stable external mapping and compare current values. The consumer must not write back unchanged data solely because its own Bitrix webhook fired. Mapping identity is Bitrix ID, never email/name.

## Deal/course contract

This release does not change the existing Deal-driven enrolment engine. Employee creation and Corporate membership are not proof of purchased course access.
