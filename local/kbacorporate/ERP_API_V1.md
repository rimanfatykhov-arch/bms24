# KBA Corporate ERP API v1

Plugin release: `0.40.0`
Public PHP bridge: `1.21`
Stable contract release: `1.3.1`

Company payloads may additionally contain `primary_activity` (up to 255 characters)
and `founded_year` (1800 through the current year, or `0` when unknown). These
fields are optional and do not change contract version 1 compatibility.
ERP exchange contract major version: `1`

The contract is stable. Additive response fields are allowed within major version 1,
clients must ignore unknown response fields, and any breaking change requires a new
major version. Clients may compare `schema_hash` from the contract or status endpoint
to a previously approved fingerprint.

## Boundary and ownership

ERP owns company and employee external identifiers and sends role assignments. Moodle owns course participation, evidence, confirmed competency levels and individual development plans. External systems must never read or write `local_kbacorp_*` tables directly. Existing literal provider tokens, including the legacy token `odoo`, remain valid and must not be renamed after an integration starts.

The exchange is idempotent by `(system, entity_type, external_id)`. Repeating the same message is safe. Access roles are merged additively. An ERP event can deactivate only a business-role assignment previously created by that same mapped external assignment; manual assignments are preserved.

## Secure Moodle setup

1. Enable Moodle web services and the REST protocol.
2. Open **Site administration → Server → Web services → External services**.
3. Enable `KBA Corporate ERP API v1` only when integration testing starts.
4. Create a dedicated service user. Do not use a human administrator account.
5. Grant only `local/kbacorporate:useerpapi` at system context through a dedicated role.
6. Add the service user as an authorised user of this restricted service.
7. Create and securely store its Moodle web-service token in the ERP integration vault.

The service is intentionally installed disabled and restricted to explicitly authorised users.

## REST functions

| Function | Type | Purpose |
|---|---|---|
| `local_kbacorporate_erp_contract` | Read | Machine-readable version, ownership and payload contract |
| `local_kbacorporate_erp_status` | Read | Readiness, stable release and contract fingerprint |
| `local_kbacorporate_erp_push_entity` | Write | Idempotent company, employee or role-assignment upsert |
| `local_kbacorporate_erp_pull_company_snapshot` | Read | Paged Moodle competency and development snapshot |

Base endpoint:

`/webservice/rest/server.php?wstoken=TOKEN&moodlewsrestformat=json&wsfunction=FUNCTION`

Each function returns one UTF-8 JSON string. REST clients must decode the Moodle response and then decode that returned JSON string.

## Discovery and readiness

Call `local_kbacorporate_erp_contract` during connector setup and retain its
`version`, `release` and `schema_hash`. Call `local_kbacorporate_erp_status` for
readiness monitoring. A client must stop before writes when the contract major
version is unsupported or an unapproved schema fingerprint is detected.

The status response contains no company or employee data and exposes no credentials. Since
v0.40.0 it also returns aggregate `operational_audit` statistics and `hardening_ready`.

## Production hardening

- Each entity write is serialized by `(system, entity_type, external_id)` and committed transactionally.
- Payloads are limited to 65,535 bytes, depth 8 and 500 values; keys are limited to 100 bytes.
- Every local mapping target is checked against the declared company before persistence.
- The operational audit stores only HMAC hashes, status, duration and exception class code.
- Payloads, raw IDs, PII, credentials and exception messages are never written to the audit table.
- Expired technical records are removed daily according to the 30–3650 day retention setting.

## Push order

1. `company`
2. `employee`
3. `department`
4. `department_membership`
5. `business_role`
6. `business_role_assignment`
7. `learning_catalog_assignment`
8. `economic_value`

### Company payload

```json
{
  "name": "ООО Альфа",
  "code": "ALPHA",
  "active": true
}
```

### Employee payload

The backwards-compatible link mode uses an existing Moodle user ID:

```json
{
  "company_external_id": "odoo.company:42",
  "moodle_user_id": 153,
  "code": "EMP-007",
  "access_roles": ["employee", "hr"]
}
```

Allowed access-role values remain `employee`, `corporate_manager`, `department_manager`, and `hr`. Unknown values are ignored and reported by the internal merge result; existing roles are never removed.

Employee Sync can also resolve an existing account by exact username/email or explicitly create one:

```json
{
  "company_external_id": "odoo.company:42",
  "username": "employee.007",
  "firstname": "Иван",
  "lastname": "Иванов",
  "email": "employee.007@example.com",
  "create_if_missing": true,
  "code": "EMP-007",
  "access_roles": ["employee"]
}
```

Account creation is opt-in. A generated password is never returned; the new manual-auth
account is marked for mandatory password change. Only accounts created by the same ERP
employee mapping may later have firstname, lastname or email updated through this API.
Pre-existing Moodle accounts are linked without overwriting their profile.

