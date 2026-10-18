# KBA Connect v5.0.35 RC1 — Changelog

## Contact Role Sync
- ONCRMCONTACTUPDATE / profile_sync is no longer a no-op.
- Contact is reloaded from Bitrix24 when the queue item is processed.
- Existing Moodle user is resolved without creating a new LMS account.
- Contact COMPANY_ID is used to refresh Corporate membership.
- Corporate roles are read from the Bitrix Contact role field.
- Roles are handed to KBA Corporate Dashboard using MERGE only.
- Queue payload keeps the execution result for History/debug.
- Added [CORPORATE_ROLE_SYNC] logging and last-role-sync status on Corporate page.

## Access Transport
- accesschannel is now enforced.
- Moodle Email / SMTP uses email_to_user().
- `none` disables automatic access delivery.
- Unimplemented Bitrix24 email transports are no longer presented as working UI options.
- Configured sender name/email are applied to the Moodle sender object.
- Timeline records `Moodle Email / SMTP` as the actual channel.
- Stale v0.4.6 timeline templates that say Bitrix24 / {activityid} fall back to the current safe template.
