# Employee Contact Projection Hotfix — BMS 0.6.9.1

Build: 2026092131

## Problem

KBA Connect already reads `PHONE` from Bitrix Contact and passes it to `local_kbacompany::upsert_employee_from_external()`. Before this hotfix, `local_kbacompany` used that phone only when creating a new Moodle user. If the Contact resolved to an existing Moodle user, `user.phone1` was left unchanged even when empty.

The BMS Company relationship is canonical in `local_kbacompany_member`; Moodle profile company fields are not the source of truth.

## Phone projection

Inbound Bitrix phone is accepted only when non-empty and not email-like.

For an existing Moodle user:

- empty `phone1` → fill from Bitrix;
- email-like `phone1` → replace with valid Bitrix phone;
- same valid phone → keep;
- different valid non-empty Moodle phone → keep Moodle value; do not overwrite automatically.

This preserves the existing outbound rule from KBA Connect v5.0.36.1.22: EMAIL and PHONE are independent and an email value must never be propagated as PHONE.

## Company display projection

Canonical ownership remains:

`Moodle User + BMS Company membership + Position assignment`.

The standard Moodle `user.institution` field is treated only as a display projection (the UI may label it as company/organization).

Rules:

- blank institution → fill with current canonical BMS Company name;
- same value → keep;
- value matching a BMS company from the user's membership history → it may be updated after a Company transfer;
- any other non-empty value → preserve as unmanaged data.

No custom profile field is created. No company name becomes an identity key.

## Diagnostics returned to KBA Connect

`upsert_employee_from_external()` now returns:

- `phone_projection`: `not_provided | invalid_ignored | created | filled | replaced_invalid | already_current | kept_existing`;
- `company_projection`: `not_available | created | filled | updated_managed_projection | already_current | kept_unmanaged`;
- current projected `phone`;
- current projected `profile_company`.

## Safety

- no course enrolment changes;
- no Corporate learning ownership changes;
- no Bitrix mapping identity changes;
- no schema changes;
- no new Person entity;
- no automatic overwrite of a valid user-maintained phone/company display value.
