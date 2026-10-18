# KBA Connect v5.0.30 — Message Templates Restore

## Restored from v0.4.6

The administration UI again exposes configurable message templates:

### Access email

- delivery channel;
- sender name;
- sender email;
- subject;
- body.

Original config names are preserved:
- accesschannel
- accesssendername
- accesssenderemail
- accessemailsubject
- accessemailbody

### Bitrix24 timeline common block

- timelineenabled
- timeline_sync_header
- timeline_sync_user
- timeline_sync_email
- timeline_sync_course
- timeline_sync_group

### Original event templates

- account created
- course enrolled
- group added
- learning started
- course completed
- access sent
- synchronization error
- first login
- progress threshold
- webinar attended

### v5 active summaries

Two additional templates are used by the current v5 CRM writeback:
- timeline_v5_deal_summary
- timeline_v5_company_summary

They replace the previously hard-coded Deal/Company timeline comments.

## Compatibility

Existing v0.4.6 config values are never overwritten by upgrade.
Defaults are created only when the setting does not already exist.
