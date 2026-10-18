# KBA Connect v5.0.36.1.22 — BMS Employee Phone Mapping Fix

- Outbound BMS employee EMAIL and PHONE are handled independently.
- An email-like legacy value is never written to Bitrix24 PHONE.
- Existing contacts are updated when a valid BMS/Moodle phone differs from the Bitrix phone.
- If the current Bitrix PHONE is clearly an email and no valid phone is available, only that invalid value is cleared.
- Company, Corporate membership and Deal-driven enrolment contracts are unchanged.
- Schema-neutral; no DDL.
