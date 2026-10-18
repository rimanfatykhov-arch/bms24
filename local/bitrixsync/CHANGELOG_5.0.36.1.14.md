# KBA Connect v5.0.36.1.14 — Automatic Multi-Contact Deal Processing Fix

- Normal Deal webhook/queue processing now invokes `multi_contact_deal_service`.
- Explicit targeted queue items with `payload.userid` retain the single-user `deal_flow_service` path.
- Fixes secondary Bitrix Contacts being discovered but never created/enrolled in Moodle.
- Stores the last multi-contact execution summary for production verification.
