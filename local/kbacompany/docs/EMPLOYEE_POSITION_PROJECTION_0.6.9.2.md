# Employee Position Projection — 0.6.9.2

## Purpose
Close the display gap visible in KBA Corporate employee cards where Bitrix Contact has a standard `POST` value but Moodle/KBA Corporate shows an empty Position.

## Contract
`Bitrix Contact.POST → KBA Connect → local_kbacompany inbound employee contract → Moodle custom profile field shortname position → KBA Corporate display`.

## Ownership
The Moodle profile field is a compatibility/display projection only. Canonical BMS Position and dated Position Assignment remain owned by BMS and are never auto-created from CRM free text.

## Update rules
- non-empty incoming position: fill or update profile field `position`;
- identical value: unchanged;
- empty incoming position: do not clear existing value;
- missing profile field: return `profile_field_missing`;
- no enrolment or competency side effects.

## Diagnostics
Inbound result includes `position_projection` and `profile_position`.
