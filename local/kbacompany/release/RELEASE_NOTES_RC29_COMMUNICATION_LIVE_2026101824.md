# BMS24 RC29 — Communication Center 3.1 live-refresh (build2026101824)

**Plugin:** `local_kbacompany 1.1.0-rc29`\
**Version:** `2026101824`\
**Required Moodle:** 4.0+\
**Schema changes:** none\
**New roles/capabilities:** none\
**Affected page:** `communication_center.php`

## What changed

- Added a low-bandwidth, read-only JSON change probe at `communication_live_poll.php`, backed by `classes/local/communication_live_service.php`.
- The probe is scoped to the logged-in company member and validates both thread membership and the underlying object ACL. It provides only a SHA-256 change token and a partial-window flag; no message text or identifiers.
- Probe frequency is 25 seconds **only when the page is visible and online**. Requests are serial, exponential backoff is applied on failure, and 401/403 stops the probe.
- Full authorized page HTML is fetched **only after a change**. Only the conversation list, counters/rail and messages are patched; composer, selected context and navigation stay mounted.
- A typed draft, chosen attachment, focused input, message search and historical reading protect the current work from live DOM replacements. Older messages are never silently switched to a new page.
- New messages are shown at the bottom when the reader is already at the bottom. When reading older messages, the existing scroll position is kept and an action offers navigation to the newest messages.
- Notification-only screens may update their visible feed without recreating the BMS24 shell.
- The native Moodle POST + sesskey mechanism remains the only message write path.

## Rate and functional limits

- A live probe inspects up to the 60 most recently active participant threads and up to 20 notifications, plus the selected thread when necessary. This bounded window prevents unlimited work per poll, but an account with many older threads may need manual refresh to see activity outside the monitored window.
- This is **periodic polling**, not a WebSocket or instant-delivery service.
- No live presence, typing indicators, delivery/read receipts, mobile push or cross-site message sharing are added.
- The selected conversation uses the canonical participant and underlying-object authorization checks; if permission is lost, the probe no longer supplies a usable snapshot.

## Installation

1. Backup Moodle code and database.
2. Update `local_kbacompany` from the INSTALL archive.
3. Run the normal Moodle plugin upgrade and purge all caches.
4. Open the Communication Center in two user sessions that share a conversation.
5. Send a message in session B and, without reloading session A, verify its appearance within the polling interval.
6. Repeat while session A has an unsent draft, while it is scrolled up, and while its tab is hidden.
7. Check a conversation whose underlying task permissions have been revoked: it must never be disclosed by the probe or the refreshed page.

## Files

- `communication_center.php`
- `communication_live_poll.php` (new)
- `classes/local/communication_live_service.php` (new)
- `amd/src/communication_live_ui.js`
- `amd/build/communication_live_ui.min.js`
- `styles.css`
- `lang/ru/local_kbacompany.php`
- `lang/en/local_kbacompany.php`
- `db/upgrade.php`
- `version.php`
- `cli/check_communication_1824_contract.py` (new)
- `tests/communication_live_service_test.php` (new)

## Acceptance status

Local syntax, regression, model contract and isolated browser checks completed. Live Moodle 4.1 / PHP 8.1 / MySQL 5.7 acceptance and performance measurements are still required before production sign-off.
