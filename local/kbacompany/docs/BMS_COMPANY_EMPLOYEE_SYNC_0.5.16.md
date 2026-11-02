# BMS Company & Employee Sync — v0.5.16

## Business model

The release formalizes three independent relationships:

1. **BMS Company / Employee** — organizational master data.
2. **Corporate Learning membership** — access to corporate training services.
3. **Course enrolment** — participation in one Moodle course.

They share Moodle User identity but must not be conflated.

## Inbound company flow

`Bitrix Company → KBA Connect evaluates «Клиент BMS» → BMS integration API → canonical Company`.

A company absent from BMS is created only when `bmsclient=true`. An already mapped company with `bmsclient=false` is archived, not deleted. Existing Corporate-backed BMS contexts keep their established UUID and can be adopted using `legacycompanyid` without rekeying OFS, positions, indicators, Battle Plans or Tasks.

## Inbound employee flow

`Bitrix Contact → KBA Connect → external Company mapping → Moodle User → BMS membership`.

Resolution order:

1. existing Bitrix Contact external mapping;
2. validated email as matching hint;
3. optional Moodle User creation only when caller explicitly allows it.

Bitrix Corporate Learning role codes are accepted as integration context but do not grant BMS owner/manager/HR rights automatically.

## Outbound employee flow

`BMS employee creation → Moodle User → BMS membership → syncout(employee_upsert)`.

The outbox command carries no Bitrix secret. It contains local identity and descriptive data needed by KBA Connect. The employee remains usable in BMS if external systems are unavailable.

The `employee_upsert` command is allowed to complete only when the consumer reports:

- `external_contact_id` resolved/created;
- `corporate_member_reconciled = true`.

At completion, Bitrix Contact ID is bound to Moodle User ID.

## Historical membership

Membership close sets `active=0` and `effectiveto`, preserving all historical references. Inbound Bitrix company transfer closes only earlier Bitrix-sourced memberships; manual BMS relationships are not destructively removed.

## Existing Corporate members

For legacy companies backed by `local_kbacorporate`, the Employees screen merges canonical BMS memberships with a read-only compatibility projection of existing Corporate members. Canonical membership wins if both exist. This avoids a blank employee directory during staged migration.

## Explicit non-goals

v0.5.16 does not:

- copy Corporate licences, seats, progress, grades, BBB or certificates into BMS;
- auto-create BMS Positions from CRM job-title text;
- auto-enrol employees into Moodle courses;
- move Bitrix credentials into BMS;
- write directly into `local_kbacorp_*` tables;
- merge BMS Company and Corporate Learning Company into one physical table.
