# BMS24 RC29 — Communication Center 3.0 (build2026101823)

## Compatibility and scope

- Plugin: `local_kbacompany 1.1.0-rc29`, build `2026101823`.
- Based on `build2026101822`; includes 1821/1822 communication hotfixes.
- Moodle 4.0+ version requirement retained. Target acceptance: Moodle 4.1 / PHP 8.1.34.
- No schema changes, new capabilities, or additional module dependencies.
- Existing messages, users, tasks, links and company ACL remain owned by canonical services.

## Changes

- Conversation rows now show type-safe initial avatars, names, timestamps, latest message previews and unread/mention badges.
- Compact current-dialog header contains action to reveal message search and open the object context drawer. The drawer has a separate close button.
- Message history differentiates incoming/outgoing by **actual author ID**, groups messages by dates and automatically scrolls to the latest page by default.
- Composer uses the existing session-protected POST endpoint, prevents double submission, supports Enter-to-send and Shift+Enter-for-newline, ignores IME composition and retains draft after a failed request.
- Sidebar scroll position is remembered per company for the browser tab (scroll offset only; no message content stored).
- On narrow screens, list and active conversation switch with a return link.
- Existing contextual actions from messages, attachments, mentions, discussion creation and server-side pagination are preserved.
- Legacy communication context navigation is still suppressed; app shell remains unchanged.

## Changed files

- `communication_center.php`
- `classes/local/communication_center_ui_service.php`
- `amd/src/communication_live_ui.js` and `amd/build/communication_live_ui.min.js`
- `styles.css`
- `lang/ru/local_kbacompany.php`, `lang/en/local_kbacompany.php`
- `version.php`, `db/upgrade.php`
- `cli/check_communication_1822_hotfix.py` (version compatibility regression)

## Installation

1. Back up Moodle and the database.
2. Install ZIP as upgrade for `local_kbacompany`.
3. Apply Moodle upgrade until version `2026101823`; purge caches.
4. Hard-reload `communication_center.php`.
5. Test with employee, manager, two companies and a long conversation list.

## Acceptance tests required on server

- Three-pane layout and independent scroll, plus mobile list/dialog switching.
- Correct read/unread badges, author placement and context permissions.
- Real messages, attachments, searches, older-message navigation and error handling.
- Enter, Shift+Enter and IME keyboard cases.
- Explicit access-denial checks for cross-company thread IDs.

**Local static/browser smoke tests are not production Moodle E2E tests.**
