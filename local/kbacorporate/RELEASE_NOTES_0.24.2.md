# KBA Corporate Dashboard 0.24.2

This release adds a simple approval loop to each BMS implementation request.

## Administrator

- Open the implementation program settings.
- Change the commercial or delivery status.
- Add a response for the customer and save.

## Customer

- Receives a Moodle notification and optional email when a status changes.
- Sees the current statuses, KBA response and last status-update date in the request card.

Contact-only edits do not generate unnecessary status notifications. Delivery failure is logged and never blocks the saved administrative change.
