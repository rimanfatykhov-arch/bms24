# Release 0.6.9.1 — Employee Contact Projection Hotfix

- Fixes inbound Bitrix Contact phone projection for an already existing Moodle user.
- A valid incoming phone fills `user.phone1` only when Moodle phone is empty or obviously invalid/email-like.
- An existing valid Moodle phone is preserved to avoid bidirectional sync ping-pong.
- Moodle `institution` is used only as a display projection of the BMS Company: an empty value is filled; an unmanaged non-empty value is never overwritten.
- Canonical Company identity remains `local_kbacompany_member` + BMS Company UUID.
- New inbound diagnostics are returned to KBA Connect: `phone_projection` and `company_projection`.
- Employee record shows the Moodle profile company projection separately from the canonical BMS Company.
- No database schema, ACL, Web Service, enrolment or competency changes.
