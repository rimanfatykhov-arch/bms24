# BMS v0.5.16 — Company & Employee Sync

Build: `2026091514`

## Purpose

v0.5.16 introduces the canonical BMS Company and Employee integration foundation before the Financial Model. It separates business-company/employee master data from Corporate Learning while preserving the existing Bitrix24 Deal → Product → Moodle Course enrolment flow.

## Company admission

A new Bitrix24 company may enter BMS only after KBA Connect has explicitly evaluated the Bitrix Company flag **«Клиент BMS» = Да** and calls the BMS integration API with `bmsclient=true`.

- Bitrix Company ID is the stable external identity.
- Company name is descriptive data and is never the join key.
- Repeated sync updates the same BMS Company.
- `Да → Нет` archives the BMS Company; history is not deleted.
- Existing pre-0.5.16 Corporate-backed companies are grandfathered and retain their historical BMS UUIDs until explicitly adopted/mapped.

## Employees

A BMS employee is **Moodle User + historical Company membership**, not a second Person/User record.

- Bitrix Contact ID maps to Moodle User ID through provider-neutral external mapping.
- Email is only a matching/creation hint, not the permanent external key.
- Membership history uses `effectivefrom/effectiveto` and archive semantics.
- Existing `local_kbacorporate` members remain visible as a read-only compatibility projection until reconciliation.
- Corporate Learning roles never silently grant new BMS management permissions.

## BMS → Bitrix24 → KBA Corporate

Creating an employee manually in BMS:

1. resolves or creates the Moodle User;
2. creates canonical BMS membership;
3. writes a durable `employee_upsert` command to the BMS outbox;
4. KBA Connect is responsible for creating/resolving the Bitrix Contact under the mapped Bitrix Company;
5. KBA Connect is responsible for reconciling the same Moodle User into `local_kbacorporate` membership;
6. only after both steps may KBA Connect mark the command completed.

`local_kbacompany` never stores Bitrix credentials and never calls Bitrix24 REST directly.

## Course enrolment boundary

Employee membership does **not** enrol courses.

Existing semantics remain:

`Bitrix24 Deal → Product → Moodle Course → Moodle User → Enrolment`.

Therefore a non-BMS company may buy a course without becoming a BMS Company, while a BMS Company may have employees with zero course enrolments.

## Schema

Additive tables:

- `local_kbacompany_company`
- `local_kbacompany_member`
- `local_kbacompany_extmap`
- `local_kbacompany_syncout`

No existing table is dropped or destructively rewritten.

## Deployment note

The BMS-side internal API and durable outbox are included in v0.5.16. Full outbound execution requires a companion KBA Connect build that consumes the v0.5.16 outbox contract. If the installed KBA Connect does not expose the consumer callback, commands remain durable in `pending` state and are shown in integration diagnostics; BMS employee creation is not rolled back.
