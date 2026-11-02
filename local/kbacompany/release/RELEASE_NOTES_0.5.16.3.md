# BMS v0.5.16.3 — Employee Phone Sync Safety

- Existing Moodle users now accept an explicitly entered BMS employee phone into `user.phone1`.
- `employee_upsert` uses the resulting Moodle phone rather than ignoring the form value.
- Email-like legacy values in `phone1` are never emitted as phone data.
- Re-entering the same employee email remains idempotent for User and membership and creates a fresh outbound reconciliation command when needed.
- Schema-neutral; no DDL.
