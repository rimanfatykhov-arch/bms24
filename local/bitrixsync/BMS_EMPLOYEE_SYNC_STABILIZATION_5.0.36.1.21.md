# BMS Employee Sync Stabilization — 5.0.36.1.21

## Inbound

`Bitrix Company -> Corporate snapshot -> BMS Company upsert -> Contacts -> Moodle User resolution -> BMS membership -> Corporate membership`.

If `crm.contact.get` is empty/unavailable, the Company contact list row is used as a fallback profile with EMAIL/PHONE/NAME/COMPANY_ID so email matching can still resolve an existing Moodle user. Every contact is represented in the result; hydration failure is explicit.

## Outbound

`BMS employee_upsert -> resolve Company external identity -> resolve/create Bitrix Contact -> KBA Corporate membership -> complete BMS outbox`.

Recovered Corporate Company mapping is persisted in KBA Connect so pending commands can resolve through their legacy Corporate company ID after Company reconciliation.

## Boundaries

KBA Connect remains the only Bitrix REST owner. `local_kbacompany` does not call Bitrix24 directly. `local_kbacorporate` is accessed only through its documented bridge API. Course enrolment remains Deal-driven.
