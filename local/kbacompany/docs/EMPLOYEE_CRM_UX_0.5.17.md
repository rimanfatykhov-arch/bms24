# Employee CRM UX — BMS 0.5.17

Build: 2026091519

## Bounded-context rule
`local_kbacompany` owns BMS Company membership, organization/staffing, BMS roles and the integration outbox. Moodle Core owns User. KBA Connect owns Bitrix REST/external orchestration and bridges to KBA Corporate. KBA Corporate keeps learning membership/licences/progress. KBA Theme remains presentation/App Shell only.

## UX goal
Use a CRM-style employee workflow inspired by classic SugarCRM navigation patterns: compact list → record → edit → explicit confirmation for removal. No SugarCRM branding or copied visual design.

## Identity
There is no separate Person entity. The employee is a canonical Moodle User plus historical `local_kbacompany_member` membership and staffing assignment. Bitrix Contact ID is an external mapping to Moodle User ID. Email is editable matching data, not the durable external identity.

## Employee list
- Employee name opens the record.
- Compact View / Edit / Delete actions are shown for active canonical memberships when the actor has company-management ACL.
- Archived memberships are view-only.
- Compatibility-projection employees from KBA Corporate are view-only until reconciliation.
- Canonical record links carry membership UUID so historical/reopened memberships do not collapse to an ambiguous user-only URL.

## Employee record
Shows company, membership status/source/effective dates, Moodle User ID, full name, email, phone, organizational unit, position, current BMS roles, Bitrix Contact ID and sync state. It links to the existing BMS Organization and Positions sections. No new profile table is created.

## Edit
Editable fields are firstname, lastname, email and phone from canonical Moodle User. Server-side checks enforce company isolation, management ACL, CSRF sesskey, email format, Moodle same-email policy and phone/email separation. Successful save updates Moodle User and queues `employee_upsert`; active membership is not recreated.

## Sync status
The latest employee-specific outbox command (`employee_upsert` or `employee_close`) is considered. Pending/error commands take precedence over an already existing Bitrix Contact mapping, so the CRM does not show a stale “Synchronized” state immediately after a new outbound change.

## Delete semantics
“Remove from company” closes the active canonical membership (`active=0`, `effectiveto=now`) and queues `employee_close`. It never physically deletes Moodle User, Bitrix Contact, Battle Plans, Tasks, indicators or history. Mutation is only available from the dedicated confirmation page; the list has no direct close POST endpoint.

## Diagnostics
Consumer availability is true when either the KBA Connect bridge service class or `\local_bitrixsync\task\process_bms_bridge` is available. This preserves compatibility with the current KBA Connect production consumer.

## Schema and compatibility
Schema-neutral; no new capabilities or external services. New code is written within PHP 7.3 syntax constraints and targets Moodle 4.1.22 while retaining the plugin minimum Moodle 4.0 requirement.
