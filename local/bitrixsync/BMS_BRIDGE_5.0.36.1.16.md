# BMS Company & Employee Bridge

## Required companion
- local_kbacompany v0.5.16 build 2026091514 or later.
- local_kbacorporate API v1.x with `sync_employee()` / `remove_employee()`; server baseline 0.16.10 API 1.13 is compatible.

## Activation
1. Upgrade local_kbacompany to v0.5.16.
2. Upgrade KBA Connect to v5.0.36.1.16.
3. Open KBA Connect CRM Fields and provision/verify Company field `KBA — Клиент BMS`.
4. Enable `BMS company and employee bridge`.
5. Enable Company webhook acceptance if real-time Company changes are required.
6. In Bitrix24, send `ONCRMCOMPANYADD` and `ONCRMCOMPANYUPDATE` to the existing secured v5 webhook URL using the configured outgoing token.
7. Mark only real BMS customers with `KBA — Клиент BMS = Да`.
8. Ensure Moodle cron runs every minute.

## Outbound employee flow
BMS employee -> Moodle User -> BMS outbox -> KBA Connect -> Bitrix Contact -> KBA Corporate employee membership -> BMS command done.

## Inbound company flow
Bitrix Company -> explicit BMS flag -> KBA Connect -> BMS Company -> Company Contacts -> existing/created Moodle Users -> BMS memberships -> KBA Corporate employee memberships.

## Learning flow
Unchanged: Bitrix Deal -> Products -> Moodle Courses -> Enrolments.
