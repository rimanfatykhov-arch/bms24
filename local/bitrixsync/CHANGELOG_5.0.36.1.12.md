# KBA Connect v5.0.36.1.12 — Access Delivery Flow Fix

- Fixed the automatic v5 deal queue path: `deal_flow_service` now invokes `access_delivery_service`.
- Previously only `multi_contact_deal_service` invoked access delivery, so normal webhook/queue-created Moodle users could receive neither password email nor access Timeline comments.
- Email transport and Bitrix Timeline writes are now isolated.
- A Timeline error no longer converts an already-sent email into a failed delivery.
- Email failure attempts to write a safe failure comment to Contact/Deal Timeline.
- Added safe last-delivery diagnostics to Message Templates page.
- No password is stored in diagnostics or Timeline.
