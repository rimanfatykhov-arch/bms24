# BMS Employee Position Mapping — 5.0.36.1.23

Flow: Bitrix Contact `POST` → KBA Connect → BMS employee inbound contract → Moodle custom profile field `position`.

Rules:
1. `POST` is the CRM source for the display projection only.
2. KBA Connect forwards trimmed `POST` as `position`.
3. Empty `POST` does not request clearing of existing Moodle profile data.
4. Canonical BMS Position and Position Assignment are not created from CRM free text.
5. Course enrolment and corporate-role flows are unchanged.
