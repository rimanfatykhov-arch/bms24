# KBA Connect v5.0.31.4 RC1

## Access Delivery + Corporate Employee Sync

- Moodle login for newly created users is now the Bitrix24 contact email.
- A one-time temporary password is generated and password change is forced on first login.
- Access email uses the restored v0.4.6 message templates.
- Access delivery is duplicated to Bitrix24 contact and deal Timeline without exposing the password.
- Existing legacy `Moodle Password` field is populated when present and enabled.
- KBA-created, never-used accounts whose access was not delivered can be safely recovered once.
- Corporate employee attachment is explicitly synchronized through the KBA Corporate Dashboard public API independently of course license mode.
- Production Control Center shows Corporate and Access statuses per contact.

Regression case: Deal 3761 / Contact 12657 / Moodle User 41.