Send a mapped employee with `"active": false` to soft-deactivate only the ERP-owned
company employee relation. The Moodle account is never suspended or deleted, historical
audits and plans are retained, and a pre-existing manual company membership is preserved.
Sending `"active": true` again reactivates the same mapping and membership.

### Department payload

```json
{
  "company_external_id": "odoo.company:42",
  "name": "Отдел продаж",
  "code": "SALES",
  "active": true
}
```

An ERP-created department may be renamed or soft-deactivated. If `code` resolves to an
existing manual department, the mapping is linked without overwriting or deactivating that
record. Department deactivation does not delete employee placements.

### Department membership payload

```json
{
  "company_external_id": "odoo.company:42",
  "department_external_id": "odoo.department:10",
  "employee_external_id": "odoo.employee:7",
  "department_role": "employee",
  "active": true
}
```

`department_role` is `employee` or `manager`. The identity tuple is immutable for one
external assignment ID. Deactivation removes only an assignment created by the same ERP
mapping; a pre-existing manual placement is preserved.

### Business role payload

```json
{
  "company_external_id": "odoo.company:42",
  "role_code": "BMS-ROLE-SALES-HEAD",
  "name": "Руководитель отдела продаж",
  "description": "Отвечает за систему продаж",
  "active": true
}
```

ERP manages only company-scoped role metadata that it created. A matching manual role is
linked without overwrite. Competency requirements, target levels, priorities and Learning
Plans are never changed by this operation. Deactivation is soft and preserves assignments
and competency-profile history.

### Business-role assignment payload

```json
{
  "company_external_id": "odoo.company:42",
  "employee_external_id": "odoo.employee:7",
  "role_code": "BMS-ROLE-OWNER",
  "active": true
}
```

Send the same external assignment ID with `"active": false` to deactivate an ERP-owned assignment. A pre-existing manual assignment is returned as `already_assigned_preserved` and will not be deactivated by ERP.

### Learning catalogue assignment payload

```json
{
  "company_external_id": "odoo.company:42",
  "course_idnumber": "KBA-SALES-101",
  "group_id": 0,
  "active": true
}
```

The course may be resolved by unique `course_idnumber` or `moodle_course_id`. This operation
only maintains `local_kbacorp_course`; it never enrols or unenrols a user, never modifies a
Moodle Learning Plan, never writes completion and never changes a competency level. A manual
catalogue row is preserved.

### Economic value payload

```json
{
  "company_external_id": "odoo.company:42",
  "indicator_code": "REV",
  "period_type": "month",
  "period_start": 1788210000,
  "scenario": "actual",
  "value": "1250000.50",
  "currency": "RUB"
}
```

Only a unique active indicator code is accepted. ERP values are stored with source `erp` and
remain unverified until Moodle review. A manual or differently mapped value for the same
indicator/period/scenario is never overwritten. Repeating an unchanged value creates no new
economic log entry. An economic event never establishes causality or BMS maturity change.

## Pull pagination

Call `local_kbacorporate_erp_pull_company_snapshot` with:

- `system` — stable provider token such as `odoo`;
- `companyexternalid` — the company ID previously pushed by that provider;
- `afteruserid` — exclusive cursor, initially `0`;
- `limit` — `1..200`, default `100`.

Continue while `page.has_more` is true, passing `page.next_after_moodle_user_id` into the next request.

The snapshot contains:

- company status;
- active organisation departments and company business roles with external IDs and aggregate counts;
- employee external and Moodle IDs, without name or email;
- Moodle-owned enrolment/completion status for company-catalogue courses;
- assessed competency state with explicit `unassessed` / `null` handling;
- latest economic values as non-causal context;
- additive access roles and BMS business roles;
- departments;
- current confirmed and target competency levels, gap and status;
- individual development-plan state and progress.

Evidence text, comments, email addresses, names, passwords, tokens and credentials are not exported.

## Operational rules

- Use one immutable `system` token for the lifetime of an integration.
- Never recycle external IDs for another entity.
- Set `create_if_missing=true` only after validating username, name and email in ERP.
- Deliver the Moodle password-reset/onboarding route outside this API; no password is returned.
- Treat learning status as read-only Moodle state; do not push completion through this contract.
- Route competency confirmation through Moodle assessment/reassessment, never through course completion.
- Review ERP economic values in Moodle before marking them verified.
- Keep payloads below 65,535 bytes and page size at or below 200.
- Retry the same message with the same external ID and idempotency key.
- Treat `success=false` and Moodle REST exceptions as failed operations; do not advance the source checkpoint.
- Run initial exchange against a non-production Moodle copy before enabling the production service.

## Semantic safeguards

- Course completion does not confirm a competency level.
- An unassessed competency is returned as `null`, never as level `0`.
- Course completion, an implementation action, or accepted evidence does not raise BMS maturity.
- BMS maturity changes only through a new confirmed audit.
- Learning, implementation and economic event correlation is not automatic proof of causality.
